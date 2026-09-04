<?php

namespace Tests\Feature;

use App\Models\BalanceOrder;
use App\Models\SchoolOnboarding;
use App\Services\Balance\OnlineRevenueSync;
use App\Services\Balance\ShopComparison;
use App\Services\Statistics\SchoolYear;
use App\Services\Statistics\StatisticsWarmer;
use App\Services\Statistics\StatisticsFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Wo Auftragsbilanz und Webshop einander berühren: der automatische Nachtrag
 * der Online-Einnahmen und der Abgleich beider Zahlen.
 *
 * Der Fake wertet `after`/`before` ehrlich aus — sonst zählte der monatsweise
 * Abruf jede Bestellung zwölfmal und der Nachtrag schriebe Unsinn in die
 * Datenbank.
 */
class AuftragsbilanzShopTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-02-15';

    /**
     * Das Fenster der Schule: 01.10.–30.11.2025. Mit dem Standardpuffer
     * (7 Tage davor, 21 danach) reicht der Auswertungszeitraum vom 24.09. bis
     * zum 21.12.2025.
     *
     * Darin liegen drei Bestellungen: 179,70 € + 90,00 € + 79,80 € = 349,50 €.
     * Die vierte (im Jänner) liegt außerhalb und darf NICHT mitzählen.
     */
    private const FENSTERUMSATZ = 349.50;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::TODAY));
        Cache::flush();
        config([
            'ordersuite.woocommerce.store_url' => 'https://shop.example',
            'ordersuite.woocommerce.consumer_key' => 'ck_test',
            'ordersuite.woocommerce.consumer_secret' => 'cs_test',
            'ordersuite.password' => '',
        ]);
        $this->fakeShop();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------- Nachtragen

    public function test_der_nachtrag_uebernimmt_genau_den_umsatz_des_verknuepften_fensters(): void
    {
        $onboarding = $this->collectiveSchool();
        $order = $this->order(['school_onboarding_id' => $onboarding->id, 'online_source' => 'shop', 'revenue_online' => 0.0]);

        $this->warmUp();
        $result = app(OnlineRevenueSync::class)->sync(new SchoolYear(2025));

        $this->assertTrue($result['complete']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(self::FENSTERUMSATZ, $order->fresh()->revenue_online);
    }

    public function test_der_nachtrag_zieht_die_umsatzsteuer_nach_wenn_sie_hergeleitet_war(): void
    {
        $onboarding = $this->collectiveSchool();
        // USt. war aus dem Bruttobetrag hergeleitet (0 € brutto → 0 € USt.)
        $order = $this->order([
            'school_onboarding_id' => $onboarding->id, 'online_source' => 'shop',
            'revenue_online' => 0.0, 'revenue_cash' => 0.0, 'vat' => 0.0,
        ]);

        $this->warmUp();
        app(OnlineRevenueSync::class)->sync(new SchoolYear(2025));

        $this->assertSame(BalanceOrder::vatFromGross(self::FENSTERUMSATZ), $order->fresh()->vat);
    }

    public function test_eine_von_hand_gesetzte_umsatzsteuer_bleibt_unangetastet(): void
    {
        $onboarding = $this->collectiveSchool();
        // Ausdrücklich 0 bei einem Bruttobetrag, aus dem sich etwas anderes
        // ergäbe — das ist der Fall der Jahre vor der GmbH-Gründung.
        $order = $this->order([
            'school_onboarding_id' => $onboarding->id, 'online_source' => 'shop',
            'revenue_online' => 1000.0, 'revenue_cash' => 0.0, 'vat' => 0.0,
        ]);

        $this->warmUp();
        app(OnlineRevenueSync::class)->sync(new SchoolYear(2025));

        $this->assertSame(self::FENSTERUMSATZ, $order->fresh()->revenue_online);
        $this->assertSame(0.0, $order->fresh()->vat, 'Eine ausdrückliche 0 darf nicht überschrieben werden.');
    }

    public function test_haendisch_gepflegte_auftraege_ruehrt_der_nachtrag_nicht_an(): void
    {
        $onboarding = $this->collectiveSchool();
        $order = $this->order([
            'school_onboarding_id' => $onboarding->id,
            'online_source' => 'manual', 'revenue_online' => 1234.56,
        ]);

        $this->warmUp();
        $result = app(OnlineRevenueSync::class)->sync(new SchoolYear(2025));

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1234.56, $order->fresh()->revenue_online);
    }

    public function test_ein_auftrag_ohne_fenster_im_jahr_wird_nicht_auf_null_gesetzt(): void
    {
        // Antrag ohne Bestellfenster in diesem Schuljahr (Listenbestellung):
        // Der Bericht kennt dafür kein Fenster. Der eingetragene Betrag ist
        // dann das Beste, was da ist — er darf nicht verloren gehen.
        $onboarding = SchoolOnboarding::create([
            'school_name' => 'Liste Musterstadt', 'delivery_type' => 'list',
            'status' => 'angelegt', 'woo_category_id' => 7,
            'window_start' => '2025-10-01', 'window_end' => '2025-11-30',
        ]);
        $order = $this->order([
            'school_onboarding_id' => $onboarding->id,
            'online_source' => 'shop', 'revenue_online' => 800.00,
        ]);

        $this->warmUp();
        app(OnlineRevenueSync::class)->sync(new SchoolYear(2025));

        $this->assertSame(800.00, $order->fresh()->revenue_online);
    }

    public function test_ohne_vollstaendige_shop_daten_wird_nichts_geschrieben(): void
    {
        $onboarding = $this->collectiveSchool();
        $order = $this->order(['school_onboarding_id' => $onboarding->id, 'online_source' => 'shop', 'revenue_online' => 42.0]);

        // Kein warmUp(): Es liegt nichts im Zwischenspeicher.
        $result = app(OnlineRevenueSync::class)->sync(new SchoolYear(2025));

        $this->assertFalse($result['complete']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(42.0, $order->fresh()->revenue_online, 'Halbe Daten dürfen keine halben Zahlen schreiben.');
    }

    public function test_der_nachtrag_fragt_den_shop_nie_selbst(): void
    {
        $onboarding = $this->collectiveSchool();
        $this->order(['school_onboarding_id' => $onboarding->id, 'online_source' => 'shop']);

        $this->warmUp();
        $vorher = count(Http::recorded());

        app(OnlineRevenueSync::class)->sync(new SchoolYear(2025));

        $this->assertSame($vorher, count(Http::recorded()),
            'Der Nachtrag darf ausschließlich mit bereits geladenen Monaten rechnen.');
    }

    // ---------------------------------------------------------------- Abgleich

    public function test_der_abgleich_meldet_eine_grobe_abweichung(): void
    {
        $this->collectiveSchool();
        // Eingetragen ist deutlich weniger, als der Shop meldet.
        $this->order(['revenue_online' => 100.00, 'online_source' => 'shop']);

        $this->warmUp();
        $result = app(ShopComparison::class)->forYear(new SchoolYear(2025));

        $this->assertTrue($result['available']);
        $this->assertTrue($result['mismatch']);
        $this->assertSame(100.00, $result['entered']);
        $this->assertGreaterThan(0, $result['difference']);
    }

    public function test_kleine_rundungsunterschiede_sind_keine_meldung_wert(): void
    {
        $this->collectiveSchool();
        $shopUmsatz = $this->shopRevenue2025();
        // Ein Euro Unterschied auf mehrere hundert Euro: unterhalb beider Schwellen.
        $this->order(['revenue_online' => round($shopUmsatz - 1.0, 2), 'online_source' => 'shop']);

        $this->warmUp();
        $result = app(ShopComparison::class)->forYear(new SchoolYear(2025));

        $this->assertTrue($result['available']);
        $this->assertFalse($result['mismatch']);
    }

    public function test_ohne_geladene_monate_sagt_der_abgleich_das_ehrlich(): void
    {
        $this->collectiveSchool();
        $this->order(['revenue_online' => 100.00]);

        $result = app(ShopComparison::class)->forYear(new SchoolYear(2025));

        $this->assertFalse($result['available']);
        $this->assertNull($result['shop']);
        $this->assertFalse($result['mismatch']);
        $this->assertSame(100.00, $result['entered']);
    }

    // ------------------------------------------------------------------ Helfer

    private function collectiveSchool(): SchoolOnboarding
    {
        return SchoolOnboarding::create([
            'school_name' => 'BG Musterstadt',
            'delivery_type' => 'collective',
            'status' => 'angelegt',
            'woo_category_id' => 7,
            'window_start' => '2025-10-01',
            'window_end' => '2025-11-30',
            'created_at' => '2025-09-01',
        ]);
    }

    private function order(array $attributes = []): BalanceOrder
    {
        return BalanceOrder::create(array_merge([
            'number' => '001',
            'school_name' => 'BG Musterstadt',
            'school_year' => 2025,
            'ordered_on' => '2025-11-30',
            'online_source' => 'shop',
            'revenue_online' => 0.0,
            'revenue_cash' => 0.0,
            'vat' => 0.0,
            'source' => 'manual',
        ], $attributes));
    }

    private function warmUp(): void
    {
        app(StatisticsWarmer::class)->warm($this->filters(), 60.0);
    }

    private function filters(): StatisticsFilters
    {
        return StatisticsFilters::fromRequest(Request::create('/statistiken', 'GET', []));
    }

    /** Gesamter Shop-Umsatz des Schuljahres 2025/26 im Fake. */
    private function shopRevenue2025(): float
    {
        $sum = 0.0;
        foreach ($this->allOrders() as $order) {
            if (! (new SchoolYear(2025))->contains(Carbon::parse($order['date_created']))) {
                continue;
            }
            foreach ($order['line_items'] as $item) {
                $sum += (float) $item['total'] + (float) $item['total_tax'];
            }
        }

        return round($sum, 2);
    }

    private function fakeShop(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'shop.example/wp-json/wc/v3/products/categories*' => Http::response([
                ['id' => 1, 'name' => 'Schulen', 'count' => 0, 'parent' => 0],
                ['id' => 7, 'name' => 'BG Musterstadt', 'count' => 2, 'parent' => 1],
            ], 200, ['X-WP-TotalPages' => '1']),
            'shop.example/wp-json/wc/v3/products*' => Http::response([
                ['id' => 101, 'name' => 'BG Musterstadt Schulhoodie', 'categories' => [['id' => 7]]],
                ['id' => 102, 'name' => 'BG Musterstadt Schulshirt', 'categories' => [['id' => 7]]],
            ], 200, ['X-WP-TotalPages' => '1']),
            'shop.example/wp-json/wc/v3/orders*' => function ($request) {
                $after = (string) $request->data()['after'];
                $before = (string) $request->data()['before'];

                // after/before sind AUSSCHLIESSEND — genau wie in WooCommerce.
                return Http::response(array_values(array_filter(
                    $this->allOrders(),
                    static fn (array $o) => $o['date_created'] > $after && $o['date_created'] < $before,
                )), 200, ['X-WP-TotalPages' => '1']);
            },
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function allOrders(): array
    {
        return [
            // Im Fenster: 149,75 + 29,95 = 179,70
            ['id' => 1, 'date_created' => '2025-10-12T10:00:00', 'status' => 'completed', 'line_items' => [
                ['product_id' => 101, 'parent_name' => 'BG Musterstadt Schulhoodie', 'quantity' => 3,
                    'total' => '149.75', 'total_tax' => '29.95', 'meta_data' => []],
            ]],
            // Genau um Mitternacht des Monatsersten: 75,00 + 15,00 = 90,00
            ['id' => 2, 'date_created' => '2025-11-01T00:00:00', 'status' => 'completed', 'line_items' => [
                ['product_id' => 102, 'parent_name' => 'BG Musterstadt Schulshirt', 'quantity' => 3,
                    'total' => '75.00', 'total_tax' => '15.00', 'meta_data' => []],
            ]],
            // Nachzügler nach dem Fensterende, noch im Puffer: 66,50 + 13,30 = 79,80
            ['id' => 3, 'date_created' => '2025-12-06T09:30:00', 'status' => 'processing', 'line_items' => [
                ['product_id' => 102, 'parent_name' => 'BG Musterstadt Schulshirt', 'quantity' => 2,
                    'total' => '66.50', 'total_tax' => '13.30', 'meta_data' => []],
            ]],
            // Weit nach dem Puffer: zählt in den Jahresumsatz, aber NICHT ins Fenster
            ['id' => 4, 'date_created' => '2026-01-20T12:00:00', 'status' => 'completed', 'line_items' => [
                ['product_id' => 101, 'parent_name' => 'BG Musterstadt Schulhoodie', 'quantity' => 1,
                    'total' => '50.00', 'total_tax' => '10.00', 'meta_data' => []],
            ]],
        ];
    }
}
