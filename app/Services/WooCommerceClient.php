<?php

namespace App\Services;

use App\Exceptions\WooCommerceApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Schmaler Read-only-Client für die WooCommerce REST API (v3).
 * Alle Fehler werden als WooCommerceApiException mit verständlicher
 * deutscher Erklärung gemeldet.
 */
class WooCommerceClient
{
    public function isConfigured(): bool
    {
        $config = config('ordersuite.woocommerce');

        return $config['store_url'] !== '' && $config['consumer_key'] !== '' && $config['consumer_secret'] !== '';
    }

    /**
     * Alle Produktkategorien (= Schulen/Organisationen), alphabetisch.
     *
     * @return list<array{id: int, name: string, count: int}>
     */
    public function productCategories(): array
    {
        $categories = [];
        foreach ($this->fetchAllPages('products/categories', ['orderby' => 'name', 'order' => 'asc']) as $category) {
            $categories[] = [
                'id' => (int) $category['id'],
                'name' => html_entity_decode((string) $category['name'], ENT_QUOTES | ENT_HTML5),
                'count' => (int) ($category['count'] ?? 0),
                // Übergeordnete Kategorie — die Statistik erkennt daran, welche
                // Kategorien Schulen sind (Kinder von „Schulen").
                'parent' => (int) ($category['parent'] ?? 0),
            ];
        }

        return $categories;
    }

    /**
     * Produkte einer Kategorie: ID => Hauptproduktname. Dient dem Filtern der
     * Bestellpositionen UND als verbindliche Quelle für "Product Name (main)"
     * wie im Plugin-Export (Positionsnamen aus der API enthalten teils den
     * Variantenzusatz, z. B. "Schulhoodie - Fire Red, S").
     *
     * @return array<int, string>
     */
    public function productsInCategory(int $categoryId): array
    {
        $products = [];
        foreach ($this->fetchAllPages('products', ['category' => (string) $categoryId, 'status' => 'any', '_fields' => 'id,name']) as $product) {
            $name = isset($product['name']) && is_string($product['name'])
                ? html_entity_decode($product['name'], ENT_QUOTES | ENT_HTML5)
                : '';
            $products[(int) $product['id']] = $name;
        }

        return $products;
    }

    /**
     * Alle Produkte mit ihren Kategorien — Grundlage der Statistik, um eine
     * Bestellposition ihrer Schule zuzuordnen, ohne je Schule einzeln
     * abzufragen (bei 40 Schulen sonst 40 Abrufe pro Auswertung).
     *
     * @return array<int, array{name: string, categories: list<int>}>
     */
    public function allProducts(): array
    {
        $products = [];
        foreach ($this->fetchAllPages('products', ['status' => 'any', '_fields' => 'id,name,categories']) as $product) {
            $name = isset($product['name']) && is_string($product['name'])
                ? html_entity_decode($product['name'], ENT_QUOTES | ENT_HTML5)
                : '';
            $products[(int) $product['id']] = [
                'name' => $name,
                'categories' => array_map(
                    static fn ($category) => (int) ($category['id'] ?? 0),
                    is_array($product['categories'] ?? null) ? $product['categories'] : [],
                ),
            ];
        }

        return $products;
    }

    /**
     * Nur die Felder, die für den Export gebraucht werden — hält die
     * Antworten klein und den Abruf schnell.
     */
    private const ORDER_FIELDS = 'id,total,customer_note,billing,meta_data,line_items';

    /**
     * Für die Statistik zusätzlich das Bestelldatum, dafür ohne Adress- und
     * Notizfelder. Positionsbeträge stehen in den `line_items`.
     */
    private const STATISTICS_ORDER_FIELDS = 'id,date_created,status,line_items';

    /**
     * Bestellungen eines Zeitraums für die Auswertung (mit Bestelldatum).
     *
     * `$after` und `$before` sind vollständige Zeitpunkte im Format
     * `Y-m-d\TH:i:s` und werden von der API **ausschließend** behandelt. Die
     * Statistik ruft monatsweise ab und setzt die Grenzen deshalb bewusst auf
     * die letzte Sekunde des Vormonats bzw. den ersten Augenblick des
     * Folgemonats — sonst fiele eine Bestellung, die genau um Mitternacht des
     * Monatsersten eingeht, in jedem Monat durchs Raster.
     *
     * @param  list<string>  $statuses
     * @return list<array<string, mixed>>
     */
    public function ordersForStatistics(array $statuses, string $after, string $before): array
    {
        // Kürzerer Zeitablauf als sonst: die Statistik ruft viele Monate
        // nacheinander ab, eine einzelne hängende Anfrage darf nicht das ganze
        // Zeitbudget der Seite verbrauchen.
        return $this->fetchAllPages('orders', [
            'status' => implode(',', $statuses),
            'orderby' => 'id',
            'order' => 'asc',
            '_fields' => self::STATISTICS_ORDER_FIELDS,
            'after' => $after,
            'before' => $before,
        ], (int) config('statistics.request_timeout_seconds'));
    }

    /**
     * Bestellungen mit den gewünschten Status, sortiert nach Order-ID absteigend
     * (wie der Plugin-Export). Optional nach Bestelldatum eingegrenzt.
     *
     * @param  list<string>  $statuses
     * @return list<array<string, mixed>>
     */
    public function orders(array $statuses, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->fetchAllPages('orders', $this->orderQuery($statuses, $dateFrom, $dateTo));
    }

