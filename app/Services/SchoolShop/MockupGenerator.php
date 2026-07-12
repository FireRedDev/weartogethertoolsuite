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
     * Rendert alle Mockups eines Produkts. Reihenfolge: Lifestyle zuerst
     * (erstes Bild = WooCommerce-Produktbild), dann Details (Galerie).
     *
     * @param  array<string, mixed>  $product  Eintrag aus dem products-JSON
     * @return list<array{url: string, label: string}>
     */
    public function generateForProduct(SchoolOnboarding $onboarding, array $product, string $logoUrl): array
    {
        $templates = config("schoolshop.mockups.templates.{$product['key']}", ['lifestyle' => [], 'detail' => []]);
        $placement = $this->placement($onboarding);
        $images = [];

        foreach ($this->pickLifestyle($onboarding, $product['key'], $templates['lifestyle'] ?? []) as $i => $template) {
            $label = "{$onboarding->school_name} {$product['key']} lifestyle ".($template['model'] ?? $i);
            $images[] = [
                'url' => $this->client->render($template['mockup_uuid'], $template['smart_object_uuid'], $logoUrl, $placement, $label),
                'label' => $label,
            ];
        }

        foreach ($this->pickDetails($product, $templates['detail'] ?? []) as $template) {
            $label = "{$onboarding->school_name} {$product['key']} detail ".($template['color'] ?? '');
            $images[] = [
                'url' => $this->client->render($template['mockup_uuid'], $template['smart_object_uuid'], $logoUrl, $placement, $label),
                'label' => trim($label),
            ];
        }

        return $images;
    }

    /** @return array{x: float, y: float, width: float} */
    private function placement(SchoolOnboarding $onboarding): array
    {
        $placements = config('schoolshop.mockups.placements');
        $key = $onboarding->mockup_placement;

        return $placements[$key] ?? $placements['brust_links'];
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
