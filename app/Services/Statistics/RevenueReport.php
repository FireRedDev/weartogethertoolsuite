<?php

namespace App\Services\Statistics;

use App\Models\SchoolOnboarding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rechnet aus den Shop-Bestellungen die Kennzahlen des Statistik-Moduls —
 * je Schuljahr einmal, plus dasselbe fürs Vorjahr zum Vergleich.
 *
 * Zwei Zuordnungen laufen nebeneinander und beantworten verschiedene Fragen:
 *
 *  - **Nach Schuljahr**: eine Bestellposition zählt in das Schuljahr, in dem
 *    das Bestelldatum liegt. Das ist die Grundlage für Gesamtumsatz,
 *    Monatsverlauf, Ø je Bestellung und die Ranglisten.
 *  - **Nach Bestellfenster**: eine Position zählt zum Fenster ihrer Schule,
 *    wenn das Bestelldatum im (bewusst breiter gefassten) Fensterzeitraum
 *    liegt. Das ist die Grundlage für „Ø Umsatz je Bestellfenster". Ein
 *    Fenster gehört zu dem Schuljahr, in dem es endet.
 *
 * Der Fensterzeitraum ist absichtlich größer als im Antrag eingestellt: nach
 * Ablauf wird häufig noch eine Woche verlängert, und Nachzügler bestellen auch
 * danach. Da nie mehrere Fenster derselben Schule direkt aneinander liegen,
 * kann der Puffer keine fremden Bestellungen einsammeln.
 */
class RevenueReport
{
    public function __construct(private readonly OrderRepository $repository) {}

    /**
     * @return array<string, mixed>
     */
    public function build(StatisticsFilters $filters): array
    {
        // Zeitbudget dieses Seitenaufrufs starten. Reicht es nicht für alle
        // Monate, liefert das Ergebnis `complete = false` und die Seite sagt,
        // dass sie noch aufgebaut wird — statt minutenlang zu hängen.
        $this->repository->startBudget();

        $products = $this->repository->products($filters->fresh);
        $schools = SchoolOnboarding::query()
            ->whereNotNull('woo_category_id')
            ->orderBy('school_name')
            ->get();

        $current = $this->aggregate($filters->year, $filters, $products, $schools);
        $previous = $this->aggregate($filters->year->previous(), $filters, $products, $schools);

        /*
         * Grundlage der Prognose: die abgeschlossenen Vorjahre, neuestes zuerst.
         * Das erste ist bereits berechnet. Jedes weitere kostet weitere Abrufe —
         * die werden nur angestoßen, wenn die eigentliche Auswertung schon
         * vollständig ist UND noch Zeit übrig ist. Sonst wartet die Seite auf
         * Daten, die nur die Prognose verfeinern.
         */
        $history = $previous['year']->isComplete() ? [$previous] : [];
        $extra = (int) config('statistics.forecast.history_years') - 1;
        for ($i = 1; $i <= max(0, $extra); $i++) {
            if (! $current['complete'] || ! $previous['complete'] || $this->repository->budgetLeft() <= 0) {
                break;
            }
            $olderYear = new SchoolYear($filters->year->startYear - 1 - $i);
            if (! $olderYear->isComplete()) {
                break;
            }
            $older = $this->aggregate($olderYear, $filters, $products, $schools);
            if (! $older['complete']) {
                break;
            }
            $history[] = $older;
        }

        // Vergleichbarer Zwischenstand: Vorjahr bis zum selben Tag im Schuljahr.
        $dayOffset = $filters->year->isCurrent()
            ? (int) $filters->year->start()->diffInDays(Carbon::today())
            : null;
        $previousAtSamePoint = $dayOffset === null
            ? $previous['revenue']
            : $this->revenueUntilDayOffset($previous, $dayOffset);

        return [
            'filters' => $filters,
            'current' => $current,
            'previous' => $previous,
            'history' => $history,
            // Konnten alle Monate geladen werden? Sonst zeigt die Seite an,
            // dass die Auswertung noch aufgebaut wird.
            'complete' => $current['complete'] && $previous['complete'],
            'loaded' => $current['loaded'] + $previous['loaded'],
            'months' => $current['total'] + $previous['total'],
            'previousAtSamePoint' => $previousAtSamePoint,
            'products' => $this->mergeRanking($current['products'], $previous['products']),
            'colors' => $this->mergeRanking($current['colors'], $previous['colors']),
            'schools' => $schools,
        ];
    }

