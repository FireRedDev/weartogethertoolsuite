<?php

namespace App\Services\SchoolShop;

/**
 * Baut und normalisiert den Konfigurator-Zustand (products-JSON eines
 * Onboardings): je Katalogprodukt aktiviert/deaktiviert, Preis, Aufpreis,
 * Größen und Farben — vorbefüllt aus Formularwünschen, frei anpassbar.
 */
class ProductConfigurator
{
    /**
     * Startzustand aus den Formular-Produktwünschen.
     *
     * @param  list<string>  $formProducts  Produktnamen aus dem Formular
     * @param  list<string>  $formColors  Farbwünsche aus dem Formular
     */
    public static function defaultsFromFormProducts(array $formProducts, array $formColors): array
    {
        $map = config('schoolshop.form_product_map');
        $requestedKeys = [];
        $unmapped = [];
        foreach ($formProducts as $formProduct) {
            $key = $map[trim($formProduct)] ?? null;
            if ($key === null) {
                // tolerant: "Hoodie " mit Leerzeichen etc.
                foreach ($map as $label => $mapped) {
                    if (trim($label) === trim($formProduct)) {
                        $key = $mapped;
                        break;
                    }
                }
            }
            if ($key !== null) {
                $requestedKeys[] = $key;
            } else {
                $unmapped[] = $formProduct;
            }
        }

        $colors = array_values(array_filter(array_map(
            fn ($c) => mb_strtolower(trim(preg_replace('/\s*\(.*\)$/', '', $c))),
            $formColors,
        )));
        if ($colors === []) {
            $colors = config('schoolshop.default_colors');
        }

        $products = [];
        foreach (config('schoolshop.catalog') as $key => $preset) {
            $products[] = self::productDefaults($key, $preset, in_array($key, $requestedKeys, true), $colors);
        }

        if ($unmapped !== []) {
            // Nicht zuordenbare Wünsche sichtbar machen (z. B. Gymbag, Haube)
            $products[] = [
                'key' => '_unmapped',
                'label' => 'Nicht zugeordnete Wünsche: '.implode(', ', $unmapped),
                'enabled' => false,
                'unmapped' => true,
            ];
        }

        return $products;
    }

    public static function defaultsAllDisabled(): array
    {
        $products = [];
        foreach (config('schoolshop.catalog') as $key => $preset) {
            $products[] = self::productDefaults($key, $preset, false, config('schoolshop.default_colors'));
        }

        return $products;
    }

    private static function productDefaults(string $key, array $preset, bool $enabled, array $colors): array
    {
        return [
            'key' => $key,
            'label' => $preset['label'],
            'enabled' => $enabled,
            'base_price' => $preset['base_price'],
            'indiv_surcharge' => ! empty($preset['no_individualisierung']) ? 0.0 : (float) config('schoolshop.indiv_surcharge'),
            'sizes' => $preset['sizes'],
            'colors' => $colors,
            // Nur für On-Demand relevant (IDs via: php artisan printify:check)
            'printify_blueprint_id' => null,
            'printify_provider_id' => null,
        ];
    }

    /**
     * Übernimmt Formulareingaben des Konfigurators in den products-Zustand.
     *
     * @param  array<int, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $input  key => Felder
     */
    public static function applyInput(array $current, array $input): array
    {
        foreach ($current as $i => $product) {
            $key = $product['key'] ?? null;
            if ($key === null || ! empty($product['unmapped'])) {
                continue;
            }
            $fields = $input[$key] ?? [];
            $current[$i]['enabled'] = ! empty($fields['enabled']);
            if (isset($fields['base_price']) && is_numeric(str_replace(',', '.', (string) $fields['base_price']))) {
                $current[$i]['base_price'] = round((float) str_replace(',', '.', (string) $fields['base_price']), 2);
            }
            if (isset($fields['indiv_surcharge']) && is_numeric(str_replace(',', '.', (string) $fields['indiv_surcharge']))) {
                $current[$i]['indiv_surcharge'] = round((float) str_replace(',', '.', (string) $fields['indiv_surcharge']), 2);
            }
            foreach (['sizes', 'colors'] as $listField) {
                if (isset($fields[$listField])) {
                    $current[$i][$listField] = array_values(array_filter(array_map('trim', explode(',', (string) $fields[$listField]))));
                }
            }
            foreach (['printify_blueprint_id', 'printify_provider_id'] as $idField) {
                if (array_key_exists($idField, $fields)) {
                    $value = trim((string) $fields[$idField]);
                    $current[$i][$idField] = ctype_digit($value) && $value !== '' ? (int) $value : null;
                }
            }
        }

        return $current;
    }
}
