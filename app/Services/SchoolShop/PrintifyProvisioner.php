<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;

/**
 * On-Demand-Weg: legt Printify-Produkte an (Blueprint + Print Provider je
 * Produkt), prüft die Mindestmarge und published in den WooCommerce-Shop.
 *
 * Preisregel (Vorgabe): Verkaufspreis >= (Produktionskosten + Versand) * (1 + Marge).
 */
class PrintifyProvisioner
{
    public function __construct(private readonly PrintifyClient $printify) {}

    /**
     * Mindest-Verkaufspreis in Cent für einen Blueprint/Provider
     * (teuerste Variante + Versand erster Artikel, plus Marge).
     *
     * @return array{min_price_cents: int, max_variant_cost_cents: int, shipping_cents: int}
     */
    public function minimumPrice(int $blueprintId, int $providerId): array
    {
        $variants = $this->printify->variants($blueprintId, $providerId);
        $maxCost = 0;
        foreach ($variants as $variant) {
            $maxCost = max($maxCost, (int) ($variant['cost'] ?? 0));
        }
        $shipping = $this->printify->firstItemShippingCents($blueprintId, $providerId) ?? 0;
        $margin = (float) config('schoolshop.printify.min_margin');

        return [
            'min_price_cents' => (int) ceil(($maxCost + $shipping) * (1 + $margin)),
            'max_variant_cost_cents' => $maxCost,
            'shipping_cents' => $shipping,
        ];
    }

    /**
     * Prüft den konfigurierten Verkaufspreis gegen die Mindestmarge.
     *
     * @return array{ok: bool, message: string, min_price_cents: int}
     */
    public function checkPrice(float $salePriceEur, int $blueprintId, int $providerId): array
    {
        $minimum = $this->minimumPrice($blueprintId, $providerId);
        $salePriceCents = (int) round($salePriceEur * 100);
        $ok = $salePriceCents >= $minimum['min_price_cents'];

        return [
            'ok' => $ok,
            'min_price_cents' => $minimum['min_price_cents'],
            'message' => sprintf(
                'Produktionskosten max. %.2f EUR + Versand %.2f EUR, Mindestpreis inkl. %d%% Marge: %.2f EUR — Verkaufspreis %.2f EUR %s',
                $minimum['max_variant_cost_cents'] / 100,
                $minimum['shipping_cents'] / 100,
                (int) round(config('schoolshop.printify.min_margin') * 100),
                $minimum['min_price_cents'] / 100,
                $salePriceEur,
                $ok ? 'OK' : 'ZU NIEDRIG',
            ),
        ];
    }

    /**
     * Legt ein Printify-Produkt an und published es in den Shop.
     * Bricht ab, wenn der Preis die Mindestmarge verletzt.
     *
     * @param  array{key: string, base_price: float, colors: list<string>}  $product
     * @return array{printify_product_id: string, price_check: array}
     */
    public function createAndPublish(
        SchoolOnboarding $onboarding,
        array $product,
        int $blueprintId,
        int $providerId,
        string $logoUrl,
        ?string $backLogoUrl = null,
    ): array {
        $priceCheck = $this->checkPrice((float) $product['base_price'], $blueprintId, $providerId);
        if (! $priceCheck['ok']) {
            throw new \RuntimeException('Preisprüfung fehlgeschlagen: '.$priceCheck['message']);
        }

        $image = $this->printify->uploadImageFromUrl(
            basename(parse_url($logoUrl, PHP_URL_PATH) ?: 'logo.png'),
            $logoUrl,
        );

        $variants = $this->printify->variants($blueprintId, $providerId);
        $priceCents = (int) round((float) $product['base_price'] * 100);
        $variantPayload = [];
        $variantIds = [];
        foreach ($variants as $variant) {
            $variantPayload[] = ['id' => (int) $variant['id'], 'price' => $priceCents, 'is_enabled' => true];
            $variantIds[] = (int) $variant['id'];
        }

        // Frontprint links auf der Brust (wie die Bestellemail-Vorlage);
        // optionaler Backprint mittig.
        $placeholders = [[
            'position' => 'front',
            'images' => [[
                'id' => $image['id'],
                'x' => 0.5, 'y' => 0.35, 'scale' => 0.35, 'angle' => 0,
            ]],
        ]];
        if ($backLogoUrl !== null) {
            $backImage = $this->printify->uploadImageFromUrl(
                basename(parse_url($backLogoUrl, PHP_URL_PATH) ?: 'backprint.png'),
                $backLogoUrl,
            );
            $placeholders[] = [
                'position' => 'back',
                'images' => [[
                    'id' => $backImage['id'],
                    'x' => 0.5, 'y' => 0.4, 'scale' => 0.7, 'angle' => 0,
                ]],
            ];
        }

        $preset = config("schoolshop.catalog.{$product['key']}");
        $created = $this->printify->createProduct([
            'title' => $onboarding->school_name.' '.$preset['name_suffix'],
            'description' => strip_tags($preset['description']),
            'blueprint_id' => $blueprintId,
            'print_provider_id' => $providerId,
            'variants' => $variantPayload,
            'print_areas' => [[
                'variant_ids' => $variantIds,
                'placeholders' => $placeholders,
            ]],
        ]);

        $this->printify->publishProduct((string) $created['id']);

        return ['printify_product_id' => (string) $created['id'], 'price_check' => $priceCheck];
    }
}
