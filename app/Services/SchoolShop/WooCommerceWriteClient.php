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
    /**
     * Zeitablauf einer Anfrage. Der Verbindungstest der Admin-Seite setzt ihn
     * kurz: Dort laufen fünf Prüfungen nacheinander, und ausgerechnet die
     * Seite, die man aufruft, WEIL etwas hängt, darf nicht selbst hängen.
     */
    private int $timeout = 60;

    private static function statusTimeout(): int
    {
        return max(2, (int) config('schoolshop.status_timeout_seconds', 5));
    }

    public function isConfigured(): bool
    {
        return config('ordersuite.woocommerce.store_url') !== ''
            && config('schoolshop.woocommerce_write.consumer_key') !== ''
            && config('schoolshop.woocommerce_write.consumer_secret') !== '';
    }

    /** Verbindungstest für den Admin-Status: minimaler, seitenfreier GET-Aufruf. */
    public function testConnection(): void
    {
        $this->timeout = self::statusTimeout();
        $this->request('get', 'products/shipping_classes', ['per_page' => '1']);
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
            $this->assertPageLimit($page, "products/attributes/{$attributeId}/terms");
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

    /**
     * Vorhandene Variationen eines Produkts — Grundlage dafür, dass ein
     * abgebrochener Anlagevorgang wiederholt werden kann, ohne Variationen
     * doppelt anzulegen.
     *
     * @return list<array<string, mixed>>
     */
    public function variations(int $productId): array
    {
        $variations = $this->request('get', "products/{$productId}/variations", ['per_page' => '100'])->json();

        return is_array($variations) ? $variations : [];
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
     * Alle Produkte einer Kategorie (paginiert). Für „Bestellfenster schließen"
     * zuverlässiger als die Namenssuche, da die Schul-Kategorie eindeutig ist.
     *
     * @return list<array<string, mixed>>
     */
    public function findProductsByCategory(int $categoryId): array
    {
        $all = [];
        $page = 1;
        do {
            $this->assertPageLimit($page, 'products');
            $products = $this->request('get', 'products', [
                'category' => (string) $categoryId,
                'per_page' => '100',
                'page' => (string) $page,
                'status' => 'any',
            ])->json();
            $products = is_array($products) ? $products : [];
            foreach ($products as $product) {
                $all[] = $product;
            }
            $page++;
        } while (count($products) === 100);

        return $all;
    }

    /**
     * Notbremse für seitenweise Abrufe.
     *
     * Ohne sie blättert eine Schleife endlos weiter, sobald der Shop immer
     * wieder eine volle Seite liefert (Caching-Plugin, Proxy, fehlerhafte
     * Paginierung). Der PHP-Prozess hängt dann für immer; nach ein paar
     * Aufrufen ist keine Arbeitskraft mehr frei und die ganze Anwendung
     * antwortet nicht mehr — genau so ist die Statistik schon einmal
     * ausgefallen (siehe ordersuite.woocommerce.max_pages).
     */
    private function assertPageLimit(int $page, string $endpoint): void
    {
        $maxPages = (int) config('ordersuite.woocommerce.max_pages');
        if ($maxPages > 0 && $page > $maxPages) {
            throw WooCommerceApiException::tooManyPages(
                "GET {$endpoint}: mehr als {$maxPages} Seiten abgerufen — der Shop liefert immer weiter volle Seiten. Abgebrochen, um die Anwendung nicht zu blockieren.",
            );
        }
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
            // Umleitungen NICHT folgen: Bei einem 301/302 würde aus einem POST
            // stillschweigend ein GET (so ging real ein 'Kategorie anlegen'
            // verloren, weil WC_STORE_URL mit www konfiguriert war, der Shop
            // aber ohne www läuft). Stattdessen klarer Abbruch mit Erklärung.
            $pending = Http::withBasicAuth($key, $secret)->timeout($this->timeout)->acceptJson()
                ->withOptions(['allow_redirects' => false]);
            $response = $method === 'get'
                ? $pending->get($url, $query)
                : $pending->{$method}($query === [] ? $url : $url.'?'.http_build_query($query), $body);
        } catch (ConnectionException $e) {
            throw WooCommerceApiException::unreachable("{$method} {$url}: {$e->getMessage()}");
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            $location = $response->header('Location') ?: '(unbekannt)';
            throw new WooCommerceApiException(
                'Die Shop-Adresse in der Konfiguration leitet um — dabei gehen Schreibzugriffe verloren.',
                strtoupper($method)." {$url}: HTTP {$response->status()} Umleitung nach {$location}",
                'Bitte WC_STORE_URL in der .env-Datei exakt auf die endgültige Shop-Adresse setzen (auf www./ohne www. und http/https achten — die richtige Adresse steht in der Umleitung in den technischen Details) und danach php artisan config:cache ausführen.',
            );
        }

        // Rückfallebene für Hoster, die den Authorization-Header verwerfen:
        // Schlüssel in der Adresse. Bewusst NUR nach einem 401 und nur beim
        // Lesen — sonst stünden die Zugangsdaten bei jedem Schreibzugriff im
        // Zugriffslog des Webservers. Die Umleitungssperre bleibt auch hier,
        // damit eine falsch konfigurierte Shop-Adresse dieselbe klare Meldung
        // ergibt statt einer irreführenden.
        if ($response->status() === 401 && str_contains($response->body(), 'woocommerce_rest_cannot_view')) {
            $withKeys = $query + ['consumer_key' => $key, 'consumer_secret' => $secret];
            $retry = Http::timeout($this->timeout)->acceptJson()->withOptions(['allow_redirects' => false]);
            $response = $method === 'get'
                ? $retry->get($url, $withKeys)
                : $retry->{$method}($url.'?'.http_build_query($withKeys), $body);
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
