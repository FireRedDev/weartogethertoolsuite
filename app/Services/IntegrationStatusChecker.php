<?php

namespace App\Services;

use App\Exceptions\WooCommerceApiException;
use App\Models\IntegrationStatus;
use App\Models\WebhookLog;
use App\Services\SchoolShop\DynamicMockupsClient;
use App\Services\SchoolShop\PrintifyClient;
use App\Services\SchoolShop\WooCommerceWriteClient;
use App\Services\SchoolShop\WordPressClient;

/**
 * Prüft alle API-Anbindungen/Schnittstellen der Toolsuite live und pflegt
 * deren Status in integration_statuses. Wechselt eine konfigurierte
 * Schnittstelle von OK/unbekannt auf fehlgeschlagen, wird EINMALIG (pro
 * Ausfall-Episode) eine Benachrichtigung über WordPressAdminNotifier
 * ausgelöst — nie ein direkter E-Mail-Versand durch die Toolsuite selbst.
 */
class IntegrationStatusChecker
{
    public function __construct(
        private readonly WooCommerceClient $wooRead,
        private readonly WooCommerceWriteClient $wooWrite,
        private readonly WordPressClient $wordpress,
        private readonly PrintifyClient $printify,
        private readonly DynamicMockupsClient $mockups,
        private readonly WordPressAdminNotifier $notifier,
    ) {}

    /**
     * Führt alle Live-Checks aus, aktualisiert die DB und benachrichtigt bei
     * neuen Ausfällen.
     *
     * @return list<array{key: string, label: string, configured: bool, ok: bool, message: string, notify: ?array}>
     */
    public function checkAll(): array
    {
        $checks = [
            [
                'key' => 'woocommerce_read',
                'label' => 'WooCommerce – Lesezugriff (Modul 1: Auftragsdokumente)',
                'configured' => $this->wooRead->isConfigured(),
                'run' => fn () => $this->wooRead->testConnection(),
                'setup_hint' => 'WC_STORE_URL / WC_CONSUMER_KEY / WC_CONSUMER_SECRET in der .env',
            ],
            [
                'key' => 'woocommerce_write',
                'label' => 'WooCommerce – Schreibzugriff (Modul 2/3: Shop-Anlage, Bestellfenster schließen)',
                'configured' => $this->wooWrite->isConfigured(),
                'run' => fn () => $this->wooWrite->testConnection(),
                'setup_hint' => 'WC_RW_CONSUMER_KEY / WC_RW_CONSUMER_SECRET in der .env',
            ],
            [
                'key' => 'wordpress',
                'label' => 'WordPress – CPT „schule" (Pods, Modul 2/3)',
                'configured' => $this->wordpress->isConfigured(),
                'run' => fn () => $this->wordpress->testConnection(),
                'setup_hint' => 'WP_APP_USER / WP_APP_PASSWORD in der .env; Pods-REST-API für „schule" aktiviert',
            ],
            [
                'key' => 'printify',
                'label' => 'Printify (Modul 2: On-Demand-Produkte)',
                'configured' => $this->printify->isConfigured(),
                'run' => fn () => $this->printify->testConnection(),
                'setup_hint' => 'PRINTIFY_API_TOKEN / PRINTIFY_SHOP_ID in der .env — optional, nur für On-Demand-Schulen',
            ],
            [
                'key' => 'dynamic_mockups',
                'label' => 'Dynamic Mockups (Modul 2: optionale Produktfotos)',
                'configured' => $this->mockups->isConfigured(),
                'run' => fn () => $this->mockups->testConnection(),
                'setup_hint' => 'DYNAMIC_MOCKUPS_API_KEY in der .env — optional, nur falls Mockups genutzt werden',
            ],
        ];

        $results = [];
        foreach ($checks as $check) {
            $results[] = $this->runAndPersist($check);
        }
        $results[] = $this->webhookStatus();

        return $results;
    }

    /** @param  array{key: string, label: string, configured: bool, run: callable, setup_hint: string}  $check */
    private function runAndPersist(array $check): array
    {
        $ok = false;
        $message = 'Nicht eingerichtet — '.$check['setup_hint'];

        if ($check['configured']) {
            try {
                ($check['run'])();
                $ok = true;
                $message = 'Verbindung erfolgreich.';
            } catch (WooCommerceApiException $e) {
                $message = $e->userMessage().' ('.$e->getMessage().')';
            } catch (\Throwable $e) {
                $message = get_class($e).': '.$e->getMessage();
            }
        }

        $previous = IntegrationStatus::where('key', $check['key'])->first();
        // Nur benachrichtigen, wenn konfiguriert+fehlgeschlagen UND die
        // vorherige bekannte Episode NICHT bereits derselbe Ausfall war
        // (previous fehlt, war ok, oder war noch nicht konfiguriert).
        $shouldNotify = $check['configured'] && ! $ok
            && ($previous === null || $previous->ok || ! $previous->configured);

        $notifyResult = null;
        $notifiedAt = $previous?->notified_at;
        if ($shouldNotify) {
            $notifyResult = $this->notifier->notify(
                "Schnittstelle gestört: {$check['label']}",
                "Die Schnittstelle \"{$check['label']}\" der Wear Together Order Suite meldet gerade einen Fehler:\n\n{$message}\n\n"
                    ."Bitte im Admin-Bereich der Toolsuite unter „Admin-Informationen\" prüfen (Zeitpunkt: ".now()->toDateTimeString().').',
            );
            $notifiedAt = now();
        } elseif ($ok) {
            $notifiedAt = null; // Ausfall-Episode beendet — nächster Ausfall meldet wieder
        }

        IntegrationStatus::updateOrCreate(
            ['key' => $check['key']],
            [
                'configured' => $check['configured'],
                'ok' => $ok,
                'message' => $message,
                'checked_at' => now(),
                'notified_at' => $notifiedAt,
            ],
        );

        return [
            'key' => $check['key'],
            'label' => $check['label'],
            'configured' => $check['configured'],
            'ok' => $ok,
            'message' => $message,
            'notify' => $notifyResult,
        ];
    }

    /**
     * FluentForms-Webhook ist eingehend — kein aktiver Verbindungstest
     * möglich. Zeigt stattdessen Konfigurationsstatus + letzten Treffer aus
     * webhook_logs. Löst NIE eine automatische Benachrichtigung aus (ein
     * bloßes Ausbleiben von Formular-Einsendungen ist kein zuverlässiges
     * Fehlersignal).
     */
    private function webhookStatus(): array
    {
        $configured = (string) config('schoolshop.webhook_secret') !== '';
        $lastHit = WebhookLog::orderByDesc('id')->first();

        $message = match (true) {
            ! $configured => 'Nicht eingerichtet — FLUENTFORMS_WEBHOOK_SECRET in der .env.',
            $lastHit === null => 'Eingerichtet, aber noch nie aufgerufen.',
            default => sprintf(
                'Letzter Treffer %s: %s %s — %s',
                $lastHit->created_at->format('d.m.Y H:i'),
                $lastHit->method,
                $lastHit->secret_ok ? '(Secret OK)' : '(Secret falsch)',
                $lastHit->outcome,
            ),
        };
        $ok = $configured && $lastHit !== null && $lastHit->secret_ok;

        return [
            'key' => 'fluentforms_webhook',
            'label' => 'FluentForms-Webhook (Modul 2: Formular-Eingang)',
            'configured' => $configured,
            'ok' => $ok,
            'message' => $message,
            'notify' => null,
        ];
    }
}
