<?php

namespace App\Http\Controllers;

use App\Services\SchoolShop\FluentFormsMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Empfängt FluentForms-Submissions (Formular "Webshopstartfragebogen").
 * Das Secret ist Teil der URL; ein falsches Secret liefert 404.
 */
class FluentFormsWebhookController extends Controller
{
    public function receive(Request $request, string $secret, FluentFormsMapper $mapper): JsonResponse
    {
        $expected = (string) config('schoolshop.webhook_secret');
        abort_if($expected === '' || ! hash_equals($expected, $secret), 404);

        $payload = $request->all();
        // FluentForms verschachtelt die Antworten je nach Konfiguration unter "response"
        if (isset($payload['response']) && is_array($payload['response'])) {
            $payload = array_merge($payload, $payload['response']);
        }

        $onboarding = $mapper->map($payload);
        $onboarding->save();

        return response()->json(['ok' => true, 'id' => $onboarding->id]);
    }
}