    /**
     * @param  array<int, array{name: string, categories: list<int>}>  $products
     * @param  Collection<int, SchoolOnboarding>  $schools
     * @return array<string, mixed>
     */
    private function aggregate(SchoolYear $year, StatisticsFilters $filters, array $products, Collection $schools): array
    {
        $fetch = $this->repository->orders($year, $filters->statuses, $filters->fetchPadding(), $filters->fresh);
        $orders = $fetch['orders'];

        $categoryToSchool = $this->categoryToSchool($schools);
        $windows = $this->windows($year, $filters, $schools);
        $inScope = $this->schoolsInScope($filters, $schools);

        $revenue = 0.0;
        $quantity = 0;
        $orderIds = [];
        $unassigned = 0.0;
        $months = $this->emptyMonths($year);
        $days = [];          // Tagesumsatz für den Vorjahresvergleich zum Stichtag
        $productTotals = [];
        $colorTotals = [];
        $windowRevenue = array_fill_keys(array_keys($windows), 0.0);

        foreach ($orders as $order) {
            /** @var Carbon $date */
            $date = $order['date'];
            $inYear = $year->contains($date);

            foreach ($order['items'] as $item) {
                $school = $this->schoolFor($item['product_id'], $products, $categoryToSchool);

                // Fensterzuordnung läuft unabhängig vom Schuljahr, weil ein
                // Fenster über den Jahreswechsel hinausreichen kann.
                if ($school !== null && isset($windows[$school->id]) && $windows[$school->id]['contains']($date)) {
                    $windowRevenue[$school->id] += $item['revenue'];
                }

                if (! $inYear) {
                    continue;
                }
                if ($inScope !== null && ($school === null || ! isset($inScope[$school->id]))) {
                    continue;
                }

                $revenue += $item['revenue'];
                $quantity += $item['quantity'];
                $orderIds[$order['id']] = true;
                if ($school === null) {
                    $unassigned += $item['revenue'];
                }

                $monthKey = $date->format('Y-m');
                if (isset($months[$monthKey])) {
                    $months[$monthKey]['revenue'] += $item['revenue'];
                }
                $dayKey = (int) $year->start()->diffInDays($date);
                $days[$dayKey] = ($days[$dayKey] ?? 0.0) + $item['revenue'];

                $productName = $this->productLabel($item, $school);
                $productTotals[$productName] ??= ['name' => $productName, 'revenue' => 0.0, 'quantity' => 0];
                $productTotals[$productName]['revenue'] += $item['revenue'];
                $productTotals[$productName]['quantity'] += $item['quantity'];

                $colorName = $item['color'] ?? 'ohne Farbangabe';
                $colorTotals[$colorName] ??= ['name' => $colorName, 'revenue' => 0.0, 'quantity' => 0];
                $colorTotals[$colorName]['revenue'] += $item['revenue'];
                $colorTotals[$colorName]['quantity'] += $item['quantity'];
            }
        }

        $collective = $this->windowSummary($windows, $windowRevenue, 'collective');
        $ondemand = $this->windowSummary($windows, $windowRevenue, 'ondemand');
        $orderCount = count($orderIds);

        return [
            'year' => $year,
            'label' => $year->label(),
            'complete' => $fetch['complete'],
            'loaded' => $fetch['loaded'],
            'total' => $fetch['total'],
            'revenue' => round($revenue, 2),
            'quantity' => $quantity,
            'orders' => $orderCount,
            'avgPerOrder' => $orderCount > 0 ? round($revenue / $orderCount, 2) : null,
            'unassigned' => round($unassigned, 2),
            'months' => array_values($months),
            'days' => $days,
            'collective' => $collective,
            'ondemand' => $ondemand,
            'products' => $this->sortRanking($productTotals),
            'colors' => $this->sortRanking($colorTotals),
        ];
    }

