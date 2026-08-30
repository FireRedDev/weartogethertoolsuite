<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use App\Services\Statistics\RevenueForecast;
use App\Services\Statistics\RevenueReport;
use App\Services\Statistics\SchoolYear;
use App\Services\Statistics\StatisticsFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Modul „Statistiken": Umsatzauswertung nach österreichischem Schuljahr.
 *
 * Gerechnet wird gegen simulierte Shop-Antworten mit einem festen „heute",
 * damit die Zahlen unabhängig vom Ausführungstag reproduzierbar sind.
 */
class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    /** Mitten im Schuljahr 2025/26 — so ist immer ein Rest zu prognostizieren. */
    private const TODAY = '2026-02-15';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::TODAY));
        Cache::flush();
        config([
            'ordersuite.woocommerce.store_url' => 'https://shop.example',
            'ordersuite.woocommerce.consumer_key' => 'ck_test',
            'ordersuite.woocommerce.consumer_secret' => 'cs_test',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Schuljahr

    public function test_schuljahr_beginnt_im_september_und_endet_ende_august(): void
    {
        $year = SchoolYear::forDate(Carbon::parse('2026-02-15'));

        $this->assertSame(2025, $year->startYear);
        $this->assertSame('2025/26', $year->label());
        $this->assertSame('2025-09-01', $year->start()->toDateString());
        $this->assertSame('2026-08-31', $year->end()->toDateString());
    }

    public function test_sommerferien_zaehlen_zum_ablaufenden_schuljahr(): void
    {
        // 20. Juli 2026 liegt in den Ferien NACH dem Unterrichtsjahr 2025/26 —
        // die Bestellung gehört zu diesem Schuljahr, nicht zum nächsten.
        $this->assertSame('2025/26', SchoolYear::forDate(Carbon::parse('2026-07-20'))->label());
        $this->assertSame('2025/26', SchoolYear::forDate(Carbon::parse('2026-08-31'))->label());
        $this->assertSame('2026/27', SchoolYear::forDate(Carbon::parse('2026-09-01'))->label());
    }

    public function test_schuljahr_hat_zwoelf_monate_beginnend_im_september(): void
    {
        $months = (new SchoolYear(2025))->months();

        $this->assertCount(12, $months);
        $this->assertSame('Sep', $months[0]['short']);
        $this->assertSame('September 2025', $months[0]['label']);
        $this->assertSame('Aug', $months[11]['short']);
        $this->assertSame('August 2026', $months[11]['label']);
    }

    // ------------------------------------------------------------- Kennzahlen

    public function test_umsatz_kennzahlen_und_vorjahresvergleich(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $data = app(RevenueReport::class)->build($this->filters());

        // 2025/26: Sammelbestellung 3 × 59,90 + 2 × 39,90 = 259,50
        //          On-Demand 1 × 45,00 = 45,00
        $this->assertEqualsWithDelta(304.50, $data['current']['revenue'], 0.01);
        $this->assertSame(3, $data['current']['orders']);
        $this->assertEqualsWithDelta(101.50, $data['current']['avgPerOrder'], 0.01);
        $this->assertSame(6, $data['current']['quantity']);

        // 2024/25 zum Vergleich: 2 × 59,90 = 119,80
        $this->assertEqualsWithDelta(119.80, $data['previous']['revenue'], 0.01);
    }

    public function test_durchschnitt_je_sammelbestellfenster_und_je_ondemand_shop(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $data = app(RevenueReport::class)->build($this->filters());

        // Genau ein Sammelbestellfenster endet in 2025/26 (BG Musterstadt)
        $this->assertSame(1, $data['current']['collective']['count']);
        $this->assertEqualsWithDelta(259.50, $data['current']['collective']['avg'], 0.01);

        // Genau ein On-Demand-Shop ist aktiv
        $this->assertSame(1, $data['current']['ondemand']['count']);
        $this->assertEqualsWithDelta(45.00, $data['current']['ondemand']['avg'], 0.01);
    }

    public function test_nachzuegler_nach_fensterende_zaehlen_dank_puffer_mit(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        // Das Fenster von BG Musterstadt endet am 30.11.2025; eine Bestellung
        // stammt vom 06.12.2025 (typische Nachfrist-Verlängerung).
        $withPadding = app(RevenueReport::class)->build($this->filters());
        $withoutPadding = app(RevenueReport::class)->build($this->filters(['vorlauf' => 0, 'nachlauf' => 0]));

        $this->assertEqualsWithDelta(259.50, $withPadding['current']['collective']['revenue'], 0.01);
        $this->assertEqualsWithDelta(179.70, $withoutPadding['current']['collective']['revenue'], 0.01);
    }

    public function test_monatsverlauf_folgt_dem_schuljahr(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $months = array_values(app(RevenueReport::class)->build($this->filters())['current']['months']);

        $this->assertCount(12, $months);
        $this->assertSame('September 2025', $months[0]['label']);
        // Oktober-Bestellung: 3 × 59,90
        $this->assertEqualsWithDelta(179.70, $months[1]['revenue'], 0.01);
        // Dezember-Nachzügler: 2 × 39,90
        $this->assertEqualsWithDelta(79.80, $months[3]['revenue'], 0.01);
    }

    // --------------------------------------------------------------- Ranglisten

    public function test_produkt_rangliste_fasst_ueber_schulen_hinweg_zusammen(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $ranking = app(RevenueReport::class)->build($this->filters())['products'];
        $names = array_column($ranking, 'name');

        // Schulname und Druckzusatz fallen weg
        $this->assertContains('Schulhoodie', $names);
        $this->assertNotContains('BG Musterstadt Schulhoodie', $names);

        $hoodie = collect($ranking)->firstWhere('name', 'Schulhoodie');
        $this->assertSame(3, $hoodie['quantity']);
        // Vorjahreswert steht am selben Eintrag
        $this->assertSame(2, $hoodie['previousQuantity']);
    }

    public function test_farb_rangliste_erkennt_deutsche_und_englische_attribute(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $colors = collect(app(RevenueReport::class)->build($this->filters())['colors'])
            ->keyBy('name');

        $this->assertSame(3, $colors['Blau']['quantity']);       // pa_color (Sammelbestellung)
        $this->assertSame(1, $colors['Heather Grey']['quantity']); // "Colors" (Printify/On-Demand)
    }

    // ----------------------------------------------------------------- Filter

    public function test_filter_auf_lieferart_grenzt_die_auswertung_ein(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $ondemand = app(RevenueReport::class)->build($this->filters(['lieferart' => 'ondemand']));

        $this->assertEqualsWithDelta(45.00, $ondemand['current']['revenue'], 0.01);
        $this->assertSame(0, $ondemand['current']['collective']['count']);
    }

    // ---------------------------------------------------------------- Prognose

    public function test_prognose_rechnet_mit_dem_saisonverlauf_der_vorjahre(): void
    {
        $forecast = app(RevenueForecast::class)->build(
            current: $this->aggregate(2025, [0, 4000.0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]),
            history: [$this->aggregate(2024, [0, 5000.0, 0, 0, 0, 0, 0, 0, 5000.0, 0, 0, 0])],
            target: null,
            today: Carbon::parse('2026-02-15'),
        );

        // Im Vorjahr fiel der Umsatz je zur Hälfte auf Oktober und Mai. Bis
        // Mitte Februar war also die Hälfte des Jahres gelaufen — 4.000 €
        // entsprechen damit rund 8.000 € Jahresumsatz.
        $this->assertTrue($forecast['possible']);
        $this->assertEqualsWithDelta(0.5, $forecast['cumulativeShare'], 0.01);
        $this->assertEqualsWithDelta(8000.0, $forecast['projection'], 1.0);
        $this->assertEqualsWithDelta(4000.0, $forecast['remaining'], 1.0);
    }

    public function test_zielumsatz_ist_ohne_eingabe_der_vorjahreswert(): void
    {
        $forecast = app(RevenueForecast::class)->build(
            current: $this->aggregate(2025, [0, 4000.0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]),
            history: [$this->aggregate(2024, [0, 5000.0, 0, 0, 0, 0, 0, 0, 5000.0, 0, 0, 0])],
            target: null,
            today: Carbon::parse('2026-02-15'),
        );

        $this->assertTrue($forecast['targetIsDefault']);
        $this->assertEqualsWithDelta(10000.0, $forecast['target'], 0.01);
        $this->assertEqualsWithDelta(6000.0, $forecast['openToTarget'], 0.01);
    }

    public function test_eigener_zielumsatz_ueberschreibt_den_vorjahreswert(): void
    {
        $forecast = app(RevenueForecast::class)->build(
            current: $this->aggregate(2025, [0, 4000.0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]),
            history: [$this->aggregate(2024, [0, 5000.0, 0, 0, 0, 0, 0, 0, 5000.0, 0, 0, 0])],
            target: 7000.0,
            today: Carbon::parse('2026-02-15'),
        );

        $this->assertFalse($forecast['targetIsDefault']);
        $this->assertEqualsWithDelta(7000.0, $forecast['target'], 0.01);
        // Hochrechnung 8.000 € liegt 1.000 € über dem Ziel
        $this->assertEqualsWithDelta(1000.0, $forecast['gapToTarget'], 1.0);
    }

    public function test_ohne_vorjahresdaten_gibt_es_eine_erklaerung_statt_einer_zahl(): void
    {
        $forecast = app(RevenueForecast::class)->build(
            current: $this->aggregate(2025, [0, 4000.0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]),
            history: [],
            target: null,
            today: Carbon::parse('2026-02-15'),
        );

        $this->assertFalse($forecast['possible']);
        $this->assertNull($forecast['projection']);
        $this->assertStringContainsString('Vergleichsdaten', $forecast['reason']);
    }

    // -------------------------------------------------------------------- Seite

    public function test_die_seite_zeigt_kennzahlen_diagramme_und_tabellen(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $response = $this->get(route('statistics.index'));

        $response->assertOk();
        $response->assertSee('Ø Umsatz je Bestellung');
        $response->assertSee('Ø je Sammelbestellfenster');
        $response->assertSee('Ø je On-Demand-Shop');
        $response->assertSee('Meistverkaufte Produkte');
        $response->assertSee('Beliebteste Produktfarben');
        $response->assertSee('Prognose bis Schuljahresende');
        // Jedes Diagramm hat eine Tabellenansicht — Farbe ist nie der einzige Träger
        $response->assertSee('Als Tabelle');
        $response->assertSee('<svg', false);
    }

    public function test_die_seite_erklaert_den_fensterpuffer_im_info_symbol(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $response = $this->get(route('statistics.index'));

        $response->assertOk();
        $response->assertSee('Warum ein Puffer um das Bestellfenster?', false);
        $response->assertSee('automatische Nachfrist', false);
        $response->assertSee('class="info-toggle"', false);
    }

    public function test_ohne_shop_verbindung_erklaerte_meldung_statt_absturz(): void
    {
        config(['ordersuite.woocommerce.store_url' => '']);

        $response = $this->get(route('statistics.index'));

        $response->assertOk();
        $response->assertSee('nicht eingerichtet');
    }

    public function test_shop_fehler_wird_erklaert_statt_als_500er(): void
    {
        $this->makeSchools();
        Http::preventStrayRequests();
        Http::fake(['shop.example/*' => Http::response('nope', 503)]);

        $response = $this->get(route('statistics.index'));

        $response->assertOk();
        $response->assertSee('Technische Details', false);
    }

    // ------------------------------------------------------------------ Helfer

    private function filters(array $query = []): StatisticsFilters
    {
        return StatisticsFilters::fromRequest(Request::create('/statistiken', 'GET', $query));
    }

    /** Zwei Schulen mit Shop-Kategorie: eine Sammelbestellung, eine On-Demand. */
    private function makeSchools(): void
    {
        SchoolOnboarding::create([
            'school_name' => 'BG Musterstadt',
            'delivery_type' => 'collective',
            'status' => 'angelegt',
            'woo_category_id' => 7,
            'window_start' => '2025-10-01',
            'window_end' => '2025-11-30',
            'created_at' => '2025-09-01',
        ]);

        // Vorjahresfenster derselben Art, damit der Vergleich etwas zu zeigen hat
        SchoolOnboarding::create([
            'school_name' => 'HAK Altstadt',
            'delivery_type' => 'collective',
            'status' => 'abgeschlossen',
            'woo_category_id' => 8,
            'window_start' => '2024-10-01',
            'window_end' => '2024-11-30',
            'created_at' => '2024-09-01',
        ]);

        SchoolOnboarding::create([
            'school_name' => 'BORG Neustadt',
            'delivery_type' => 'ondemand',
            'status' => 'angelegt',
            'woo_category_id' => 9,
            'window_start' => SchoolOnboarding::ONDEMAND_WINDOW_START,
            'window_end' => SchoolOnboarding::ONDEMAND_WINDOW_END,
            'created_at' => '2025-09-15',
        ]);
    }

    private function fakeShop(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'shop.example/wp-json/wc/v3/products*' => Http::response([
                ['id' => 101, 'name' => 'BG Musterstadt Schulhoodie', 'categories' => [['id' => 7]]],
                ['id' => 102, 'name' => 'BG Musterstadt Schulshirt', 'categories' => [['id' => 7]]],
                ['id' => 103, 'name' => 'HAK Altstadt STICK-Schulhoodie', 'categories' => [['id' => 8]]],
                ['id' => 104, 'name' => 'BORG Neustadt Schulpolo', 'categories' => [['id' => 9]]],
            ], 200, ['X-WP-TotalPages' => '1']),
            'shop.example/wp-json/wc/v3/orders*' => function ($request) {
                $after = substr((string) $request->data()['after'], 0, 10);

                return Http::response($this->ordersBetween($after), 200, ['X-WP-TotalPages' => '1']);
            },
        ]);
    }

    /**
     * Simulierte Bestellungen. Der Abruf grenzt serverseitig ein — hier wird
     * anhand des `after`-Parameters entschieden, welches Schuljahr geliefert
     * wird (die Fakes kennen kein echtes Datumsfilter).
     */
    private function ordersBetween(string $after): array
    {
        $orders2025 = [
            [
                'id' => 5001,
                'date_created' => '2025-10-12T10:00:00',
                'status' => 'completed',
                'line_items' => [[
                    'product_id' => 101,
                    'parent_name' => 'BG Musterstadt Schulhoodie',
                    'quantity' => 3,
                    'total' => '149.75',
                    'total_tax' => '29.95',
                    'meta_data' => [
                        ['key' => 'pa_size', 'display_key' => 'Größe', 'display_value' => 'M'],
                        ['key' => 'pa_color', 'display_key' => 'Farbe', 'display_value' => 'Blau'],
                    ],
                ]],
            ],
            [
                // Nachzügler nach dem Fensterende (30.11.) — muss dank Puffer zählen
                'id' => 5002,
                'date_created' => '2025-12-06T09:30:00',
                'status' => 'processing',
                'line_items' => [[
                    'product_id' => 102,
                    'parent_name' => 'BG Musterstadt Schulshirt',
                    'quantity' => 2,
                    'total' => '66.50',
                    'total_tax' => '13.30',
                    'meta_data' => [
                        ['key' => 'pa_color', 'display_key' => 'Farbe', 'display_value' => 'Weiß'],
                    ],
                ]],
            ],
            [
                'id' => 5003,
                'date_created' => '2026-01-20T14:00:00',
                'status' => 'completed',
                'line_items' => [[
                    'product_id' => 104,
                    'parent_name' => 'BORG Neustadt Schulpolo',
                    'quantity' => 1,
                    'total' => '37.50',
                    'total_tax' => '7.50',
                    // Printify liefert das Farbattribut englisch
                    'meta_data' => [
                        ['key' => 'Colors', 'display_key' => 'Colors', 'display_value' => 'Heather Grey'],
                    ],
                ]],
            ],
        ];

        $orders2024 = [
            [
                'id' => 4001,
                'date_created' => '2024-10-15T11:00:00',
                'status' => 'completed',
                'line_items' => [[
                    'product_id' => 103,
                    'parent_name' => 'HAK Altstadt STICK-Schulhoodie',
                    'quantity' => 2,
                    'total' => '99.83',
                    'total_tax' => '19.97',
                    'meta_data' => [
                        ['key' => 'pa_color', 'display_key' => 'Farbe', 'display_value' => 'Blau'],
                    ],
                ]],
            ],
        ];

        return match (true) {
            $after >= '2025-01-01' => $orders2025,
            $after >= '2024-01-01' => $orders2024,
            default => [],
        };
    }

    /**
     * Minimales Jahresergebnis für die Prognose-Tests.
     *
     * @param  list<float>  $monthlyRevenue  zwölf Monatswerte, September zuerst
     * @return array<string, mixed>
     */
    private function aggregate(int $startYear, array $monthlyRevenue): array
    {
        $year = new SchoolYear($startYear);
        $months = [];
        foreach ($year->months() as $index => $month) {
            $months[] = [
                'short' => $month['short'],
                'label' => $month['label'],
                'revenue' => $monthlyRevenue[$index] ?? 0.0,
            ];
        }

        return [
            'year' => $year,
            'label' => $year->label(),
            'revenue' => array_sum($monthlyRevenue),
            'months' => $months,
        ];
    }
}
