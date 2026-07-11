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
            ];
        }

        return $categories;
    }

    /**
     * IDs aller Produkte einer Kategorie (zum Filtern der Bestellpositionen).
     *
     * @return list<int>
     */
    public function productIdsInCategory(int $categoryId): array
    {
        $ids = [];
        foreach ($this->fetchAllPages('products', ['category' => (string) $categoryId, 'status' => 'any', '_fields' => 'id']) as $product) {
            $ids[] = (int) $product['id'];
        }

        return $ids;
    }

    /**
     * Nur die Felder, die für den Export gebraucht werden — hält die
     * Antworten klein und den Abruf schnell.
     */
    private const ORDER_FIELDS = 'id,total,customer_note,billing,meta_data,line_items';

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
        $this->request('orders', ['per_page' => '1', '_fields' => 'id']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllPages(string $endpoint, array $query): array
    {
        $perPage = (int) config('ordersuite.woocommerce.per_page');
        $results = [];
        for ($page = 1; ; $page++) {
            $response = $this->request($endpoint, $query + ['per_page' => (string) $perPage, 'page' => (string) $page]);
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
        }
    }

    private function request(string $endpoint, array $query): Response
    {
        if (! $this->isConfigured()) {
            throw WooCommerceApiException::notConfigured();
        }
        $config = config('ordersuite.woocommerce');
        $url = rtrim($config['store_url'], '/')."/wp-json/wc/v3/{$endpoint}";

        try {
            $response = Http::withBasicAuth($config['consumer_key'], $config['consumer_secret'])
                ->timeout((int) $config['timeout_seconds'])
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
                $response = Http::timeout((int) $config['timeout_seconds'])
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
