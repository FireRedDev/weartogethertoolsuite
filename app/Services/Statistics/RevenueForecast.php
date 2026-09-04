<?php

namespace App\Services\Statistics;

use App\Models\SeasonGoal;
use Illuminate\Support\Carbon;

/**
 * Hochrechnung des Gesamtumsatzes für das laufende Schuljahr.
 *
 * Methode: aus den abgeschlossenen Vorjahren wird der SAISONALE VERLAUF
 * gemittelt — welcher Anteil des Jahresumsatzes fiel jeweils in welchen Monat.
 * Ein Schuljahr verläuft stark ungleichmäßig (die meisten Bestellfenster
 * liegen im Herbst und im Frühjahr), eine lineare Hochrechnung „bisheriger
 * Umsatz ÷ verstrichene Tage" wäre daher grob falsch.
 *
 *     Prognose = Umsatz bis heute ÷ erwarteter Anteil bis heute
 *
 * Der Zielumsatz kommt aus der gespeicherten Saisonvorgabe (`SeasonGoal`);
 * ohne Eintrag gilt der Vorjahreswert. Dort stehen auch die Umsätze außerhalb
 * des Webshops: bereits erzielte zählen zum Ist, zusätzlich erwartete nur in
 * die Hochrechnung.
 */
class RevenueForecast
{
    /**
     * @param  list<array<string, mixed>>  $history  abgeschlossene Vorjahre (Ergebnisse aus RevenueReport)
     * @param  array<string, mixed>  $current
     * @param  bool  $allSources  Sind ALLE Umsatzquellen eingeschaltet?
     * @return array<string, mixed>
     */
    public function build(
        array $current,
        array $history,
        ?SeasonGoal $goal = null,
        ?Carbon $today = null,
        bool $allSources = true,
    ): array {
        /** @var SchoolYear $year */
        $year = $current['year'];
        $today = $today ?? Carbon::today();
        $previousTotal = (float) ($history[0]['revenue'] ?? 0.0);

        $target = $goal?->target_revenue;
        $targetIsDefault = $target === null;

        /*
         * Ohne eingetragenes Ziel gilt der Vorjahresumsatz — aber nur, wenn
         * ALLE Quellen eingeschaltet sind. Ist eine abgeschaltet, ist der
         * Vorjahreswert nur ein Ausschnitt: Mit ausgeschalteter Shop-Quelle
         * bliebe vom Vorjahr allein das Bargeld übrig, und aus 48.166 € würden
         * 4.400 €. Das Saisonziel ist eine Vereinbarung im Team und darf sich
         * nicht danach richten, welche Schalter gerade jemand gesetzt hat —
         * lieber gar kein Ziel als ein zehnmal zu niedriges.
         */
        $targetKnown = $target !== null || $allSources;
        $target = $target ?? ($allSources ? $previousTotal : 0.0);

        // Umsätze außerhalb des Webshops: `manualRevenue` ist bereits erzielt
        // und zählt zum Ist, `manualForecast` ist zusätzlich erwartet und
        // zählt nur in die Hochrechnung.
        $manualRevenue = $goal?->manualRevenue() ?? 0.0;
        $manualForecast = $goal?->manualForecast() ?? 0.0;

        $usable = array_values(array_filter($history, static fn ($a) => (float) $a['revenue'] > 0));
        $shape = $this->seasonalShape($usable);
        $monthIndex = $this->monthIndex($year, $today);
        $cumulativeShare = $this->cumulativeShare($shape, $monthIndex, $year, $today);

        $ytd = (float) $current['revenue'];
        $complete = ! $year->isCurrent() || $monthIndex === null;

        $projection = null;
        $reason = null;
        if ($complete) {
            $projection = $ytd;
            $reason = 'Das Schuljahr ist abgeschlossen — die Zahl ist der tatsächliche Umsatz, keine Prognose.';
        } elseif ($shape === null) {
            $reason = 'Für eine Hochrechnung fehlen Vergleichsdaten: in den Vorjahren wurde kein Umsatz erfasst.';
        } elseif ($cumulativeShare < 0.02) {
            $reason = 'Das Schuljahr hat gerade erst begonnen — für eine belastbare Hochrechnung ist es noch zu früh.';
        } else {
            $projection = round($ytd / $cumulativeShare, 2);
        }

        $monthsLeft = $monthIndex === null ? 0 : max(0, 11 - $monthIndex);

        // Ist und Hochrechnung jeweils inklusive der manuellen Umsätze — das
        // sind die Zahlen, gegen die das Ziel gemessen wird.
        $achieved = round($ytd + $manualRevenue, 2);
        $projectionTotal = $projection === null ? null : round($projection + $manualRevenue + $manualForecast, 2);

        return [
            'possible' => $projection !== null && ! $complete,
            'complete' => $complete,
            'reason' => $reason,
            'basis' => array_map(static fn ($a) => (string) $a['label'], $usable),
            'ytd' => round($ytd, 2),
            'manualRevenue' => $manualRevenue,
            'manualForecast' => $manualForecast,
            'achieved' => $achieved,
            'cumulativeShare' => round($cumulativeShare, 4),
            'projection' => $projection,
            'projectionTotal' => $projectionTotal,
            'remaining' => $projection === null ? null : round(max(0, $projection - $ytd), 2),
            'target' => round($target, 2),
            'targetIsDefault' => $targetIsDefault,
            'targetKnown' => $targetKnown,
            'previousTotal' => round($previousTotal, 2),
            'previousTotalComplete' => $allSources,
            'targetShare' => $targetKnown && $target > 0 ? round($achieved / $target, 4) : null,
            'gapToTarget' => $projectionTotal === null || ! $targetKnown ? null : round($projectionTotal - $target, 2),
            'openToTarget' => round(max(0, $target - $achieved), 2),
            'monthsLeft' => $monthsLeft,
            'neededPerMonth' => $targetKnown && $monthsLeft > 0 ? round(max(0, $target - $achieved) / $monthsLeft, 2) : null,
            'curve' => $this->curve($current, $history[0] ?? null, $shape, $monthIndex, $projection),
        ];
    }

