<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Schickt eine Admin-Benachrichtigung AUSSCHLIESSLICH über die WordPress
 * REST API — die Toolsuite selbst hat keinen Mailer und verschickt nie
 * direkt E-Mails. Voraussetzung ist ein kleines mu-Plugin auf der
 * WordPress-Seite, das einen REST-Endpunkt bereitstellt und dort wp_mail()
 * aufruft (Vorlage: wordpress-mu-plugin/weartogether-notify.php im Repo).
 *
 * Ist das mu-Plugin nicht installiert, schlägt der Aufruf einfach fehl
 * (404) und wird geloggt — das Admin-Status-Dashboard bleibt in jedem Fall
 * die verlässliche Quelle, die E-Mail ist nur ein optionales Extra.
 */
class WordPressAdminNotifier
{
    public function isConfigured(): bool
    {
        return config('ordersuite.woocommerce.store_url') !== ''
            && config('schoolshop.wordpress.user') !== ''
            && config('schoolshop.wordpress.password') !== '';
    }

    /** @return array{attempted: bool, ok: bool, detail: string} */
    public function notify(string $subject, string $message): array
    {
        if (! $this->isConfigured()) {
            return ['attempted' => false, 'ok' => false, 'detail' => 'WordPress-Zugang nicht konfiguriert (WP_APP_USER/WP_APP_PASSWORD).'];
        }

        $url = rtrim((string) config('ordersuite.woocommerce.store_url'), '/').'/wp-json/weartogether/v1/notify';

        try {
            $response = Http::withBasicAuth(
                (string) config('schoolshop.wordpress.user'),
                (string) config('schoolshop.wordpress.password'),
            )->timeout(30)->acceptJson()->withOptions(['allow_redirects' => false])
                ->post($url, ['subject' => $subject, 'message' => $message]);
        } catch (\Throwable $e) {
            Log::warning('WordPressAdminNotifier: Aufruf fehlgeschlagen.', ['error' => $e->getMessage()]);

            return ['attempted' => true, 'ok' => false, 'detail' => 'Verbindung fehlgeschlagen: '.$e->getMessage()];
        }

        if ($response->status() === 404) {
            $detail = 'Endpunkt /wp-json/weartogether/v1/notify existiert nicht — das mu-Plugin ist auf dieser WordPress-Seite nicht installiert (siehe wordpress-mu-plugin/weartogether-notify.php).';
            Log::info('WordPressAdminNotifier: mu-Plugin nicht installiert, E-Mail-Benachrichtigung übersprungen.');

            return ['attempted' => true, 'ok' => false, 'detail' => $detail];
        }

        if (! $response->successful()) {
            $detail = "HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300);
            Log::warning('WordPressAdminNotifier: WordPress hat die Benachrichtigung abgelehnt.', ['detail' => $detail]);

            return ['attempted' => true, 'ok' => false, 'detail' => $detail];
        }

        $sent = (bool) ($response->json('ok') ?? true);
        Log::info('WordPressAdminNotifier: Benachrichtigung an WordPress gesendet.', ['sent' => $sent]);

        return ['attempted' => true, 'ok' => $sent, 'detail' => $sent ? 'E-Mail über wp_mail() ausgelöst.' : 'WordPress meldet wp_mail()=false (z. B. kein Mailserver konfiguriert).'];
    }
}
