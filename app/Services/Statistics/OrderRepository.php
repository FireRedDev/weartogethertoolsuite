<?php

namespace App\Services\Statistics;

use App\Services\WooCommerceClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Holt die Rohdaten der Auswertung aus dem Shop und bringt sie in eine
 * schlanke, gecachte Form: je Bestellung Datum und Positionen, je Position
 * Produkt, Menge, Umsatz und Farbe.
 *
 * Bewusst EIN Abruf je Schuljahr statt einer je Schule: bei vierzig Schulen
 * wären das sonst vierzig Durchläufe über den kompletten Bestellbestand.
 * Die Zuordnung zur Schule passiert danach im Speicher über die
 * Produkt-Kategorie-Karte (siehe RevenueReport).
 *
 * Abgeschlossene Schuljahre ändern sich nicht mehr und werden deshalb
 * deutlich länger zwischengespeichert als das laufende.
 */
class OrderRepository
{
    public function __construct(private readonly WooCommerceClient $client) {}

    /**
     * Produkt-ID => Name und Kategorie-IDs.
     *
     * @return array<int, array{name: string, categories: list<int>}>
     */
    public function products(bool $fresh = false): array
    {
        $key = 'statistics.products';
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember(
            $key,
            now()->addMinutes((int) config('statistics.cache.current_minutes')),
            fn () => $this->client->allProducts(),
        );
    }

    /**
     * Bestellungen eines Schuljahres, normalisiert.
     *
     * Der Abruf greift bewusst über den Schuljahresrand hinaus (`$paddingDays`),
     * weil Bestellungen einer Schule dem Fenster zugeordnet werden und ein
     * Fenster über den Jahreswechsel hinausreichen kann.
     *
     * @param  list<string>  $statuses
     * @return list<array{id: int, date: Carbon, items: list<array{product_id: int, name: string, quantity: int, revenue: float, color: ?string}>}>
     */
    public function orders(SchoolYear $year, array $statuses, int $paddingDays = 0, bool $fresh = false): array
    {
        $from = $year->start()->copy()->subDays($paddingDays)->toDateString();
        $to = $year->end()->copy()->addDays($paddingDays)->toDateString();
        $key = sprintf('statistics.orders.%s.%s.%s.%s', $year->key(), $from, $to, md5(implode(',', $statuses)));

        if ($fresh) {
            Cache::forget($key);
        }

        $ttl = $year->isComplete()
            ? now()->addHours((int) config('statistics.cache.past_hours'))
            : now()->addMinutes((int) config('statistics.cache.current_minutes'));

        $raw = Cache::remember($key, $ttl, fn () => $this->normalize(
            $this->client->ordersForStatistics($statuses, $from, $to),
        ));

        // Datum als String im Zwischenspeicher — Carbon-Objekte überstehen
        // nicht jeden Cache-Treiber unbeschadet.
        return array_map(static function (array $order): array {
            $order['date'] = Carbon::parse($order['date']);

            return $order;
        }, $raw);
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<array{id: int, date: string, items: list<array{product_id: int, name: string, quantity: int, revenue: float, color: ?string}>}>
     */
    private function normalize(array $orders): array
    {
        $withTax = (bool) config('statistics.revenue_includes_tax');
        $normalized = [];

        foreach ($orders as $order) {
            $date = $order['date_created'] ?? null;
            if (! is_string($date) || $date === '') {
                continue;
            }

            $items = [];
            foreach ($order['line_items'] ?? [] as $item) {
                $revenue = (float) ($item['total'] ?? 0);
                if ($withTax) {
                    $revenue += (float) ($item['total_tax'] ?? 0);
                }

                $items[] = [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'name' => $this->itemName($item),
                    'quantity' => max(0, (int) ($item['quantity'] ?? 0)),
                    'revenue' => round($revenue, 2),
                    'color' => $this->color($item),
                ];
            }

            if ($items === []) {
                continue;
            }

            $normalized[] = [
                'id' => (int) ($order['id'] ?? 0),
                'date' => Carbon::parse($date)->toDateTimeString(),
                'items' => $items,
            ];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $item */
    private function itemName(array $item): string
    {
        foreach (['parent_name', 'name'] as $field) {
            $value = $item[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            }
        }

        return '';
    }

    /**
     * Farbe der Position. Sammelbestellfenster-Produkte tragen `pa_color`,
     * Printify-Produkte oft ein englisch benanntes Attribut — deshalb erst
     * exakter, dann Teilstring-Vergleich, jeweils ohne Groß-/Kleinschreibung.
     *
     * @param  array<string, mixed>  $item
     */
    private function color(array $item): ?string
    {
        /** @var list<string> $candidates */
        $candidates = config('statistics.color_meta_keys');
        $metas = [];

        foreach ($item['meta_data'] ?? [] as $meta) {
            $key = (string) ($meta['key'] ?? '');
            if ($key === '' || str_starts_with($key, '_')) {
                continue;
            }
            $value = $meta['display_value'] ?? $meta['value'] ?? null;
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }
            $metas[] = [
                'names' => array_map(
                    'mb_strtolower',
                    array_filter([$key, (string) ($meta['display_key'] ?? '')], static fn ($n) => $n !== ''),
                ),
                'value' => html_entity_decode(strip_tags(trim((string) $value)), ENT_QUOTES | ENT_HTML5),
            ];
        }

        foreach ([true, false] as $exact) {
            foreach ($metas as $meta) {
                foreach ($meta['names'] as $name) {
                    foreach ($candidates as $candidate) {
                        $candidate = mb_strtolower($candidate);
                        if ($exact ? $name === $candidate : str_contains($name, $candidate)) {
                            return $meta['value'];
                        }
                    }
                }
            }
        }

        return null;
    }
}