    /**
     * Auswertungszeitraum je Schule.
     *
     * @param  Collection<int, SchoolOnboarding>  $schools
     * @return array<int, array{school: SchoolOnboarding, type: string, from: Carbon, to: Carbon, contains: callable}>
     */
    private function windows(SchoolYear $year, StatisticsFilters $filters, Collection $schools): array
    {
        $windows = [];
        $inScope = $this->schoolsInScope($filters, $schools);

        foreach ($schools as $school) {
            if ($inScope !== null && ! isset($inScope[$school->id])) {
                continue;
            }

            if ($school->delivery_type === 'ondemand') {
                // On-Demand hat kein Bestellfenster — gewertet wird das ganze
                // Schuljahr, frühestens ab Anlage des Antrags.
                $created = $school->created_at ? Carbon::parse($school->created_at)->startOfDay() : $year->start();
                if ($created->gt($year->end())) {
                    continue;
                }
                $from = $created->gt($year->start()) ? $created : $year->start();
                $to = $year->end();
            } elseif ($school->delivery_type === 'collective') {
                if ($school->window_end === null) {
                    continue;
                }
                // Ein Fenster gehört zu dem Schuljahr, in dem es endet.
                if (! $year->contains($school->window_end)) {
                    continue;
                }
                $start = $school->window_start ?? $school->window_end;
                $from = Carbon::parse($start)->startOfDay()->subDays($filters->paddingBefore);
                $to = Carbon::parse($school->window_end)->endOfDay()->addDays($filters->paddingAfter);
            } else {
                // Listenbestellung: kein Webshop, kein Umsatz zuzuordnen.
                continue;
            }

            $windows[$school->id] = [
                'school' => $school,
                'type' => $school->delivery_type,
                'from' => $from,
                'to' => $to,
                'contains' => static fn (Carbon $date) => $date->betweenIncluded($from, $to),
            ];
        }

        return $windows;
    }

