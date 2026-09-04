<?php

namespace App\Services\Statistics;

use App\Models\SeasonGoal;

/**
 * Die Planungsrechnung der Saison: Wie weit ist das Ziel noch weg, und wie
 * viele Bestellfenster braucht es dafür noch?
 *
 * Gedacht zum laufenden Mitplanen — die Frage im Alltag lautet nicht „wie hoch
 * ist der Umsatz", sondern „wie viele Schulen müssen wir noch gewinnen".
 *
 * **Sammelbestellfenster und On-Demand-Shops bringen unterschiedlich viel.**
 * Deshalb wird der Bedarf je Art getrennt gerechnet, mit einem Durchschnitt,
 * der sich aus dem Vorjahr UND der bisherigen Saison ergibt. Gewichtet wird
 * dabei nach Anzahl: Je mehr Fenster die laufende Saison schon hat, desto mehr
 * bestimmt sie den Wert.
 *
 * Gezählt werden nur **abgeschlossene** Fenster. Ein noch laufendes hat
 * naturgemäß weniger Umsatz und würde den Durchschnitt drücken — die Planung
 * käme dann zu pessimistisch heraus.
 */
class SeasonPlan
{
    /**
     * @param  array<string, mixed>  $current  Auswertung des laufenden Schuljahres
     * @param  array<string, mixed>|null  $previous  Auswertung des Vorjahres
     * @param  array<string, mixed>  $forecast  Ergebnis von RevenueForecast
     * @return array<string, mixed>
     */
    public function build(array $current, ?array $previous, array $forecast, SeasonGoal $goal): array
    {
        $target = (float) $forecast['target'];
        $achieved = (float) $forecast['achieved'];
        // Ohne bekanntes Ziel gibt es nichts zu planen: Ist eine Umsatzquelle
        // abgeschaltet, taugt der Vorjahreswert nicht als Vorgabe (siehe
        // RevenueForecast). Dann bleibt die Bedarfsrechnung leer statt gegen
        // eine Zahl zu rechnen, die nur die halbe Wahrheit ist.
        $targetKnown = (bool) ($forecast['targetKnown'] ?? true);
        $open = $targetKnown ? round(max(0.0, $target - $achieved), 2) : 0.0;

        $types = [];
        foreach (['collective' => 'Sammelbestellfenster', 'ondemand' => 'On-Demand-Shop'] as $key => $label) {
            $types[$key] = $this->forType($key, $label, $current, $previous, $open);
        }

        // Was die Hochrechnung ohnehin noch erwartet — die Lücke DARÜBER hinaus
        // ist das, was zusätzlich hereingeholt werden muss.
        $expectedRest = $forecast['projection'] === null
            ? null
            : round(max(0.0, (float) $forecast['projectionTotal'] - $achieved), 2);
        $gapAfterForecast = $expectedRest === null ? null : round(max(0.0, $open - $expectedRest), 2);

        return [
            'target' => round($target, 2),
            'targetKnown' => $targetKnown,
            'achieved' => round($achieved, 2),
            'open' => $open,
            'reached' => $targetKnown && $open <= 0.0,
            'expectedRest' => $expectedRest,
            'gapAfterForecast' => $gapAfterForecast,
            'types' => $types,
            'hasBasis' => $types['collective']['avg'] !== null || $types['ondemand']['avg'] !== null,
        ];
    }

    /**
     * Bedarf für EINE Fensterart.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    private function forType(string $key, string $label, array $current, ?array $previous, float $open): array
    {
        $now = $current[$key];
        $before = $previous[$key] ?? null;

        // Gewichteter Durchschnitt über abgeschlossene Fenster beider Jahre.
        $count = (int) $now['done'] + (int) ($before['done'] ?? 0);
        $sum = (float) $now['doneRevenue'] + (float) ($before['doneRevenue'] ?? 0.0);
        $avg = $count > 0 && $sum > 0 ? round($sum / $count, 2) : null;

        return [
            'label' => $label,
            'total' => (int) $now['count'],
            'done' => (int) $now['done'],
            'running' => (int) $now['running'],
            'upcoming' => (int) $now['upcoming'],
            'revenue' => round((float) $now['revenue'], 2),
            'avg' => $avg,
            'avgBasis' => $count,
            'avgFromPrevious' => (int) ($before['done'] ?? 0),
            'needed' => $avg !== null && $open > 0 ? (int) ceil($open / $avg) : null,
        ];
    }
}
