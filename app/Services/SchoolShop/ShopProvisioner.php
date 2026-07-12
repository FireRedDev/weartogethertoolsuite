<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;

/**
 * Legt für ein Onboarding alles im Shop an: Produktkategorie, variable
 * Produkte mit Variationen (Individualisierung Ja/Nein) und PIF-Feldern,
 * Versandklasse (On-Demand) sowie den Pods-CPT-Eintrag "schule".
 *
 * plan() liefert eine Vorschau (Dry-Run), apply() führt aus und protokolliert
 * jeden Schritt — Fehler brechen ab, bereits erledigte Schritte bleiben
 * erhalten und werden bei erneutem Ausführen übersprungen (idempotent über
 * gespeicherte IDs).
 */
class ShopProvisioner
{
    public function __construct(
        private readonly WooCommerceWriteClient $woo,
        private readonly WordPressClient $wordpress,
    ) {}

    /** @return list<string> Menschlich lesbare Schritte (Dry-Run). */
    public function plan(SchoolOnboarding $onboarding): array
    {
        $steps = [];
        $parent = config('schoolshop.parent_category_name');
        $steps[] = $onboarding->woo_category_id
            ? "Produktkategorie vorhanden (ID {$onboarding->woo_category_id}) - wird wiederverwendet"
            : "Produktkategorie '{$parent} > {$onboarding->school_name}' anlegen";

        $existing = $onboarding->woo_product_ids ?? [];
        foreach ($onboarding->enabledProducts() as $product) {
            $name = $onboarding->school_name.' '.config("schoolshop.catalog.{$product['key']}.name_suffix");
            $indiv = ($product['indiv_surcharge'] ?? 0) > 0
                ? sprintf(' + Variante Individualisierung Ja (%.2f EUR)', $product['base_price'] + $product['indiv_surcharge'])
                : '';
            $steps[] = isset($existing[$product['key']])
                ? "Produkt '{$name}' bereits angelegt (ID {$existing[$product['key']]}) - wird übersprungen"
                : sprintf("Produkt '%s' anlegen: %.2f EUR%s | Größen: %s | Farben: %s", $name, $product['base_price'], $indiv, implode('/', $product['sizes']), implode('/', $product['colors']));
        }

        if ($onboarding->delivery_type === 'ondemand') {
            $steps[] = "Versandklasse '".config('schoolshop.shipping_class_ondemand')."' wird jedem Produkt zugewiesen";
        } else {
            $steps[] = 'Sammelbestellfenster: Produkte ohne Versandklasse (kostenloser Versand)';
        }

        $steps[] = $onboarding->pods_post_id
            ? "Schule-Eintrag (CPT) vorhanden (ID {$onboarding->pods_post_id}) - wird übersprungen"
            : sprintf(
                'Schule-Eintrag (CPT) anlegen: Bestellfenster %s - %s, On-Demand: %s',
                $onboarding->window_start?->format('d.m.Y') ?? '-',
                $onboarding->window_end?->format('d.m.Y') ?? '-',
                $onboarding->delivery_type === 'ondemand' ? 'Ja' : 'Nein',
            );

        return $steps;
    }

    /**
     * Führt die Anlage aus.
     *
     * @return list<array{step: string, ok: bool, detail: string}>
     */
    public function apply(SchoolOnboarding $onboarding): array
    {
        $log = [];
        $run = function (string $step, callable $action) use (&$log): mixed {
            try {
                $result = $action();
                $log[] = ['step' => $step, 'ok' => true, 'detail' => is_string($result) ? $result : ''];

                return $result;
            } catch (ProvisionAbortedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $log[] = ['step' => $step, 'ok' => false, 'detail' => $e->getMessage()];
                throw new ProvisionAbortedException($log, $e);
            }
        };

        try {
            // 1. Kategorie
            if (! $onboarding->woo_category_id) {
                $parentCategory = $run("Übergeordnete Kategorie '".config('schoolshop.parent_category_name')."' sicherstellen",
                    fn () => $this->woo->ensureCategory(config('schoolshop.parent_category_name')));
                $category = $run("Kategorie '{$onboarding->school_name}' anlegen",
                    fn () => $this->woo->ensureCategory($onboarding->school_name, (int) $parentCategory['id']));
                $onboarding->woo_category_id = (int) $category['id'];
                $onboarding->save();
            }

            // 2. Versandklasse (nur On-Demand)
            $shippingClassSlug = '';
            if ($onboarding->delivery_type === 'ondemand') {
                $slug = config('schoolshop.shipping_class_ondemand');
                $run("Versandklasse '{$slug}' prüfen", function () use ($slug) {
                    if ($this->woo->findShippingClass($slug) === null) {
                        throw new \RuntimeException("Versandklasse '{$slug}' existiert nicht im Shop. Bitte unter WooCommerce → Einstellungen → Versand → Versandklassen anlegen.");
                    }

                    return "Versandklasse '{$slug}' vorhanden";
                });
                $shippingClassSlug = $slug;
            }

            // 3. Globale Attribute auflösen
            $attributeIds = $run('Globale Produkt-Attribute laden', fn () => $this->woo->globalAttributes());

            // 4. Produkte
            $productIds = $onboarding->woo_product_ids ?? [];
            $klassen = $this->klassenListe($onboarding);
            foreach ($onboarding->enabledProducts() as $product) {
                if (isset($productIds[$product['key']])) {
                    $log[] = ['step' => "Produkt {$product['key']} bereits vorhanden", 'ok' => true, 'detail' => 'ID '.$productIds[$product['key']]];

                    continue;
                }
                $created = $run("Produkt '".$onboarding->school_name.' '.config("schoolshop.catalog.{$product['key']}.name_suffix")."' anlegen (inkl. Variationen)",
                    fn () => $this->createProduct($onboarding, $product, $attributeIds, $klassen, $shippingClassSlug));
                $productIds[$product['key']] = (int) $created['id'];
                $onboarding->woo_product_ids = $productIds;
                $onboarding->save();
            }

            // 5. Pods-CPT "schule"
            if (! $onboarding->pods_post_id) {
                $pods = $run('Schule-Eintrag (CPT) anlegen', fn () => $this->wordpress->createSchule($onboarding->school_name, [
                    'bestellfensterstart' => $onboarding->window_start?->format('Y-m-d 00:00:00') ?? '',
                    'bestellfensterende' => $onboarding->window_end?->format('Y-m-d 23:59:59') ?? '',
                    'produkte_shortcode' => mb_strtolower($onboarding->school_name),
                    'bestellfenster_offen' => config('schoolshop.pods.bestellfenster_offen_default'),
                    'lieferstatus' => '',
                    'on-demand' => $onboarding->delivery_type === 'ondemand' ? '1' : '0',
                    'versandklasse_on_demand_fur_jedes_produkt_gesetzt' => $onboarding->delivery_type === 'ondemand' ? '1' : '0',
                    'crm_eintrag' => '',
                    'woocommerce_produkt_kategorie' => $onboarding->woo_category_id,
                ]));
                $onboarding->pods_post_id = (int) ($pods['id'] ?? 0) ?: null;
            }

            $onboarding->status = 'angelegt';
        } finally {
            $onboarding->provision_log = array_merge($onboarding->provision_log ?? [], $log);
            $onboarding->save();
        }

        return $log;
    }

