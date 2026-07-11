<?php

namespace App\Services;

/**
 * Baut aus WooCommerce-API-Bestellungen exakt die Tabelle, die bisher das
 * Plugin "Advanced Order Export For WooCommerce" erzeugt hat (eine Zeile pro
 * Bestellposition/Stückgruppe, Spalten und Formate identisch zum bisherigen
 * XLSX-Export, Bestellungen nach Order-ID absteigend).
 */
class ShopOrderFetcher
{
    /** Spaltenreihenfolge exakt wie der Plugin-Export. */
    public const COLUMNS = [
        'Item Name(löschen)',
        'Karton',
        'Vorname',
        'Nachnahme (Rechnungsadresse)',
        'Anzahl',
        'Größe',
        'Farbe',
        'Klasse',
        'Individualisierung',
        'Input Fields',
        'Individualisierungstext(zählt nur wenn Individualisierung Ja)',
        'Product Variation',
        'Bestellnotiz',
        'Bestellung Gesamtsumme(löschen)',
    ];

    public function __construct(private readonly WooCommerceClient $client) {}

    /**
     * @param  list<string>  $statuses
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, orderCount: int}
     */
    public function fetch(?int $categoryId, array $statuses, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $categoryProductIds = null;
        if ($categoryId !== null) {
            $productIds = $this->client->productIdsInCategory($categoryId);
            $categoryProductIds = array_flip($productIds);
            // Serverseitig pro Produkt filtern statt den kompletten
            // Bestellbestand zu laden (bei >10.000 Bestellungen sonst
            // Gateway-Timeout). Bestellungen mit mehreren Produkten der
            // Kategorie werden über die Order-ID dedupliziert.
            $byId = [];
            foreach ($productIds as $productId) {
                foreach ($this->client->ordersForProduct($productId, $statuses, $dateFrom, $dateTo) as $order) {
                    $byId[(int) $order['id']] = $order;
                }
            }
            krsort($byId); // Order-ID absteigend, wie der Plugin-Export
            $orders = array_values($byId);
        } else {
            $orders = $this->client->orders($statuses, $dateFrom, $dateTo);
        }

        $rows = [];
        $ordersWithRows = 0;
        foreach ($orders as $order) {
            $orderRows = 0;
            foreach ($order['line_items'] ?? [] as $item) {
                if ($categoryProductIds !== null && ! isset($categoryProductIds[(int) ($item['product_id'] ?? 0)])) {
                    continue;
                }
                $rows[] = $this->buildRow($order, $item);
                $orderRows++;
            }
            if ($orderRows > 0) {
                $ordersWithRows++;
            }
        }

        return ['columns' => self::COLUMNS, 'rows' => $rows, 'orderCount' => $ordersWithRows];
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function buildRow(array $order, array $item): array
    {
        $config = config('ordersuite.woocommerce');
        $visibleMetas = $this->visibleMetas($item);
        $inputFieldNeedle = (string) $config['input_fields_label_contains'];

        // Attributspalten ([P] pa_size, pa_color, klasse, pa_individualisierung)
        $attributes = [];
        foreach ($config['attribute_meta_keys'] as $column => $candidates) {
            $attributes[$column] = null;
            foreach ($visibleMetas as $meta) {
                if (in_array($meta['key'], $candidates, true) || in_array($meta['display_key'], $candidates, true)) {
                    $attributes[$column] = $meta['display_value'];
                    break;
                }
            }
        }

        // "Input Fields" wie das Plugin: "\n{Label}: {Wert}" je Eingabefeld
        $inputFields = '';
        foreach ($visibleMetas as $meta) {
            if ($inputFieldNeedle !== '' && str_contains($meta['display_key'], $inputFieldNeedle)) {
                $inputFields .= "\n".$meta['display_key'].': '.$meta['display_value'];
            }
        }

        // "Product Variation": sichtbare Metas außer Eingabefeldern, "Label: Wert | ..."
        $variationParts = [];
        foreach ($visibleMetas as $meta) {
            if ($inputFieldNeedle !== '' && str_contains($meta['display_key'], $inputFieldNeedle)) {
                continue;
            }
            $variationParts[] = $meta['display_key'].': '.$meta['display_value'];
        }

        // Bestell-Meta für die Individualisierungstext-Spalte (_additional_wooccm4)
        $orderIndivText = null;
        foreach ($order['meta_data'] ?? [] as $meta) {
            if (($meta['key'] ?? '') === $config['order_meta_indiv_text']) {
                $value = $meta['value'] ?? null;
                $orderIndivText = is_scalar($value) ? (string) $value : null;
                break;
            }
        }

        return [
            'Item Name(löschen)' => $this->productName($item),
            'Karton' => 'x', // statisches Feld wie im Plugin konfiguriert
            'Vorname' => self::emptyToNull($order['billing']['first_name'] ?? null),
            'Nachnahme (Rechnungsadresse)' => self::emptyToNull($order['billing']['last_name'] ?? null),
            'Anzahl' => (int) ($item['quantity'] ?? 0),
            'Größe' => self::emptyToNull($attributes['Größe']),
            'Farbe' => self::emptyToNull($attributes['Farbe']),
            'Klasse' => self::emptyToNull($attributes['Klasse']),
            'Individualisierung' => self::emptyToNull($attributes['Individualisierung']),
            'Input Fields' => self::emptyToNull($inputFields),
            'Individualisierungstext(zählt nur wenn Individualisierung Ja)' => self::emptyToNull($orderIndivText),
            'Product Variation' => self::emptyToNull(implode(' | ', $variationParts)),
            'Bestellnotiz' => self::emptyToNull($order['customer_note'] ?? null),
            'Bestellung Gesamtsumme(löschen)' => (float) ($order['total'] ?? 0),
        ];
    }

    /**
     * Sichtbare Positions-Metas (keys ohne führenden Unterstrich) mit
     * String-Werten, in API-Reihenfolge.
     *
     * @param  array<string, mixed>  $item
     * @return list<array{key: string, display_key: string, display_value: string}>
     */
    private function visibleMetas(array $item): array
    {
        $metas = [];
        foreach ($item['meta_data'] ?? [] as $meta) {
            $key = (string) ($meta['key'] ?? '');
            if ($key === '' || str_starts_with($key, '_')) {
                continue;
            }
            $displayValue = $meta['display_value'] ?? $meta['value'] ?? '';
            if (! is_scalar($displayValue)) {
                continue;
            }
            $metas[] = [
                'key' => $key,
                'display_key' => trim((string) ($meta['display_key'] ?? $key)) === '' ? $key : (string) ($meta['display_key'] ?? $key),
                'display_value' => html_entity_decode(strip_tags((string) $displayValue), ENT_QUOTES | ENT_HTML5),
            ];
        }

        return $metas;
    }

    /** Hauptproduktname wie "[P] Product Name (main)" im Plugin. */
    private function productName(array $item): ?string
    {
        $parent = $item['parent_name'] ?? null;
        if (is_string($parent) && $parent !== '') {
            return $parent;
        }
        $name = $item['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private static function emptyToNull(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }
}
