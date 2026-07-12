<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\ShopProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MockupGenerationTest extends TestCase
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
            'schoolshop.mockups.api_key' => 'dm_test_key',
            // Vorlagen-Pool: 2 Frauen + 1 Mann (Lifestyle), Detail burgundy + grün
            'schoolshop.mockups.templates.schulpullover' => [
                'lifestyle' => [
                    ['mockup_uuid' => 'mock-f1', 'smart_object_uuid' => 'so-f1', 'model' => 'female'],
                    ['mockup_uuid' => 'mock-f2', 'smart_object_uuid' => 'so-f2', 'model' => 'female'],
                    ['mockup_uuid' => 'mock-m1', 'smart_object_uuid' => 'so-m1', 'model' => 'male'],
                ],
                'detail' => [
                    ['mockup_uuid' => 'mock-d1', 'smart_object_uuid' => 'so-d1', 'color' => 'burgundy'],
                    ['mockup_uuid' => 'mock-d2', 'smart_object_uuid' => 'so-d2', 'color' => 'grün'],
                ],
            ],
        ]);
    }

    /** Standard-Fakes für die komplette Sammelbestellfenster-Anlage + Dynamic Mockups. */
    private function fakeProvisioningApis(int $renderStatus = 200): void
    {
        Http::fake([
            // Dynamic Mockups
            'app.dynamicmockups.com/api/v1/mockups/*' => Http::response(['data' => [
                'uuid' => 'any', 'smart_objects' => [
                    ['uuid' => 'so-f1', 'size' => ['width' => 1000, 'height' => 1200]],
                    ['uuid' => 'so-f2', 'size' => ['width' => 1000, 'height' => 1200]],
                    ['uuid' => 'so-m1', 'size' => ['width' => 1000, 'height' => 1200]],
                    ['uuid' => 'so-d1', 'size' => ['width' => 1000, 'height' => 1200]],
                ],
            ]]),
            'app.dynamicmockups.com/api/v1/renders' => $renderStatus === 200
                ? Http::response(['data' => ['export_path' => 'https://cdn.dm.example/render.jpg']])
                : Http::response(['message' => 'render failed'], $renderStatus),
            // WooCommerce + WordPress (wie test_full_provisioning...)
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
            'shop.example/wp-json/wc/v3/products/401*' => Http::response(['id' => 401], 200),
            'shop.example/wp-json/wc/v3/products?*' => Http::response(['id' => 401, 'name' => 'AHS Testschule Schulpullover'], 201),
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
    }

    /** Onboarding per Webhook anlegen, nur Schulpullover aktiv, Mockups einschalten. */
    private function onboardingWithMockups(bool $enabled = true): SchoolOnboarding
    {
        $payload = [
            'input_text_6' => 'AHS Testschule',
            'email' => 'x@y.at',
            'input_radio_7' => 'Sammelbestellung online',
            'multi_select_4' => ['Hoodie'],
            'multi_select_3' => ['Burgundy', 'Weiß'],
            'multi_select' => ['Frontprint'],
            'file-upload_1' => ['https://shop.example/uploads/logo_ahs.png'],
            'description_3' => '1a,1b',
            'datetime' => '16.04.2026',
        ];
        $this->postJson('/webhooks/fluentforms/test-secret', $payload)->assertOk();
        $onboarding = SchoolOnboarding::sole();
        $onboarding->forceFill(['mockups_enabled' => $enabled, 'mockup_placement' => 'brust_links'])->save();

        return $onboarding->fresh();
    }

    public function test_configurator_saves_mockup_settings_default_off(): void
    {
        $onboarding = $this->onboardingWithMockups(false);
        $this->assertFalse($onboarding->mockups_enabled); // Standard AUS

        $this->put("/schulen/{$onboarding->id}", [
            'school_name' => $onboarding->school_name,
            'delivery_type' => 'collective',
            'status' => $onboarding->status,
            'mockups_enabled' => '1',
            'mockup_placement' => 'mitte_voll',
        ])->assertRedirect();
        $onboarding->refresh();
        $this->assertTrue($onboarding->mockups_enabled);
        $this->assertSame('mitte_voll', $onboarding->mockup_placement);

        // Checkbox weggelassen => wieder AUS
        $this->put("/schulen/{$onboarding->id}", [
            'school_name' => $onboarding->school_name,
            'delivery_type' => 'collective',
            'status' => $onboarding->status,
        ])->assertRedirect();
        $this->assertFalse($onboarding->fresh()->mockups_enabled);
    }

    public function test_disabled_mockups_render_nothing(): void
    {
        $this->fakeProvisioningApis();
        $onboarding = $this->onboardingWithMockups(false);

        app(ShopProvisioner::class)->apply($onboarding);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'dynamicmockups.com'));
    }

    public function test_mockups_rendered_and_set_as_product_images(): void
    {
        $this->fakeProvisioningApis();
        $onboarding = $this->onboardingWithMockups();

        $log = app(ShopProvisioner::class)->apply($onboarding);

        // 3 Renders: 1 Frau + 1 Mann (Lifestyle) + Detail burgundy (grün ist nicht Schulfarbe)
        $this->assertCount(3, Http::recorded(fn ($req) => str_contains($req->url(), '/api/v1/renders')));

        // Platzierung brust_links auf 1000x1200-Druckbereich: Box 280, left 130, top 124
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/api/v1/renders')) {
                return false;
            }
            $asset = $r->data()['smart_objects'][0]['asset'] ?? [];

            return ($asset['size']['width'] ?? null) === 280
                && ($asset['position']['left'] ?? null) === 130
                && ($asset['position']['top'] ?? null) === 124
                && ($asset['fit'] ?? null) === 'contain';
        });

        // Produktbilder gesetzt: PUT products/401 mit 3 images (src = Render-URLs)
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/wc/v3/products/401') || $r->method() !== 'PUT') {
                return false;
            }
            $images = $r->data()['images'] ?? [];

            return count($images) === 3
                && collect($images)->every(fn ($i) => $i['src'] === 'https://cdn.dm.example/render.jpg');
        });

        $onboarding->refresh();
        $this->assertArrayHasKey('schulpullover', $onboarding->mockup_images);
        $this->assertCount(3, $onboarding->mockup_images['schulpullover']);
        $this->assertTrue(collect($log)->contains(fn ($l) => str_starts_with($l['step'], 'Mockups schulpullover') && $l['ok']));
    }

    public function test_render_failure_does_not_abort_provisioning(): void
    {
        $this->fakeProvisioningApis(renderStatus: 500);
        $onboarding = $this->onboardingWithMockups();

        $log = app(ShopProvisioner::class)->apply($onboarding);

        $onboarding->refresh();
        // Produkte + CPT trotzdem angelegt
        $this->assertSame(['schulpullover' => 401], $onboarding->woo_product_ids);
        $this->assertSame('angelegt', $onboarding->status);
        // Fehler im Protokoll sichtbar, aber kein Abbruch
        $this->assertTrue(collect($log)->contains(fn ($l) => str_starts_with($l['step'], 'Mockups schulpullover') && ! $l['ok']));
    }

    public function test_rerun_skips_already_rendered_products(): void
    {
        $this->fakeProvisioningApis();
        $onboarding = $this->onboardingWithMockups();

        app(ShopProvisioner::class)->apply($onboarding);
        app(ShopProvisioner::class)->apply($onboarding->fresh());

        // Keine doppelten Credits: Renders nur aus dem ersten Lauf
        $this->assertCount(3, Http::recorded(fn ($req) => str_contains($req->url(), '/api/v1/renders')));
    }

    public function test_products_without_templates_are_skipped_with_note(): void
    {
        config(['schoolshop.mockups.templates.schulpullover' => ['lifestyle' => [], 'detail' => []]]);
        $this->fakeProvisioningApis();
        $onboarding = $this->onboardingWithMockups();

        $log = app(ShopProvisioner::class)->apply($onboarding);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/v1/renders'));
        $this->assertTrue(collect($log)->contains(fn ($l) => str_starts_with($l['step'], 'Mockups schulpullover')
            && $l['ok'] && str_contains($l['detail'], 'keine Vorlagen')));
    }
}
