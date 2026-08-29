<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\OnboardingStatus;
use App\Services\SchoolShop\OrderWindowExtender;
use App\Services\SchoolShop\ShopProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Der Lebenszyklus eines Bestellfensters: Status bedeuten eine tatsächlich
 * ausgeführte Handlung, abgelaufene Fenster bekommen eine einmalige Nachfrist,
 * und ein geschlossenes Fenster lässt sich wieder öffnen.
 */
class OrderWindowWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ordersuite.woocommerce.store_url' => 'https://shop.example',
            'schoolshop.woocommerce_write.consumer_key' => 'ck_rw',
            'schoolshop.woocommerce_write.consumer_secret' => 'cs_rw',
            'schoolshop.wordpress.user' => 'admin',
            'schoolshop.wordpress.password' => 'app-password',
        ]);
    }

    private function onboarding(array $attributes = []): SchoolOnboarding
    {
        return SchoolOnboarding::create([
            'school_name' => 'AHS Testschule',
            'status' => OnboardingStatus::IN_BEARBEITUNG,
            'source' => 'manuell',
            'delivery_type' => 'collective',
            'products' => [],
            ...$attributes,
        ]);
    }

    private function save(SchoolOnboarding $onboarding, array $fields = []): \Illuminate\Testing\TestResponse
    {
        return $this->put("/schulen/{$onboarding->id}", [
            'school_name' => $onboarding->school_name,
            'delivery_type' => $onboarding->delivery_type,
            'status' => $onboarding->status,
            ...$fields,
        ]);
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    public function test_every_status_is_explained_in_the_form(): void
    {
        $onboarding = $this->onboarding();

        $response = $this->get("/schulen/{$onboarding->id}");

        $response->assertOk();
        $response->assertSee('Der Konfigurator wird befüllt');   // Erklärung des aktuellen Status
        // Hinweis, dass „Im Shop angelegt" nur durch die Aktion entsteht
        $response->assertSee('Im Shop angelegt');
        $response->assertSee('durchgelaufen ist');
    }

    /** „Im Shop angelegt" darf man nicht behaupten — es entsteht durch die Anlage. */
    public function test_provisioned_status_cannot_be_set_by_hand(): void
    {
        $onboarding = $this->onboarding();

        $this->save($onboarding, ['status' => OnboardingStatus::ANGELEGT])
            ->assertSessionHasErrors('status');

        $this->assertSame(OnboardingStatus::IN_BEARBEITUNG, $onboarding->fresh()->status);
    }

    public function test_closed_status_cannot_be_set_by_hand_once_a_shop_exists(): void
    {
        $onboarding = $this->onboarding(['woo_category_id' => 77]);

        $this->save($onboarding, ['status' => OnboardingStatus::ABGESCHLOSSEN])
            ->assertSessionHasErrors('status');

        // Ohne Shop-Anlage darf ein Antrag dagegen abgehakt werden (Absage/Dublette)
        $ohneShop = $this->onboarding(['school_name' => 'BG Absage']);
        $this->save($ohneShop, ['status' => OnboardingStatus::ABGESCHLOSSEN])->assertSessionHasNoErrors();
        $this->assertSame(OnboardingStatus::ABGESCHLOSSEN, $ohneShop->fresh()->status);
    }

    public function test_a_provisioned_school_can_go_back_into_editing(): void
    {
        $onboarding = $this->onboarding(['status' => OnboardingStatus::ANGELEGT, 'woo_category_id' => 77]);

        $this->save($onboarding, ['status' => OnboardingStatus::IN_BEARBEITUNG])->assertSessionHasNoErrors();

        $this->assertSame(OnboardingStatus::IN_BEARBEITUNG, $onboarding->fresh()->status);
        // Danach führt der Weg zurück wieder nur über die tatsächliche Anlage
        $this->assertArrayNotHasKey(OnboardingStatus::ANGELEGT, OnboardingStatus::manualOptions($onboarding->fresh()));
    }

    public function test_a_closed_window_offers_no_manual_way_back(): void
    {
        $onboarding = $this->onboarding(['status' => OnboardingStatus::ABGESCHLOSSEN, 'woo_category_id' => 77]);

        $this->assertSame([OnboardingStatus::ABGESCHLOSSEN], array_keys(OnboardingStatus::manualOptions($onboarding)));
        $this->save($onboarding, ['status' => OnboardingStatus::ANGELEGT])->assertSessionHasErrors('status');
    }

    // ------------------------------------------------------------------
    // Automatische Nachfrist
    // ------------------------------------------------------------------

    public function test_expired_window_is_extended_once_and_pushed_to_wordpress(): void
    {
        Http::fake(['shop.example/wp-json/wp/v2/schule/900' => Http::response(['id' => 900], 200)]);
        $onboarding = $this->onboarding([
            'status' => OnboardingStatus::ANGELEGT,
            'window_start' => now()->subMonth(),
            'window_end' => now()->subDays(2),
            'pods_post_id' => 900,
            'auto_extend' => true,
            'auto_extend_days' => 10,
        ]);

        $log = app(OrderWindowExtender::class)->runDue();

        $this->assertCount(1, $log);
        $this->assertTrue($log[0]['ok']);
        $onboarding->refresh();
        $this->assertSame(now()->addDays(10)->toDateString(), $onboarding->window_end->toDateString());
        $this->assertNotNull($onboarding->auto_extended_at);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wp/v2/schule/900')
            && str_starts_with($r->data()['bestellfensterende'] ?? '', now()->addDays(10)->format('Y-m-d')));

        // Ein zweites Mal passiert nichts — sonst schlösse sich das Fenster nie
        $this->assertCount(0, app(OrderWindowExtender::class)->runDue());
    }

    public function test_extension_only_happens_after_the_window_expired(): void
    {
        $this->onboarding([
            'status' => OnboardingStatus::ANGELEGT,
            'window_start' => now()->subWeek(),
            'window_end' => now()->addDay(),
            'auto_extend' => true,
        ]);

        $this->assertCount(0, app(OrderWindowExtender::class)->due());
    }

    public function test_extension_can_be_switched_off_and_the_duration_set(): void
    {
        $onboarding = $this->onboarding(['status' => OnboardingStatus::ANGELEGT]);

        $this->save($onboarding, ['auto_extend' => '1', 'auto_extend_days' => '14'])->assertSessionHasNoErrors();
        $this->assertTrue($onboarding->fresh()->auto_extend);
        $this->assertSame(14, $onboarding->fresh()->auto_extend_days);

        // Häkchen weg = keine automatische Verlängerung
        $this->save($onboarding->fresh())->assertSessionHasNoErrors();
        $this->assertFalse($onboarding->fresh()->auto_extend);
    }

    public function test_ondemand_windows_are_never_extended(): void
    {
        $this->onboarding([
            'delivery_type' => 'ondemand',
            'status' => OnboardingStatus::ANGELEGT,
            'window_end' => now()->subWeek(),
            'auto_extend' => true,
        ]);

        $this->assertCount(0, app(OrderWindowExtender::class)->due());
    }

    public function test_changing_the_end_date_by_hand_frees_the_extension_again(): void
    {
        $onboarding = $this->onboarding([
            'status' => OnboardingStatus::ANGELEGT,
            'window_start' => now()->subMonth(),
            'window_end' => now()->subDay(),
            'auto_extend' => true,
        ]);
        app(OrderWindowExtender::class)->runDue();
        $this->assertNotNull($onboarding->fresh()->auto_extended_at);

        $this->save($onboarding->fresh(), [
            'window_start' => now()->subMonth()->toDateString(),
            'window_end' => now()->addMonth()->toDateString(),
            'auto_extend' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertNull($onboarding->fresh()->auto_extended_at);
    }

    public function test_command_reports_what_would_be_extended(): void
    {
        $this->onboarding([
            'school_name' => 'BG Nachfrist',
            'status' => OnboardingStatus::ANGELEGT,
            'window_start' => now()->subMonth(),
            'window_end' => now()->subDay(),
            'auto_extend' => true,
        ]);

        $this->artisan('windows:extend --dry-run')
            ->expectsOutputToContain('BG Nachfrist')
            ->assertSuccessful();

        // Testlauf ändert nichts
        $this->assertCount(1, app(OrderWindowExtender::class)->due());
    }

    // ------------------------------------------------------------------
    // Wieder öffnen
    // ------------------------------------------------------------------

    public function test_a_closed_window_can_be_reopened(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products?*' => Http::response([
                ['id' => 601, 'name' => 'AHS Testschule Schulpullover', 'status' => 'private', 'catalog_visibility' => 'hidden'],
            ]),
            'shop.example/wp-json/wc/v3/products/601*' => Http::response(['id' => 601], 200),
            'shop.example/wp-json/wp/v2/schule/900' => Http::response(['id' => 900], 200),
        ]);
        $onboarding = $this->onboarding([
            'status' => OnboardingStatus::ABGESCHLOSSEN,
            'woo_category_id' => 77,
            'pods_post_id' => 900,
            'window_start' => now()->subMonth(),
            'window_end' => now()->subWeek(),
            'auto_extended_at' => now()->subWeek(),
        ]);
        $newEnd = now()->addDays(10);

        $this->post("/bestellfenster-oeffnen/{$onboarding->id}", ['new_end' => $newEnd->toDateString()])
            ->assertRedirect(route('close-window.index'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/wc/v3/products/601')
            && ($r->data()['status'] ?? null) === 'publish'
            && ($r->data()['catalog_visibility'] ?? null) === 'visible');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wp/v2/schule/900')
            && ($r->data()['bestellfenster_offen'] ?? null) === 'JA');

        $onboarding->refresh();
        $this->assertSame(OnboardingStatus::ANGELEGT, $onboarding->status);
        $this->assertSame($newEnd->toDateString(), $onboarding->window_end->toDateString());
        // Neues Fenster, neue Nachfrist
        $this->assertNull($onboarding->auto_extended_at);
    }

    public function test_reopening_needs_a_future_end_date(): void
    {
        $onboarding = $this->onboarding(['status' => OnboardingStatus::ABGESCHLOSSEN, 'woo_category_id' => 77]);

        $this->post("/bestellfenster-oeffnen/{$onboarding->id}", ['new_end' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('new_end');
    }

    public function test_only_closed_schools_are_offered_for_reopening(): void
    {
        $this->onboarding(['school_name' => 'BG Offen', 'status' => OnboardingStatus::ANGELEGT, 'woo_category_id' => 77]);
        $this->onboarding(['school_name' => 'BG Zu', 'status' => OnboardingStatus::ABGESCHLOSSEN, 'woo_category_id' => 78]);

        $response = $this->get('/bestellfenster-schliessen');

        $response->assertOk()->assertSee('Bestellfenster wieder öffnen');
        // Im Öffnen-Auswahlfeld steht nur die geschlossene Schule
        $this->assertStringContainsString('BG Zu (Ende war', $response->getContent());
        $this->assertStringNotContainsString('BG Offen (Ende war', $response->getContent());
    }
}