    private function createProduct(SchoolOnboarding $onboarding, array $product, array $attributeIds, array $klassen, string $shippingClassSlug): array
    {
        $preset = config("schoolshop.catalog.{$product['key']}");
        $name = $onboarding->school_name.' '.$preset['name_suffix'];
        $hasIndiv = ($product['indiv_surcharge'] ?? 0) > 0;

        $attributes = [];
        $attributeSpecs = [
            ['label' => 'Größe', 'options' => $product['sizes'], 'variation' => false],
            ['label' => 'Farbe', 'options' => $product['colors'], 'variation' => false],
            ['label' => 'Klasse', 'options' => $klassen, 'variation' => false],
            ['label' => 'Individualisierung', 'options' => $hasIndiv ? ['Ja', 'Nein'] : ['Nein'], 'variation' => true],
        ];
        foreach ($attributeSpecs as $position => $spec) {
            if ($spec['options'] === []) {
                continue;
            }
            $globalId = $attributeIds[mb_strtolower($spec['label'])] ?? null;
            $attribute = [
                'position' => $position,
                'visible' => true,
                'variation' => $spec['variation'],
                'options' => array_values($spec['options']),
            ];
            if ($globalId !== null) {
                $attribute['id'] = $globalId;
                $this->woo->ensureAttributeTerms($globalId, $attribute['options']);
            } else {
                $attribute['name'] = $spec['label'];
            }
            $attributes[] = $attribute;
        }

        $payload = [
            'name' => $name,
            'type' => 'variable',
            'status' => 'publish',
            'categories' => [['id' => $onboarding->woo_category_id]],
            'description' => $preset['description'],
            'short_description' => $preset['description'],
            'attributes' => $attributes,
            'meta_data' => collect(config('schoolshop.pif_meta'))
                ->map(fn ($value, $key) => ['key' => $key, 'value' => $value])
                ->values()
                ->all(),
        ];
        if ($shippingClassSlug !== '') {
            $payload['shipping_class'] = $shippingClassSlug;
        }

        $created = $this->woo->createProduct($payload);
        $productId = (int) $created['id'];

        // Variationen: Individualisierung Nein (Basispreis) / Ja (Basis + Aufpreis)
        $indivAttribute = fn (string $value) => [array_merge(
            isset($attributeIds['individualisierung']) ? ['id' => $attributeIds['individualisierung']] : ['name' => 'Individualisierung'],
            ['option' => $value],
        )];
        $this->woo->createVariation($productId, [
            'regular_price' => number_format((float) $product['base_price'], 2, '.', ''),
            'attributes' => $indivAttribute('Nein'),
        ]);
        if ($hasIndiv) {
            $this->woo->createVariation($productId, [
                'regular_price' => number_format((float) $product['base_price'] + (float) $product['indiv_surcharge'], 2, '.', ''),
                'attributes' => $indivAttribute('Ja'),
            ]);
        }

        return $created;
    }

    /** @return list<string> */
    private function klassenListe(SchoolOnboarding $onboarding): array
    {
        $klassen = array_values(array_filter(array_map('trim', explode(',', (string) $onboarding->class_list))));
        if ($klassen === []) {
            return [];
        }

        return array_values(array_unique(array_merge(config('schoolshop.default_klassen_extra'), $klassen)));
    }
}
