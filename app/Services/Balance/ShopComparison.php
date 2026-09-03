<?php

namespace App\Services\Balance;

use App\Models\BalanceOrder;
use App\Services\Statistics\OrderRepository;
use App\Services\Statistics\SchoolYear;
use Illuminate\Support\Carbon;

/**
 * Vergleicht die eingetragenen Online-Einnahmen eines Schuljahres mit dem, was
 * der Webshop tatsächlich meldet.
 *
 * Der Sinn: Ab jetzt wird alles in der Software gepflegt, die alten Zahlen
 * kommen aus der Excel. Weichen beide voneinander ab, ist entweder in der Excel
 * etwas anders gerechnet worden (Erstattungen, Rundungen, Aufträge außerhalb
 * des Shops) oder ein Auftrag fehlt. Beides soll auffallen und nicht still in
 * einer Summe verschwinden.
 *
 * **Fragt den Shop NIE selbst.** Gerechnet wird ausschließlich mit den Monaten,
 * die das Statistikmodul bereits geladen hat; fehlt etwas, meldet die Prüfung
 * `available = false` und die Seite sagt das ehrlich dazu.
 */
class ShopComparison
{
    public function __construct(private readonly OrderRepository $repository) {}

    /**
     * @return array{available: bool, shop: ?float, entered: float, difference: ?float, share: ?float, mismatch: bool, fetchedAt: ?Carbon, orders: int}
     */
    public function forYear(SchoolYear $year): array
    {
        $entered = round(
            (float) BalanceOrder::query()->ofYear($year)->sum('revenue_online'),
            2,
        );
        $orders = BalanceOrder::query()->ofYear($year)->count();

        // Budget 0 = nur der Zwischenspeicher. Kein Seitenaufruf wartet hier
        // auf den Shop; fehlende Monate machen die Prüfung unbeantwortbar,
        // nicht langsam.
        $this->repository->startBudget(0.0);
        $result = $this->repository->orders(
            $year,
            (array) config('ordersuite.woocommerce.default_statuses'),
        );

        if (! $result['complete']) {
            return [
                'available' => false, 'shop' => null, 'entered' => $entered,
                'difference' => null, 'share' => null, 'mismatch' => false,
                'fetchedAt' => $result['fetchedAt'], 'orders' => $orders,
            ];
        }

        $shop = 0.0;
        foreach ($result['orders'] as $order) {
            foreach ($order['items'] as $item) {
                $shop += (float) $item['revenue'];
            }
        }
        $shop = round($shop, 2);

        $difference = round($shop - $entered, 2);
        $share = $entered > 0 ? abs($difference) / $entered : ($shop > 0 ? 1.0 : 0.0);

        return [
            'available' => true,
            'shop' => $shop,
            'entered' => $entered,
            'difference' => $difference,
            'share' => round($share, 4),
            // Erst melden, wenn die Abweichung SOWOHL anteilig ALS AUCH
            // betragsmäßig ins Gewicht fällt — sonst meldet die Seite jeden
            // Rundungscent.
            'mismatch' => $share >= (float) config('auftragsbilanz.mismatch.share')
                && abs($difference) >= (float) config('auftragsbilanz.mismatch.amount'),
            'fetchedAt' => $result['fetchedAt'],
            'orders' => $orders,
        ];
    }
}
