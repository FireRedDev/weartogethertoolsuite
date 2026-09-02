<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;

/**
 * Erzeugt Produktfotos je Produkt: 1–2 Lifestyle-Fotos (Model-Fotos, bevorzugt
 * 1 Frau + 1 Mann) plus Produktdetail-Fotos in den gewählten Schulfarben.
 *
 * Vorlagen-Pools stehen in config('schoolshop.mockups.templates'); die Auswahl
 * ist pro Schule deterministisch geseedet, damit unterschiedliche Schulen
 * unterschiedliche Models/Posen bekommen, Wiederholungsläufe derselben Schule
 * aber stabil bleiben (keine doppelten Credits, konsistente Bilder).
 */
class MockupGenerator
{
    public function __construct(private readonly DynamicMockupsClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Die zu rendernden Vorlagen eines Produkts, in Anzeigereihenfolge:
     * Lifestyle zuerst (erstes Bild = WooCommerce-Produktbild), dann Details
     * (Galerie).
     *
     * Bewusst getrennt vom Rendern: Jeder Render kostet Credits, deshalb muss
     * der Aufrufer einzeln rendern und jedes fertige Bild sofort vermerken
     * können. Würde erst am Ende gespeichert, wären bei einem Fehler im letzten
     * Bild alle vorher bezahlten verloren.
     *
     * @param  array<string, mixed>  $product  Eintrag aus dem products-JSON
     * @return list<array{label: string, mockup_uuid: string, smart_object_uuid: string}>
     */
    public function planForProduct(SchoolOnboarding $onboarding, array $product): array
    {
        $templates = config("schoolshop.mockups.templates.{$product['key']}", ['lifestyle' => [], 'detail' => []]);
        $plan = [];

        foreach ($this->pickLifestyle($onboarding, $product['key'], $templates['lifestyle'] ?? []) as $i => $template) {
            $plan[] = [
                'label' => trim("{$onboarding->school_name} {$product['key']} lifestyle ".($template['model'] ?? $i)),
                'mockup_uuid' => $template['mockup_uuid'],
                'smart_object_uuid' => $template['smart_object_uuid'],
            ];
        }

        foreach ($this->pickDetails($product, $templates['detail'] ?? []) as $template) {
            $plan[] = [
                'label' => trim("{$onboarding->school_name} {$product['key']} detail ".($template['color'] ?? '')),
                'mockup_uuid' => $template['mockup_uuid'],
                'smart_object_uuid' => $template['smart_object_uuid'],
            ];
        }

        return $plan;
    }

    /**
     * Rendert EIN Bild. Mockups zeigen die Vorderseite — Position und Größe
     * kommen daher aus dem Frontprint.
     *
     * @param  array{label: string, mockup_uuid: string, smart_object_uuid: string}  $template
     */
    public function renderOne(SchoolOnboarding $onboarding, array $template, string $logoUrl): string
    {
        return $this->client->render(
            $template['mockup_uuid'],
            $template['smart_object_uuid'],
            $logoUrl,
            $onboarding->logoPlacement('front'),
            $template['label'],
        );
    }

    /**
     * 1 Frau + 1 Mann, jeweils deterministisch aus dem Pool geseedet (Schule +
     * Produkt) — verschiedene Schulen bekommen so verschiedene Models/Posen.
     * Gibt es nur ein Geschlecht im Pool, werden bis zu 2 daraus gewählt.
     *
     * @param  list<array<string, mixed>>  $pool
     * @return list<array<string, mixed>>
     */
    private function pickLifestyle(SchoolOnboarding $onboarding, string $productKey, array $pool): array
    {
        if ($pool === []) {
            return [];
        }
        $byModel = ['female' => [], 'male' => [], 'other' => []];
        foreach ($pool as $template) {
            $model = in_array($template['model'] ?? '', ['female', 'male'], true) ? $template['model'] : 'other';
            $byModel[$model][] = $template;
        }

        $picked = [];
        foreach (['female', 'male'] as $model) {
            if ($byModel[$model] !== []) {
                $picked[] = $this->seededPick($byModel[$model], "{$onboarding->id}|{$productKey}|{$model}");
            }
        }
        if ($picked === [] && $byModel['other'] !== []) {
            $picked[] = $this->seededPick($byModel['other'], "{$onboarding->id}|{$productKey}|other");
        }
        // Nur ein Geschlecht vorhanden → zweites, anderes Foto aus demselben Pool ergänzen
        if (count($picked) === 1) {
            $singlePool = array_values(array_filter($pool, fn ($t) => $t !== $picked[0]));
            if ($singlePool !== []) {
                $picked[] = $this->seededPick($singlePool, "{$onboarding->id}|{$productKey}|second");
            }
        }

        return $picked;
    }

    /**
     * Detail-Vorlagen passend zu den gewählten Schulfarben (max. 4, wie die
     * bisherige Produktgalerie). Farben ohne passende Vorlage werden bewusst
     * übersprungen — ein Detailfoto in der falschen Farbe wäre irreführend.
     *
     * @param  array<string, mixed>  $product
     * @param  list<array<string, mixed>>  $pool
     * @return list<array<string, mixed>>
     */
    private function pickDetails(array $product, array $pool): array
    {
        $colors = array_map(fn ($c) => mb_strtolower(trim((string) $c)), $product['colors'] ?? []);

        return array_slice(array_values(array_filter(
            $pool,
            fn ($t) => in_array(mb_strtolower(trim((string) ($t['color'] ?? ''))), $colors, true),
        )), 0, 4);
    }

    /**
     * Deterministische Auswahl aus einem Pool (stabil pro Seed).
     *
     * @param  list<array<string, mixed>>  $pool
     * @return array<string, mixed>
     */
    private function seededPick(array $pool, string $seed): array
    {
        return $pool[crc32($seed) % count($pool)];
    }
}
