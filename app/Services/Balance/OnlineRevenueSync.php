<?php

namespace App\Services\Balance;

use App\Models\BalanceOrder;
use App\Services\Statistics\RevenueReport;
use App\Services\Statistics\SchoolYear;
use App\Services\Statistics\StatisticsFilters;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Trägt die Online-Einnahmen verknüpfter Aufträge aus dem Webshop nach.
 *
 * Ein Auftrag, der an einem Bestellfenster hängt und auf „aus dem Webshop"
 * steht, wird nicht mehr von Hand gepflegt: Sein Online-Betrag ist der Umsatz,
 * den der Shop im Zeitraum dieses Fensters für die Kategorie der Schule
 * gemeldet hat — dieselbe Rechnung wie im Statistikmodul, damit beide Module
 * niemals verschiedene Zahlen zeigen.
 *
 * **Holt nie selbst beim Shop.** Gerechnet wird mit den Monaten, die das
 * Statistikmodul schon geladen hat (`allowFetching: false`). Fehlt etwas,
 * passiert schlicht nichts und der nächste Lauf macht weiter — ein Nachtrag,
 * der eine Seite aufhält, wäre die Rückkehr genau der Bauart, die die
 * Anwendung zweimal lahmgelegt hat.
 */
class OnlineRevenueSync
{
    /** Nicht öfter als alle paar Minuten — der Nachtrag ist keine Echtzeitanzeige. */
    private const THROTTLE_KEY = 'balance.sync.throttle';

    private const LOCK_KEY = 'balance.sync.lock';

    public function __construct(private readonly RevenueReport $report) {}

    /**
     * Ein Schuljahr nachtragen.
     *
     * @return array{updated: int, checked: int, complete: bool}
     */
    public function sync(SchoolYear $year): array
    {
        $orders = BalanceOrder::query()->ofYear($year)
            ->where('online_source', 'shop')
            ->whereNotNull('school_onboarding_id')
            ->get();

        if ($orders->isEmpty()) {
            return ['updated' => 0, 'checked' => 0, 'complete' => true];
        }

        $data = $this->report->build($this->filtersFor($year), allowFetching: false);
        if (! $data['complete']) {
            return ['updated' => 0, 'checked' => $orders->count(), 'complete' => false];
        }

        $byOnboarding = [];
        foreach (['collective', 'ondemand'] as $type) {
            foreach ($data['current'][$type]['list'] ?? [] as $window) {
                if (($window['onboardingId'] ?? null) !== null) {
                    $byOnboarding[(int) $window['onboardingId']] = (float) $window['revenue'];
                }
            }
        }

        $updated = 0;
        foreach ($orders as $order) {
            $shop = $byOnboarding[(int) $order->school_onboarding_id] ?? null;
            // Kein Fenster im Bericht heißt: Der Antrag hat in diesem Schuljahr
            // keines (Listenbestellung, Fenster in einem anderen Jahr). Dann
            // gibt es nichts nachzutragen — und der eingetragene Wert bleibt
            // stehen, statt auf 0 gesetzt zu werden.
            if ($shop === null) {
                continue;
            }

            $vatWasDerived = abs($order->vat - BalanceOrder::vatFromGross($order->revenueTotal())) < 0.02;
            if (abs($order->revenue_online - $shop) < 0.005) {
                continue;
            }

            $order->revenue_online = round($shop, 2);
            // Die Umsatzsteuer war aus dem Bruttobetrag hergeleitet — dann muss
            // sie mitwandern. Wurde sie von Hand gesetzt (etwa 0 vor der
            // GmbH-Gründung), bleibt sie unangetastet.
            if ($vatWasDerived) {
                $order->vat = BalanceOrder::vatFromGross($order->revenueTotal());
            }
            $order->save();
            $updated++;
        }

        return ['updated' => $updated, 'checked' => $orders->count(), 'complete' => true];
    }

    /**
     * Gedrosselter Nachtrag, angestoßen NACH der Antwort einer Seite.
     * Fehler landen im Log und bleiben ohne Folgen — der Nachtrag ist eine
     * Bequemlichkeit, kein Vorgang, für den jemand wartet.
     */
    public function syncAfterResponse(SchoolYear $year): void
    {
        $key = self::THROTTLE_KEY.'.'.$year->key();
        if (Cache::get($key) !== null) {
            return;
        }
        Cache::put($key, true, now()->addMinutes(10));

        $lock = Cache::lock(self::LOCK_KEY, 120);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->sync($year);
        } catch (\Throwable $e) {
            Log::warning('Online-Einnahmen konnten nicht nachgetragen werden: '.$e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * Dieselben Einstellungen wie die Statistik-Seite ohne Filter — nur so
     * kommen dort und hier dieselben Fensterumsätze heraus.
     */
    private function filtersFor(SchoolYear $year): StatisticsFilters
    {
        return new StatisticsFilters(
            year: $year,
            deliveryType: 'all',
            schoolId: null,
            paddingBefore: (int) config('statistics.window_padding.before'),
            paddingAfter: (int) config('statistics.window_padding.after'),
            statuses: (array) config('ordersuite.woocommerce.default_statuses'),
            fresh: false,
        );
    }
}
