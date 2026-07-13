<?php

namespace App\Services\SchoolShop;

use App\Exceptions\WooCommerceApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Dynamic Mockups REST API v1 (app.dynamicmockups.com) — rendert das Schullogo
 * auf Mockup-Vorlagen (Model-Fotos + Produktdetails). Auth per X-API-KEY.
 */
class DynamicMockupsClient
{
    public function isConfigured(): bool
    {
        return (string) config('schoolshop.mockups.api_key') !== '';
    }

    /** Verbindungstest für den Admin-Status. */
    public function testConnection(): void
    {
        $this->request('get', '/mockups');
    }

    /** @return list<array<string, mixed>> Mockup-Vorlagen (My Templates / Bibliothek). */
    public function listMockups(): array
    {
        $data = $this->request('get', '/mockups')->json();

        return is_array($data['data'] ?? null) ? $data['data'] : (is_array($data) ? $data : []);
    }

    /**
     * Details einer Vorlage inkl. Smart-Objects (uuid, size, position,
     * print_area_presets) — 24h gecacht, da statisch.
     */
    public function getMockup(string $mockupUuid): array
    {
        return Cache::remember("dynamic_mockups.mockup.{$mockupUuid}", now()->addDay(), function () use ($mockupUuid) {
            $data = $this->request('get', "/mockups/{$mockupUuid}")->json();

            return is_array($data['data'] ?? null) ? $data['data'] : (is_array($data) ? $data : []);
        });
    }

    /**
     * Rendert das Logo auf eine Vorlage. Platzierung relativ zum Druckbereich
     * des Smart-Objects (x/y = Mittelpunkt 0..1, width = Breitenanteil 0..1);
     * ist dessen Größe über die API nicht ermittelbar, rendert Dynamic Mockups
     * mit seiner Standardposition (fit contain im Druckbereich).
     *
     * @param  array{x: float, y: float, width: float}  $placement
     * @return string URL des fertigen Bildes (CDN)
     */
    public function render(string $mockupUuid, string $smartObjectUuid, string $logoUrl, array $placement, string $label): string
    {
        $asset = ['url' => $logoUrl, 'fit' => 'contain'];

        $area = $this->smartObjectArea($mockupUuid, $smartObjectUuid);
        if ($area !== null) {
            // Quadratische Box um den Mittelpunkt; 'contain' erhält das Logo-Seitenverhältnis.
            $box = (int) round($placement['width'] * $area['width']);
            $left = (int) round($placement['x'] * $area['width'] - $box / 2);
            $top = (int) round($placement['y'] * $area['height'] - $box / 2);
            $asset['size'] = ['width' => $box, 'height' => $box];
            $asset['position'] = [
                'left' => max(0, min($left, $area['width'] - $box)),
                'top' => max(0, min($top, $area['height'] - $box)),
            ];
        }

        $response = $this->request('post', '/renders', [
            'mockup_uuid' => $mockupUuid,
            'export_label' => $label,
            'export_options' => [
                'image_format' => 'jpg',
                'image_size' => (int) config('schoolshop.mockups.image_size', 1500),
                'mode' => 'view',
            ],
            'smart_objects' => [
                ['uuid' => $smartObjectUuid, 'asset' => $asset],
            ],
        ])->json();

        $url = $response['data']['export_path'] ?? $response['export_path'] ?? null;
        if (! is_string($url) || $url === '') {
            throw WooCommerceApiException::unexpectedResponse(
                'Dynamic Mockups /renders: Antwort enthält keinen export_path. Rohe Antwort: '.mb_substr(json_encode($response), 0, 500),
            );
        }

        return $url;
    }

    /** @return array{width: int, height: int}|null Druckbereich des Smart-Objects, falls über die API ermittelbar. */
    private function smartObjectArea(string $mockupUuid, string $smartObjectUuid): ?array
    {
        try {
            $mockup = $this->getMockup($mockupUuid);
        } catch (\Throwable) {
            return null; // Platzierung dann per Dynamic-Mockups-Standard
        }
        foreach ($mockup['smart_objects'] ?? [] as $so) {
            if (($so['uuid'] ?? null) === $smartObjectUuid) {
                $w = (int) ($so['size']['width'] ?? 0);
                $h = (int) ($so['size']['height'] ?? 0);

                return ($w > 0 && $h > 0) ? ['width' => $w, 'height' => $h] : null;
            }
        }

        return null;
    }

    private function request(string $method, string $path, array $body = []): Response
    {
        if (! $this->isConfigured()) {
            throw new WooCommerceApiException(
                'Die Mockup-Erzeugung ist noch nicht eingerichtet.',
                'DYNAMIC_MOCKUPS_API_KEY fehlt in der .env-Datei.',
                'API-Key in Dynamic Mockups (app.dynamicmockups.com → API) erstellen, in der .env eintragen und php artisan config:cache ausführen.',
            );
        }

        $base = rtrim((string) config('schoolshop.mockups.base_url'), '/');
        try {
            $pending = Http::withHeaders(['x-api-key' => config('schoolshop.mockups.api_key')])
                ->timeout(120)->acceptJson();
            $response = $method === 'get' ? $pending->get($base.$path) : $pending->post($base.$path, $body);
        } catch (ConnectionException $e) {
            throw WooCommerceApiException::unreachable("{$method} {$path}: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            $details = strtoupper($method).' '.$base.$path.": HTTP {$response->status()}. ".mb_substr($response->body(), 0, 300);
            throw match ($response->status()) {
                401, 403 => new WooCommerceApiException(
                    'Dynamic Mockups hat den API-Key abgelehnt.',
                    $details,
                    'Bitte DYNAMIC_MOCKUPS_API_KEY in der .env prüfen (app.dynamicmockups.com → API) und php artisan config:cache ausführen.',
                ),
                402, 429 => new WooCommerceApiException(
                    'Dynamic Mockups: Render-Kontingent erschöpft oder Rate-Limit erreicht.',
                    $details,
                    'Credits/Plan im Dynamic-Mockups-Dashboard prüfen und später erneut versuchen.',
                ),
                default => WooCommerceApiException::unexpectedResponse($details),
            };
        }

        return $response;
    }
}
