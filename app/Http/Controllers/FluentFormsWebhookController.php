<?php

namespace App\Http\Controllers;

use App\Models\SchoolOnboarding;
use App\Models\WebhookLog;
use App\Services\SchoolShop\FluentFormsMapper;
use App\Services\SchoolShop\ProductConfigurator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Empfängt FluentForms-Submissions (Formular "Webshopstartfragebogen").
 * Das Secret ist Teil der URL; ein falsches Secret liefert 404.
 *
 * JEDER Treffer (GET wie POST, auch mit falschem Secret) wird zuerst in der
 * webhook_logs-Tabelle protokolliert — sichtbar unter Schul-Onboarding. So
 * lässt sich zweifelsfrei sehen, ob und was FluentForms an die App schickt.
 * Eine Submission geht nie verloren: Schlägt die automatische Zuordnung fehl,
 * wird der Rohdatensatz trotzdem als Antrag gespeichert.
 */
class FluentFormsWebhookController extends Controller
{
    /**
     * Browser-Test: dieselbe URL per GET öffnen bestätigt, dass Secret + URL
     * stimmen (200) — sonst 404/503.
     */
    public function verify(Request $request, string $secret): JsonResponse
    {
        $expected = (string) config('schoolshop.webhook_secret');
        $secretOk = $expected !== '' && hash_equals($expected, $secret);
        WebhookLog::record($request, $secretOk, $secretOk ? 'Browser-Test OK (GET)' : 'Browser-Test abgelehnt (GET)');

        if ($expected === '') {
            return response()->json(['ok' => false, 'error' => 'Server-Secret nicht gesetzt (FLUENTFORMS_WEBHOOK_SECRET).'], 503);
        }
        if (! $secretOk) {
            return response()->json(['ok' => false, 'error' => 'Secret in der URL stimmt nicht.'], 404);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Webhook-URL ist korrekt. FluentForms kann Submissions per POST an genau diese URL senden.',
        ]);
    }

    public function receive(Request $request, string $secret, FluentFormsMapper $mapper): JsonResponse
    {
        $expected = (string) config('schoolshop.webhook_secret');
        $secretOk = $expected !== '' && hash_equals($expected, $secret);
        // ZUERST protokollieren — noch vor jeder Prüfung, damit jeder Eingang sichtbar ist.
        $logEntry = WebhookLog::record($request, $secretOk, 'empfangen (POST)');

        if ($expected === '') {
            $logEntry->update(['outcome' => 'abgelehnt: Server-Secret nicht gesetzt (503)']);
            Log::error('FluentForms-Webhook aufgerufen, aber FLUENTFORMS_WEBHOOK_SECRET ist in der .env nicht gesetzt.');

            return response()->json([
                'ok' => false,
                'error' => 'Auf dem Server ist kein Webhook-Secret konfiguriert (FLUENTFORMS_WEBHOOK_SECRET fehlt in der .env). '
                    .'Bitte setzen und danach php artisan config:cache ausführen.',
            ], 503);
        }
        if (! $secretOk) {
            $logEntry->update(['outcome' => 'abgelehnt: Secret falsch (404)']);
            Log::warning('FluentForms-Webhook mit falschem Secret aufgerufen.', [
                'received_length' => mb_strlen($secret), 'expected_length' => mb_strlen($expected),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'Ungültige Webhook-URL: Das Secret am Ende der URL stimmt nicht mit FLUENTFORMS_WEBHOOK_SECRET überein.',
            ], 404);
        }

        $payload = $request->all();
        // FluentForms verschachtelt die Antworten je nach Konfiguration unter "response"
        if (isset($payload['response']) && is_array($payload['response'])) {
            $payload = array_merge($payload, $payload['response']);
        }

        Log::info('FluentForms-Webhook empfangen.', [
            'content_type' => $request->header('Content-Type'),
            'field_keys' => array_keys($payload),
        ]);

        if ($payload === []) {
            $logEntry->update(['outcome' => 'leerer Payload empfangen — als leerer Antrag gesichert']);
            Log::warning('FluentForms-Webhook: leerer Payload. Sendet FluentForms die Felder als JSON/Formulardaten?');
        }

        try {
            $onboarding = $mapper->map($payload);
            $onboarding->save();
        } catch (\Throwable $e) {
            // Nichts verlieren: Rohdaten trotzdem als Antrag sichern.
            report($e);
            $fallback = new SchoolOnboarding([
                'status' => 'neu',
                'source' => 'webhook',
                'school_name' => '⚠ Formular-Eingang – Zuordnung fehlgeschlagen',
                'delivery_type' => 'collective',
                'products' => ProductConfigurator::defaultsAllDisabled(),
                'print_areas' => [],
                'raw_entry' => $payload,
                'notes' => 'Automatische Zuordnung fehlgeschlagen: '.$e->getMessage(),
            ]);
            $fallback->save();
            $logEntry->update(['outcome' => "Zuordnung fehlgeschlagen — Rohdaten als Antrag #{$fallback->id} gesichert"]);
            Log::error('FluentForms-Webhook: Zuordnung fehlgeschlagen, Rohdaten gesichert.', [
                'onboarding_id' => $fallback->id, 'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'saved_as_id' => $fallback->id,
                'error' => 'Submission wurde gespeichert, aber die automatische Zuordnung ist fehlgeschlagen: '.$e->getMessage(),
            ]);
        }

        $logEntry->update(['outcome' => "Antrag #{$onboarding->id} angelegt: {$onboarding->school_name}"]);
        Log::info('FluentForms-Webhook: Onboarding angelegt.', [
            'onboarding_id' => $onboarding->id, 'school_name' => $onboarding->school_name,
        ]);

        return response()->json(['ok' => true, 'id' => $onboarding->id]);
    }
}