    /**
     * Bestellungen, die ein bestimmtes Produkt enthalten (serverseitiger
     * Filter — entscheidend bei großen Shops, damit nicht der komplette
     * Bestellbestand geladen werden muss).
     *
     * @param  list<string>  $statuses
     * @return list<array<string, mixed>>
     */
    public function ordersForProduct(int $productId, array $statuses, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->fetchAllPages(
            'orders',
            $this->orderQuery($statuses, $dateFrom, $dateTo) + ['product' => (string) $productId],
        );
    }

    /** @param  list<string>  $statuses */
    private function orderQuery(array $statuses, ?string $dateFrom, ?string $dateTo): array
    {
        $query = [
            'status' => implode(',', $statuses),
            'orderby' => 'id',
            'order' => 'desc',
            '_fields' => self::ORDER_FIELDS,
        ];
        if ($dateFrom !== null) {
            $query['after'] = $dateFrom.'T00:00:00';
        }
        if ($dateTo !== null) {
            $query['before'] = $dateTo.'T23:59:59';
        }

        return $query;
    }

    /** Verbindungstest: eine minimale Anfrage. */
    public function testConnection(): void
    {
        // Kurzer Zeitablauf: Die Admin-Seite prüft fünf Schnittstellen
        // nacheinander und darf nicht selbst zur hängenden Seite werden.
        $this->request('orders', ['per_page' => '1', '_fields' => 'id'], max(2, (int) config('schoolshop.status_timeout_seconds', 5)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllPages(string $endpoint, array $query, ?int $timeout = null): array
    {
        $perPage = (int) config('ordersuite.woocommerce.per_page');
        $maxPages = (int) config('ordersuite.woocommerce.max_pages');
        $results = [];
        for ($page = 1; ; $page++) {
            $response = $this->request($endpoint, $query + ['per_page' => (string) $perPage, 'page' => (string) $page], $timeout);
            $batch = $response->json();
            if (! is_array($batch)) {
                throw WooCommerceApiException::unexpectedResponse(
                    "GET {$endpoint}: Antwort ist kein JSON-Array (Seite {$page}).",
                );
            }
            $results = array_merge($results, $batch);
            $totalPages = (int) $response->header('X-WP-TotalPages');
            if (count($batch) < $perPage || ($totalPages > 0 && $page >= $totalPages)) {
                return $results;
            }

            /*
             * Notbremse. Ohne sie läuft diese Schleife ewig, sobald ein
             * Caching-Plugin oder Proxy den Header X-WP-TotalPages entfernt und
             * jede Seite volle 100 Einträge liefert — der PHP-Prozess hängt
             * dann für immer, und nach ein paar Aufrufen ist keine Arbeitskraft
             * mehr frei: die ganze Anwendung antwortet nicht mehr.
             */
            if ($page >= $maxPages) {
                throw WooCommerceApiException::tooManyPages(sprintf(
                    'GET %s: nach %d Seiten à %d Einträgen abgebrochen (X-WP-TotalPages: %s). '
                    .'Entweder ist die Abfrage zu groß, oder der Shop liefert den Seitenzähler nicht mit.',
                    $endpoint,
                    $page,
                    $perPage,
                    $response->header('X-WP-TotalPages') ?: 'fehlt',
                ));
            }
        }
    }

    private function request(string $endpoint, array $query, ?int $timeout = null): Response
    {
        if (! $this->isConfigured()) {
            throw WooCommerceApiException::notConfigured();
        }
        $config = config('ordersuite.woocommerce');
        $url = rtrim($config['store_url'], '/')."/wp-json/wc/v3/{$endpoint}";
        $timeout = $timeout ?: (int) $config['timeout_seconds'];

        try {
            $response = Http::withBasicAuth($config['consumer_key'], $config['consumer_secret'])
                ->timeout($timeout)
                ->acceptJson()
                ->get($url, $query);

            // Viele Hosting-Setups (Apache/LiteSpeed/FastCGI) verwerfen den
            // Authorization-Header, bevor WordPress ihn sieht — WooCommerce
            // meldet dann "woocommerce_rest_cannot_view". Offizieller Fallback:
            // Schlüssel als Query-Parameter, ausschließlich über HTTPS.
            if (
                $response->status() === 401
                && str_starts_with($url, 'https://')
                && str_contains($response->body(), 'woocommerce_rest_cannot_view')
            ) {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->get($url, $query + [
                        'consumer_key' => $config['consumer_key'],
                        'consumer_secret' => $config['consumer_secret'],
                    ]);
            }
        } catch (ConnectionException $e) {
            $details = "GET {$url}: {$e->getMessage()}";
            if (str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout')) {
                throw WooCommerceApiException::timeout($details);
            }
            throw WooCommerceApiException::unreachable($details);
        }

        if ($response->successful()) {
            if (! is_array($response->json())) {
                throw WooCommerceApiException::unexpectedResponse(
                    "GET {$url}: HTTP {$response->status()}, aber keine JSON-Daten. Beginn der Antwort: ".mb_substr($response->body(), 0, 200),
                );
            }

            return $response;
        }

        $details = "GET {$url}: HTTP {$response->status()}. ".mb_substr($response->body(), 0, 300);
        throw match (true) {
            $response->status() === 401 => WooCommerceApiException::unauthorized($details),
            $response->status() === 403 => WooCommerceApiException::forbidden($details),
            $response->status() === 404 => WooCommerceApiException::apiNotFound($details),
            $response->status() >= 500 => WooCommerceApiException::serverError($response->status(), $details),
            default => WooCommerceApiException::unexpectedResponse($details),
        };
    }
}
