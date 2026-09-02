<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;
use Illuminate\Support\Facades\Cache;

/**
 * On-Demand-Weg: legt Printify-Produkte an (Blueprint + Print Provider je
 * Produkt), prüft die Mindestmarge und published in den WooCommerce-Shop.
 *
 * Preisregel (Vorgabe): Verkaufspreis >= (Produktionskosten + Versand) * (1 + Marge).
 *
 * Angelegt werden ausschließlich Varianten in den im Konfigurator gewählten
 * Farben und Größen — sonst läuft ein Produkt in das Printify-Limit von 100
 * Varianten und bekommt Vorschaubilder in Farben, die die Schule nie bestellt.
 */
class PrintifyProvisioner
{
    /** ISO-3166-1-alpha-2-Codes der EU (für die Versand-Region-Anzeige im Konfigurator). */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE',
    ];

    public function __construct(private readonly PrintifyClient $printify) {}

    /**
     * Katalogdaten eines Blueprint/Provider-Paares (Provider-Stammdaten,
     * Varianten, Versandprofil) — 24h gecacht, damit der Konfigurator nicht
     * bei jedem Aufruf auf Printify wartet. Der Katalog ändert sich praktisch nie.
     *
     * @return array{provider: array, variants: list<array>, shipping: ?array}
     */
    private function catalog(int $blueprintId, int $providerId, bool $fresh = false): array
    {
        $key = "printify.catalog.{$blueprintId}.{$providerId}";
        if ($fresh) {
            // Vor dem Anlegen frisch holen: Eine Preiserhöhung des Providers
            // bliebe sonst einen Tag unsichtbar, und die Margenprüfung liefe
            // mit veralteten Kosten.
            Cache::forget($key);
        }

        return Cache::remember(
            $key,
            now()->addDay(),
            fn () => [
                'provider' => $this->printify->providerDetails($providerId),
                'variants' => $this->printify->variants($blueprintId, $providerId),
                'shipping' => $this->printify->shippingProfile($blueprintId, $providerId),
            ],
        );
    }

    /**
     * Kennzahlen eines On-Demand-Produkts für die Anzeige im Konfigurator:
     * Einkaufspreis (Produktionskosten), Versand, Marge und welche der
     * gewünschten Farben/Größen es bei diesem Provider überhaupt gibt.
     *
     * @param  array<string, mixed>  $product  Eintrag aus dem products-JSON
     * @return ?array<string, mixed>  null, wenn Blueprint/Provider fehlen
     */
    public function economics(array $product): ?array
    {
        $blueprintId = (int) ($product['printify_blueprint_id'] ?? 0);
        $providerId = (int) ($product['printify_provider_id'] ?? 0);
        if ($blueprintId === 0 || $providerId === 0) {
            return null;
        }

        $catalog = $this->catalog($blueprintId, $providerId);
        $selection = $this->selectVariants($catalog['variants'], $product);

        // Gerechnet wird ausschließlich mit den Varianten, die auch angelegt
        // werden. Passt keine, gibt es keine Marge anzuzeigen — der frühere
        // Rückgriff auf den gesamten Katalog zeigte Zahlen, die für dieses
        // Produkt nie gelten (die Anlage bricht in dem Fall ohnehin ab).
        $costs = array_values(array_filter(
            array_map(fn ($v) => (int) ($v['cost'] ?? 0), $selection['variants']),
            fn ($c) => $c > 0,
        ));

        $country = $catalog['provider']['location']['country'] ?? null;
        $shipping = $catalog['shipping'];
        $shippingCents = $shipping['cost_cents'] ?? null;
        $maxCostCents = $costs === [] ? null : max($costs);

        // Der Verkaufspreis im Shop ist BRUTTO, die Printify-Kosten sind NETTO.
        // Verglichen wird deshalb netto gegen netto; angezeigt wird der
        // Mindestpreis brutto, weil im Konfigurator ein Bruttopreis steht.
        $vat = (float) config('schoolshop.printify.vat_rate');
        $salePrice = (float) ($product['base_price'] ?? 0);
        $netSaleCents = (int) round($salePrice * 100 / (1 + $vat));
        $baseCents = ($maxCostCents ?? 0) + ($shippingCents ?? 0);
        $minMargin = (float) config('schoolshop.printify.min_margin');
        $minNetCents = (int) ceil($baseCents * (1 + $minMargin));

        return [
            'provider_title' => $catalog['provider']['title'] ?? '?',
            'country' => $country,
            'is_eu' => $country !== null && in_array($country, self::EU_COUNTRIES, true),

            'shipping_eur' => $shippingCents !== null ? $shippingCents / 100 : null,
            'shipping_countries' => $shipping['countries'] ?? [],
            'shipping_is_row' => (bool) ($shipping['is_rest_of_world'] ?? false),
            'shipping_is_fallback' => (bool) ($shipping['is_fallback'] ?? false),

            'cost_min_eur' => $costs === [] ? null : min($costs) / 100,
            'cost_max_eur' => $maxCostCents !== null ? $maxCostCents / 100 : null,

            'variant_total' => count($catalog['variants']),
            'variant_selected' => count($selection['variants']),
            'missing_colors' => $selection['missing_colors'],
            'missing_sizes' => $selection['missing_sizes'],
            'available_colors' => $selection['available_colors'],
            'capped' => $selection['capped'],

            // Mindest-VERKAUFSPREIS brutto — direkt vergleichbar mit dem Wert
            // im Konfigurator.
            'min_price_eur' => $baseCents > 0 ? ceil($minNetCents * (1 + $vat)) / 100 : null,
            'margin_pct' => $baseCents > 0 ? ($netSaleCents - $baseCents) / $baseCents * 100 : null,
            'margin_ok' => $baseCents > 0 && $netSaleCents >= $minNetCents,
            'net_price_eur' => $netSaleCents / 100,
            'vat_rate' => $vat,
        ];
    }

    /**
     * Wählt aus dem Variantenkatalog die Varianten in den gewünschten Farben
     * und Größen aus.
     *
     * Bietet der Blueprint eine Dimension gar nicht an (z. B. keine Größen bei
     * einer Tasche), wird danach auch nicht gefiltert. Findet sich zu keiner
     * gewünschten Farbe/Größe ein Treffer, bleibt die Auswahl leer — der
     * Aufrufer bricht dann mit einer Meldung ab, die die verfügbaren Werte nennt.
     *
     * @param  list<array<string, mixed>>  $allVariants
     * @param  array<string, mixed>  $product
     * @return array{variants: list<array<string, mixed>>, missing_colors: list<string>, missing_sizes: list<string>, available_colors: list<string>, available_sizes: list<string>, capped: bool}
     */
    public function selectVariants(array $allVariants, array $product): array
    {
        $rows = array_map(fn ($variant) => ['variant' => $variant] + $this->variantOptions($variant), $allVariants);

        $available = fn (string $dimension) => array_values(array_unique(array_filter(
            array_column($rows, $dimension),
            fn ($v) => $v !== null && $v !== '',
        )));
        $availableColors = $available('color');
        $availableSizes = $available('size');

        $colorMatch = $this->matchValues($product['colors'] ?? [], $availableColors, config('schoolshop.printify.color_aliases', []));
        $sizeMatch = $this->matchValues($product['sizes'] ?? [], $availableSizes, config('schoolshop.printify.size_aliases', []));

        $selected = array_values(array_filter($rows, function (array $row) use ($colorMatch, $sizeMatch) {
            foreach ([['color', $colorMatch], ['size', $sizeMatch]] as [$dimension, $match]) {
                if ($match['filter'] === null) {
                    continue; // Dimension nicht gefiltert (Blueprint hat sie nicht / kein Wunsch hinterlegt)
                }
                if (! in_array($this->normalize($row[$dimension] ?? ''), $match['filter'], true)) {
                    return false;
                }
            }

            return true;
        }));

        // Letzte Sicherung gegen das Printify-Limit von 100 Varianten pro
        // Produkt. Gekürzt wird REIHUM über die Farben statt stur am Ende
        // abzuschneiden: Sonst fiele eine gewünschte Farbe, die zufällig hinten
        // im Katalog steht, vollständig heraus — und zwar unbemerkt, weil sie
        // ja gefunden wurde und deshalb nicht als „fehlend" gilt.
        $max = (int) config('schoolshop.printify.max_variants', 100);
        $capped = count($selected) > $max;
        $droppedColors = [];
        if ($capped) {
            [$selected, $droppedColors] = $this->capEvenly($selected, $max);
        }

        return [
            'variants' => array_column($selected, 'variant'),
            'missing_colors' => $colorMatch['missing'],
            'missing_sizes' => $sizeMatch['missing'],
            'available_colors' => $availableColors,
            'available_sizes' => $availableSizes,
            'capped' => $capped,
            'dropped_colors' => $droppedColors,
        ];
    }

    /**
     * Kürzt die Auswahl auf `$max`, indem reihum je Farbe eine Variante
     * genommen wird. So bleibt jede Farbe vertreten, solange es überhaupt
     * Plätze gibt; erst wenn es mehr Farben als Plätze gibt, fallen welche
     * heraus — und die werden dann ausdrücklich benannt.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function capEvenly(array $rows, int $max): array
    {
        $byColor = [];
        foreach ($rows as $row) {
            $byColor[(string) ($row['color'] ?? '')][] = $row;
        }

        $kept = [];
        while (count($kept) < $max && $byColor !== []) {
            foreach ($byColor as $color => $group) {
                if (count($kept) >= $max) {
                    break;
                }
                $kept[] = array_shift($byColor[$color]);
                if ($byColor[$color] === []) {
                    unset($byColor[$color]);
                }
            }
        }

        $dropped = [];
        foreach ($rows as $row) {
            $color = (string) ($row['color'] ?? '');
            if ($color !== '' && ! in_array($color, array_column($kept, 'color'), true) && ! in_array($color, $dropped, true)) {
                $dropped[] = $color;
            }
        }

        return [array_values($kept), $dropped];
    }

    /**
     * Farbe/Größe einer Printify-Variante. Bevorzugt das options-Objekt,
     * fällt sonst auf den Titel ("Black / S") zurück.
     *
     * @param  array<string, mixed>  $variant
     * @return array{color: ?string, size: ?string}
     */
    private function variantOptions(array $variant): array
    {
        $options = is_array($variant['options'] ?? null) ? $variant['options'] : [];
        $fromOptions = function (array $names) use ($options): ?string {
            foreach ($options as $key => $value) {
                if (is_scalar($value) && in_array(mb_strtolower((string) $key), $names, true)) {
                    return trim((string) $value) ?: null;
                }
            }

            return null;
        };

        $color = $fromOptions(['color', 'colors', 'colour']);
        $size = $fromOptions(['size', 'sizes']);

        if ($color === null || $size === null) {
            // Rückfall auf den Titel („Black / S"). Die Reihenfolge ist nicht
            // garantiert, deshalb wird der Größenteil an seiner Form erkannt
            // (S, XL, 2XL, 128, 38/40) statt stur das letzte Stück zu nehmen —
            // sonst würde bei „S / Black" die Farbe als Größe gelesen.
            $parts = array_values(array_filter(array_map('trim', explode('/', (string) ($variant['title'] ?? '')))));
            if (count($parts) > 1) {
                $looksLikeSize = static fn (string $p) => (bool) preg_match('/^(\d{2,3}|[0-9]?X{0,3}[SML]|One Size|Einheitsgröße)$/i', $p);
                $sizeParts = array_values(array_filter($parts, $looksLikeSize));
                $colorParts = array_values(array_filter($parts, static fn ($p) => ! $looksLikeSize($p)));
                $size ??= $sizeParts[0] ?? end($parts);
                $color ??= $colorParts[0] ?? $parts[0];
            } elseif ($parts !== []) {
                // Nur ein Teil: das ist die Farbe, nicht die Größe.
                $color ??= $parts[0];
            }
        }

        return ['color' => $color, 'size' => $size];
    }

    /**
     * Ordnet deutsche Konfigurator-Werte den englischen Katalogwerten zu:
     * erst exakt, sonst als Teilstring ("Black" trifft dann auch "Black Heather").
     *
     * @param  list<string>  $wanted
     * @param  list<string>  $availableValues
     * @param  array<string, list<string>>  $aliases
     * @return array{filter: ?list<string>, missing: list<string>}
     */
    private function matchValues(array $wanted, array $availableValues, array $aliases): array
    {
        $wanted = array_values(array_filter(array_map('trim', $wanted), fn ($v) => $v !== ''));
        if ($wanted === [] || $availableValues === []) {
            return ['filter' => null, 'missing' => []];
        }

        $filter = [];
        $missing = [];
        foreach ($wanted as $want) {
            $needle = $this->normalize($want);
            $candidates = array_unique([$needle, ...array_map(fn ($a) => $this->normalize($a), $aliases[$needle] ?? [])]);

            $hits = array_filter($availableValues, fn ($value) => in_array($this->normalize($value), $candidates, true));
            if ($hits === []) {
                $hits = array_filter($availableValues, function ($value) use ($candidates) {
                    foreach ($candidates as $candidate) {
                        if ($candidate !== '' && str_contains($this->normalize($value), $candidate)) {
                            return true;
                        }
                    }

                    return false;
                });
            }

            if ($hits === []) {
                $missing[] = $want;

                continue;
            }
            foreach ($hits as $hit) {
                $filter[$this->normalize($hit)] = true;
            }
        }

        // Keine einzige gewünschte Farbe/Größe gefunden: leerer Filter, damit der
        // Aufrufer den Fall erkennt (statt still alle Varianten anzulegen).
        return ['filter' => array_keys($filter), 'missing' => $missing];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * Mindest-Verkaufspreis in Cent für einen Blueprint/Provider
     * (teuerste angelegte Variante + Versand erster Artikel, plus Marge).
     *
     * @param  array<string, mixed>  $product  optional: begrenzt auf die gewählten Farben/Größen
     * @return array{min_price_cents: int, max_variant_cost_cents: int, shipping_cents: int}
     */
    public function minimumPrice(int $blueprintId, int $providerId, array $product = []): array
    {
        $catalog = $this->catalog($blueprintId, $providerId);
        $variants = $catalog['variants'];
        if ($product !== []) {
            $selected = $this->selectVariants($variants, $product)['variants'];
            if ($selected !== []) {
                $variants = $selected;
            }
        }

        $maxCost = 0;
        foreach ($variants as $variant) {
            $maxCost = max($maxCost, (int) ($variant['cost'] ?? 0));
        }
        $shipping = $catalog['shipping']['cost_cents'] ?? 0;
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
     * @param  array<string, mixed>  $product
     * @return array{ok: bool, message: string, min_price_cents: int}
     */
    public function checkPrice(float $salePriceEur, int $blueprintId, int $providerId, array $product = []): array
    {
        $minimum = $this->minimumPrice($blueprintId, $providerId, $product);
        // Der Verkaufspreis im Shop ist brutto, die Printify-Kosten sind netto.
        $vat = (float) config('schoolshop.printify.vat_rate');
        $netCents = (int) round($salePriceEur * 100 / (1 + $vat));
        $minGrossCents = (int) ceil($minimum['min_price_cents'] * (1 + $vat));
        $ok = $netCents >= $minimum['min_price_cents'];

        return [
            'ok' => $ok,
            'min_price_cents' => $minimum['min_price_cents'],
            'min_price_gross_cents' => $minGrossCents,
            'message' => sprintf(
                'Produktionskosten max. %.2f EUR + Versand %.2f EUR (netto), Mindestpreis inkl. %d%% Marge: %.2f EUR netto = %.2f EUR brutto — '
                .'Verkaufspreis %.2f EUR brutto (= %.2f EUR netto bei %d%% USt.) %s',
                $minimum['max_variant_cost_cents'] / 100,
                $minimum['shipping_cents'] / 100,
                (int) round(config('schoolshop.printify.min_margin') * 100),
                $minimum['min_price_cents'] / 100,
                $minGrossCents / 100,
                $salePriceEur,
                $netCents / 100,
                (int) round($vat * 100),
                $ok ? 'OK' : 'ZU NIEDRIG',
            ),
        ];
    }

    /**
     * Legt ein Printify-Produkt an und published es in den Shop.
     * Bricht ab, wenn der Preis die Mindestmarge verletzt oder keine der
     * gewünschten Farben/Größen im Katalog vorkommt.
     *
     * @param  array{key: string, base_price: float, colors: list<string>, sizes: list<string>}  $product
     * @return array{printify_product_id: string, price_check: array, notes: list<string>}
     */
    public function createAndPublish(
        SchoolOnboarding $onboarding,
        array $product,
        int $blueprintId,
        int $providerId,
    ): array {
        $preset = ProductConfigurator::preset($product);
        $catalog = $this->catalog($blueprintId, $providerId, fresh: true);
        $selection = $this->selectVariants($catalog['variants'], $product);

        if ($selection['variants'] === []) {
            throw new \RuntimeException(sprintf(
                'Keine passende Printify-Variante für "%s": gewünschte Farben (%s) bzw. Größen (%s) gibt es bei diesem Print-Provider nicht. '
                .'Verfügbare Farben: %s. Verfügbare Größen: %s. Bitte im Konfigurator anpassen oder einen anderen Provider wählen.',
                $preset['label'],
                implode(', ', $product['colors'] ?? []) ?: '—',
                implode(', ', $product['sizes'] ?? []) ?: '—',
                implode(', ', $selection['available_colors']) ?: '—',
                implode(', ', $selection['available_sizes']) ?: '—',
            ));
        }

        $priceCheck = $this->checkPrice((float) $product['base_price'], $blueprintId, $providerId, $product);
        if (! $priceCheck['ok']) {
            throw new \RuntimeException('Preisprüfung fehlgeschlagen: '.$priceCheck['message']);
        }

        $priceCents = (int) round((float) $product['base_price'] * 100);
        $variantPayload = [];
        $variantIds = [];
        foreach ($selection['variants'] as $variant) {
            $variantPayload[] = ['id' => (int) $variant['id'], 'price' => $priceCents, 'is_enabled' => true];
            $variantIds[] = (int) $variant['id'];
        }

        // Ein Placeholder je aktivem Druck, mit der im Konfigurator gewählten
        // Position/Größe (x/y = Mittelpunkt, scale = Breitenanteil).
        $placeholders = [];
        $notes = [];
        foreach ($onboarding->activePrintSlots() as $slot) {
            $logoUrl = $onboarding->logoUrl($slot);
            if ($logoUrl === null) {
                $notes[] = SchoolOnboarding::PRINT_SLOTS[$slot].': kein Logo hinterlegt — Druck übersprungen.';

                continue;
            }
            $placement = $onboarding->logoPlacement($slot);
            $image = $this->printify->uploadImageFromUrl(
                basename(parse_url($logoUrl, PHP_URL_PATH) ?: $slot.'.png'),
                $logoUrl,
            );
            $placeholders[] = [
                'position' => $slot === 'back' ? 'back' : 'front',
                'images' => [[
                    'id' => $image['id'],
                    'x' => $placement['x'],
                    'y' => $placement['y'],
                    'scale' => $placement['width'],
                    'angle' => 0,
                ]],
            ];
            $notes[] = SchoolOnboarding::PRINT_SLOTS[$slot].': '.$onboarding->logoPlacementLabel($slot);
        }
        if ($placeholders === []) {
            throw new \RuntimeException(
                'Kein druckbares Logo vorhanden. Bitte im Bereich „Schullogo & Druck" ein Logo hochladen (oder den Druck deaktivieren).',
            );
        }

        $notes[] = sprintf('%d von %d Katalog-Varianten angelegt', count($variantIds), count($catalog['variants']));
        foreach ([['Farben', $selection['missing_colors']], ['Größen', $selection['missing_sizes']]] as [$label, $missing]) {
            if ($missing !== []) {
                $notes[] = "Nicht im Printify-Katalog und daher ausgelassen ({$label}): ".implode(', ', $missing);
            }
        }
        if ($selection['capped']) {
            $notes[] = 'Achtung: Printify erlaubt max. '.config('schoolshop.printify.max_variants')
                .' Varianten pro Produkt — die Auswahl wurde gekürzt (reihum je Farbe, damit keine Farbe ganz wegfällt). '
                .'Bitte Farben/Größen im Konfigurator eingrenzen.';
            if (($selection['dropped_colors'] ?? []) !== []) {
                $notes[] = 'Trotz Kürzung ganz entfallen: '.implode(', ', $selection['dropped_colors'])
                    .' — es gibt mehr Farben als Variantenplätze.';
            }
        }

        $description = $preset['printify_description'] ?? $preset['description'];
        $created = $this->printify->createProduct([
            'title' => $onboarding->school_name.' '.$preset['name_suffix'],
            'description' => strip_tags($description),
            'blueprint_id' => $blueprintId,
            'print_provider_id' => $providerId,
            'variants' => $variantPayload,
            'print_areas' => [[
                'variant_ids' => $variantIds,
                'placeholders' => $placeholders,
            ]],
        ]);

        return [
            'printify_product_id' => (string) $created['id'],
            'price_check' => $priceCheck,
            'notes' => $notes,
        ];
    }

    /**
     * Veröffentlicht ein bereits angelegtes Printify-Produkt im Shop.
     *
     * Bewusst getrennt vom Anlegen: Scheitert das Veröffentlichen, ist das
     * Produkt bei Printify trotzdem vorhanden und seine ID im Tool vermerkt.
     * Der nächste Versuch veröffentlicht nur noch — statt ein zweites Produkt
     * anzulegen, das später ebenfalls im Shop erscheinen würde.
     */
    public function publish(string $printifyProductId): string
    {
        $this->printify->publishProduct($printifyProductId);

        return "Produkt {$printifyProductId} veröffentlicht";
    }
}
