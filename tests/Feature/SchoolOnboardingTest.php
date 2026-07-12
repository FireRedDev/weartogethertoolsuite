<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\PrintifyProvisioner;
use App\Services\SchoolShop\ShopProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SchoolOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'schoolshop.webhook_secret' => 'test-secret',
            'ordersuite.woocommerce.store_url' => 'https://shop.example',
            'schoolshop.woocommerce_write.consumer_key' => 'ck_rw',
            'schoolshop.woocommerce_write.consumer_secret' => 'cs_rw',
            'schoolshop.wordpress.user' => 'admin',
            'schoolshop.wordpress.password' => 'app-password',
            'schoolshop.printify.api_token' => 'pfy_token',
            'schoolshop.printify.shop_id' => '99',
        ]);
    }

    /** Payload nachgebaut aus Entry #669 (Webshopstartfragebogen). */
    private function webhookPayload(): array
    {
        return [
            'input_text' => 'Laurens Yassemipour',
            'email' => 'laurens@example.at',
            'phone' => '+43 66303059352',
            'input_radio' => 'eine Schule',
            'input_radio_1' => 'SchulsprecherIn',
            'input_radio_8' => 'E-Mail',
            'input_text_6' => 'AHS Testschule',
            'address_1' => [
                'address_line_1' => 'Liese-Prokop-Straße 1',
                'city' => 'Korneuburg', 'zip' => '2100', 'state' => 'Niederösterreich', 'country' => 'Österreich',
            ],
            'numeric-field_1' => '752',
            'numeric-field' => '50',
            'input_radio_7' => 'Sammelbestellung online',
            'multi_select_4' => ['Hoodie', 'Polo-Shirt (nur bei Sammelbestellungen)', 'T-Shirt', 'Sweater'],
            'multi_select_3' => ['Weiß', 'Burgundy', 'Dunkelgrau', 'Blau'],
            'multi_select' => ['Frontprint', 'Backprint'],
            'input_radio_5' => 'Ja',
            'file-upload_1' => ['https://shop.example/uploads/logo_ahs.png'],
            'description_5' => 'Logo linke Brustseite',
            'description_3' => '1a,1b,2a,2b',
            'datetime' => '16.04.2026',
        ];
    }

    public function test_webhook_creates_onboarding_with_mapped_fields(): void
    {
        $this->postJson('/webhooks/fluentforms/test-secret', $this->webhookPayload())
            ->assertOk()
            ->assertJson(['ok' => true]);

        $onboarding = SchoolOnboarding::sole();
        $this->assertSame('AHS Testschule', $onboarding->school_name);
        $this->assertSame('collective', $onboarding->delivery_type);
        $this->assertSame('neu', $onboarding->status);
        $this->assertSame('1a,1b,2a,2b', $onboarding->class_list);
        $this->assertSame('2026-04-16', $onboarding->window_start->format('Y-m-d'));
        $this->assertSame(
            '2026-04-16',
            $onboarding->window_end->copy()->subDays(config('schoolshop.default_window_days'))->format('Y-m-d'),
        );

        $enabled = collect($onboarding->enabledProducts())->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['schulpullover', 'schulpolo', 'schulshirt', 'schulsweater'], $enabled);

        // Farben normalisiert (kleingeschrieben, Zusätze entfernt)
        $hoodie = collect($onboarding->products)->firstWhere('key', 'schulpullover');
        $this->assertSame(['weiß', 'burgundy', 'dunkelgrau', 'blau'], $hoodie['colors']);
        $this->assertSame(39.99, $hoodie['base_price']);
    }

    public function test_webhook_rejects_wrong_secret(): void
    {
        $this->postJson('/webhooks/fluentforms/falsch', $this->webhookPayload())->assertNotFound();
        $this->assertSame(0, SchoolOnboarding::count());
    }

    public function test_ondemand_delivery_uses_ondemand_product_and_color_fields(): void
    {
        $payload = $this->webhookPayload();
        $payload['input_radio_7'] = 'On-Demand online';
        $payload['multi_select_1'] = ['Hoodie', 'T-Shirt'];
        $payload['multi_select_2'] = ['Schwarz', 'Navy'];

        $this->postJson('/webhooks/fluentforms/test-secret', $payload)->assertOk();

        $onboarding = SchoolOnboarding::sole();
        $this->assertSame('ondemand', $onboarding->delivery_type);
        $enabled = collect($onboarding->enabledProducts())->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['schulpullover', 'schulshirt'], $enabled);
        $this->assertSame(['schwarz', 'navy'], $onboarding->enabledProducts()[0]['colors']);
    }

    public function test_full_provisioning_creates_category_products_variations_and_cpt(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products/categories?*search=Schulen*' => Http::response([
                ['id' => 15, 'name' => 'Schulen', 'parent' => 0],
            ]),
            'shop.example/wp-json/wc/v3/products/categories?*search=AHS*' => Http::response([]),
            'shop.example/wp-json/wc/v3/products/categories?*' => Http::response(['id' => 77, 'name' => 'AHS Testschule', 'parent' => 15], 201),
            'shop.example/wp-json/wc/v3/products/attributes/*/terms*' => Http::response([]),
            'shop.example/wp-json/wc/v3/products/attributes?*' => Http::response([
                ['id' => 1, 'name' => 'Größe'], ['id' => 2, 'name' => 'Farbe'],
                ['id' => 3, 'name' => 'Klasse'], ['id' => 4, 'name' => 'Individualisierung'],
            ]),
            'shop.example/wp-json/wc/v3/products/*/variations*' => Http::response(['id' => 501], 201),
            'shop.example/wp-json/wc/v3/products?*' => Http::response(['id' => 401, 'name' => 'AHS Testschule Schulpullover'], 201),
            // CPT: Create/Update/Get liefern den Eintrag mit gesetzten Feldern zurück
            // (damit die Rücklese-Verifikation die Felder als gesetzt erkennt)
            'shop.example/wp-json/wp/v2/schule*' => Http::response([
                'id' => 900,
                'bestellfensterstart' => '2026-04-16 00:00:00',
                'bestellfensterende' => '2026-05-11 23:59:59',
                'produkte_shortcode' => 'ahs testschule',
                'bestellfenster_offen' => 'NEIN',
                'on-demand' => '0',
                'woocommerce_produkt_kategorie' => 77,
                'featured_media' => 0,
            ], 201),
            'shop.example/wp-json/wp/v2/media*' => Http::response(['id' => 555, 'source_url' => 'https://shop.example/logo.png'], 201),
            'shop.example/uploads/*' => Http::response('binary-image-data', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->postJson('/webhooks/fluentforms/test-secret', $this->webhookPayload())->assertOk();
        $onboarding = SchoolOnboarding::sole();
        // Nur ein Produkt aktiv lassen, damit die Anzahl der Aufrufe klar ist
        $onboarding->products = collect($onboarding->products)
            ->map(fn ($p) => [...$p, 'enabled' => ($p['key'] ?? '') === 'schulpullover'])->all();
        $onboarding->save();

        $log = app(ShopProvisioner::class)->apply($onboarding->fresh());

        $onboarding->refresh();
        $this->assertSame(77, $onboarding->woo_category_id);
        $this->assertSame(['schulpullover' => 401], $onboarding->woo_product_ids);
        $this->assertSame(900, $onboarding->pods_post_id);
        $this->assertSame('angelegt', $onboarding->status);
        $this->assertTrue(collect($log)->every(fn ($l) => $l['ok']));

        // Produkt-Anlage: PIF-Metas, Kategorie, Attribute inkl. Klassen aus der Klassenliste.
        // Wie der Excel-Master: ALLE Attribute variation=true (Variationen = "Any"
        // außer Individualisierung), Standard-Größe M, Beschreibung ohne \n-Literale.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/wc/v3/products?') || $request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();
            $metaKeys = array_column($body['meta_data'] ?? [], 'key');
            $klasse = collect($body['attributes'] ?? [])->firstWhere('id', 3);
            $allVariation = collect($body['attributes'] ?? [])->every(fn ($a) => $a['variation'] === true);
            $defaultSize = collect($body['default_attributes'] ?? [])->firstWhere('id', 1);

            return $body['type'] === 'variable'
                && $body['categories'] === [['id' => 77]]
                && in_array('_alg_wc_pif_enabled_local_1', $metaKeys, true)
                && in_array('1a', $klasse['options'] ?? [], true)
                && in_array('LehrerIn', array_map('trim', $klasse['options'] ?? []), true)
                && $allVariation
                && ($defaultSize['option'] ?? null) === 'M'
                && ! str_contains($body['description'] ?? '', '\\n');
        });

        // Zwei Variationen: Nein 39.99, Ja 47.98
        Http::assertSent(fn ($r) => str_contains($r->url(), '/variations')
            && ($r->data()['regular_price'] ?? '') === '39.99');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/variations')
            && ($r->data()['regular_price'] ?? '') === '47.98');

        // CPT mit Bestellfenster + Kategorie-Verknüpfung
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wp/v2/schule')
            && ($r->data()['woocommerce_produkt_kategorie'] ?? null) === 77
            && str_starts_with((string) ($r->data()['bestellfensterstart'] ?? ''), '2026-04-16'));

        // Felder werden auch per Update-Aufruf auf den bestehenden Eintrag gesetzt
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wp/v2/schule/900') && $r->method() === 'POST');
        // Logo wird als Beitragsbild gesetzt
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wp/v2/media') && $r->method() === 'POST');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wp/v2/schule/900') && ($r->data()['featured_media'] ?? null) === 555);
    }

    public function test_cpt_created_but_fields_not_saved_produces_actionable_warning(): void
    {
        // CPT wird angelegt, aber die Pods-Felder bleiben leer (fehlende
        // REST-Schreibrechte pro Feld) — das Rücklesen muss das erkennen und
        // eine konkrete Handlungsanweisung ausgeben, statt still zu schlucken.
        Http::fake([
            'shop.example/wp-json/wc/v3/products/categories?*search=Schulen*' => Http::response([['id' => 15, 'name' => 'Schulen', 'parent' => 0]]),
            'shop.example/wp-json/wc/v3/products/categories?*' => Http::response(['id' => 77, 'name' => 'AHS Testschule', 'parent' => 15], 201),
            'shop.example/wp-json/wc/v3/products/attributes/*/terms*' => Http::response([]),
            'shop.example/wp-json/wc/v3/products/attributes?*' => Http::response([['id' => 3, 'name' => 'Klasse'], ['id' => 4, 'name' => 'Individualisierung']]),
            'shop.example/wp-json/wc/v3/products/*/variations*' => Http::response(['id' => 501], 201),
            'shop.example/wp-json/wc/v3/products?*' => Http::response(['id' => 401], 201),
            // Antwort OHNE die Felder → Verifikation muss anschlagen
            'shop.example/wp-json/wp/v2/schule*' => Http::response(['id' => 900], 201),
            'shop.example/wp-json/wp/v2/media*' => Http::response(['id' => 555], 201),
            'shop.example/uploads/*' => Http::response('img', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->postJson('/webhooks/fluentforms/test-secret', $this->webhookPayload())->assertOk();
        $onboarding = SchoolOnboarding::sole();
        $onboarding->products = collect($onboarding->products)->map(fn ($p) => [...$p, 'enabled' => ($p['key'] ?? '') === 'schulpullover'])->all();
        $onboarding->save();

        $log = app(ShopProvisioner::class)->apply($onboarding->fresh());

        $verify = collect($log)->firstWhere('step', 'Schule-Felder prüfen');
        $this->assertNotNull($verify);
        $this->assertFalse($verify['ok']);
        $this->assertStringContainsString('NICHT gespeichert', $verify['detail']);
        $this->assertStringContainsString('bestellfensterstart', $verify['detail']);
        $this->assertStringContainsString('Pods', $verify['detail']);
    }

    public function test_provisioning_aborts_when_ondemand_shipping_class_missing(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products/categories?*search=Schulen*' => Http::response([['id' => 15, 'name' => 'Schulen', 'parent' => 0]]),
            'shop.example/wp-json/wc/v3/products/categories?*' => Http::response(['id' => 78, 'name' => 'X', 'parent' => 15], 201),
            'shop.example/wp-json/wc/v3/products/shipping_classes*' => Http::response([['slug' => 'andere-klasse']]),
        ]);

        $payload = $this->webhookPayload();
        $payload['input_radio_7'] = 'On-Demand online';
        $payload['multi_select_1'] = ['Hoodie'];
        $this->postJson('/webhooks/fluentforms/test-secret', $payload)->assertOk();
        $onboarding = SchoolOnboarding::sole();

        $response = $this->post("/schulen/{$onboarding->id}/anlegen");
        $response->assertRedirect();
        $provisionError = session('provisionError');
        $this->assertNotNull($provisionError);
        $this->assertStringContainsString('Versandklasse', $provisionError['technical']);
    }

    public function test_ui_flow_edit_and_email_generation(): void
    {
        $this->postJson('/webhooks/fluentforms/test-secret', $this->webhookPayload())->assertOk();
        $onboarding = SchoolOnboarding::sole();

        $this->get('/schulen')->assertOk()->assertSee('AHS Testschule');
        $this->get("/schulen/{$onboarding->id}")
            ->assertOk()
            ->assertSee('Konfigurator')
            ->assertSee('Bestellemail an die Druckerei')
            ->assertSee('Schulpullover – JH001 in weiß, burgundy, dunkelgrau, blau')
            ->assertSee('Frontprint in einer proportionalen Breite von 8cm');

        // Konfigurator: Preis ändern und Produkt deaktivieren
        $this->put("/schulen/{$onboarding->id}", [
            'school_name' => 'AHS Testschule',
            'delivery_type' => 'collective',
            'status' => 'in_bearbeitung',
            'class_list' => '1a,1b',
            'products' => [
                'schulpullover' => ['enabled' => '1', 'base_price' => '42,50', 'indiv_surcharge' => '7,99', 'sizes' => 'S, M, L', 'colors' => 'schwarz, weiß'],
            ],
        ])->assertRedirect();

        $onboarding->refresh();
        $hoodie = collect($onboarding->products)->firstWhere('key', 'schulpullover');
        $this->assertSame(42.5, $hoodie['base_price']);
        $this->assertSame(['S', 'M', 'L'], $hoodie['sizes']);
        $this->assertSame(['schulpullover'], collect($onboarding->enabledProducts())->pluck('key')->all());
    }

    public function test_redirecting_store_url_aborts_with_clear_explanation(): void
    {
        // Reale Ursache eines Produktionsfehlers: WC_STORE_URL mit www,
        // Shop läuft ohne www -> 301 machte aus POST ein GET, die Antwort
        // war eine Kategorien-LISTE statt der neuen Kategorie.
        Http::fake([
            'shop.example/*' => Http::response('', 301, ['Location' => 'https://shop-ohne-www.example/wp-json/wc/v3/products/categories']),
        ]);

        $this->postJson('/webhooks/fluentforms/test-secret', $this->webhookPayload())->assertOk();
        $onboarding = SchoolOnboarding::sole();

        $this->post("/schulen/{$onboarding->id}/anlegen")->assertRedirect();

        $provisionError = session('provisionError');
        $this->assertNotNull($provisionError);
        $this->assertStringContainsString('leitet um', $provisionError['user']);
        $this->assertStringContainsString('shop-ohne-www.example', $provisionError['technical']);
        $this->assertStringContainsString('WC_STORE_URL', $provisionError['hint']);
    }

    public function test_onboarding_can_be_deleted(): void
    {
        $this->postJson('/webhooks/fluentforms/test-secret', $this->webhookPayload())->assertOk();
        $onboarding = SchoolOnboarding::sole();

        $this->delete("/schulen/{$onboarding->id}")->assertRedirect(route('schools.index'));
        $this->assertSame(0, SchoolOnboarding::count());
    }

    public function test_ondemand_provisioning_creates_printify_products_instead_of_woo_products(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products/categories?*search=Schulen*' => Http::response([['id' => 15, 'name' => 'Schulen', 'parent' => 0]]),
            'shop.example/wp-json/wc/v3/products/categories?*' => Http::response(['id' => 77, 'name' => 'AHS Testschule', 'parent' => 15], 201),
            'shop.example/wp-json/wc/v3/products/shipping_classes*' => Http::response([['id' => 9, 'slug' => 'on-demand']]),
            'shop.example/wp-json/wp/v2/schule*' => Http::response([
                'id' => 900, 'bestellfensterstart' => '2026-04-16 00:00:00', 'bestellfensterende' => 'x',
                'produkte_shortcode' => 'x', 'bestellfenster_offen' => 'NEIN', 'on-demand' => '1',
                'woocommerce_produkt_kategorie' => 77,
            ], 201),
            'shop.example/wp-json/wp/v2/media*' => Http::response(['id' => 555], 201),
            'shop.example/uploads/*' => Http::response('img', 200, ['Content-Type' => 'image/png']),
            'api.printify.com/v1/uploads/images.json' => Http::response(['id' => 'img-1'], 200),
            'api.printify.com/v1/catalog/blueprints/6/print_providers/27/variants.json' => Http::response([
                'variants' => [['id' => 101, 'cost' => 1500], ['id' => 102, 'cost' => 1600]],
            ]),
            'api.printify.com/v1/catalog/blueprints/6/print_providers/27/shipping.json' => Http::response([
                'profiles' => [['countries' => ['AT'], 'first_item' => ['cost' => 450]]],
            ]),
            'api.printify.com/v1/shops/99/products.json' => Http::response(['id' => 'pfy-1'], 200),
            'api.printify.com/v1/shops/99/products/pfy-1/publish.json' => Http::response(['ok' => true], 200),
        ]);

        $payload = $this->webhookPayload();
        $payload['input_radio_7'] = 'On-Demand online';
        $payload['multi_select_1'] = ['Hoodie'];
        $this->postJson('/webhooks/fluentforms/test-secret', $payload)->assertOk();

        $onboarding = SchoolOnboarding::sole();
        // Blueprint/Provider im Konfigurator zuweisen; Preis über der Marge
        $onboarding->products = collect($onboarding->products)->map(fn ($p) => [
            ...$p,
            'enabled' => ($p['key'] ?? '') === 'schulpullover',
            'base_price' => 39.99,
            'printify_blueprint_id' => 6,
            'printify_provider_id' => 27,
        ])->all();
        $onboarding->save();

        $log = app(ShopProvisioner::class)->apply($onboarding->fresh());

        $onboarding->refresh();
        $this->assertSame(['schulpullover' => 'pfy-1'], $onboarding->printify_product_ids);
        $this->assertNull($onboarding->woo_product_ids); // KEINE Woo-Produkte selbst angelegt
        $this->assertTrue(collect($log)->contains(fn ($l) => str_contains($l['step'], 'Margen-Prüfung')));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/shops/99/products.json')
            && ($r->data()['blueprint_id'] ?? null) === 6
            && collect($r->data()['variants'] ?? [])->every(fn ($v) => $v['price'] === 3999));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/publish.json'));
        // Kein direkter Woo-Produkt-POST im On-Demand-Weg
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/wc/v3/products?') && $r->method() === 'POST');
    }

    public function test_ondemand_provisioning_requires_blueprint_assignment(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products/categories?*search=Schulen*' => Http::response([['id' => 15, 'name' => 'Schulen', 'parent' => 0]]),
            'shop.example/wp-json/wc/v3/products/categories?*' => Http::response(['id' => 77, 'name' => 'X', 'parent' => 15], 201),
            'shop.example/wp-json/wc/v3/products/shipping_classes*' => Http::response([['id' => 9, 'slug' => 'on-demand']]),
        ]);

        $payload = $this->webhookPayload();
        $payload['input_radio_7'] = 'On-Demand online';
        // Umhängetasche (schultasche) hat keinen Supplier-Code und daher keine Printify-Katalog-Defaults.
        $payload['multi_select_1'] = ['Umhängetasche'];
        $this->postJson('/webhooks/fluentforms/test-secret', $payload)->assertOk();
        $onboarding = SchoolOnboarding::sole();

        $this->post("/schulen/{$onboarding->id}/anlegen")->assertRedirect();
        $verify = collect(session('provisionLog'))->firstWhere('ok', false);
        $this->assertStringContainsString('printify:check', $verify['detail']);
    }

    public function test_ondemand_sync_sets_shipping_class_and_category(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products?search*' => Http::response([
                ['id' => 601, 'name' => 'AHS Testschule Schulpullover', 'shipping_class' => '', 'categories' => [['id' => 5]]],
                ['id' => 602, 'name' => 'AHS Testschule Schulshirt', 'shipping_class' => 'on-demand', 'categories' => [['id' => 77]]],
            ]),
            'shop.example/wp-json/wc/v3/products/601*' => Http::response(['id' => 601], 200),
            'shop.example/wp-json/wp/v2/schule/900' => Http::response(['id' => 900], 200),
        ]);

        $payload = $this->webhookPayload();
        $payload['input_radio_7'] = 'On-Demand online';
        $payload['multi_select_1'] = ['Hoodie'];
        $this->postJson('/webhooks/fluentforms/test-secret', $payload)->assertOk();
        $onboarding = SchoolOnboarding::sole();
        $onboarding->forceFill(['woo_category_id' => 77, 'pods_post_id' => 900, 'printify_product_ids' => ['schulpullover' => 'pfy-1']])->save();

        $this->post("/schulen/{$onboarding->id}/ondemand-sync")->assertRedirect();

        // Produkt 601 bekommt Versandklasse + Kategorie, 602 war schon korrekt
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wc/v3/products/601')
            && ($r->data()['shipping_class'] ?? null) === 'on-demand'
            && collect($r->data()['categories'] ?? [])->contains(fn ($c) => $c['id'] === 77));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/wc/v3/products/602'));
        // CPT-Flag wird gesetzt
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wp/v2/schule/900')
            && ($r->data()['versandklasse_on_demand_fur_jedes_produkt_gesetzt'] ?? null) === '1');
    }

    public function test_printify_price_rule_enforces_minimum_margin(): void
    {
        Http::fake([
            'api.printify.com/v1/catalog/blueprints/6/print_providers/27/variants.json' => Http::response([
                'variants' => [
                    ['id' => 1, 'cost' => 1500], ['id' => 2, 'cost' => 1800],
                ],
            ]),
            'api.printify.com/v1/catalog/blueprints/6/print_providers/27/shipping.json' => Http::response([
                'profiles' => [['countries' => ['AT'], 'first_item' => ['cost' => 450]]],
            ]),
        ]);

        $provisioner = app(PrintifyProvisioner::class);
        // (18.00 + 4.50) * 1.10 = 24.75 EUR Mindestpreis
        $tooLow = $provisioner->checkPrice(24.74, 6, 27);
        $this->assertFalse($tooLow['ok']);
        $this->assertSame(2475, $tooLow['min_price_cents']);

        $ok = $provisioner->checkPrice(24.75, 6, 27);
        $this->assertTrue($ok['ok']);
    }
}
