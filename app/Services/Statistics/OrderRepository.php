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
 * **Abgerufen wird monatsweise, und jeder Monat wird einzeln gecacht.**
 * Das ist keine Feinoptimierung, sondern der Kern: ein Schuljahr eines echten
 * Shops sind schnell mehrere tausend Bestellungen. Würde ein Seitenaufruf das
 * ganze Jahr am Stück laden, liefe er minutenlang, liefe in den Zeitablauf des
 * Webservers — und weil dabei nichts gespeichert würde, begänne jeder Versuch
 * wieder bei null. Ein paar solcher Aufrufe belegen alle PHP-Arbeitskräfte,
 * und die gesamte Anwendung antwortet nicht mehr.
 *
 * Deshalb:
 *  - je Kalendermonat ein eigener Abruf und ein eigener Zwischenspeicher-
 *    Eintrag (vergangene Monate 24 h, der laufende 30 min),
 *  - ein Zeitbudget pro Seitenaufruf; ist es aufgebraucht, kommt zurück, was
 *    schon da ist, mit `complete = false`,
 *  - jeder fertige Monat bleibt gespeichert, der nächste Aufruf macht dort
 *    weiter. Nach ein bis zwei Aufrufen ist die Auswertung vollständig und
 *    danach sofort da.
 *
 * Ganze Kalendermonate deshalb, weil sich dieselben Monate zwischen zwei
 * Schuljahren überlappen (Puffer über den Jahresrand) und so nur einmal
 * geholt werden müssen.
 */
class OrderRepository
{
    /** Wann der Zeitraum dieses Seitenaufrufs begonnen hat (für das Budget). */
    private ?float $startedAt = null;

    public function __construct(private readonly WooCommerceClient $client) {}

    /** Abweichendes Budget dieses Durchgangs (null = Wert aus der Konfiguration). */
    private ?float $budgetOverride = null;

    /**
     * Startet das Zeitbudget neu — einmal je Seitenaufruf.
     *
     * `$budgetSeconds = 0` heißt: gar nichts holen. Genau damit arbeitet der
     * Seitenaufruf. Die Auswertung nutzt dann ausschließlich, was schon im
     * Zwischenspeicher liegt, und meldet sonst `complete = false`; geholt wird
     * allein im Hintergrund-Aufbau. Ohne diese Möglichkeit könnte ein
     * abgelaufener Monat die Seite doch wieder minutenlang an den Shop hängen.
     */
    public function startBudget(?float $budgetSeconds = null): void
    {
        $this->startedAt = microtime(true);
        $this->budgetOverride = $budgetSeconds;
    }

