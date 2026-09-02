<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;
use App\Services\ShopOrderFetcher;
use Illuminate\Support\Facades\Cache;

/**
 * „Wie viel wurde bisher bestellt?" je Schule — aus der WooCommerce-API, über
 * die Schul-Produktkategorie und den Bestellzeitraum.
 *
 * Wird an mehreren Stellen gleichzeitig angezeigt (Antrag, Startseite), läuft
 * aber über echte API-Aufrufe. Deshalb kurz gecacht: aktuell genug, um den
 * Verlauf zu verfolgen, ohne bei jedem Seitenaufruf den Shop zu belasten.
 */
class SchoolOrderStats
{
    private const TTL_MINUTES = 15;

    public function __construct(private readonly ShopOrderFetcher $fetcher) {}

    /**
     * @return ?array{orders: int, items: int, expected: ?int, share: ?float}
     *                null, wenn die Schule keine Kategorie hat oder der Shop nicht erreichbar ist
     */
    public function for(SchoolOnboarding $onboarding): ?array
    {
        if (! $onboarding->woo_category_id) {
            return null;
        }

        // NUR aus dem Zwischenspeicher. Der Abruf braucht eine eigene,
        // seitenweise Abfrage JE PRODUKT der Schule; synchron im Seitenaufruf
        // wartet die Antragsseite dadurch im schlechten Fall minutenlang.
        // Geholt wird nach der Antwort (siehe warm()).
        $summary = Cache::get($this->cacheKey($onboarding));
        if ($summary === null) {
            return null;
        }

        $expected = $onboarding->expected_orders !== null ? (int) $onboarding->expected_orders : null;

        return [
            'orders' => $summary['orders'],
            'items' => $summary['items'],
            'expected' => $expected,
            'share' => $expected > 0 ? $summary['orders'] / $expected : null,
        ];
    }

    /**
     * Holt die Zahlen beim Shop und legt sie ab — gedacht für den Aufruf NACH
     * der Antwort (`app()->terminating()`), damit kein Seitenaufruf darauf
     * wartet. Fehler bleiben folgenlos; beim nächsten Aufruf steht es dann da.
     */
    public function warm(SchoolOnboarding $onboarding): void
    {
        if (! $onboarding->woo_category_id || Cache::has($this->cacheKey($onboarding))) {
            return;
        }

        try {
            $summary = $this->fetcher->summary(
                (int) $onboarding->woo_category_id,
                config('ordersuite.woocommerce.default_statuses'),
                $onboarding->window_start?->toDateString(),
                // Bis einschließlich Enddatum — die API filtert auf Zeitstempel
                $onboarding->window_end?->copy()->addDay()->toDateString(),
            );
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        Cache::put($this->cacheKey($onboarding), $summary, now()->addMinutes(self::TTL_MINUTES));
    }

    /** Verwirft den Zwischenspeicher — nach dem Schließen oder Wiederöffnen sinnvoll. */
    public function forget(SchoolOnboarding $onboarding): void
    {
        Cache::forget($this->cacheKey($onboarding));
    }

    private function cacheKey(SchoolOnboarding $onboarding): string
    {
        return sprintf(
            'school_orders.%d.%s.%s',
            $onboarding->woo_category_id,
            $onboarding->window_start?->toDateString() ?? '-',
            $onboarding->window_end?->toDateString() ?? '-',
        );
    }
}
