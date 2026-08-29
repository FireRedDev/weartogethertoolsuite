<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;
use App\Services\PresentationSheet\PresentationSheetRenderer;
use Illuminate\Support\Facades\Http;

/**
 * Ruft die Bestellseite einer Schule so ab, wie es ein:e Schüler:in nach dem
 * Scannen des QR-Codes täte. Verhindert den peinlichsten Fall: Das
 * Präsentationsblatt hängt aus und führt auf eine 404-Seite oder eine Seite
 * ohne Produkte.
 */
class ShopPageChecker
{
    public function __construct(private readonly PresentationSheetRenderer $sheet) {}

    /**
     * @return array{ok: bool, url: string, status: ?int, message: string}
     */
    public function check(SchoolOnboarding $onboarding): array
    {
        $url = $this->sheet->shopUrl($onboarding);

        try {
            $response = Http::timeout(20)->withHeaders(['User-Agent' => 'WearTogetherOrderSuite/1.0'])->get($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'url' => $url, 'status' => null,
                'message' => 'Die Seite ist nicht erreichbar: '.$e->getMessage()];
        }

        $status = $response->status();
        if ($status === 404) {
            return ['ok' => false, 'url' => $url, 'status' => $status,
                'message' => 'Die Seite gibt es nicht (404). Stimmt die Adresse? Sie wird aus dem Schulnamen abgeleitet — '
                    .'im Präsentationsblatt lässt sie sich überschreiben.'];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'url' => $url, 'status' => $status,
                'message' => "Die Seite antwortet mit HTTP {$status}."];
        }

        // Grobe Gegenprobe: steht der Schulname drauf und sieht man Produkte?
        $body = $response->body();
        $hints = [];
        if (! str_contains(mb_strtolower($body), mb_strtolower($onboarding->school_name))) {
            $hints[] = 'der Schulname kommt auf der Seite nicht vor';
        }
        if (! preg_match('/add-to-cart|woocommerce|in den warenkorb/i', $body)) {
            $hints[] = 'es sind keine bestellbaren Produkte erkennbar';
        }

        if ($hints !== []) {
            return ['ok' => false, 'url' => $url, 'status' => $status,
                'message' => 'Die Seite lädt (HTTP '.$status.'), aber '.implode(' und ', $hints)
                    .'. Bitte vor dem Aushängen selbst ansehen.'];
        }

        return ['ok' => true, 'url' => $url, 'status' => $status,
            'message' => 'Die Bestellseite ist erreichbar und zeigt Produkte.'];
    }
}
