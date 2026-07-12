<?php

namespace App\Http\Controllers;

use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\FluentFormsMapper;
use App\Services\SchoolShop\ProductConfigurator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Empfängt FluentForms-Submissions (Formular "Webshopstartfragebogen").
 * Das Secret ist Teil der URL; ein falsches Secret liefert 404.
 *
 * Alle Aufrufe werden protokolliert (storage/logs/laravel.log), und eine
 * Submission geht nie verloren: Schlägt die automatische Zuordnung fehl, wird
 * der Rohdatensatz trotzdem als Antrag gespeichert und in der Schulliste
 * sichtbar gemacht (mit Fehlerhinweis), damit er nicht spurlos verschwindet.
 */
class FluentFormsWebhookController extends Controller
{
    /**
     * Browser-Test: dieselbe URL per GET öffnen bestätigt, dass Secret + URL
     * stimmen (200) — sonst 404. So lässt sich ohne FluentForms prüfen, ob die
     * in FluentForms eingetragene Webhook-URL korrekt ist.
     */
    public function verify(string $secret): JsonResponse
    {
        $this->assertSecret($secret);

        return response()->json([
            'ok' => true,
            'message' => 'Webhook-URL ist korrekt. FluentForms kann Submissions per POST an genau diese URL senden.',
        ]);
    }

    public function receive(Request $request, string $secret, FluentFormsMapper $mapper): JsonResponse
    {
        $this->assertSecret($secret);

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
            Log::warning('FluentForms-Webhook: leerer Payload. Sendet FluentForms die Felder als JSON/Formulardaten?');
        }

        try {
            $onboarding = $mapper->map($payload);
            $onboarding->save();
        } catch (\Throwable $e) {
            // Nichts verlieren: Rohdaten trotzdem als Antrag sichern, damit die
            // Submission in der Liste auftaucht und manuell nachbearbeitet werden kann.
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

            Log::error('FluentForms-Webhook: Zuordnung fehlgeschlagen, Rohdaten gesichert.', [
                'onboarding_id' => $fallback->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'saved_as_id' => $fallback->id,
                'error' => 'Submission wurde gespeichert, aber die automatische Zuordnung ist fehlgeschlagen: '.$e->getMessage(),
            ]);
        }

        Log::info('FluentForms-Webhook: Onboarding angelegt.', [
            'onboarding_id' => $onboarding->id,
            'school_name' => $onboarding->school_name,
        ]);

        return response()->json(['ok' => true, 'id' => $onboarding->id]);
    }

    /**
     * Prüft das Secret aus der URL. Ein leeres Server-Secret ist ein
     * Konfigurationsfehler (nicht "nicht gefunden"), deshalb eigene 503-Antwort
     * mit klarer Meldung. Falsches Secret → 404 mit Hinweis.
     */
    private function assertSecret(string $secret): void
    {
        $expected = (string) config('schoolshop.webhook_secret');

        if ($expected === '') {
            Log::error('FluentForms-Webhook aufgerufen, aber FLUENTFORMS_WEBHOOK_SECRET ist in der .env nicht gesetzt.');
            abort(response()->json([
                'ok' => false,
                'error' => 'Auf dem Server ist kein Webhook-Secret konfiguriert (FLUENTFORMS_WEBHOOK_SECRET fehlt in der .env). '
                    .'Bitte setzen und danach php artisan config:cache ausführen.',
            ], 503));
        }

        if (! hash_equals($expected, $secret)) {
            Log::warning('FluentForms-Webhook mit falschem Secret aufgerufen.', [
                'received_length' => mb_strlen($secret),
                'expected_length' => mb_strlen($expected),
            ]);
            abort(response()->json([
                'ok' => false,
                'error' => 'Ungültige Webhook-URL: Das Secret am Ende der URL stimmt nicht mit FLUENTFORMS_WEBHOOK_SECRET überein.',
            ], 404));
        }
    }
}
