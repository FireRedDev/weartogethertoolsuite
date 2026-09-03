<?php

namespace App\Services\Balance;

use App\Models\BalanceOrder;
use App\Services\Statistics\SchoolYear;
use Illuminate\Support\Collection;

/**
 * Die Auswertungen der Auftragsbilanz — genau die Zahlen, die bisher in den
 * Pivot-Blättern der Excel standen.
 *
 * **Rein aus der eigenen Datenbank.** Kein einziger Aufruf an WooCommerce,
 * WordPress oder Printify. Das ist Absicht: Diese Zahlen müssen sofort da sein,
 * auch wenn der Shop klemmt — sonst wäre die Auftragsbilanz genauso von der
 * Ladeseite abhängig wie die Shop-Auswertung, und die Eingabemaske ließe sich
 * bei einer Störung nicht mehr benutzen.
 *
 * Was hier NICHT passiert: die Umsätze mit den Shop-Zahlen verheiraten. Das
 * gehört ins Statistikmodul, weil nur dort bekannt ist, welche Monate des Shops
 * überhaupt geladen sind.
 */
class BalanceReport
{
    /**
     * Alle Kennzahlen eines Schuljahres — die Kopfzeile der Excel-Jahresgruppe.
     *
     * @return array<string, mixed>
     */
    public function forYear(SchoolYear $year): array
    {
        return $this->summarize(BalanceOrder::query()->ofYear($year)->orderBy('ordered_on')->orderBy('number')->get(), $year);
    }

    /**
     * Dieselben Kennzahlen für jedes Schuljahr, in dem Aufträge liegen —
     * das Blatt „Schuljahresbilanz".
     *
     * @return list<array<string, mixed>>
     */
    public function byYear(): array
    {
        $orders = BalanceOrder::query()->orderBy('school_year')->orderBy('number')->get();

        $rows = [];
        foreach ($orders->groupBy('school_year') as $startYear => $group) {
            $rows[] = $this->summarize($group, new SchoolYear((int) $startYear));
        }

        return $rows;
    }

    /** Die Schuljahre, in denen überhaupt Aufträge stehen — neuestes zuerst. */
    public function years(): array
    {
        return BalanceOrder::query()
            ->select('school_year')->distinct()->orderByDesc('school_year')
            ->pluck('school_year')
            ->map(static fn ($y) => new SchoolYear((int) $y))
            ->all();
    }

