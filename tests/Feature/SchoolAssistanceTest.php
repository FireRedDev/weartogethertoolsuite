<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\OnboardingStatus;
use App\Services\SchoolShop\SchoolOrderStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Die kleinen Helfer rund um einen Schul-Antrag: Bestellzahlen, Folgejahr,
 * vorbefüllter Dokumenten-Export, E-Mail an die Schule, Logo-Prüfung,
 * Bestellseiten-Check und die Datensicherung.
 */
class SchoolAssistanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'ordersuite.woocommerce.store_url' => 'https://shop.example',
            'ordersuite.woocommerce.consumer_key' => 'ck_ro',
            'ordersuite.woocommerce.consumer_secret' => 'cs_ro',
            'schoolshop.wordpress.user' => 'admin',
            'schoolshop.wordpress.password' => 'app-password',
        ]);
    }

    private function onboarding(array $attributes = []): SchoolOnboarding
    {
        return SchoolOnboarding::create([
            'school_name' => 'AHS Testschule',
            'status' => OnboardingStatus::ANGELEGT,
            'source' => 'manuell',
            'delivery_type' => 'collective',
            'contact_name' => 'Frau Muster',
            'contact_email' => 'kontakt@schule.at',
            'window_start' => '2026-01-19',
            'window_end' => '2026-02-09',
            'woo_category_id' => 77,
            'expected_orders' => 50,
            'products' => [[
                'key' => 'schulshirt', 'label' => 'Schulshirt', 'enabled' => true, 'base_price' => 24.99,
                'indiv_surcharge' => 7.99, 'sizes' => ['S', 'M'], 'colors' => ['schwarz', 'navy'],
            ]],
            ...$attributes,
        ]);
    }

    // ------------------------------------------------------------------
    // Bestellzahlen
    // ------------------------------------------------------------------

    private function fakeOrders(array $extra = []): void
    {
        Http::fake([
            ...$extra,
            'shop.example/wp-json/wc/v3/products?*category=77*' => Http::response([
                ['id' => 601, 'name' => 'AHS Testschule Schulshirt'],
            ]),
            'shop.example/wp-json/wc/v3/orders?*product=601*' => Http::response([
                ['id' => 9001, 'line_items' => [['product_id' => 601, 'quantity' => 2]]],
                ['id' => 9002, 'line_items' => [['product_id' => 601, 'quantity' => 3]]],
            ]),
            'shop.example/wp-json/wc/v3/orders*' => Http::response([]),
        ]);
    }

    public function test_order_numbers_are_counted_per_school(): void
    {
        $this->fakeOrders();
        $onboarding = $this->onboarding();

        // Geholt wird nach der Antwort, gelesen wird aus dem Zwischenspeicher —
        // die Antragsseite darf nicht auf eine Abfrage je Produkt warten.
        app(SchoolOrderStats::class)->warm($onboarding);
        $stats = app(SchoolOrderStats::class)->for($onboarding);

        $this->assertSame(2, $stats['orders']);
        $this->assertSame(5, $stats['items']);        // Mengen werden summiert
        $this->assertSame(50, $stats['expected']);
        $this->assertEqualsWithDelta(0.04, $stats['share'], 0.001);
    }

    public function test_order_numbers_appear_on_the_school_page(): void
    {
        $this->fakeOrders();
        $onboarding = $this->onboarding();

        // Erster Aufruf: noch keine Zahlen — sie werden erst NACH der Antwort
        // geholt, damit die Seite nicht auf eine Abfrage je Produkt wartet.
        $this->get("/schulen/{$onboarding->id}")->assertOk();

        // Der Nachlauf des ersten Aufrufs hat sie inzwischen gefüllt.
        $this->get("/schulen/{$onboarding->id}")
            ->assertOk()
            ->assertSee('Bestellungen bisher')
            ->assertSee('Teile bisher');
    }

    public function test_a_school_without_category_has_no_numbers(): void
    {
        $onboarding = $this->onboarding(['woo_category_id' => null, 'status' => OnboardingStatus::IN_BEARBEITUNG]);

        $this->assertNull(app(SchoolOrderStats::class)->for($onboarding));
    }

    public function test_an_unreachable_shop_does_not_break_the_page(): void
    {
        Http::fake(['shop.example/*' => Http::response('kaputt', 500)]);
        $onboarding = $this->onboarding();

        app(SchoolOrderStats::class)->warm($onboarding);
        $this->assertNull(app(SchoolOrderStats::class)->for($onboarding));
        $this->get("/schulen/{$onboarding->id}")->assertOk();
    }

    // ------------------------------------------------------------------
    // Folgejahr
    // ------------------------------------------------------------------

    public function test_duplicating_keeps_the_configuration_but_starts_a_new_window(): void
    {
        $onboarding = $this->onboarding([
            'pods_post_id' => 900,
            'woo_product_ids' => ['schulshirt' => 601],
            'class_list' => '1a,1b',
            'sheet_front_path' => 'presentation-sheets/1/front.png',
            'documents_exported_at' => now(),
            'logo_front_url' => 'https://shop.example/logo.png',
        ]);

        $this->post("/schulen/{$onboarding->id}/folgejahr")->assertRedirect();

        $copy = SchoolOnboarding::where('id', '!=', $onboarding->id)->sole();
        // Übernommen
        $this->assertSame('AHS Testschule', $copy->school_name);
        $this->assertSame($onboarding->products, $copy->products);
        $this->assertSame('https://shop.example/logo.png', $copy->logo_front_url);
        // Neu
        $this->assertSame(OnboardingStatus::IN_BEARBEITUNG, $copy->status);
        $this->assertNull($copy->window_end);
        $this->assertNull($copy->class_list);
        $this->assertNull($copy->woo_category_id);
        $this->assertNull($copy->pods_post_id);
        $this->assertNull($copy->woo_product_ids);
        $this->assertNull($copy->sheet_front_path);
        $this->assertNull($copy->documents_exported_at);
        $this->assertStringContainsString('Antrag #'.$onboarding->id, $copy->notes);
        // Das Original bleibt unangetastet
        $this->assertSame(77, $onboarding->fresh()->woo_category_id);
    }

    // ------------------------------------------------------------------
    // Auftragsdokumente
    // ------------------------------------------------------------------

    public function test_export_form_is_prefilled_from_the_school(): void
    {
        Http::fake(['shop.example/wp-json/wc/v3/products/categories*' => Http::response([
            ['id' => 77, 'name' => 'AHS Testschule', 'count' => 3],
            ['id' => 78, 'name' => 'Andere Schule', 'count' => 1],
        ])]);
        $onboarding = $this->onboarding();

        $response = $this->get(route('shop.form', ['onboarding' => $onboarding->id]));

        $response->assertOk();
        $this->assertStringContainsString('value="77" selected', $response->getContent());
        $this->assertStringContainsString('value="2026-01-19"', $response->getContent());
        $this->assertStringContainsString('value="2026-02-10"', $response->getContent()); // Enddatum einschließlich
        $this->assertStringContainsString('name="onboarding_id" value="'.$onboarding->id.'"', $response->getContent());
    }

    public function test_generating_documents_is_recorded_on_the_school(): void
    {
        $onboarding = $this->onboarding(['status' => OnboardingStatus::ABGESCHLOSSEN]);
        $this->assertNull($onboarding->documents_exported_at);
        $this->fakeOrders();

        $this->post('/shop-export', [
            'category' => 77,
            'statuses' => config('ordersuite.woocommerce.default_statuses'),
            'onboarding_id' => $onboarding->id,
        ]);

        $this->assertNotNull($onboarding->fresh()->documents_exported_at);
    }

    // ------------------------------------------------------------------
    // E-Mail an die Schule
    // ------------------------------------------------------------------

    public function test_school_email_contains_link_period_and_products(): void
    {
        $this->fakeOrders();
        $onboarding = $this->onboarding();

        $response = $this->get("/schulen/{$onboarding->id}");

        $response->assertOk()
            ->assertSee('E-Mail an die Schule')
            ->assertSee('Hallo Frau Muster,')
            ->assertSee('https://wear-together.at/schule/ahs-testschule/')
            ->assertSee('Montag, 19.01.2026 bis Montag, 09.02.2026')
            ->assertSee('Casual T-Shirt');
    }

    // ------------------------------------------------------------------
    // Logo-Prüfung
    // ------------------------------------------------------------------

    public function test_quality_warnings_name_the_two_print_problems(): void
    {
        $logos = app(\App\Services\SchoolShop\LogoManager::class);

        // Zu klein UND nicht freigestellt
        $warnings = $logos->qualityWarnings(UploadedFile::fake()->image('klein.png', 200, 120));
        $this->assertStringContainsString('unscharf', implode(' ', $warnings));
        $this->assertStringContainsString('freigestellt', implode(' ', $warnings));

        // Groß genug, aber JPEG kann keine Transparenz
        $warnings = $logos->qualityWarnings(UploadedFile::fake()->image('gross.jpg', 1200, 1200));
        $this->assertStringNotContainsString('unscharf', implode(' ', $warnings));
        $this->assertStringContainsString('freigestellt', implode(' ', $warnings));
    }

    public function test_a_poor_logo_is_flagged_but_still_stored(): void
    {
        $this->fakeOrders(['shop.example/wp-json/wp/v2/media*' => Http::response(['id' => 1, 'source_url' => 'https://shop.example/l.png'], 201)]);
        $onboarding = $this->onboarding();

        $this->post("/schulen/{$onboarding->id}/logo/front", [
            'logo' => UploadedFile::fake()->image('klein.png', 200, 120),
        ])->assertSessionHasErrors('logo');

        // Hinweis, keine Ablehnung — die Datei liegt trotzdem im Antrag
        $this->assertTrue($onboarding->fresh()->hasUploadedLogo('front'));
    }

    // ------------------------------------------------------------------
    // Bestellseite prüfen
    // ------------------------------------------------------------------

    public function test_shop_page_check_reports_a_missing_page(): void
    {
        Http::fake(['wear-together.at/*' => Http::response('nicht gefunden', 404)]);
        $onboarding = $this->onboarding();

        $this->post("/schulen/{$onboarding->id}/seite-pruefen")->assertRedirect();

        $this->assertFalse(session('shopPageCheck')['ok']);
        $this->assertStringContainsString('404', session('shopPageCheck')['message']);
    }

    public function test_shop_page_check_accepts_a_working_page(): void
    {
        Http::fake(['wear-together.at/*' => Http::response(
            '<html><body><h1>AHS Testschule</h1><button class="add-to-cart">In den Warenkorb</button></body></html>',
        )]);
        $onboarding = $this->onboarding();

        $this->post("/schulen/{$onboarding->id}/seite-pruefen")->assertRedirect();

        $this->assertTrue(session('shopPageCheck')['ok']);
    }

    public function test_shop_page_check_warns_when_no_products_are_visible(): void
    {
        Http::fake(['wear-together.at/*' => Http::response('<html><body><h1>AHS Testschule</h1>Leer</body></html>')]);
        $onboarding = $this->onboarding();

        $this->post("/schulen/{$onboarding->id}/seite-pruefen");

        $this->assertFalse(session('shopPageCheck')['ok']);
        $this->assertStringContainsString('keine bestellbaren Produkte', session('shopPageCheck')['message']);
    }

    // ------------------------------------------------------------------
    // Datensicherung
    // ------------------------------------------------------------------

    public function test_backup_contains_database_and_uploads_but_no_credentials(): void
    {
        Storage::disk('public')->put('school-logos/1/front-abc.png', 'PNGDATA');
        Storage::disk('public')->put('presentation-sheets/1/render/qr.png', 'ZWISCHENSTAND');

        $result = app(\App\Services\BackupCreator::class)->create();

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($result['path']) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertContains('uploads/school-logos/1/front-abc.png', $names);
        $this->assertContains('LIESMICH.txt', $names);
        // Zwischenstände und Zugangsdaten bleiben draußen
        $this->assertNotContains('uploads/presentation-sheets/1/render/qr.png', $names);
        $this->assertEmpty(array_filter($names, fn ($n) => str_contains($n, '.env')));

        @unlink($result['path']);
    }

    public function test_backup_can_be_downloaded_from_the_admin_page(): void
    {
        Http::fake();

        $response = $this->post('/admin-informationen/sicherung');

        $response->assertOk();
        $this->assertStringContainsString('.zip', $response->headers->get('content-disposition'));
    }
}
