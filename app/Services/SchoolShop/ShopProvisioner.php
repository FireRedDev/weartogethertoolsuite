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
        private readonly PrintifyProvisioner $printify,
        private readonly MockupGenerator $mockups,
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
            $name = $onboarding->school_name.' '.ProductConfigurator::preset($product)['name_suffix'];
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

        if ($onboarding->mockups_enabled && $onboarding->delivery_type !== 'ondemand') {
            $placementLabel = config("schoolshop.mockups.placements.{$onboarding->mockup_placement}.label", $onboarding->mockup_placement);
            $steps[] = "Mockups erzeugen (Model-Fotos + Detailansichten, Logo-Platzierung: {$placementLabel}) und als Produktbild/Galerie setzen";
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

            // 4. Produkte: Sammelbestellfenster legt sie direkt in WooCommerce
            // an; On-Demand legt sie in Printify an (Printify published sie
            // selbst in den Shop, danach "On-Demand-Nachbearbeitung" klicken).
            if ($onboarding->delivery_type === 'ondemand') {
                $this->applyPrintify($onboarding, $run, $log);
            } else {
                $attributeIds = $run('Globale Produkt-Attribute laden', fn () => $this->woo->globalAttributes());
                $productIds = $onboarding->woo_product_ids ?? [];
                $klassen = $this->klassenListe($onboarding);
                foreach ($onboarding->enabledProducts() as $product) {
                    if (isset($productIds[$product['key']])) {
                        $log[] = ['step' => "Produkt {$product['key']} bereits vorhanden", 'ok' => true, 'detail' => 'ID '.$productIds[$product['key']]];

                        continue;
                    }
                    $created = $run("Produkt '".$onboarding->school_name.' '.ProductConfigurator::preset($product)['name_suffix']."' anlegen (inkl. Variationen)",
                        fn () => $this->createProduct($onboarding, $product, $attributeIds, $klassen, $shippingClassSlug));
                    $productIds[$product['key']] = (int) $created['id'];
                    $onboarding->woo_product_ids = $productIds;
                    $onboarding->save();
                }

                // 4b. Optional: Mockups erzeugen und als Produktbilder setzen.
                // Fehler hier brechen die Anlage NICHT ab — die Produkte stehen
                // bereits, Bilder lassen sich per erneutem Klick nachholen.
                if ($onboarding->mockups_enabled) {
                    $this->applyMockups($onboarding, $log);
                }
            }

            // 5. Pods-CPT "schule" anlegen (nur falls noch nicht vorhanden)
            $fields = $this->schuleFields($onboarding);
            if (! $onboarding->pods_post_id) {
                $pods = $run('Schule-Eintrag (CPT) anlegen', fn () => $this->wordpress->createSchule($onboarding->school_name, $fields));
                $onboarding->pods_post_id = (int) ($pods['id'] ?? 0) ?: null;
                $onboarding->save();
            }

            // 5b. Felder immer (idempotent) setzen und zurücklesen — so lässt
            // sich ein bereits angelegter Eintrag nach dem Aktivieren der
            // Pods-Feld-Schreibrechte per erneutem Klick nachbefüllen.
            if ($onboarding->pods_post_id) {
                $run('Schule-Felder setzen', fn () => $this->wordpress->updateSchule($onboarding->pods_post_id, $fields));
                $this->verifySchuleFields($onboarding->pods_post_id, $fields, $log);
            }

            // 5c. Logo als Beitragsbild (falls vorhanden)
            $logoUrl = ($onboarding->logo_files ?? [])[0] ?? null;
            if ($logoUrl && $onboarding->pods_post_id) {
                $run('Logo als Beitragsbild setzen', function () use ($onboarding, $logoUrl) {
                    $mediaId = $this->wordpress->uploadMediaFromUrl($logoUrl);
                    $this->wordpress->setFeaturedImage($onboarding->pods_post_id, $mediaId);

                    return "Media-ID {$mediaId}";
                });
            }

            $onboarding->status = 'angelegt';
        } catch (ProvisionAbortedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Sicherheitsnetz: Fehler außerhalb eines run()-Schritts (z. B. beim
            // Speichern oder bei der Klassenlisten-Auswertung) sollen genauso
            // sichtbar werden wie API-Fehler, statt als nackter 500er zu enden.
            $log[] = [
                'step' => 'Unerwarteter Fehler',
                'ok' => false,
                'detail' => get_class($e).': '.$e->getMessage().' in '.basename($e->getFile()).':'.$e->getLine(),
            ];
            throw new ProvisionAbortedException($log, $e);
        } finally {
            $onboarding->provision_log = array_merge($onboarding->provision_log ?? [], $log);
            $onboarding->save();
        }

        return $log;
    }

    private function createProduct(SchoolOnboarding $onboarding, array $product, array $attributeIds, array $klassen, string $shippingClassSlug): array
    {
        $preset = ProductConfigurator::preset($product);
        $name = $onboarding->school_name.' '.$preset['name_suffix'];
        $hasIndiv = ($product['indiv_surcharge'] ?? 0) > 0;

        // Wie der Excel-Master: ALLE Attribute sind Variationsattribute — die
        // Variationen belegen nur "Individualisierung" konkret, der Rest bleibt
        // "Any". So wählen Kund:innen Größe/Farbe/Klasse im Dropdown, und die
        // Auswahl erscheint im Warenkorb/Checkout und in der Bestellung
        // (Grundlage für die Auftragsdokumente in Modul 1).
        $attributes = [];
        $attributeSpecs = [
            ['label' => 'Größe', 'options' => $product['sizes']],
            ['label' => 'Farbe', 'options' => $product['colors']],
            ['label' => 'Klasse', 'options' => $klassen],
            ['label' => 'Individualisierung', 'options' => $hasIndiv ? ['Ja', 'Nein'] : ['Nein']],
        ];
        foreach ($attributeSpecs as $position => $spec) {
            if ($spec['options'] === []) {
                continue;
            }
            $globalId = $attributeIds[mb_strtolower($spec['label'])] ?? null;
            $attribute = [
                'position' => $position,
                'visible' => true,
                'variation' => true,
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

        // Vorauswahl wie im Excel-Master (Standard-Größe M)
        $defaultAttributes = [];
        $defaultSize = $preset['default_size'] ?? null;
        if ($defaultSize && in_array($defaultSize, $product['sizes'], true)) {
            $defaultAttributes[] = array_merge(
                isset($attributeIds['größe']) ? ['id' => $attributeIds['größe']] : ['name' => 'Größe'],
                ['option' => $defaultSize],
            );
        }

        $payload = [
            'name' => $name,
            'type' => 'variable',
            'status' => 'publish',
            'categories' => [['id' => $onboarding->woo_category_id]],
            'description' => $preset['description'],
            'short_description' => $preset['description'],
            'attributes' => $attributes,
            'default_attributes' => $defaultAttributes,
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

    /**
     * Optionaler Mockup-Schritt (Sammelbestellfenster): rendert Model-Fotos +
     * Detailansichten mit dem Schullogo (Dynamic Mockups) und setzt sie als
     * Produktbild + Galerie. Bewusst nicht über $run(): Fehler werden im
     * Protokoll vermerkt, brechen die Anlage aber nicht ab (Bilder sind
     * kosmetisch und per erneutem Klick nachholbar). Bereits gerenderte
     * Produkte werden übersprungen (keine doppelten Credits).
     *
     * @param  list<array{step: string, ok: bool, detail: string}>  $log
     */
    private function applyMockups(SchoolOnboarding $onboarding, array &$log): void
    {
        $logoUrl = ($onboarding->logo_files ?? [])[0] ?? null;
        if (! $logoUrl) {
            $log[] = ['step' => 'Mockups', 'ok' => false, 'detail' => 'Kein Logo hinterlegt — Mockups übersprungen. Logo-Datei im Antrag ergänzen und erneut anlegen.'];

            return;
        }
        if (! $this->mockups->isConfigured()) {
            $log[] = ['step' => 'Mockups', 'ok' => false, 'detail' => 'DYNAMIC_MOCKUPS_API_KEY fehlt in der .env — Mockups übersprungen.'];

            return;
        }

        $done = $onboarding->mockup_images ?? [];
        $productIds = $onboarding->woo_product_ids ?? [];
        foreach ($onboarding->enabledProducts() as $product) {
            $key = $product['key'];
            $productId = $productIds[$key] ?? null;
            if ($productId === null) {
                continue;
            }
            if (isset($done[$key])) {
                $log[] = ['step' => "Mockups {$key}", 'ok' => true, 'detail' => 'bereits erzeugt — übersprungen'];

                continue;
            }

            try {
                $images = $this->mockups->generateForProduct($onboarding, $product, $logoUrl);
            } catch (\Throwable $e) {
                $log[] = ['step' => "Mockups {$key}", 'ok' => false, 'detail' => 'Rendern fehlgeschlagen (Produkt selbst wurde angelegt): '.$e->getMessage()];

                continue;
            }
            if ($images === []) {
                $log[] = ['step' => "Mockups {$key}", 'ok' => true, 'detail' => "keine Vorlagen für '{$key}' konfiguriert (config/schoolshop.php → mockups.templates) — übersprungen"];

                continue;
            }

            try {
                // WooCommerce lädt die Bild-URLs selbst in die Mediathek
                // (erstes Bild = Produktbild, Rest = Produktgalerie).
                $this->woo->updateProduct((int) $productId, [
                    'images' => array_map(fn ($img) => ['src' => $img['url'], 'name' => $img['label'], 'alt' => $img['label']], $images),
                ]);
            } catch (\Throwable $e) {
                $log[] = ['step' => "Mockups {$key}", 'ok' => false, 'detail' => 'Bilder gerendert, aber Zuweisung am Produkt fehlgeschlagen: '.$e->getMessage()];

                continue;
            }

            $done[$key] = array_column($images, 'url');
            $onboarding->mockup_images = $done;
            $onboarding->save();
            $log[] = ['step' => "Mockups {$key}", 'ok' => true, 'detail' => count($images).' Bild(er) gesetzt (Produktbild + Galerie)'];
        }
    }

    /**
     * On-Demand: Produkte in Printify anlegen und publishen (inkl. Margen-
     * Prüfung). Blueprint/Provider kommen aus dem Konfigurator, das Logo aus
     * den Formular-Uploads.
     *
     * @param  list<array{step: string, ok: bool, detail: string}>  $log
     */
    private function applyPrintify(SchoolOnboarding $onboarding, callable $run, array &$log): void
    {
        $frontLogo = ($onboarding->logo_files ?? [])[0] ?? null;
        $backLogo = ($onboarding->logo_files ?? [])[1] ?? null;
        $wantsBackprint = in_array('Backprint', $onboarding->print_areas ?? [], true);
        if (! $frontLogo) {
            throw new ProvisionAbortedException([...$log, [
                'step' => 'Printify-Produkte anlegen', 'ok' => false,
                'detail' => 'Kein Logo vorhanden. Bitte im Antrag eine Logo-Datei hinterlegen (kommt normalerweise aus dem Formular-Upload).',
            ]], new \RuntimeException('Logo fehlt'));
        }

        $printifyIds = $onboarding->printify_product_ids ?? [];
        foreach ($onboarding->enabledProducts() as $product) {
            $key = $product['key'];
            if (isset($printifyIds[$key])) {
                $log[] = ['step' => "Printify-Produkt {$key} bereits vorhanden", 'ok' => true, 'detail' => 'ID '.$printifyIds[$key]];

                continue;
            }
            $blueprintId = (int) ($product['printify_blueprint_id'] ?? 0);
            $providerId = (int) ($product['printify_provider_id'] ?? 0);
            $preset = ProductConfigurator::preset($product);
            if ($blueprintId === 0 || $providerId === 0) {
                throw new ProvisionAbortedException([...$log, [
                    'step' => "Printify-Produkt '".$preset['label']."'", 'ok' => false,
                    'detail' => 'Blueprint-ID/Print-Provider-ID fehlen im Konfigurator. IDs nachschlagen: über die Suche im Konfigurator (🔍-Button), am Server mit php artisan printify:check --blueprints=SUCHBEGRIFF (z. B. JH001), oder direkt auf printify.com — dann im Konfigurator eintragen und speichern.',
                ]], new \RuntimeException('Printify-Zuordnung fehlt'));
            }

            $result = $run(
                "Printify-Produkt '".$onboarding->school_name.' '.$preset['name_suffix']."' anlegen + publishen (inkl. Margen-Prüfung)",
                fn () => $this->printify->createAndPublish(
                    $onboarding,
                    $product,
                    $blueprintId,
                    $providerId,
                    $frontLogo,
                    $wantsBackprint ? $backLogo : null,
                ),
            );
            $log[] = ['step' => "Margen-Prüfung {$key}", 'ok' => true, 'detail' => $result['price_check']['message']];
            $printifyIds[$key] = $result['printify_product_id'];
            $onboarding->printify_product_ids = $printifyIds;
            $onboarding->save();
        }

        $log[] = [
            'step' => 'Hinweis On-Demand', 'ok' => true,
            'detail' => 'Printify legt die Shop-Produkte jetzt selbst an (dauert einige Minuten). Danach bitte "On-Demand-Nachbearbeitung" klicken, um Versandklasse und Kategorie zu setzen.',
        ];
    }

    /**
     * Nachbearbeitung nach dem Printify-Sync: setzt Versandklasse "on-demand"
     * und die Schul-Kategorie auf den von Printify angelegten Shop-Produkten.
     *
     * @return list<array{step: string, ok: bool, detail: string}>
     */
    public function ondemandSync(SchoolOnboarding $onboarding): array
    {
        $log = [];
        $slug = config('schoolshop.shipping_class_ondemand');
        $products = $this->woo->findProductsByName($onboarding->school_name);
        if ($products === []) {
            $log[] = [
                'step' => 'Shop-Produkte suchen', 'ok' => false,
                'detail' => "Keine Produkte mit '{$onboarding->school_name}' im Namen gefunden. Printify braucht nach dem Publish einige Minuten — bitte später erneut versuchen.",
            ];

            return $log;
        }

        $updated = 0;
        foreach ($products as $product) {
            $needsShipping = ($product['shipping_class'] ?? '') !== $slug;
            $categoryIds = array_column($product['categories'] ?? [], 'id');
            $needsCategory = $onboarding->woo_category_id && ! in_array($onboarding->woo_category_id, $categoryIds, true);
            if (! $needsShipping && ! $needsCategory) {
                continue;
            }
            $payload = ['shipping_class' => $slug];
            if ($onboarding->woo_category_id) {
                $payload['categories'] = array_map(fn ($id) => ['id' => $id], array_unique([...$categoryIds, $onboarding->woo_category_id]));
            }
            $this->woo->updateProduct((int) $product['id'], $payload);
            $log[] = ['step' => "Produkt '".($product['name'] ?? $product['id'])."'", 'ok' => true, 'detail' => "Versandklasse '{$slug}' + Kategorie gesetzt"];
            $updated++;
        }
        if ($updated === 0) {
            $log[] = ['step' => 'Nachbearbeitung', 'ok' => true, 'detail' => 'Alle gefundenen Produkte waren bereits korrekt konfiguriert.'];
        }

        if ($onboarding->pods_post_id) {
            $this->wordpress->updateSchule($onboarding->pods_post_id, ['versandklasse_on_demand_fur_jedes_produkt_gesetzt' => '1']);
            $log[] = ['step' => 'Schule-Eintrag aktualisiert', 'ok' => true, 'detail' => 'versandklasse_on_demand_fur_jedes_produkt_gesetzt = 1'];
        }

        $onboarding->provision_log = array_merge($onboarding->provision_log ?? [], $log);
        $onboarding->save();

        return $log;
    }

    /**
     * Modul 3 "Bestellfenster schließen": setzt alle Produkte der Schule auf
     * privat (aus Shop/Suche entfernt, für Kund:innen nicht mehr bestellbar)
     * und stellt im CPT "schule" das Feld "Bestellfenster offen" auf NEIN.
     *
     * Produkte werden bevorzugt über die Schul-Kategorie gefunden (eindeutig);
     * fehlt sie, wird auf die Namenssuche zurückgegriffen.
     *
     * @return list<array{step: string, ok: bool, detail: string}>
     */
    public function closeOrderWindow(SchoolOnboarding $onboarding): array
    {
        $log = [];

        $products = $onboarding->woo_category_id
            ? $this->woo->findProductsByCategory((int) $onboarding->woo_category_id)
            : $this->woo->findProductsByName($onboarding->school_name);

        if ($products === []) {
            $log[] = [
                'step' => 'Shop-Produkte suchen', 'ok' => false,
                'detail' => $onboarding->woo_category_id
                    ? "Keine Produkte in der Schul-Kategorie (ID {$onboarding->woo_category_id}) gefunden."
                    : "Keine Produkte mit '{$onboarding->school_name}' im Namen gefunden. Wurde der Shop für diese Schule schon angelegt?",
            ];
        }

        $closed = 0;
        foreach ($products as $product) {
            if (($product['status'] ?? '') === 'private') {
                $log[] = ['step' => "Produkt '".($product['name'] ?? $product['id'])."'", 'ok' => true, 'detail' => 'war bereits privat — übersprungen'];

                continue;
            }
            // status=private entfernt das Produkt komplett aus Shop & Suche für
            // Kund:innen; catalog_visibility=hidden zusätzlich als Absicherung.
            $this->woo->updateProduct((int) $product['id'], ['status' => 'private', 'catalog_visibility' => 'hidden']);
            $log[] = ['step' => "Produkt '".($product['name'] ?? $product['id'])."'", 'ok' => true, 'detail' => 'auf privat gesetzt'];
            $closed++;
        }
        if ($products !== [] && $closed === 0) {
            $log[] = ['step' => 'Produkte', 'ok' => true, 'detail' => 'Alle gefundenen Produkte waren bereits privat.'];
        }

        if ($onboarding->pods_post_id) {
            $this->wordpress->updateSchule((int) $onboarding->pods_post_id, ['bestellfenster_offen' => 'NEIN']);
            $log[] = ['step' => 'Schule-Eintrag aktualisiert', 'ok' => true, 'detail' => 'Bestellfenster offen = NEIN'];
        } else {
            $log[] = ['step' => 'Schule-Eintrag', 'ok' => false, 'detail' => 'Kein CPT-Eintrag (pods_post_id) hinterlegt — „Bestellfenster offen" konnte nicht gesetzt werden.'];
        }

        // Status im Tool nachziehen, wenn alles glattlief.
        if (collect($log)->every(fn ($l) => $l['ok'])) {
            $onboarding->status = 'abgeschlossen';
        }
        $onboarding->provision_log = array_merge($onboarding->provision_log ?? [], $log);
        $onboarding->save();

        return $log;
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

    /**
     * Pods-Feldwerte des CPT "schule" aus dem Onboarding.
     *
     * @return array<string, mixed>
     */
    private function schuleFields(SchoolOnboarding $onboarding): array
    {
        $ondemand = $onboarding->delivery_type === 'ondemand';

        return [
            'bestellfensterstart' => $onboarding->window_start?->format('Y-m-d 00:00:00') ?? '',
            'bestellfensterende' => $onboarding->window_end?->format('Y-m-d 23:59:59') ?? '',
            'produkte_shortcode' => mb_strtolower($onboarding->school_name),
            'bestellfenster_offen' => config('schoolshop.pods.bestellfenster_offen_default'),
            'lieferstatus' => '',
            'on-demand' => $ondemand ? '1' : '0',
            // Wird erst nach der On-Demand-Nachbearbeitung (Versandklassen
            // auf den von Printify angelegten Produkten) auf 1 gesetzt.
            'versandklasse_on_demand_fur_jedes_produkt_gesetzt' => '0',
            'crm_eintrag' => '',
            'woocommerce_produkt_kategorie' => $onboarding->woo_category_id,
        ];
    }

    /**
     * Liest den CPT zurück und prüft, welche Schlüsselfelder tatsächlich
     * gesetzt wurden. Leere Felder deuten fast immer darauf hin, dass in
     * Pods die REST-Schreibrechte des jeweiligen Feldes fehlen.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<array{step: string, ok: bool, detail: string}>  $log
     */
    private function verifySchuleFields(int $postId, array $fields, array &$log): void
    {
        try {
            $post = $this->wordpress->getSchule($postId);
        } catch (\Throwable $e) {
            $log[] = ['step' => 'Schule-Felder prüfen', 'ok' => true, 'detail' => 'Rücklesen nicht möglich ('.$e->getMessage().') — bitte im WordPress-Backend kontrollieren.'];

            return;
        }

        // Nur die inhaltlich wichtigen Felder prüfen (leere sind bei uns Absicht).
        $keyFields = ['bestellfensterstart', 'bestellfensterende', 'produkte_shortcode', 'bestellfenster_offen', 'on-demand', 'woocommerce_produkt_kategorie'];
        $meta = is_array($post['meta'] ?? null) ? $post['meta'] : [];
        $missing = [];
        foreach ($keyFields as $key) {
            if (($fields[$key] ?? '') === '' || $fields[$key] === null) {
                continue; // wir wollten hier gar nichts setzen
            }
            $topLevel = $post[$key] ?? null;
            $metaValue = $meta[$key] ?? null;
            $isSet = ! in_array($topLevel, [null, '', [], '0'], true) || ! in_array($metaValue, [null, '', [], '0'], true);
            // on-demand darf legitim "0" sein
            if (! $isSet && $key === 'on-demand') {
                $isSet = ($topLevel !== null) || ($metaValue !== null);
            }
            if (! $isSet) {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            $log[] = ['step' => 'Schule-Felder prüfen', 'ok' => true, 'detail' => 'Alle Felder gesetzt.'];
        } else {
            $log[] = [
                'step' => 'Schule-Felder prüfen',
                'ok' => false,
                'detail' => 'Diese Felder wurden von WordPress NICHT gespeichert: '.implode(', ', $missing)
                    .'. Ursache ist fast immer fehlende REST-Schreibberechtigung in Pods. Bitte im Pods-Admin beim Pod "schule" für diese Felder (Feld bearbeiten → Erweitert → REST-API) sowohl Lesen als auch Schreiben aktivieren und danach erneut auf "Im Shop anlegen" klicken.',
            ];
        }
    }
}
