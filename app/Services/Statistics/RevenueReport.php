<?php

namespace App\Services\Statistics;

use App\Models\SchoolOnboarding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rechnet aus den Shop-Bestellungen die Kennzahlen des Statistik-Moduls —
 * je Schuljahr einmal, plus dasselbe fürs Vorjahr zum Vergleich.
 *
 * **Woher die Schulen kommen:** aus den PRODUKTKATEGORIEN DES SHOPS, nicht aus
 * den Onboarding-Anträgen. Die Toolsuite kennt nur die Schulen, die sie selbst
 * angelegt hat; alles, was vorher von Hand im Shop entstand, fehlte sonst
 * komplett — genau daran sind die Fenster-Durchschnitte zuvor gescheitert
 * (kaum Fenster, und die mit 0 €). Ein Onboarding-Antrag wird einer Kategorie
 * über `woo_category_id` zugeordnet, hilfsweise über den Namen; er liefert
 * dann die Zusatzangaben, die nur die Toolsuite kennt: Lieferart und die
 * Bestellfenster-Daten.
 *
 * Zwei Zuordnungen laufen nebeneinander und beantworten verschiedene Fragen:
 *
 *  - **Nach Schuljahr**: eine Bestellposition zählt in das Schuljahr, in dem
 *    das Bestelldatum liegt. Grundlage für Gesamtumsatz, Monatsverlauf,
 *    Ø je Bestellung und alle Ranglisten (Produkte, Farben, Schulen).
 *  - **Nach Bestellfenster**: eine Position zählt zum Fenster ihrer Schule,
 *    wenn das Bestelldatum im (bewusst breiter gefassten) Fensterzeitraum
 *    liegt. Grundlage für „Ø Umsatz je Bestellfenster". Ein Fenster gehört zu
 *    dem Schuljahr, in dem es endet.
 *
 * Der Fensterzeitraum ist absichtlich größer als im Antrag eingestellt: nach
 * Ablauf wird häufig noch eine Woche verlängert, und Nachzügler bestellen auch
 * danach. Da nie mehrere Fenster derselben Schule direkt aneinander liegen,
 * kann der Puffer keine fremden Bestellungen einsammeln.
 */
