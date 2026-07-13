<?php

namespace App\Services\SchoolShop;

use App\Exceptions\WooCommerceApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WordPress REST (wp/v2) mit Application Password: Pods-CPT "schule".
 * WooCommerce-API-Schlüssel gelten hier NICHT — es braucht ein
 * WordPress-Anwendungspasswort (Benutzer → Profil → Anwendungspasswörter).
 */
class WordPressClient
{
    public function isConfigured(): bool
    {
        return config('ordersuite.woocommerce.store_url') !== ''
            && config('schoolshop.wordpress.user') !== ''
            && config('schoolshop.wordpress.password') !== '';
    }

    /**
     * Verbindungstest für den Admin-Status: prüft Anwendungspasswort UND dass
     * der CPT „schule" per REST erreichbar ist (die eigentliche Abhängigkeit).
     */
    public function testConnection(): void
    {
        $restBase = config('schoolshop.wordpress.schule_post_type_rest_base');
        $this->request('get', $restBase, ['per_page' => 1, '_fields' => 'id']);
    }

    /**
     * Legt den CPT-Eintrag "schule" an (Pods). Meta-Felder werden sowohl
     * top-level (Pods-REST-Stil) als auch unter "meta" übergeben, damit es
     * mit und ohne Pods-REST-Schreibunterstützung funktioniert.
     */
    public function createSchule(string $title, array $fields): array
    {
        $restBase = config('schoolshop.wordpress.schule_post_type_rest_base');

        return $this->request('post', $restBase, array_merge(
            ['title' => $title, 'status' => 'publish', 'meta' => $fields],
            $fields,
        ))->json();
    }

    /** Setzt die Pods-Felder eines bestehenden Eintrags (idempotent). */
    public function updateSchule(int $postId, array $fields): array
    {
        $restBase = config('schoolshop.wordpress.schule_post_type_rest_base');

        return $this->request('post', "{$restBase}/{$postId}", array_merge(
            ['meta' => $fields],
            $fields,
        ))->json();
    }

    /** Liest einen Eintrag zurück (zur Verifikation der gesetzten Felder). */
    public function getSchule(int $postId): array
    {
        $restBase = config('schoolshop.wordpress.schule_post_type_rest_base');
        $data = $this->request('get', "{$restBase}/{$postId}", [])->json();

        return is_array($data) ? $data : [];
    }

    /** Setzt das Beitragsbild (Featured Image) eines Eintrags. */
    public function setFeaturedImage(int $postId, int $mediaId): void
    {
        $restBase = config('schoolshop.wordpress.schule_post_type_rest_base');
        $this->request('post', "{$restBase}/{$postId}", ['featured_media' => $mediaId]);
    }

    /**
     * Lädt ein Bild per URL in die WordPress-Mediathek und gibt die
     * Media-ID zurück.
     */
    public function uploadMediaFromUrl(string $url): int
    {
        $binary = Http::timeout(60)->get($url);
        if (! $binary->successful()) {
            throw WooCommerceApiException::unreachable("Logo-Download {$url}: HTTP {$binary->status()}");
        }
        $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'logo.png') ?: 'logo.png';
        $storeUrl = rtrim(config('ordersuite.woocommerce.store_url'), '/');

        $response = Http::withBasicAuth(config('schoolshop.wordpress.user'), config('schoolshop.wordpress.password'))
            ->timeout(60)
            ->withHeaders(['Content-Disposition' => 'attachment; filename="'.$filename.'"'])
            ->withBody($binary->body(), $binary->header('Content-Type') ?: 'image/png')
            ->post("{$storeUrl}/wp-json/wp/v2/media");

        if (! $response->successful() || ! isset($response->json()['id'])) {
            throw WooCommerceApiException::unexpectedResponse(
                "Media-Upload {$filename}: HTTP {$response->status()}. ".mb_substr($response->body(), 0, 300),
            );
        }

        return (int) $response->json()['id'];
    }

    private function request(string $method, string $endpoint, array $body = []): Response
    {
        if (! $this->isConfigured()) {
            throw new WooCommerceApiException(
                'Der WordPress-Zugriff ist noch nicht eingerichtet.',
                'WP_APP_USER / WP_APP_PASSWORD fehlen in der .env-Datei.',
                'Ein:e Administrator:in muss in WordPress unter Benutzer → Profil → Anwendungspasswörter ein Passwort erstellen und in der .env-Datei eintragen.',
            );
        }
        $url = rtrim(config('ordersuite.woocommerce.store_url'), '/')."/wp-json/wp/v2/{$endpoint}";

        try {
            $response = Http::withBasicAuth(config('schoolshop.wordpress.user'), config('schoolshop.wordpress.password'))
                ->timeout(60)
                ->acceptJson()
                ->withOptions(['allow_redirects' => false])
                ->{$method}($url, $body);
        } catch (ConnectionException $e) {
            throw WooCommerceApiException::unreachable("{$method} {$url}: {$e->getMessage()}");
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            $location = $response->header('Location') ?: '(unbekannt)';
            throw new WooCommerceApiException(
                'Die Shop-Adresse in der Konfiguration leitet um — dabei gehen Schreibzugriffe verloren.',
                strtoupper($method)." {$url}: HTTP {$response->status()} Umleitung nach {$location}",
                'Bitte WC_STORE_URL in der .env-Datei exakt auf die endgültige Shop-Adresse setzen (auf www./ohne www. achten) und danach php artisan config:cache ausführen.',
            );
        }

        if (! $response->successful()) {
            $details = strtoupper($method)." {$url}: HTTP {$response->status()}. ".mb_substr($response->body(), 0, 300);
            throw match ($response->status()) {
                401 => new WooCommerceApiException(
                    'WordPress hat die Anmeldung abgelehnt.',
                    $details,
                    'Bitte das Anwendungspasswort prüfen (Benutzer → Profil → Anwendungspasswörter). Manche Sicherheits-Plugins deaktivieren Anwendungspasswörter.',
                ),
                404 => new WooCommerceApiException(
                    'Der Inhaltstyp „schule" ist über die WordPress-Schnittstelle nicht erreichbar.',
                    $details,
                    'Im Pods-Admin beim Pod „schule" den Haken „REST-API aktivieren" setzen (Tab REST-API), damit wp/v2/schule verfügbar wird.',
                ),
                default => WooCommerceApiException::unexpectedResponse($details),
            };
        }

        return $response;
    }
}