    /**
     * Rangliste je Schule innerhalb eines Schuljahres, oder über alle Jahre,
     * wenn kein Jahr übergeben wird — die Blätter „Umsatz pro Schule",
     * „Gewinn pro Schule" und „Anzahl Schulbestellungen" in einem.
     *
     * @return list<array{name: string, revenue: float, profit: float, orders: int, products: int}>
     */
    public function bySchool(?SchoolYear $year = null): array
    {
        $query = BalanceOrder::query();
        if ($year !== null) {
            $query->ofYear($year);
        }

        $rows = [];
        foreach ($query->get()->groupBy('school_name') as $name => $group) {
            $rows[] = [
                'name' => (string) $name,
                'revenue' => round($group->sum(fn (BalanceOrder $o) => $o->revenueTotal()), 2),
                'profit' => round($group->sum(fn (BalanceOrder $o) => $o->profit()), 2),
                'orders' => $group->count(),
                'products' => (int) $group->sum(fn (BalanceOrder $o) => $o->productCount()),
            ];
        }

        usort($rows, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return $rows;
    }

    /**
     * Rangliste je Auftrag — die Blätter „Umsatz pro Bestellung" und
     * „Gewinn pro Bestellung".
     *
     * @return list<array{label: string, name: string, revenue: float, profit: float, margin: ?float, year: string}>
     */
    public function byOrder(?SchoolYear $year = null): array
    {
        $query = BalanceOrder::query();
        if ($year !== null) {
            $query->ofYear($year);
        }

        return $query->get()->map(static fn (BalanceOrder $o) => [
            'label' => $o->label(),
            'name' => $o->school_name,
            'revenue' => $o->revenueTotal(),
            'profit' => $o->profit(),
            'margin' => $o->marginShare(),
            'year' => $o->schoolYear()->label(),
        ])->sortByDesc('revenue')->values()->all();
    }

    /**
     * Stückzahlen je Produktart und Schuljahr — das Blatt „Bilanz Produkte",
     * inklusive der Durchschnittszeile („wie viele Stück je Auftrag").
     *
     * @return array{years: list<array<string, mixed>>, types: array<string, string>}
     */
    public function products(): array
    {
        $types = (array) config('auftragsbilanz.product_types');
        $rows = [];

        foreach (BalanceOrder::query()->orderBy('school_year')->get()->groupBy('school_year') as $startYear => $group) {
            $quantities = [];
            foreach (array_keys($types) as $type) {
                $quantities[$type] = (int) $group->sum(fn (BalanceOrder $o) => $o->productQuantity($type));
            }

            $rows[] = [
                'year' => new SchoolYear((int) $startYear),
                'label' => (new SchoolYear((int) $startYear))->label(),
                'orders' => $group->count(),
                'quantities' => $quantities,
                'total' => array_sum($quantities),
                'individual' => (int) $group->sum('individual'),
            ];
        }

        return ['years' => $rows, 'types' => $types];
    }

    /**
     * Die Monate eines Schuljahres mit den Umsätzen AUSSERHALB des Webshops.
     *
     * Nur dieser Teil darf im Statistikmodul zur Shop-Welt dazugezählt werden —
     * alles andere steckt dort schon in den Bestellungen.
     *
     * @return array<string, float> Schlüssel „YYYY-MM"
     */
    public function monthlyOutsideShop(SchoolYear $year): array
    {
        $months = [];
        foreach ($year->months() as $month) {
            $months[$month['start']->format('Y-m')] = 0.0;
        }

        foreach (BalanceOrder::query()->ofYear($year)->get() as $order) {
            $key = $order->ordered_on?->format('Y-m');
            if ($key !== null && array_key_exists($key, $months)) {
                $months[$key] = round($months[$key] + $order->revenueOutsideShop(), 2);
            }
        }

        return $months;
    }

    /**
     * @param  Collection<int, BalanceOrder>  $orders
     * @return array<string, mixed>
     */
    private function summarize(Collection $orders, SchoolYear $year): array
    {
        $sum = static fn (callable $fn) => round($orders->sum($fn), 2);

        $revenue = $sum(fn (BalanceOrder $o) => $o->revenueTotal());
        $profit = $sum(fn (BalanceOrder $o) => $o->profit());
        $count = $orders->count();

        return [
            'year' => $year,
            'label' => $year->label(),
            'orders' => $count,
            'revenue' => $revenue,
            'revenueOnline' => $sum(fn (BalanceOrder $o) => $o->revenue_online),
            'revenueCash' => $sum(fn (BalanceOrder $o) => $o->revenue_cash),
            'revenueOutsideShop' => $sum(fn (BalanceOrder $o) => $o->revenueOutsideShop()),
            'revenueNet' => $sum(fn (BalanceOrder $o) => $o->revenueNet()),
            'commission' => $sum(fn (BalanceOrder $o) => $o->commission),
            'expenses' => $sum(fn (BalanceOrder $o) => $o->expenses),
            'vat' => $sum(fn (BalanceOrder $o) => $o->vat),
            'profit' => $profit,
            'margin' => $revenue > 0 ? round($profit / $revenue, 4) : null,
            'avgRevenue' => $count > 0 ? round($revenue / $count, 2) : null,
            'avgProfit' => $count > 0 ? round($profit / $count, 2) : null,
            'products' => (int) $orders->sum(fn (BalanceOrder $o) => $o->productCount()),
            'individual' => (int) $orders->sum('individual'),
            // Wie viele Zeilen noch das geschätzte Datum des Schuljahresendes
            // tragen — solange das viele sind, ist der Monatsverlauf der
            // händischen Umsätze mit Vorsicht zu lesen.
            'estimatedDates' => $orders->where('date_is_estimate', true)->count(),
            'list' => $orders,
        ];
    }
}
