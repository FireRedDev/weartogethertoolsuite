<?php

namespace Tests\Feature;

use App\Models\IntegrationStatus;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'schoolshop.webhook_secret' => 'test-secret',
            'ordersuite.woocommerce.store_url' => 'https://shop.example',
            'ordersuite.woocommerce.consumer_key' => 'ck_ro',
            'ordersuite.woocommerce.consumer_secret' => 'cs_ro',
            'schoolshop.woocommerce_write.consumer_key' => 'ck_rw',
            'schoolshop.woocommerce_write.consumer_secret' => 'cs_rw',
            'schoolshop.wordpress.user' => 'admin',
            'schoolshop.wordpress.password' => 'app-password',
            'schoolshop.printify.api_token' => 'pfy_token',
            'schoolshop.printify.shop_id' => '99',
            'schoolshop.mockups.api_key' => '', // absichtlich nicht konfiguriert
        ]);
    }

    private function fakeAllOk(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/orders*' => Http::response([]),
            'shop.example/wp-json/wc/v3/products/shipping_classes*' => Http::response([]),
            'shop.example/wp-json/wp/v2/schule*' => Http::response([]),
            'api.printify.com/v1/shops.json' => Http::response([]),
            'shop.example/wp-json/weartogether/v1/notify' => Http::response(['ok' => true, 'to' => 'admin@shop.example']),
        ]);
    }

    public function test_status_page_shows_all_interfaces_and_marks_unconfigured_ones(): void
    {
        $this->fakeAllOk();

        $response = $this->get('/admin-informationen');

        $response->assertOk()
            ->assertSee('Admin-Informationen')
            ->assertSee('WooCommerce – Lesezugriff')
            ->assertSee('WooCommerce – Schreibzugriff')
            ->assertSee('WordPress')
            ->assertSee('Printify')
            ->assertSee('Dynamic Mockups')
            ->assertSee('FluentForms-Webhook')
            ->assertSee('nicht eingerichtet'); // Dynamic Mockups hat keinen Key
    }

    public function test_nav_link_present_on_every_page(): void
    {
        $this->fakeAllOk();
        $this->get('/')->assertSee('Admin-Informationen');
    }

    public function test_all_configured_interfaces_ok_persists_status(): void
    {
        $this->fakeAllOk();

        $this->get('/admin-informationen')->assertOk()->assertSee('✓ OK');

        $wooRead = IntegrationStatus::where('key', 'woocommerce_read')->sole();
        $this->assertTrue($wooRead->ok);
        $this->assertTrue($wooRead->configured);
        $this->assertNull($wooRead->notified_at);
    }

    public function test_failing_interface_triggers_one_time_notification_via_wordpress(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/orders*' => Http::response(['message' => 'invalid_key'], 401),
            'shop.example/wp-json/wc/v3/products/shipping_classes*' => Http::response([]),
            'shop.example/wp-json/wp/v2/schule*' => Http::response([]),
            'api.printify.com/v1/shops.json' => Http::response([]),
            'shop.example/wp-json/weartogether/v1/notify' => Http::response(['ok' => true, 'to' => 'admin@shop.example']),
        ]);

        // 1. Aufruf: WooCommerce-Read schlägt fehl -> Benachrichtigung wird ausgelöst
        $this->get('/admin-informationen')->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/wp-json/weartogether/v1/notify')
            && str_contains($r->data()['subject'] ?? '', 'WooCommerce – Lesezugriff'));

        $status = IntegrationStatus::where('key', 'woocommerce_read')->sole();
        $this->assertFalse($status->ok);
        $this->assertNotNull($status->notified_at);

        // 2. Aufruf (weiterhin defekt): KEINE zweite Benachrichtigung
        Http::fake([
            'shop.example/wp-json/wc/v3/orders*' => Http::response(['message' => 'invalid_key'], 401),
            'shop.example/wp-json/wc/v3/products/shipping_classes*' => Http::response([]),
            'shop.example/wp-json/wp/v2/schule*' => Http::response([]),
            'api.printify.com/v1/shops.json' => Http::response([]),
            'shop.example/wp-json/weartogether/v1/notify' => Http::response(['ok' => true]),
        ]);
        $this->get('/admin-informationen')->assertOk();
        $this->assertCount(0, Http::recorded(fn ($r) => str_contains($r->url(), '/wp-json/weartogether/v1/notify')));
    }

    public function test_recovery_then_new_failure_notifies_again(): void
    {
        // Http::fake() überschreibt eine bereits registrierte URL innerhalb desselben
        // Tests NICHT (erste Registrierung gewinnt) — für einen sich über mehrere
        // Aufrufe ändernden Endpunkt daher eine Sequence verwenden.
        Http::fake([
            'shop.example/wp-json/wc/v3/orders*' => Http::sequence()
                ->push(['message' => 'down'], 500)      // 1. Aufruf: defekt
                ->push([], 200)                          // 2. Aufruf: erholt
                ->push(['message' => 'down again'], 500), // 3. Aufruf: erneut defekt
            'shop.example/wp-json/wc/v3/products/shipping_classes*' => Http::response([]),
            'shop.example/wp-json/wp/v2/schule*' => Http::response([]),
            'api.printify.com/v1/shops.json' => Http::response([]),
            'shop.example/wp-json/weartogether/v1/notify' => Http::response(['ok' => true]),
        ]);

        // 1. Aufruf: defekt -> Benachrichtigung 1
        $this->get('/admin-informationen')->assertOk();
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/wp-json/weartogether/v1/notify')));

        // 2. Aufruf: erholt -> notified_at wird zurückgesetzt, keine weitere Benachrichtigung
        $this->get('/admin-informationen')->assertOk();
        $this->assertNull(IntegrationStatus::where('key', 'woocommerce_read')->sole()->notified_at);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/wp-json/weartogether/v1/notify')));

        // 3. Aufruf: erneuter Ausfall -> neue (zweite) Benachrichtigung
        $this->get('/admin-informationen')->assertOk();
        $this->assertCount(2, Http::recorded(fn ($r) => str_contains($r->url(), '/wp-json/weartogether/v1/notify')));
    }

    public function test_missing_mu_plugin_is_reported_but_does_not_break_page(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/orders*' => Http::response(['message' => 'down'], 500),
            'shop.example/wp-json/wc/v3/products/shipping_classes*' => Http::response([]),
            'shop.example/wp-json/wp/v2/schule*' => Http::response([]),
            'api.printify.com/v1/shops.json' => Http::response([]),
            'shop.example/wp-json/weartogether/v1/notify' => Http::response(['code' => 'rest_no_route'], 404),
        ]);

        $response = $this->get('/admin-informationen');

        $response->assertOk()->assertSee('nicht zugestellt', false);
        $status = IntegrationStatus::where('key', 'woocommerce_read')->sole();
        $this->assertNotNull($status->notified_at); // Versuch fand statt, auch wenn Zustellung scheiterte
    }

    public function test_webhook_status_reflects_last_log_and_never_notifies(): void
    {
        $this->fakeAllOk();
        WebhookLog::create([
            'method' => 'POST', 'ip' => '1.2.3.4', 'content_type' => 'application/json',
            'secret_ok' => false, 'outcome' => 'abgelehnt: Secret falsch (404)', 'body_snippet' => '{}',
        ]);

        $this->get('/admin-informationen')->assertOk()->assertSee('Secret falsch');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/notify') && str_contains($r->data()['subject'] ?? '', 'Webhook'));
    }
}
