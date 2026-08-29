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

        $key = sprintf(
            'school_orders.%d.%s.%s',
            $onboarding->woo_category_id,
            $onboarding->window_start?->toDateString() ?? '-',
            $onboarding->window_end?->toDateString() ?? '-',
        );

        try {
            $summary = Cache::remember($key, now()->addMinutes(self::TTL_MINUTES), fn () => $this->fetcher->summary(
                (int) $onboarding->woo_category_id,
                config('ordersuite.woocommerce.default_statuses'),
                $onboarding->window_start?->toDateString(),
                // Bis einschließlich Enddatum — die API filtert auf Zeitstempel
                $onboarding->window_end?->copy()->addDay()->toDateString(),
            ));
        } catch (\Throwable $e) {
            report($e);

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

    /** Verwirft den Zwischenspeicher — nach dem Schließen oder Wiederöffnen sinnvoll. */
    public function forget(SchoolOnboarding $onboarding): void
    {
        Cache::forget(sprintf(
            'school_orders.%d.%s.%s',
            $onboarding->woo_category_id,
            $onboarding->window_start?->toDateString() ?? '-',
            $onboarding->window_end?->toDateString() ?? '-',
        ));
    }
}
