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
            'name_suffix' => $preset['name_suffix'] ?? $preset['label'],
            'description' => $preset['description'] ?? '',
            'supplier_code' => $preset['supplier_code'] ?? '',
            'no_individualisierung' => ! empty($preset['no_individualisierung']),
            'enabled' => $enabled,
            'base_price' => $preset['base_price'],
            'indiv_surcharge' => ! empty($preset['no_individualisierung']) ? 0.0 : (float) config('schoolshop.indiv_surcharge'),
            'sizes' => $preset['sizes'],
            'colors' => $colors,
            // Nur für On-Demand relevant (IDs via: php artisan printify:check oder Suche im Konfigurator)
            'printify_blueprint_id' => $preset['printify_blueprint_id'] ?? null,
            'printify_provider_id' => $preset['printify_provider_id'] ?? null,
        ];
    }

    /**
     * Katalog-Metadaten eines Produkts für die Shop-Anlage (Name, Beschreibung
     * usw.). Bevorzugt die im products-JSON gespeicherten Werte — nötig für
     * im Konfigurator manuell hinzugefügte Produkte, die keinen Eintrag in
     * config('schoolshop.catalog') haben — und fällt sonst auf den
     * Katalog-Default zurück (Altbestand vor diesem Feature).
     *
     * @param  array<string, mixed>  $product
     * @return array{label: string, name_suffix: string, description: string, supplier_code: string, no_individualisierung: bool, default_size: ?string}
     */
    public static function preset(array $product): array
    {
        $catalog = config("schoolshop.catalog.{$product['key']}", []);
        $label = $product['label'] ?? $catalog['label'] ?? $product['key'];

        return [
            'label' => $label,
            'name_suffix' => $product['name_suffix'] ?? $catalog['name_suffix'] ?? $label,
            'description' => $product['description'] ?? $catalog['description'] ?? '',
            'supplier_code' => $product['supplier_code'] ?? $catalog['supplier_code'] ?? '',
            'no_individualisierung' => $product['no_individualisierung'] ?? ! empty($catalog['no_individualisierung']),
            'default_size' => $catalog['default_size'] ?? null,
        ];
    }

    /**
     * Übernimmt Formulareingaben des Konfigurators in den products-Zustand —
     * inklusive im Konfigurator neu hinzugefügter Produkte (Feld "new").
     *
     * @param  array<int, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $input  key => Felder
     */
    public static function applyInput(array $current, array $input): array
    {
        $existingKeys = [];
        foreach ($current as $i => $product) {
            $key = $product['key'] ?? null;
            if ($key !== null) {
                $existingKeys[$key] = true;
            }
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

        foreach ($input as $rawKey => $fields) {
            if (empty($fields['new']) || ! is_array($fields)) {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_\-]/', '', mb_strtolower((string) $rawKey));
            if ($key === '' || isset($existingKeys[$key])) {
                continue;
            }
            $existingKeys[$key] = true;
            $label = trim((string) ($fields['label'] ?? '')) ?: 'Neues Produkt';
            $current[] = [
                'key' => $key,
                'label' => $label,
                'name_suffix' => $label,
                'description' => '',
                'supplier_code' => '',
                'no_individualisierung' => false,
                'enabled' => ! empty($fields['enabled']),
                'base_price' => is_numeric(str_replace(',', '.', (string) ($fields['base_price'] ?? ''))) ? round((float) str_replace(',', '.', (string) $fields['base_price']), 2) : 0.0,
                'indiv_surcharge' => is_numeric(str_replace(',', '.', (string) ($fields['indiv_surcharge'] ?? ''))) ? round((float) str_replace(',', '.', (string) $fields['indiv_surcharge']), 2) : 0.0,
                'sizes' => array_values(array_filter(array_map('trim', explode(',', (string) ($fields['sizes'] ?? ''))))),
                'colors' => array_values(array_filter(array_map('trim', explode(',', (string) ($fields['colors'] ?? ''))))),
                'printify_blueprint_id' => isset($fields['printify_blueprint_id']) && ctype_digit(trim((string) $fields['printify_blueprint_id'])) ? (int) $fields['printify_blueprint_id'] : null,
                'printify_provider_id' => isset($fields['printify_provider_id']) && ctype_digit(trim((string) $fields['printify_provider_id'])) ? (int) $fields['printify_provider_id'] : null,
            ];
        }

        return $current;
    }
}