class RevenueReport
{
    public function __construct(
        private readonly OrderRepository $repository,
        private readonly ProductGrouper $grouper,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(StatisticsFilters $filters, bool $allowFetching = true): array
    {
        // Der SEITENAUFRUF ruft mit `allowFetching: false` auf: Dann arbeitet
        // die Auswertung nur mit dem, was im Zwischenspeicher liegt, und meldet
        // sonst `complete = false`. Ohne das könnte ein zwischen
        // Fortschrittsprüfung und Auswertung abgelaufener Monat die Seite doch
        // wieder an den Shop hängen — genau das soll die Ladeseite verhindern.
        // Auf der Konsole und in Tests darf dagegen geholt werden.
        $this->repository->startBudget($allowFetching ? null : 0.0);

        if (! $allowFetching && (! $this->repository->hasProducts() || ! $this->repository->hasCategories())) {
            return $this->incomplete($filters);
        }

        $products = $this->repository->products($filters->fresh);
        $schools = $this->schools($filters->fresh);

        $current = $this->aggregate($filters->year, $filters, $products, $schools);
        $previous = $this->aggregate($filters->year->previous(), $filters, $products, $schools);

        /*
         * Grundlage der Prognose: die abgeschlossenen Vorjahre, neuestes zuerst.
         * Weitere Jahre nur, wenn die eigentliche Auswertung schon vollständig
         * ist und noch Zeit übrig — sie verfeinern nur die Hochrechnung.
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
            'complete' => $current['complete'] && $previous['complete'],
            'loaded' => $current['loaded'] + $previous['loaded'],
            'months' => $current['total'] + $previous['total'],
            'previousAtSamePoint' => $previousAtSamePoint,
            'products' => $this->mergeRanking($current['products'], $previous['products']),
            'colors' => $this->mergeRanking($current['colors'], $previous['colors']),
            'schoolRanking' => $this->mergeRanking($current['schools'], $previous['schools']),
            'schools' => $schools,
        ];
    }

    /**
     * Rückgabe, wenn die Grunddaten (Produktkatalog, Kategorien) noch fehlen —
     * gleiche Form wie eine echte Auswertung, aber ausdrücklich unvollständig.
     * Der Aufrufer zeigt dann die Ladeseite.
     *
     * @return array<string, mixed>
     */
    private function incomplete(StatisticsFilters $filters): array
    {
        $empty = fn (SchoolYear $year) => [
            'year' => $year, 'label' => $year->label(), 'complete' => false, 'loaded' => 0, 'total' => 0,
            'revenue' => 0.0, 'quantity' => 0, 'orders' => 0, 'avgPerOrder' => null, 'unassigned' => 0.0,
            'months' => $this->emptyMonths($year), 'days' => [],
            'collective' => ['count' => 0, 'revenue' => 0.0, 'avg' => null, 'list' => []],
            'ondemand' => ['count' => 0, 'revenue' => 0.0, 'avg' => null, 'list' => []],
            'schoolsWithoutWindow' => 0, 'products' => [], 'colors' => [], 'schools' => [],
        ];

        return [
            'filters' => $filters,
            'current' => $empty($filters->year),
            'previous' => $empty($filters->year->previous()),
            'history' => [],
            'complete' => false,
            'loaded' => 0,
            'months' => 0,
            'previousAtSamePoint' => 0.0,
            'products' => [],
            'colors' => [],
            'schoolRanking' => [],
            'schools' => collect(),
        ];
    }

    /**
     * Die Schulen des Shops: jede Produktkategorie unterhalb der Sammel-
     * kategorie („Schulen"), angereichert um den passenden Onboarding-Antrag.
     *
     * @return Collection<int, array{id: int, name: string, onboarding: ?SchoolOnboarding, deliveryType: string}>
     */
    private function schools(bool $fresh): Collection
    {
        $categories = $this->repository->categories($fresh);

        // Die Sammelkategorie selbst gehört nicht in die Auswertung; ihre
        // Kinder sind die Schulen. Findet sie sich nicht (anderer Name, flache
        // Struktur), gelten alle Kategorien als Kandidaten.
        $parentName = mb_strtolower((string) config('schoolshop.parent_category_name'));
        $parentId = null;
        foreach ($categories as $category) {
            if (mb_strtolower($category['name']) === $parentName) {
                $parentId = $category['id'];
                break;
            }
        }

        $onboardings = SchoolOnboarding::query()->orderByDesc('window_end')->get();
        $byCategory = [];
        $byName = [];
        foreach ($onboardings as $onboarding) {
            if ($onboarding->woo_category_id !== null) {
                $byCategory[(int) $onboarding->woo_category_id] ??= $onboarding;
            }
            $byName[mb_strtolower(trim((string) $onboarding->school_name))] ??= $onboarding;
        }

        $schools = collect();
        foreach ($categories as $category) {
            if ($parentId !== null && $category['parent'] !== $parentId) {
                continue;
            }
            if ($parentId !== null && $category['id'] === $parentId) {
                continue;
            }

            $onboarding = $byCategory[$category['id']]
                ?? $byName[mb_strtolower(trim($category['name']))]
                ?? null;

            $schools->put($category['id'], [
                'id' => $category['id'],
                'name' => $category['name'],
                'onboarding' => $onboarding,
                // Ohne Antrag ist die Lieferart unbekannt; solche Schulen
                // zählen in die Umsatzrangliste, aber in keinen Fenster-
                // Durchschnitt (dafür fehlen die Fensterdaten).
                'deliveryType' => $onboarding?->delivery_type ?? 'unbekannt',
            ]);
        }

        return $schools;
    }

    /**
     * @param  array<int, array{name: string, categories: list<int>}>  $products
     * @param  Collection<int, array{id: int, name: string, onboarding: ?SchoolOnboarding, deliveryType: string}>  $schools
     * @return array<string, mixed>
     */
    private function aggregate(SchoolYear $year, StatisticsFilters $filters, array $products, Collection $schools): array
    {
        $fetch = $this->repository->orders($year, $filters->statuses, $filters->fetchPadding(), $filters->fresh);
        $orders = $fetch['orders'];

        $windows = $this->windows($year, $filters, $schools);
        $inScope = $this->schoolsInScope($filters, $schools);

        $revenue = 0.0;
        $quantity = 0;
        $orderIds = [];
        $unassigned = 0.0;
        $months = $this->emptyMonths($year);
        $days = [];
        $productTotals = [];
        $colorTotals = [];
        $schoolTotals = [];
        $windowRevenue = array_fill_keys(array_keys($windows), 0.0);

        foreach ($orders as $order) {
            /** @var Carbon $date */
            $date = $order['date'];
            $inYear = $year->contains($date);

            foreach ($order['items'] as $item) {
                $categoryId = $this->categoryFor($item['product_id'], $products, $schools);
                $school = $categoryId === null ? null : $schools->get($categoryId);

                // Fensterzuordnung läuft unabhängig vom Schuljahr, weil ein
                // Fenster über den Jahreswechsel hinausreichen kann.
                if ($categoryId !== null && isset($windows[$categoryId]) && $windows[$categoryId]['contains']($date)) {
                    $windowRevenue[$categoryId] += $item['revenue'];
                }

                if (! $inYear) {
                    continue;
                }
                if ($inScope !== null && ($categoryId === null || ! isset($inScope[$categoryId]))) {
                    continue;
                }

                $revenue += $item['revenue'];
                $quantity += $item['quantity'];
                $orderIds[$order['id']] = true;

                if ($school === null) {
                    $unassigned += $item['revenue'];
                } else {
                    $schoolTotals[$school['name']] ??= ['name' => $school['name'], 'revenue' => 0.0, 'quantity' => 0];
                    $schoolTotals[$school['name']]['revenue'] += $item['revenue'];
                    $schoolTotals[$school['name']]['quantity'] += $item['quantity'];
                }

                $monthKey = $date->format('Y-m');
                if (isset($months[$monthKey])) {
                    $months[$monthKey]['revenue'] += $item['revenue'];
                }
                $dayKey = (int) $year->start()->diffInDays($date);
                $days[$dayKey] = ($days[$dayKey] ?? 0.0) + $item['revenue'];

                $productName = $this->grouper->group($item['name'], $school['name'] ?? null);
                $productTotals[$productName] ??= ['name' => $productName, 'revenue' => 0.0, 'quantity' => 0];
                $productTotals[$productName]['revenue'] += $item['revenue'];
                $productTotals[$productName]['quantity'] += $item['quantity'];

                $colorName = $item['color'] ?? 'ohne Farbangabe';
                $colorTotals[$colorName] ??= ['name' => $colorName, 'revenue' => 0.0, 'quantity' => 0];
                $colorTotals[$colorName]['revenue'] += $item['revenue'];
                $colorTotals[$colorName]['quantity'] += $item['quantity'];
            }
        }

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
            'collective' => $this->windowSummary($windows, $windowRevenue, 'collective'),
            'ondemand' => $this->windowSummary($windows, $windowRevenue, 'ondemand'),
            'schoolsWithoutWindow' => $this->schoolsWithoutWindow($filters, $schools),
            'products' => $this->sortRanking($productTotals),
            'colors' => $this->sortRanking($colorTotals),
            'schools' => $this->sortRanking($schoolTotals, byRevenue: true),
        ];
    }

    /**
     * Auswertungszeitraum je Schule — nur für Schulen mit Onboarding-Antrag,
     * denn nur dort stehen die Bestellfenster-Daten.
     *
     * @param  Collection<int, array{id: int, name: string, onboarding: ?SchoolOnboarding, deliveryType: string}>  $schools
     * @return array<int, array{school: array<string, mixed>, type: string, from: Carbon, to: Carbon, contains: callable}>
     */
    private function windows(SchoolYear $year, StatisticsFilters $filters, Collection $schools): array
    {
        $windows = [];
        $inScope = $this->schoolsInScope($filters, $schools);

        foreach ($schools as $categoryId => $school) {
            if ($inScope !== null && ! isset($inScope[$categoryId])) {
                continue;
            }
            $onboarding = $school['onboarding'];
            if ($onboarding === null) {
                continue;
            }

            if ($onboarding->delivery_type === 'ondemand') {
                // On-Demand hat kein Bestellfenster — gewertet wird das ganze
                // Schuljahr, frühestens ab Anlage des Antrags.
                $created = $onboarding->created_at ? Carbon::parse($onboarding->created_at)->startOfDay() : $year->start();
                if ($created->gt($year->end())) {
                    continue;
                }
                $from = $created->gt($year->start()) ? $created : $year->start();
                $to = $year->end();
            } elseif ($onboarding->delivery_type === 'collective') {
                if ($onboarding->window_end === null) {
                    continue;
                }
                // Ein Fenster gehört zu dem Schuljahr, in dem es endet.
                if (! $year->contains($onboarding->window_end)) {
                    continue;
                }
                $start = $onboarding->window_start ?? $onboarding->window_end;
                $from = Carbon::parse($start)->startOfDay()->subDays($filters->paddingBefore);
                $to = Carbon::parse($onboarding->window_end)->endOfDay()->addDays($filters->paddingAfter);
            } else {
                // Listenbestellung: kein Webshop, kein Umsatz zuzuordnen.
                continue;
            }

            $windows[$categoryId] = [
                'school' => $school,
                'type' => $onboarding->delivery_type,
                'from' => $from,
                'to' => $to,
                'contains' => static fn (Carbon $date) => $date->betweenIncluded($from, $to),
            ];
        }

        return $windows;
    }

    /**
     * Wie viele Schulen mit Umsatz haben keine Fensterdaten? Damit lässt sich
     * auf der Seite erklären, warum die Durchschnitte auf weniger Schulen
     * beruhen als die Umsatzrangliste.
     *
     * @param  Collection<int, array{id: int, name: string, onboarding: ?SchoolOnboarding, deliveryType: string}>  $schools
     */
    private function schoolsWithoutWindow(StatisticsFilters $filters, Collection $schools): int
    {
        $inScope = $this->schoolsInScope($filters, $schools);

        return $schools
            ->filter(fn ($school, $categoryId) => ($inScope === null || isset($inScope[$categoryId]))
                && $school['onboarding'] === null)
            ->count();
    }

    /**
     * @param  array<int, array{school: array<string, mixed>, type: string, from: Carbon, to: Carbon, contains: callable}>  $windows
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
                'name' => $window['school']['name'],
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
     * @param  array<string, array{name: string, revenue: float, quantity: int}>  $totals
     * @return list<array{name: string, revenue: float, quantity: int}>
     */
    private function sortRanking(array $totals, bool $byRevenue = false): array
    {
        $list = array_map(
            static fn (array $row) => ['name' => $row['name'], 'revenue' => round($row['revenue'], 2), 'quantity' => $row['quantity']],
            array_values($totals),
        );
        usort($list, $byRevenue
            ? static fn ($a, $b) => [$b['revenue'], $b['quantity']] <=> [$a['revenue'], $a['quantity']]
            : static fn ($a, $b) => [$b['quantity'], $b['revenue']] <=> [$a['quantity'], $a['revenue']]);

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
     * Welche Schulen (Kategorie-IDs) darf die Auswertung sehen? `null` = keine
     * Einschränkung (dann zählen auch Bestellungen ohne Schulzuordnung mit).
     *
     * @param  Collection<int, array{id: int, name: string, onboarding: ?SchoolOnboarding, deliveryType: string}>  $schools
     * @return array<int, true>|null
     */
    private function schoolsInScope(StatisticsFilters $filters, Collection $schools): ?array
    {
        if ($filters->schoolId === null && $filters->deliveryType === 'all') {
            return null;
        }

        $scope = [];
        foreach ($schools as $categoryId => $school) {
            if ($filters->schoolId !== null && $categoryId !== $filters->schoolId) {
                continue;
            }
            if ($filters->deliveryType !== 'all' && $school['deliveryType'] !== $filters->deliveryType) {
                continue;
            }
            $scope[$categoryId] = true;
        }

        return $scope;
    }

    /**
     * Kategorie (= Schule) einer Bestellposition.
     *
     * @param  array<int, array{name: string, categories: list<int>}>  $products
     * @param  Collection<int, array<string, mixed>>  $schools
     */
    private function categoryFor(int $productId, array $products, Collection $schools): ?int
    {
        foreach ($products[$productId]['categories'] ?? [] as $categoryId) {
            if ($schools->has($categoryId)) {
                return $categoryId;
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