    /**
     * @param  array<int, array{school: SchoolOnboarding, type: string, from: Carbon, to: Carbon, contains: callable}>  $windows
     * @param  array<int, float>  $revenue
     * @return array{count: int, revenue: float, avg: ?float, list: list<array{name: string, revenue: float, from: string, to: string}>}
     */
    private function windowSummary(array $windows, array $revenue, string $type): array
    {
        $list = [];
        $total = 0.0;
        foreach ($windows as $id => $window) {
            if ($window['type'] !== $type) {
                continue;
            }
            $value = round($revenue[$id] ?? 0.0, 2);
            $total += $value;
            $list[] = [
                'name' => $window['school']->school_name,
                'revenue' => $value,
                'from' => $window['from']->format('d.m.Y'),
                'to' => $window['to']->format('d.m.Y'),
            ];
        }

        usort($list, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $count = count($list);

        return [
            'count' => $count,
            'revenue' => round($total, 2),
            'avg' => $count > 0 ? round($total / $count, 2) : null,
            'list' => $list,
        ];
    }

    /**
     * Produktname ohne Schulnamen und Druckzusätze, damit die Rangliste
     * schulübergreifend zusammenfasst („BG Korneuburg Schulhoodie" und
     * „HAK Wien STICK-Schulhoodie" werden beide zu „Schulhoodie").
     *
     * @param  array{name: string, product_id: int}  $item
     */
    private function productLabel(array $item, ?SchoolOnboarding $school): string
    {
        $name = $item['name'];
        if ($school !== null && $school->school_name !== '') {
            $name = str_ireplace($school->school_name, '', $name);
        }
        foreach (config('statistics.product_name_noise') as $noise) {
            $name = str_ireplace($noise, '', $name);
        }
        // Variantenzusatz der API abschneiden („Schulhoodie - Blau, M")
        $name = preg_replace('/\s+-\s+[^-]*$/u', '', $name) ?? $name;
        $name = trim(preg_replace('/\s{2,}/u', ' ', $name) ?? $name);

        return $name !== '' ? $name : ($item['name'] !== '' ? $item['name'] : 'Produkt #'.$item['product_id']);
    }

    /**
     * @param  array<string, array{name: string, revenue: float, quantity: int}>  $totals
     * @return list<array{name: string, revenue: float, quantity: int}>
     */
    private function sortRanking(array $totals): array
    {
        $list = array_map(
            static fn (array $row) => ['name' => $row['name'], 'revenue' => round($row['revenue'], 2), 'quantity' => $row['quantity']],
            array_values($totals),
        );
        usort($list, static fn ($a, $b) => [$b['quantity'], $b['revenue']] <=> [$a['quantity'], $a['revenue']]);

        return $list;
    }

    /**
     * Rangliste des laufenden Jahres mit dem Vorjahreswert je Eintrag.
     *
     * @param  list<array{name: string, revenue: float, quantity: int}>  $current
     * @param  list<array{name: string, revenue: float, quantity: int}>  $previous
     * @return list<array{name: string, revenue: float, quantity: int, previousRevenue: float, previousQuantity: int}>
     */
    private function mergeRanking(array $current, array $previous): array
    {
        $previousByName = [];
        foreach ($previous as $row) {
            $previousByName[mb_strtolower($row['name'])] = $row;
        }

        $limit = (int) config('statistics.ranking_limit');
        $merged = [];
        foreach (array_slice($current, 0, $limit) as $row) {
            $match = $previousByName[mb_strtolower($row['name'])] ?? null;
            $merged[] = $row + [
                'previousRevenue' => $match['revenue'] ?? 0.0,
                'previousQuantity' => $match['quantity'] ?? 0,
            ];
        }

        return $merged;
    }

    /**
     * Umsatz eines Schuljahres bis zum n-ten Tag — für „Vorjahr zum selben
     * Zeitpunkt", damit ein halbes Jahr nicht gegen ein volles verglichen wird.
     *
     * @param  array<string, mixed>  $aggregate
     */
    private function revenueUntilDayOffset(array $aggregate, int $dayOffset): float
    {
        $sum = 0.0;
        foreach ($aggregate['days'] as $day => $value) {
            if ($day <= $dayOffset) {
                $sum += $value;
            }
        }

        return round($sum, 2);
    }

    /**
     * Welche Schulen darf die Auswertung sehen? `null` = keine Einschränkung
     * (dann zählen auch Bestellungen ohne Schulzuordnung mit).
     *
     * @param  Collection<int, SchoolOnboarding>  $schools
     * @return array<int, true>|null
     */
    private function schoolsInScope(StatisticsFilters $filters, Collection $schools): ?array
    {
        if ($filters->schoolId === null && $filters->deliveryType === 'all') {
            return null;
        }

        $scope = [];
        foreach ($schools as $school) {
            if ($filters->schoolId !== null && $school->id !== $filters->schoolId) {
                continue;
            }
            if ($filters->deliveryType !== 'all' && $school->delivery_type !== $filters->deliveryType) {
                continue;
            }
            $scope[$school->id] = true;
        }

        return $scope;
    }

    /**
     * @param  Collection<int, SchoolOnboarding>  $schools
     * @return array<int, SchoolOnboarding>
     */
    private function categoryToSchool(Collection $schools): array
    {
        $map = [];
        foreach ($schools as $school) {
            $map[(int) $school->woo_category_id] = $school;
        }

        return $map;
    }

    /**
     * @param  array<int, array{name: string, categories: list<int>}>  $products
     * @param  array<int, SchoolOnboarding>  $categoryToSchool
     */
    private function schoolFor(int $productId, array $products, array $categoryToSchool): ?SchoolOnboarding
    {
        foreach ($products[$productId]['categories'] ?? [] as $categoryId) {
            if (isset($categoryToSchool[$categoryId])) {
                return $categoryToSchool[$categoryId];
            }
        }

        return null;
    }

    /** @return array<string, array{short: string, label: string, revenue: float}> */
    private function emptyMonths(SchoolYear $year): array
    {
        $months = [];
        foreach ($year->months() as $month) {
            $months[$month['start']->format('Y-m')] = [
                'short' => $month['short'],
                'label' => $month['label'],
                'revenue' => 0.0,
            ];
        }

        return $months;
    }
}
