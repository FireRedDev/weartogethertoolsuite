<?php

namespace App\Services\SchoolShop;

use App\Exceptions\WooCommerceApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Printify REST API v1 (On-Demand-Produkte).
 */
class PrintifyClient
{
    private const BASE = 'https://api.printify.com/v1';

    public function isConfigured(): bool
    {
        return config('schoolshop.printify.api_token') !== '' && config('schoolshop.printify.shop_id') !== '';
    }

    /** @return list<array<string, mixed>> */
    public function searchBlueprints(string $query): array
    {
        $blueprints = $this->request('get', '/catalog/blueprints.json')->json();
        $query = mb_strtolower($query);

        return array_values(array_filter(
            is_array($blueprints) ? $blueprints : [],
            fn ($b) => $query === '' || str_contains(mb_strtolower(($b['title'] ?? '').' '.($b['brand'] ?? '').' '.($b['model'] ?? '')), $query),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function printProviders(int $blueprintId): array
    {
        $providers = $this->request('get', "/catalog/blueprints/{$blueprintId}/print_providers.json")->json();

        return is_array($providers) ? $providers : [];
    }

    /** @return list<array<string, mixed>> */
    public function variants(int $blueprintId, int $providerId): array
    {
        $data = $this->request('get', "/catalog/blueprints/{$blueprintId}/print_providers/{$providerId}/variants.json")->json();

        return is_array($data['variants'] ?? null) ? $data['variants'] : [];
    }

    /** Versandkosten (erster Artikel) in Cent, oder null wenn unbekannt. */
    public function firstItemShippingCents(int $blueprintId, int $providerId): ?int
    {
        $data = $this->request('get', "/catalog/blueprints/{$blueprintId}/print_providers/{$providerId}/shipping.json")->json();
        foreach ($data['profiles'] ?? [] as $profile) {
            $countries = $profile['countries'] ?? [];
            if (in_array('AT', $countries, true) || in_array('REST_OF_THE_WORLD', $countries, true)) {
                return (int) ($profile['first_item']['cost'] ?? 0);
            }
        }
        $first = ($data['profiles'] ?? [])[0]['first_item']['cost'] ?? null;

        return $first !== null ? (int) $first : null;
    }

    /** Lädt ein Bild (per URL) in die Printify-Mediathek. */
    public function uploadImageFromUrl(string $fileName, string $url): array
    {
        return $this->request('post', '/uploads/images.json', ['file_name' => $fileName, 'url' => $url])->json();
    }

    public function createProduct(array $payload): array
    {
        $shopId = config('schoolshop.printify.shop_id');

        return $this->request('post', "/shops/{$shopId}/products.json", $payload)->json();
    }

    public function publishProduct(string $productId): array
    {
        $shopId = config('schoolshop.printify.shop_id');

        return $this->request('post', "/shops/{$shopId}/products/{$productId}/publish.json", [
            'title' => true, 'description' => true, 'images' => true,
            'variants' => true, 'tags' => true, 'keyFeatures' => true, 'shipping_template' => true,
        ])->json();
    }

    private function request(string $method, string $path, array $body = []): Response
    {
        if (! $this->isConfigured()) {
            throw new WooCommerceApiException(
                'Die Printify-Verbindung ist noch nicht eingerichtet.',
                'PRINTIFY_API_TOKEN / PRINTIFY_SHOP_ID fehlen in der .env-Datei.',
                'Ein:e Administrator:in muss in Printify (My Profile → Connections) einen API-Token erstellen und zusammen mit der Shop-ID in der .env-Datei eintragen.',
            );
        }

        try {
            $pending = Http::withToken(config('schoolshop.printify.api_token'))->timeout(60)->acceptJson();
            $response = $method === 'get' ? $pending->get(self::BASE.$path) : $pending->{$method}(self::BASE.$path, $body);
        } catch (ConnectionException $e) {
            throw WooCommerceApiException::unreachable("{$method} {$path}: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            $details = strtoupper($method).' '.self::BASE.$path.": HTTP {$response->status()}. ".mb_substr($response->body(), 0, 300);
            throw match ($response->status()) {
                401 => new WooCommerceApiException('Printify hat den API-Token abgelehnt.', $details, 'Bitte den Token in der .env-Datei prüfen bzw. in Printify neu erstellen.'),
                default => WooCommerceApiException::unexpectedResponse($details),
            };
        }

        return $response;
    }
}
