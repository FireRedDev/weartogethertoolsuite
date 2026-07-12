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
                ->{$method}($url, $body);
        } catch (ConnectionException $e) {
            throw WooCommerceApiException::unreachable("{$method} {$url}: {$e->getMessage()}");
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