    /**
     * Gemittelter Monatsanteil am Jahresumsatz über die Vorjahre.
     *
     * @param  list<array<string, mixed>>  $history
     * @return list<float>|null  zwölf Anteile, Summe 1.0
     */
    private function seasonalShape(array $history): ?array
    {
        if ($history === []) {
            return null;
        }

        $sums = array_fill(0, 12, 0.0);
        $used = 0;
        foreach ($history as $aggregate) {
            $total = (float) $aggregate['revenue'];
            if ($total <= 0) {
                continue;
            }
            foreach (array_values($aggregate['months']) as $index => $month) {
                if ($index > 11) {
                    break;
                }
                $sums[$index] += $month['revenue'] / $total;
            }
            $used++;
        }

        if ($used === 0) {
            return null;
        }

        $shape = array_map(static fn (float $sum) => $sum / $used, $sums);
        $sum = array_sum($shape);

        return $sum > 0 ? array_map(static fn (float $s) => $s / $sum, $shape) : null;
    }

    /** Der wievielte Monat des Schuljahres läuft gerade? null, wenn außerhalb. */
    private function monthIndex(SchoolYear $year, Carbon $today): ?int
    {
        foreach (array_values($year->months()) as $index => $month) {
            if ($today->betweenIncluded($month['start'], $month['end'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Welcher Anteil des Jahresumsatzes müsste bis heute erreicht sein?
     * Volle Monate zählen ganz, der laufende anteilig nach Tagen.
     *
     * @param  list<float>|null  $shape
     */
    private function cumulativeShare(?array $shape, ?int $monthIndex, SchoolYear $year, Carbon $today): float
    {
        if ($shape === null || $monthIndex === null) {
            return $monthIndex === null ? 1.0 : 0.0;
        }

        $share = 0.0;
        for ($i = 0; $i < $monthIndex; $i++) {
            $share += $shape[$i];
        }

        $month = array_values($year->months())[$monthIndex];
        $daysInMonth = max(1, (int) $month['start']->diffInDays($month['end']) + 1);
        $elapsed = min($daysInMonth, (int) $month['start']->diffInDays($today) + 1);
        $share += $shape[$monthIndex] * ($elapsed / $daysInMonth);

        return min(1.0, $share);
    }

    /**
     * Kumulierter Verlauf für das Diagramm: laufendes Jahr bis heute,
     * Vorjahr komplett, und die Fortschreibung bis Schuljahresende.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $previous
     * @param  list<float>|null  $shape
     * @return list<array{short: string, label: string, current: ?float, previous: ?float, forecast: ?float}>
     */
    private function curve(array $current, ?array $previous, ?array $shape, ?int $monthIndex, ?float $projection): array
    {
        $currentMonths = array_values($current['months']);
        $previousMonths = $previous === null ? [] : array_values($previous['months']);

        $curve = [];
        $runCurrent = 0.0;
        $runPrevious = 0.0;

        foreach ($currentMonths as $index => $month) {
            $runCurrent += $month['revenue'];
            $runPrevious += (float) ($previousMonths[$index]['revenue'] ?? 0.0);

            $isPastOrNow = $monthIndex === null || $index <= $monthIndex;
            $forecast = null;
            if ($projection !== null && $shape !== null && $monthIndex !== null && $index >= $monthIndex) {
                // Ab dem laufenden Monat mit dem Saisonverlauf weiterschreiben.
                $shareToHere = 0.0;
                for ($i = 0; $i <= $index; $i++) {
                    $shareToHere += $shape[$i];
                }
                $forecast = $index === $monthIndex
                    ? round($runCurrent, 2)
                    : round($projection * min(1.0, $shareToHere), 2);
            }

            $curve[] = [
                'short' => $month['short'],
                'label' => $month['label'],
                'current' => $isPastOrNow ? round($runCurrent, 2) : null,
                'previous' => $previousMonths === [] ? null : round($runPrevious, 2),
                'forecast' => $forecast,
            ];
        }

        return $curve;
    }
}
