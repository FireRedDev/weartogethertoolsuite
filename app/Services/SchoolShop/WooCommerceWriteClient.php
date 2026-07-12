<?php

namespace App\Services\SchoolShop;

use App\Exceptions\WooCommerceApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WooCommerce-Schreibzugriff (separater Read/Write-API-Schlüssel) für das
 * Schul-Onboarding: Kategorien, variable Produkte, Variationen,
 * Versandklassen, Attribut-Terms.
 */
class WooCommerceWriteClient
{
    public function isConfigured(): bool
    {
        return config('ordersuite.woocommerce.store_url') !== ''
            && config('schoolshop.woocommerce_write.consumer_key') !== ''
            && config('schoolshop.woocommerce_write.consumer_secret') !== '';
    }

    /** Kategorie anlegen oder vorhandene zurückgeben. */
    public function ensureCategory(string $name, ?int $parentId = null): array
    {
        $searchResponse = $this->request('get', 'products/categories', [
            'search' => $name,
            'per_page' => '100',
            ...($parentId !== null ? ['parent' => (string) $parentId] : []),
        ]);
        $slugQuery = $searchResponse->json();
        foreach (is_array($slugQuery) ? $slugQuery : [] as $category) {
            $matchesParent = $parentId === null || (int) ($category['parent'] ?? 0) === $parentId;
            if (mb_strtolower(html_entity_decode($category['name'] ?? '', ENT_QUOTES | ENT_HTML5)) === mb_strtolower($name) && $matchesParent) {
                return $this->assertHasId($category, "GET products/categories (Suche nach '{$name}')", $searchResponse);
            }
        }

        $body = ['name' => $name];
        if ($parentId !== null) {
            $body['parent'] = $parentId;
        }

        $createResponse = $this->request('post', 'products/categories', [], $body);

        return $this->assertHasId($createResponse->json(), "POST products/categories (Kategorie '{$name}' anlegen)", $createResponse);
    }

    /** @return array<string, mixed>|null */
    public function findShippingClass(string $slug): ?array
    {
        $classes = $this->request('get', 'products/shipping_classes', ['per_page' => '100'])->json();
        foreach (is_array($classes) ? $classes : [] as $class) {
            if (($class['slug'] ?? '') === $slug) {
                return $class;
            }
        }

        return null;
    }

    /** Globale Produkt-Attribute (pa_*): Label => id. */
    public function globalAttributes(): array
    {
        $attributes = $this->request('get', 'products/attributes', ['per_page' => '100'])->json();
        $result = [];
        foreach (is_array($attributes) ? $attributes : [] as $attribute) {
            $result[mb_strtolower(html_entity_decode($attribute['name'] ?? '', ENT_QUOTES | ENT_HTML5))] = (int) $attribute['id'];
        }

        return $result;
    }

    /** Stellt sicher, dass alle Optionen als Terms eines globalen Attributs existieren. */
    public function ensureAttributeTerms(int $attributeId, array $options): void
    {
        $existing = [];
        for ($page = 1; ; $page++) {
            $terms = $this->request('get', "products/attributes/{$attributeId}/terms", ['per_page' => '100', 'page' => (string) $page])->json();
            if (! is_array($terms) || $terms === []) {
                break;
            }
            foreach ($terms as $term) {
                $existing[] = mb_strtolower(html_entity_decode($term['name'] ?? '', ENT_QUOTES | ENT_HTML5));
            }
            if (count($terms) < 100) {
                break;
            }
        }
        foreach ($options as $option) {
            if (! in_array(mb_strtolower($option), $existing, true)) {
                $this->request('post', "products/attributes/{$attributeId}/terms", [], ['name' => $option]);
            }
        }
    }

    public function createProduct(array $payload): array
    {
        $response = $this->request('post', 'products', [], $payload);

        return $this->assertHasId($response->json(), "POST products (Produkt '".($payload['name'] ?? '?')."' anlegen)", $response);
    }

    public function updateProduct(int $productId, array $payload): array
    {
        $response = $this->request('put', "products/{$productId}", [], $payload);

        return $this->assertHasId($response->json(), "PUT products/{$productId}", $response);
    }

    public function createVariation(int $productId, array $payload): array
    {
        $response = $this->request('post', "products/{$productId}/variations", [], $payload);

        return $this->assertHasId($response->json(), "POST products/{$productId}/variations", $response);
    }

    /** @return list<array<string, mixed>> */
    public function findProductsByName(string $search): array
    {
        $products = $this->request('get', 'products', ['search' => $search, 'per_page' => '100'])->json();

        return is_array($products) ? $products : [];
    }

    /**
     * Prüft, dass eine erfolgreiche Antwort tatsächlich das erwartete Objekt
     * mit "id" enthält — sonst mit der vollständigen Roh-Antwort abbrechen,
     * statt später mit einer kryptischen "Undefined array key" zu scheitern.
     *
     * @return array<string, mixed>
     */
    private function assertHasId(mixed $data, string $context, Response $response): array
    {
        if (! is_array($data) || ! isset($data['id'])) {
            throw WooCommerceApiException::unexpectedResponse(
                "{$context}: HTTP {$response->status()} war erfolgreich, aber die Antwort enthält keine Objekt-ID. ".
                'Rohe Antwort: '.mb_substr($response->body(), 0, 800),
            );
        }

        return $data;
    }

    private function request(string $method, string $endpoint, array $query = [], array $body = []): Response
    {
        if (! $this->isConfigured()) {
            throw new WooCommerceApiException(
                'Der Schreibzugriff auf den Shop ist noch nicht eingerichtet.',
                'WC_RW_CONSUMER_KEY / WC_RW_CONSUMER_SECRET fehlen in der .env-Datei.',
                'Ein:e Administrator:in muss in WooCommerce → Einstellungen → Erweitert → REST-API einen Schlüssel mit Berechtigung „Lesen/Schreiben" erstellen und in der .env-Datei eintragen.',
            );
        }
        $url = rtrim(config('ordersuite.woocommerce.store_url'), '/')."/wp-json/wc/v3/{$endpoint}";
        $key = config('schoolshop.woocommerce_write.consumer_key');
        $secret = config('schoolshop.woocommerce_write.consumer_secret');

        try {
            $pending = Http::withBasicAuth($key, $secret)->timeout(60)->acceptJson();
            $response = $method === 'get' ? $pending->get($url, $query) : $pending->{$method}($url.'?'.http_build_query($query + [
                // Fallback für Hoster, die den Authorization-Header verwerfen
                'consumer_key' => $key, 'consumer_secret' => $secret,
            ]), $body);
        } catch (ConnectionException $e) {
            throw WooCommerceApiException::unreachable("{$method} {$url}: {$e->getMessage()}");
        }

        if ($method === 'get' && $response->status() === 401 && str_contains($response->body(), 'woocommerce_rest_cannot_view')) {
            $response = Http::timeout(60)->acceptJson()->get($url, $query + ['consumer_key' => $key, 'consumer_secret' => $secret]);
        }

        if (! $response->successful()) {
            $details = strtoupper($method)." {$url}: HTTP {$response->status()}. ".mb_substr($response->body(), 0, 300);
            throw match (true) {
                $response->status() === 401 => WooCommerceApiException::unauthorized($details),
                $response->status() === 403 => WooCommerceApiException::forbidden($details),
                $response->status() === 404 => WooCommerceApiException::apiNotFound($details),
                $response->status() >= 500 => WooCommerceApiException::serverError($response->status(), $details),
                default => WooCommerceApiException::unexpectedResponse($details),
            };
        }

        return $response;
    }
}
