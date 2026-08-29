<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\PrintifyProvisioner;
use App\Services\SchoolShop\ShopProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Schullogo je Druck (Frontprint/Backprint) und die daraus abgeleitete
 * Printify-Anlage: Platzierung, Größe und die Beschränkung auf die im
 * Konfigurator gewählten Farben/Größen.
 */
class SchoolLogoAndPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
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

    private function onboarding(array $attributes = []): SchoolOnboarding
    {
        return SchoolOnboarding::create([
            'school_name' => 'AHS Testschule',
            'status' => 'neu',
            'source' => 'manuell',
            'delivery_type' => 'collective',
            'print_areas' => ['Frontprint'],
            'products' => [],
            ...$attributes,
        ]);
    }

    // ------------------------------------------------------------------
    // Logo-Verwaltung
    // ------------------------------------------------------------------

    public function test_form_logo_is_the_default_for_both_prints(): void
    {
        $onboarding = $this->onboarding(['logo_files' => ['https://shop.example/uploads/logo.png']]);

        $this->assertSame('https://shop.example/uploads/logo.png', $onboarding->logoUrl('front'));
        $this->assertSame('https://shop.example/uploads/logo.png', $onboarding->logoUrl('back'));
        $this->assertFalse($onboarding->hasUploadedLogo('front'));
    }

    public function test_upload_stores_logo_locally_and_in_the_wordpress_media_library(): void
    {
        Http::fake(['shop.example/wp-json/wp/v2/media*' => Http::response([
            'id' => 555, 'source_url' => 'https://shop.example/uploads/1-front-logo.png',
        ], 201)]);
        $onboarding = $this->onboarding();

        $this->post("/schulen/{$onboarding->id}/logo/front", [
            'logo' => UploadedFile::fake()->image('schullogo.png', 400, 400),
        ])->assertRedirect(route('schools.show', $onboarding));

        $onboarding->refresh();
        $this->assertTrue($onboarding->hasUploadedLogo('front'));
        Storage::disk('public')->assertExists($onboarding->logoPath('front'));
        // Für Printify/Dynamic Mockups zählt die öffentlich erreichbare Mediathek-Adresse
        $this->assertSame('https://shop.example/uploads/1-front-logo.png', $onboarding->logoUrl('front'));
        // Der Backprint erbt weiterhin nichts Eigenes — er hat keine eigene Datei
        $this->assertFalse($onboarding->hasUploadedLogo('back'));
    }

    public function test_upload_survives_a_failing_media_library_and_reports_it(): void
    {
        Http::fake(['shop.example/wp-json/wp/v2/media*' => Http::response(['message' => 'nope'], 500)]);
        $onboarding = $this->onboarding();

        $response = $this->post("/schulen/{$onboarding->id}/logo/front", [
            'logo' => UploadedFile::fake()->image('schullogo.png'),
        ]);

        $response->assertSessionHasErrors('logo');
        $onboarding->refresh();
        // Lokale Kopie bleibt erhalten, damit die Datei nicht verloren geht
        $this->assertTrue($onboarding->hasUploadedLogo('front'));
        Storage::disk('public')->assertExists($onboarding->logoPath('front'));
    }

    public function test_uploaded_logo_can_be_previewed_downloaded_and_replaced(): void
    {
        Http::fake(['shop.example/wp-json/wp/v2/media*' => Http::response(['id' => 1, 'source_url' => 'https://shop.example/a.png'], 201)]);
        $onboarding = $this->onboarding();
        $this->post("/schulen/{$onboarding->id}/logo/back", ['logo' => UploadedFile::fake()->image('erstes.png')]);
        $firstPath = $onboarding->fresh()->logoPath('back');

        // Vorschau + Download über dieselbe Route (Download nur mit ?download=1)
        $this->get(route('schools.logo.show', [$onboarding, 'back']))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="'.basename($firstPath).'"');
        $this->get(route('schools.logo.show', [$onboarding, 'back', 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="'.basename($firstPath).'"');

        // Austauschen: alte Datei wird aufgeräumt
        $this->post("/schulen/{$onboarding->id}/logo/back", ['logo' => UploadedFile::fake()->image('zweites.png')]);
        $secondPath = $onboarding->fresh()->logoPath('back');
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_reset_falls_back_to_the_form_logo(): void
    {
        Http::fake(['shop.example/wp-json/wp/v2/media*' => Http::response(['id' => 1, 'source_url' => 'https://shop.example/eigen.png'], 201)]);
        $onboarding = $this->onboarding(['logo_files' => ['https://shop.example/uploads/formular.png']]);
        $this->post("/schulen/{$onboarding->id}/logo/front", ['logo' => UploadedFile::fake()->image('eigen.png')]);
        $this->assertSame('https://shop.example/eigen.png', $onboarding->fresh()->logoUrl('front'));

        $this->delete("/schulen/{$onboarding->id}/logo/front")->assertRedirect();

        $onboarding->refresh();
        $this->assertFalse($onboarding->hasUploadedLogo('front'));
        $this->assertSame('https://shop.example/uploads/formular.png', $onboarding->logoUrl('front'));
    }

    public function test_only_pixel_formats_are_accepted(): void
    {
        $onboarding = $this->onboarding();

        $this->post("/schulen/{$onboarding->id}/logo/front", [
            'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('logo');

        $this->assertFalse($onboarding->fresh()->hasUploadedLogo('front'));
    }

    public function test_unknown_print_slot_is_rejected(): void
    {
        $onboarding = $this->onboarding();

        $this->post("/schulen/{$onboarding->id}/logo/seite", ['logo' => UploadedFile::fake()->image('x.png')])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Druck-Auswahl und Platzierung
    // ------------------------------------------------------------------

    public function test_print_slots_default_to_the_form_wish_until_saved_explicitly(): void
    {
        $onboarding = $this->onboarding(['print_areas' => ['Backprint']]);
        $this->assertFalse($onboarding->prints('front'));
        $this->assertTrue($onboarding->prints('back'));

        // Speichern ohne den Logo-Bereich lässt die Drucke unangetastet …
        $this->put("/schulen/{$onboarding->id}", [
            'school_name' => $onboarding->school_name,
            'delivery_type' => 'collective',
            'status' => 'in_bearbeitung',
        ])->assertRedirect();
        $this->assertTrue($onboarding->fresh()->prints('back'));

        // … mit Marker zählt dagegen genau das, was angehakt ist.
        $this->put("/schulen/{$onboarding->id}", [
            'school_name' => $onboarding->school_name,
            'delivery_type' => 'collective',
            'status' => 'in_bearbeitung',
            'print_slots_submitted' => '1',
            'print_front' => '1',
            'logo_front_position' => 'unten_rechts',
            'logo_front_size' => 'mittel',
        ])->assertRedirect();

        $onboarding->refresh();
        $this->assertTrue($onboarding->prints('front'));
        $this->assertFalse($onboarding->prints('back'));
        $this->assertSame(['front'], $onboarding->activePrintSlots());
        $this->assertSame(['x' => 0.73, 'y' => 0.78, 'width' => 0.50], $onboarding->logoPlacement('front'));
    }

    // ------------------------------------------------------------------
    // Printify: Varianten, Drucke, Platzierung
    // ------------------------------------------------------------------

    /** Variantenkatalog mit 3 Farben × 3 Größen = 9 Varianten. */
    private function fakePrintifyCatalog(): void
    {
        $variants = [];
        $id = 100;
        foreach ([['White', 1500], ['Burgundy', 1800], ['Olive', 9900]] as [$color, $cost]) {
            foreach (['S', 'M', '2XL'] as $size) {
                $variants[] = [
                    'id' => ++$id,
                    'title' => "{$color} / {$size}",
                    'options' => ['color' => $color, 'size' => $size],
                    'cost' => $cost,
                ];
            }
        }

        Http::fake([
            'shop.example/wp-json/wc/v3/products/categories?*search=Schulen*' => Http::response([['id' => 15, 'name' => 'Schulen', 'parent' => 0]]),
            'shop.example/wp-json/wc/v3/products/categories?*' => Http::response(['id' => 77, 'name' => 'AHS Testschule', 'parent' => 15], 201),
            'shop.example/wp-json/wc/v3/products/shipping_classes*' => Http::response([['id' => 9, 'slug' => 'on-demand']]),
            'shop.example/wp-json/wp/v2/schule*' => Http::response(['id' => 900], 201),
            'shop.example/wp-json/wp/v2/media*' => Http::response(['id' => 555, 'source_url' => 'https://shop.example/l.png'], 201),
            'shop.example/uploads/*' => Http::response('img', 200, ['Content-Type' => 'image/png']),
            'api.printify.com/v1/catalog/print_providers/26.json' => Http::response([
                'id' => 26, 'title' => 'Textildruck Europa', 'location' => ['country' => 'DE'],
            ]),
            'api.printify.com/v1/catalog/blueprints/92/print_providers/26/variants.json' => Http::response(['variants' => $variants]),
            'api.printify.com/v1/catalog/blueprints/92/print_providers/26/shipping.json' => Http::response([
                'profiles' => [['countries' => ['AT'], 'first_item' => ['cost' => 400]]],
            ]),
            'api.printify.com/v1/uploads/images.json' => Http::sequence()
                ->push(['id' => 'img-front'])->push(['id' => 'img-back'])
                ->whenEmpty(Http::response(['id' => 'img-front'])),
            'api.printify.com/v1/shops/99/products.json' => Http::response(['id' => 'pfy-1'], 200),
            'api.printify.com/v1/shops/99/products/pfy-1/publish.json' => Http::response(['ok' => true], 200),
        ]);
    }

    private function ondemandOnboarding(array $product = [], array $attributes = []): SchoolOnboarding
    {
        return $this->onboarding([
            'delivery_type' => 'ondemand',
            'logo_files' => ['https://shop.example/uploads/logo.png'],
            'products' => [[
                'key' => 'schulpullover',
                'label' => 'Schulpullover',
                'name_suffix' => 'Schulpullover',
                'enabled' => true,
                'base_price' => 39.99,
                'indiv_surcharge' => 0.0,
                'sizes' => ['S', 'M', 'XXL'],
                'colors' => ['weiß', 'burgundy'],
                'printify_blueprint_id' => 92,
                'printify_provider_id' => 26,
                ...$product,
            ]],
            ...$attributes,
        ]);
    }

    public function test_only_variants_in_the_chosen_colors_and_sizes_are_created(): void
    {
        $this->fakePrintifyCatalog();
        $onboarding = $this->ondemandOnboarding();

        app(ShopProvisioner::class)->apply($onboarding);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/shops/99/products.json')) {
                return false;
            }
            $ids = array_column($r->data()['variants'] ?? [], 'id');
            sort($ids);

            // Weiß + Burgundy in S/M/2XL = 6 Varianten; Olive bleibt außen vor.
            return $ids === [101, 102, 103, 104, 105, 106];
        });
    }

    public function test_missing_colors_are_reported_in_the_log(): void
    {
        $this->fakePrintifyCatalog();
        $onboarding = $this->ondemandOnboarding(['colors' => ['weiß', 'neonpink']]);

        $log = app(ShopProvisioner::class)->apply($onboarding);

        $this->assertTrue(collect($log)->contains(
            fn ($l) => str_contains($l['detail'], 'ausgelassen (Farben): neonpink'),
        ));
    }

    public function test_provisioning_aborts_with_available_colors_when_nothing_matches(): void
    {
        $this->fakePrintifyCatalog();
        $onboarding = $this->ondemandOnboarding(['colors' => ['neonpink']]);

        $this->post("/schulen/{$onboarding->id}/anlegen")->assertRedirect();

        $failure = collect(session('provisionLog'))->firstWhere('ok', false);
        $this->assertStringContainsString('Keine passende Printify-Variante', $failure['detail']);
        $this->assertStringContainsString('Verfügbare Farben: White, Burgundy, Olive', $failure['detail']);
    }

    public function test_variant_count_is_capped_at_the_printify_limit(): void
    {
        config(['schoolshop.printify.max_variants' => 4]);
        $this->fakePrintifyCatalog();
        $onboarding = $this->ondemandOnboarding();

        $log = app(ShopProvisioner::class)->apply($onboarding);

        Http::assertSent(fn ($r) => ! str_contains($r->url(), '/shops/99/products.json')
            || count($r->data()['variants'] ?? []) === 4);
        $this->assertTrue(collect($log)->contains(fn ($l) => str_contains($l['detail'], 'max. 4')));
    }

    public function test_both_prints_are_placed_with_their_own_position_and_size(): void
    {
        $this->fakePrintifyCatalog();
        $onboarding = $this->ondemandOnboarding(attributes: [
            'print_areas' => ['Frontprint', 'Backprint'],
            'logo_front_position' => 'oben_rechts',
            'logo_front_size' => 'klein',
            'logo_back_position' => 'mitte',
            'logo_back_size' => 'gross',
        ]);

        app(ShopProvisioner::class)->apply($onboarding);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/shops/99/products.json')) {
                return false;
            }
            $placeholders = $r->data()['print_areas'][0]['placeholders'] ?? [];
            $byPosition = collect($placeholders)->keyBy('position');

            return $byPosition->count() === 2
                && $byPosition['front']['images'][0]['x'] === 0.73
                && $byPosition['front']['images'][0]['y'] === 0.22
                && $byPosition['front']['images'][0]['scale'] === 0.25
                && $byPosition['back']['images'][0]['x'] === 0.50
                && $byPosition['back']['images'][0]['scale'] === 0.90;
        });
    }

    public function test_deactivated_backprint_is_not_sent_to_printify(): void
    {
        $this->fakePrintifyCatalog();
        $onboarding = $this->ondemandOnboarding(attributes: [
            'print_areas' => ['Frontprint', 'Backprint'],
            'print_back' => false,
        ]);

        app(ShopProvisioner::class)->apply($onboarding);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/shops/99/products.json')) {
                return false;
            }
            $placeholders = $r->data()['print_areas'][0]['placeholders'] ?? [];

            return count($placeholders) === 1 && $placeholders[0]['position'] === 'front';
        });
    }

    public function test_provisioning_aborts_when_no_print_is_active(): void
    {
        $this->fakePrintifyCatalog();
        $onboarding = $this->ondemandOnboarding(attributes: ['print_front' => false, 'print_back' => false]);

        $this->post("/schulen/{$onboarding->id}/anlegen")->assertRedirect();

        $failure = collect(session('provisionLog'))->firstWhere('ok', false);
        $this->assertStringContainsString('Weder Frontprint noch Backprint', $failure['detail']);
    }

    public function test_economics_uses_only_the_selected_variants(): void
    {
        $this->fakePrintifyCatalog();
        $onboarding = $this->ondemandOnboarding();

        $economics = app(PrintifyProvisioner::class)->economics($onboarding->products[0]);

        $this->assertEquals(15.0, $economics['cost_min_eur']);
        $this->assertEquals(18.0, $economics['cost_max_eur']); // Olive (99,00) zählt nicht mit
        $this->assertEquals(4.0, $economics['shipping_eur']);
        $this->assertSame(6, $economics['variant_selected']);
        $this->assertSame(9, $economics['variant_total']);
        $this->assertTrue($economics['margin_ok']);
        // (18,00 + 4,00) -> Marge bei 39,99 = 81,8 %
        $this->assertEqualsWithDelta(81.8, $economics['margin_pct'], 0.1);
    }
}