    public function budgetLeft(): float
    {
        $budget = $this->budgetOverride ?? (float) config('statistics.budget_seconds');
        if ($this->startedAt === null) {
            return $budget;
        }

        return max(0.0, $budget - (microtime(true) - $this->startedAt));
    }

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
            now()->addHours((int) config('statistics.cache.products_hours')),
            fn () => $this->client->allProducts(),
        );
    }

    /**
     * Alle Produktkategorien des Shops — die Statistik leitet daraus die
     * Schulen ab.
     *
     * Bewusst NICHT aus den Onboarding-Anträgen: die Toolsuite kennt nur die
     * Schulen, die sie selbst angelegt hat. Alles, was vorher von Hand im Shop
     * entstanden ist, fehlte sonst in der Auswertung — und genau das hat dazu
     * geführt, dass die Fenster-Durchschnitte 0 waren.
     *
     * @return list<array{id: int, name: string, count: int, parent: int}>
     */
    public function categories(bool $fresh = false): array
    {
        $key = 'statistics.categories';
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember(
            $key,
            now()->addHours((int) config('statistics.cache.products_hours')),
            fn () => $this->client->productCategories(),
        );
    }

    public function hasCategories(): bool
    {
        return Cache::has('statistics.categories');
    }

    public function forgetCategories(): void
    {
        Cache::forget('statistics.categories');
    }

    /**
     * Bestellungen eines Schuljahres, normalisiert.
     *
     * Der Zeitraum greift bewusst über den Schuljahresrand hinaus
     * (`$paddingDays`), weil ein Bestellfenster über den Jahreswechsel reichen
     * kann und die Fensterzuordnung diese Bestellungen braucht.
     *
     * @param  list<string>  $statuses
     * @return array{orders: list<array{id: int, date: Carbon, items: list<array{product_id: int, name: string, quantity: int, revenue: float, color: ?string}>}>, complete: bool, loaded: int, total: int}
     */
    public function orders(SchoolYear $year, array $statuses, int $paddingDays = 0, bool $fresh = false): array
    {
        $from = $year->start()->copy()->subDays($paddingDays)->startOfDay();
        $to = $year->end()->copy()->addDays($paddingDays)->endOfDay();

        $orders = [];
        $loaded = 0;
        $complete = true;
        $months = $this->months($from, $to);

        foreach ($months as $month) {
            $key = $this->cacheKey($month['key'], $statuses);
            if ($fresh) {
                Cache::forget($key);
            }

            $cached = Cache::get($key);
            if ($cached === null) {
                // Nur weitermachen, solange Zeit übrig ist. Sonst bleibt der
                // Rest für den nächsten Aufruf liegen — nichts geht verloren.
                if ($this->budgetLeft() <= 0) {
                    $complete = false;

                    continue;
                }
                $cached = $this->normalize(
                    $this->client->ordersForStatistics($statuses, $month['after'], $month['before']),
                );
                Cache::put($key, $cached, $this->ttl($month['key']));
            }

            $loaded++;
            foreach ($cached as $order) {
                $date = Carbon::parse($order['date']);
                if ($date->lt($from) || $date->gt($to)) {
                    continue;
                }
                $order['date'] = $date;
                $orders[] = $order;
            }
        }

        usort($orders, static fn ($a, $b) => $a['date'] <=> $b['date']);

        return [
            'orders' => $orders,
            'complete' => $complete,
            'loaded' => $loaded,
            'total' => count($months),
        ];
    }

    /**
     * Die Monate, die ein Schuljahr braucht — Grundlage für Fortschritts-
     * anzeige und Hintergrund-Aufbau (StatisticsWarmer).
     *
     * @return list<array{key: string, after: string, before: string}>
     */
    public function monthPlan(SchoolYear $year, int $paddingDays = 0): array
    {
        return $this->months(
            $year->start()->copy()->subDays($paddingDays)->startOfDay(),
            $year->end()->copy()->addDays($paddingDays)->endOfDay(),
        );
    }

    /** @param list<string> $statuses */
    public function isMonthCached(string $monthKey, array $statuses): bool
    {
        return Cache::has($this->cacheKey($monthKey, $statuses));
    }

    /**
     * Einen einzelnen Monat holen und ablegen. Der Hintergrund-Aufbau ruft das
     * Monat für Monat auf, mit Pause dazwischen.
     *
     * @param  array{key: string, after: string, before: string}  $month
     * @param  list<string>  $statuses
     */
    public function loadMonth(array $month, array $statuses): void
    {
        $orders = $this->normalize(
            $this->client->ordersForStatistics($statuses, $month['after'], $month['before']),
        );
        Cache::put($this->cacheKey($month['key'], $statuses), $orders, $this->ttl($month['key']));
    }

    public function hasProducts(): bool
    {
        return Cache::has('statistics.products');
    }

    /** Alles verwerfen, was zu diesen Monaten gehört — für „Daten neu laden". */
    public function forget(SchoolYear $year, array $statuses, int $paddingDays = 0): void
    {
        foreach ($this->monthPlan($year, $paddingDays) as $month) {
            Cache::forget($this->cacheKey($month['key'], $statuses));
        }
    }

    public function forgetProducts(): void
    {
        Cache::forget('statistics.products');
    }

    /**
     * Die vom Zeitraum berührten Kalendermonate, jeweils ganz.
     *
     * @return list<array{key: string, after: string, before: string}>
     */
    private function months(Carbon $from, Carbon $to): array
    {
        $months = [];
        $cursor = $from->copy()->startOfMonth();
        $last = $to->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $months[] = [
                'key' => $cursor->format('Y-m'),
                // Ausschließende Grenzen (so behandelt die API after/before):
                // letzte Sekunde des Vormonats bis erster Augenblick des
                // Folgemonats. So gehört jede Bestellung zu genau einem Monat —
                // auch eine, die exakt um Mitternacht des Ersten eingeht.
                'after' => $cursor->copy()->subSecond()->format('Y-m-d\TH:i:s'),
                'before' => $cursor->copy()->addMonth()->format('Y-m-d\TH:i:s'),
            ];
            $cursor = $cursor->copy()->addMonth();
        }

        return $months;
    }

    /** @param list<string> $statuses */
    private function cacheKey(string $month, array $statuses): string
    {
        sort($statuses);

        return 'statistics.orders.'.$month.'.'.substr(md5(implode(',', $statuses)), 0, 8);
    }

    /**
     * Abgeschlossene Monate ändern sich praktisch nicht mehr und werden lange
     * gehalten; der laufende Monat kurz.
     */
    private function ttl(string $month): \DateTimeInterface
    {
        $isPast = $month < Carbon::today()->format('Y-m');

        return $isPast
            ? now()->addHours((int) config('statistics.cache.past_hours'))
            : now()->addMinutes((int) config('statistics.cache.current_minutes'));
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
