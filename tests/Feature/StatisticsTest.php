<?php

namespace Tests\Feature;

use App\Exceptions\WooCommerceApiException;
use App\Models\SchoolOnboarding;
use App\Services\Statistics\RevenueForecast;
use App\Services\Statistics\RevenueReport;
use App\Services\Statistics\SchoolYear;
use App\Services\Statistics\StatisticsFilters;
use App\Services\Statistics\StatisticsWarmer;
use App\Services\WooCommerceClient;
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

        // 2025/26: Sammelbestellung 3 × 59,90 + 2 × 39,90 + 3 × 30,00 = 349,50
        //          On-Demand 1 × 45,00, Schule ohne Antrag 4 × 30,00 = 120,00
        $this->assertEqualsWithDelta(514.50, $data['current']['revenue'], 0.01);
        $this->assertSame(5, $data['current']['orders']);
        $this->assertEqualsWithDelta(102.90, $data['current']['avgPerOrder'], 0.01);
        $this->assertSame(13, $data['current']['quantity']);

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
        $this->assertEqualsWithDelta(349.50, $data['current']['collective']['avg'], 0.01);

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

        $this->assertEqualsWithDelta(349.50, $withPadding['current']['collective']['revenue'], 0.01);
        // Ohne Puffer fehlt der Dezember-Nachzügler (79,80)
        $this->assertEqualsWithDelta(269.70, $withoutPadding['current']['collective']['revenue'], 0.01);
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

        $this->assertSame(10, $colors['Blau']['quantity']);      // pa_color (Sammelbestellung)
        $this->assertSame(1, $colors['Heather Grey']['quantity']); // "Colors" (Printify/On-Demand)
    }

    public function test_produkt_rangliste_geht_nach_produktart_nicht_nach_produktname(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $ranking = collect(app(RevenueReport::class)->build($this->filters())['products'])->keyBy('name');

        // „BG Musterstadt Schulshirt" (2 + 3 Stk.) und „VS Handschuhsheim
        // T-Shirt bedruckt" (4 Stk.) sind dieselbe Produktart — EINE Zeile.
        $this->assertTrue($ranking->has('Schulshirt'));
        $this->assertSame(9, $ranking['Schulshirt']['quantity']);
        $this->assertFalse($ranking->has('T-Shirt bedruckt'));
        $this->assertFalse($ranking->has('VS Handschuhsheim T-Shirt bedruckt'));
    }

    // ------------------------------------------------------------ Schulranking

    public function test_schul_rangliste_kommt_aus_den_shop_kategorien(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $ranking = collect(app(RevenueReport::class)->build($this->filters())['schoolRanking'])->keyBy('name');

        // Schule MIT Antrag
        $this->assertTrue($ranking->has('BG Musterstadt'));
        // Schule OHNE Antrag in der Toolsuite — früher unsichtbar
        $this->assertTrue($ranking->has('VS Handschuhsheim'));
        $this->assertEqualsWithDelta(120.00, $ranking['VS Handschuhsheim']['revenue'], 0.01);
        // Kategorie außerhalb von „Schulen" ist keine Schule
        $this->assertFalse($ranking->has('Zubehör'));
    }

    public function test_schulen_ohne_antrag_zaehlen_in_den_umsatz_aber_nicht_in_die_fenster(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $current = app(RevenueReport::class)->build($this->filters())['current'];

        // Eine Kategorie ohne Antrag ist bekannt …
        $this->assertSame(1, $current['schoolsWithoutWindow']);
        // … ihr Umsatz zählt in den Gesamtumsatz …
        $this->assertGreaterThan(0, $current['revenue']);
        // … aber sie taucht in keinem Fenster-Durchschnitt auf.
        $this->assertSame(1, $current['collective']['count']);
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

    // ------------------------------------------------- Schutz vor Dauerläufern

    /**
     * Der Auslöser des Ausfalls: ohne Obergrenze blättert der Client endlos
     * weiter, sobald der Shop den Seitenzähler nicht mitschickt und jede Seite
     * voll ist. Der PHP-Prozess hängt dann für immer.
     */
    public function test_endloses_blaettern_wird_abgebrochen_statt_haengen_zu_bleiben(): void
    {
        config(['ordersuite.woocommerce.max_pages' => 3, 'ordersuite.woocommerce.per_page' => 2]);

        Http::preventStrayRequests();
        Http::fake([
            // Immer volle Seiten, KEIN X-WP-TotalPages — genau das Muster, das
            // ein Caching-Plugin oder Proxy erzeugt.
            'shop.example/wp-json/wc/v3/orders*' => Http::response([
                ['id' => 1, 'date_created' => '2025-10-01T10:00:00', 'line_items' => []],
                ['id' => 2, 'date_created' => '2025-10-02T10:00:00', 'line_items' => []],
            ], 200),
        ]);

        $this->expectException(WooCommerceApiException::class);
        app(WooCommerceClient::class)->ordersForStatistics(['completed'], '2025-09-30T23:59:59', '2025-11-01T00:00:00');
    }

    public function test_jeder_monat_wird_einzeln_gecacht_und_nicht_erneut_geholt(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        app(RevenueReport::class)->build($this->filters());
        $first = count(Http::recorded());

        // Zweiter Aufruf: alles aus dem Zwischenspeicher, keine einzige
        // Shop-Anfrage mehr.
        app(RevenueReport::class)->build($this->filters());

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, count(Http::recorded()), 'Der zweite Aufruf hätte keine Shop-Anfrage stellen dürfen.');
    }

    public function test_reicht_die_zeit_nicht_kommt_ein_teilergebnis_statt_eines_haengers(): void
    {
        config(['statistics.budget_seconds' => 0]);
        $this->makeSchools();
        $this->fakeShop();

        $data = app(RevenueReport::class)->build($this->filters());

        $this->assertFalse($data['complete']);
        $this->assertSame(0, $data['current']['loaded']);
        $this->assertGreaterThan(0, $data['months']);
    }

    /**
     * Monatsweiser Abruf darf an der Monatsgrenze nichts verlieren und nichts
     * doppelt zählen — die API behandelt after/before ausschließend.
     */
    public function test_bestellung_exakt_um_mitternacht_zaehlt_genau_einmal(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $data = app(RevenueReport::class)->build($this->filters());
        $months = array_values($data['current']['months']);

        // Die Mitternachtsbestellung (01.11.2025, 3 × 30 € brutto) liegt im November
        $this->assertEqualsWithDelta(90.0, $months[2]['revenue'], 0.01);
    }

    // -------------------------------------------------------------------- Seite

    public function test_die_seite_zeigt_kennzahlen_diagramme_und_tabellen(): void
    {
        $this->makeSchools();
        $this->fakeShop();
        $this->warmAll();

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

    public function test_solange_daten_fehlen_zeigt_die_seite_nur_die_ladeanzeige(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $response = $this->get(route('statistics.index'));

        $response->assertOk();
        $response->assertSee('Die Auswertung wird aufgebaut');
        $response->assertSee('progress-fill', false);
        $response->assertSee('class="spinner"', false);
        // Keine halben Zahlen: gar keine Auswertung, solange nicht alles da ist
        $response->assertDontSee('Ø Umsatz je Bestellung');
        $response->assertDontSee('Meistverkaufte Produkte');
    }

    public function test_fortschritt_kommt_als_json_und_meldet_fertig(): void
    {
        $this->makeSchools();
        $this->fakeShop();

        $first = $this->getJson(route('statistics.progress'));
        $first->assertOk();
        $first->assertJsonStructure(['loaded', 'total', 'percent', 'done', 'running', 'error']);
        $this->assertGreaterThan(0, $first->json('total'));

        $this->warmAll();

        $this->getJson(route('statistics.progress'))
            ->assertOk()
            ->assertJson(['done' => true, 'percent' => 100]);
    }

    /**
     * Kernanforderung: Der Aufbau hängt nicht am Browser. Der Warmer läuft
     * eigenständig zu Ende — genauso wie er es nach dem Schließen der Seite
     * über `app()->terminating()` bzw. den Cron-Befehl tut.
     */
    public function test_der_aufbau_laeuft_auch_ohne_geoeffnete_seite_zu_ende(): void
    {
        $this->makeSchools();
        $this->fakeShop();
        $warmer = app(StatisticsWarmer::class);
        $filters = $this->filters();

        $this->assertFalse($warmer->progress($filters)['done']);

        $this->artisan('statistics:warm', ['--runs' => 10])->assertSuccessful();

        $this->assertTrue($warmer->progress($filters)['done']);
    }

    /**
     * Prüft die Mechanik, die in der Anwendung wirklich greift: der Aufruf der
     * Seite selbst stößt den Aufbau an — und zwar erst, nachdem die Antwort
     * raus ist (app()->terminating). Ein paar Aufrufe später ist alles da.
     */
    public function test_seitenaufrufe_bauen_die_daten_im_hintergrund_auf(): void
    {
        $this->makeSchools();
        $this->fakeShop();
        $warmer = app(StatisticsWarmer::class);

        $this->get(route('statistics.index'))->assertOk()->assertSee('Die Auswertung wird aufgebaut');

        // Der Aufbau lief nach der Antwort — beim nächsten Aufruf steht die
        // Auswertung.
        $this->assertTrue($warmer->progress($this->filters())['done']);
        $this->get(route('statistics.index'))->assertOk()->assertSee('Ø Umsatz je Bestellung');
    }

    public function test_es_laeuft_immer_nur_ein_durchgang_gleichzeitig(): void
    {
        $this->makeSchools();
        $this->fakeShop();
        $warmer = app(StatisticsWarmer::class);

        // Sperre von außen halten — ein zweiter Durchgang darf nicht loslegen
        // und dabei den Webshop doppelt belasten.
        $lock = Cache::lock('statistics.warm.lock', 60);
        $this->assertTrue($lock->get());

        $result = $warmer->warm($this->filters());

        $this->assertFalse($result['ran']);
        $this->assertSame(0, $result['fetched']);
        $lock->release();
    }

    public function test_shop_fehler_erscheint_auf_der_ladeseite_statt_im_nichts(): void
    {
        $this->makeSchools();
        Http::preventStrayRequests();
        Http::fake(['shop.example/*' => Http::response('nope', 503)]);

        app(StatisticsWarmer::class)->warm($this->filters());

        $response = $this->get(route('statistics.index'));

        $response->assertOk();
        $response->assertSee('Technische Details', false);
        $response->assertSee('Fehler aufgetreten');
    }

    public function test_ohne_shop_verbindung_erklaerte_meldung_statt_absturz(): void
    {
        config(['ordersuite.woocommerce.store_url' => '']);

        $response = $this->get(route('statistics.index'));

        $response->assertOk();
        $response->assertSee('nicht eingerichtet');
    }

    // ------------------------------------------------------------------ Helfer

    /** Alles laden, wie es der Hintergrund-Aufbau bzw. der Cron tut. */
    private function warmAll(array $query = []): void
    {
        $warmer = app(StatisticsWarmer::class);
        $filters = $this->filters($query);
        for ($i = 0; $i < 40 && ! $warmer->progress($filters)['done']; $i++) {
            $warmer->warm($filters);
        }
    }

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
            // Die Schulen der Auswertung sind die Kinder der Sammelkategorie
            'shop.example/wp-json/wc/v3/products/categories*' => Http::response([
                ['id' => 1, 'name' => 'Schulen', 'count' => 0, 'parent' => 0],
                ['id' => 7, 'name' => 'BG Musterstadt', 'count' => 3, 'parent' => 1],
                ['id' => 8, 'name' => 'HAK Altstadt', 'count' => 2, 'parent' => 1],
                ['id' => 9, 'name' => 'BORG Neustadt', 'count' => 1, 'parent' => 1],
                // Kategorie ohne Antrag in der Toolsuite — muss trotzdem
                // in der Umsatzrangliste auftauchen
                ['id' => 12, 'name' => 'VS Handschuhsheim', 'count' => 1, 'parent' => 1],
                // Keine Schule: hängt nicht unter „Schulen"
                ['id' => 20, 'name' => 'Zubehör', 'count' => 5, 'parent' => 0],
            ], 200, ['X-WP-TotalPages' => '1']),
            'shop.example/wp-json/wc/v3/products*' => Http::response([
                ['id' => 101, 'name' => 'BG Musterstadt Schulhoodie', 'categories' => [['id' => 7]]],
                ['id' => 102, 'name' => 'BG Musterstadt Schulshirt', 'categories' => [['id' => 7]]],
                ['id' => 103, 'name' => 'HAK Altstadt STICK-Schulhoodie', 'categories' => [['id' => 8]]],
                ['id' => 104, 'name' => 'BORG Neustadt Schulpolo', 'categories' => [['id' => 9]]],
                // Gleiche Produktart, ganz anderer Produktname — muss in der
                // Rangliste mit „Schulshirt" zusammenfallen
                ['id' => 105, 'name' => 'VS Handschuhsheim T-Shirt bedruckt', 'categories' => [['id' => 12]]],
            ], 200, ['X-WP-TotalPages' => '1']),
            // Der Fake muss after/before ehrlich auswerten: die Auswertung
            // ruft monatsweise ab, ein Fake der immer alles zurückgibt würde
            // jede Bestellung zwölfmal zählen.
            'shop.example/wp-json/wc/v3/orders*' => function ($request) {
                return Http::response(
                    $this->ordersBetween((string) $request->data()['after'], (string) $request->data()['before']),
                    200,
                    ['X-WP-TotalPages' => '1'],
                );
            },
        ]);
    }

    /**
     * Simulierte Bestellungen, gefiltert wie die echte API: `after` und
     * `before` sind ausschließende Zeitpunkte.
     */
    private function ordersBetween(string $after, string $before): array
    {
        $all = $this->allOrders();

        return array_values(array_filter($all, static function (array $order) use ($after, $before) {
            $date = $order['date_created'];

            return $date > $after && $date < $before;
        }));
    }

    /** @return list<array<string, mixed>> */
    private function allOrders(): array
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
                // Genau um Mitternacht des Monatsersten — Grenzfall des
                // monatsweisen Abrufs: darf weder verloren gehen noch doppelt
                // gezählt werden.
                'id' => 5004,
                'date_created' => '2025-11-01T00:00:00',
                'status' => 'completed',
                'line_items' => [[
                    'product_id' => 102,
                    'parent_name' => 'BG Musterstadt Schulshirt',
                    'quantity' => 3,
                    'total' => '75.00',
                    'total_tax' => '15.00',
                    'meta_data' => [
                        ['key' => 'pa_color', 'display_key' => 'Farbe', 'display_value' => 'Blau'],
                    ],
                ]],
            ],
            [
                // Schule OHNE Antrag in der Toolsuite — darf trotzdem in der
                // Umsatzrangliste stehen (Kategorie kommt aus dem Shop)
                'id' => 5005,
                'date_created' => '2026-02-03T08:00:00',
                'status' => 'completed',
                'line_items' => [[
                    'product_id' => 105,
                    'parent_name' => 'VS Handschuhsheim T-Shirt bedruckt',
                    'quantity' => 4,
                    'total' => '100.00',
                    'total_tax' => '20.00',
                    'meta_data' => [
                        ['key' => 'pa_color', 'display_key' => 'Farbe', 'display_value' => 'Blau'],
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

        return array_merge($orders2025, $orders2024);
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
