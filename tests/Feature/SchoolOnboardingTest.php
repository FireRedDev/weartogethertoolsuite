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
            'shop.example/wp-json/wp/v2/schule*' => Http::response(['id' => 900], 201),
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

        // Produkt-Anlage: PIF-Metas, Kategorie, Attribute inkl. Klassen aus der Klassenliste
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/wc/v3/products?') || $request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();
            $metaKeys = array_column($body['meta_data'] ?? [], 'key');
            $klasse = collect($body['attributes'] ?? [])->firstWhere('id', 3);

            return $body['type'] === 'variable'
                && $body['categories'] === [['id' => 77]]
                && in_array('_alg_wc_pif_enabled_local_1', $metaKeys, true)
                && in_array('1a', $klasse['options'] ?? [], true)
                && in_array('LehrerIn', array_map('trim', $klasse['options'] ?? []), true);
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
