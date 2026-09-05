<?php

namespace Tests\Feature;

use App\Models\BalanceOrder;
use App\Models\SchoolOnboarding;
use App\Services\Statistics\RevenueForecast;
use App\Services\Statistics\SchoolYear;
use App\Services\Statistics\SeasonPlan;
use App\Services\Statistics\StatisticsFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Die Seiten aus der Sicht von jemandem, der die Software nicht kennt.
 *
 * Hier steht nicht, ob die Zahlen stimmen — das prüfen AuftragsbilanzTest und
 * StatisticsTest. Hier steht, ob die Seiten sagen, was sie zeigen, und ob es
 * von jedem Zustand aus einen Weg weitergibt. Sackgassen sind der teuerste
 * Fehler in einem Werkzeug, das nebenbei bedient wird.
 *
 * Alle Tests laufen ohne Shop: `preventStrayRequests()` macht einen
 * versehentlich eingebauten Aufruf sichtbar, statt ihn hinausgehen zu lassen.
 */
class BedienbarkeitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00'));
        Http::preventStrayRequests();
        config(['ordersuite.password' => '']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /*
     * ---------------------------------------------------------------
     *  Die Quellenschalter
     * ---------------------------------------------------------------
     */

    /**
     * Der Fehler, den das hier verhindert: `query()` verband die aktuellen
     * Filter per `+` mit den Übersteuerungen — und bei `+` gewinnt der LINKE
     * Operand. Dadurch trug der Link zum Ausschalten kein `shop=0`, und der
     * Link zum Einschalten wurde es nicht mehr los: Die Schalter taten nichts.
     */
    public function test_quellenschalter_kann_ausgeschaltet_werden(): void
    {
        $filters = $this->filters(shop: true, other: true);

        $this->assertSame('0', $filters->query(['shop' => '0'])['shop'] ?? null);
    }

    public function test_quellenschalter_kann_wieder_eingeschaltet_werden(): void
    {
        $filters = $this->filters(shop: false, other: true);

        $this->assertArrayNotHasKey('shop', $filters->query(['shop' => null]));
    }

    public function test_uebersteuerung_veraendert_nur_den_genannten_wert(): void
    {
        $query = $this->filters(shop: false, other: true)->query(['schuljahr' => '2024']);

        $this->assertSame('2024', $query['schuljahr']);
        $this->assertSame('0', $query['shop']);
    }

    /*
     * ---------------------------------------------------------------
     *  Das Saisonziel ist eine Vereinbarung, keine Ansicht
     * ---------------------------------------------------------------
     */

    /**
     * Mit abgeschalteter Shop-Quelle bliebe vom Vorjahr nur das Bargeld übrig.
     * Als Zielvorschlag wäre das um ein Vielfaches zu niedrig — dann lieber
     * gar kein Vorschlag.
     */
    public function test_ohne_alle_quellen_gibt_es_keinen_zielvorschlag(): void
    {
        $forecast = (new RevenueForecast)->build(
            $this->yearData(SchoolYear::current(), 1000.0),
            [$this->yearData(SchoolYear::current()->previous(), 4400.15)],
            null,
            null,
            allSources: false,
        );

        $this->assertFalse($forecast['targetKnown']);
        $this->assertNull($forecast['targetShare']);
        $this->assertFalse($forecast['previousTotalComplete']);
    }

    public function test_mit_allen_quellen_gilt_wieder_der_vorjahresumsatz(): void
    {
        $forecast = (new RevenueForecast)->build(
            $this->yearData(SchoolYear::current(), 1000.0),
            [$this->yearData(SchoolYear::current()->previous(), 48166.49)],
            null,
            null,
            allSources: true,
        );

        $this->assertTrue($forecast['targetKnown']);
        $this->assertSame(48166.49, $forecast['target']);
    }

    /** Ohne bekanntes Ziel gibt es nichts zu planen — und schon gar kein „Ziel erreicht". */
    public function test_ohne_ziel_meldet_die_planung_kein_ziel_erreicht(): void
    {
        $current = $this->yearData(SchoolYear::current(), 0.0);
        $forecast = (new RevenueForecast)->build(
            $current,
            [$this->yearData(SchoolYear::current()->previous(), 4400.15)],
            null,
            null,
            allSources: false,
        );

        $plan = (new SeasonPlan)->build($current, null, $forecast, new \App\Models\SeasonGoal);

        $this->assertFalse($plan['targetKnown']);
        $this->assertFalse($plan['reached']);
        $this->assertSame(0.0, $plan['open']);
    }

    /*
     * ---------------------------------------------------------------
     *  Keine Sackgassen
     * ---------------------------------------------------------------
     */

    /**
     * Ohne Shop-Zugang zeigte die Statistik nur eine rote Fehlermeldung —
     * obwohl die Auftragsbilanz mit voller Gewinnrechnung danebenliegt. Der
     * Ausweg war nur über „?shop=0" in der Adresszeile erreichbar.
     */
    public function test_statistik_ohne_shop_bietet_den_weg_ueber_die_auftragsbilanz(): void
    {
        config(['ordersuite.woocommerce.store_url' => '', 'ordersuite.woocommerce.consumer_key' => '']);
        $this->order(['revenue_online' => 4000.0, 'expenses' => 1000.0]);

        $response = $this->get('/statistiken');

        $response->assertOk();
        $response->assertSee('Ohne Shop-Zahlen auswerten');
        $response->assertSee('shop=0', false);
    }

    /** Ohne Aufträge gibt es nichts anzubieten — dann bleibt es bei der Fehlermeldung. */
    public function test_ohne_auftraege_bleibt_die_fehlermeldung_allein_stehen(): void
    {
        config(['ordersuite.woocommerce.store_url' => '', 'ordersuite.woocommerce.consumer_key' => '']);

        $response = $this->get('/statistiken');

        $response->assertOk();
        $response->assertDontSee('Ohne Shop-Zahlen auswerten');
    }

    /**
     * Der Weg „Bestellfenster → Auftrag anlegen" war fertig gebaut
     * (`?antrag=`), aber nirgends verlinkt.
     */
    public function test_antragsseite_fuehrt_in_die_auftragsbilanz(): void
    {
        $onboarding = $this->onboarding();

        $response = $this->get("/schulen/{$onboarding->id}");

        $response->assertOk();
        $response->assertSee('Auftrag anlegen');
        $response->assertSee(route('balance.create', ['antrag' => $onboarding->id]), false);
    }

    public function test_antragsseite_zeigt_den_verknuepften_auftrag_mit_zahlen(): void
    {
        $onboarding = $this->onboarding();
        $this->order([
            'school_onboarding_id' => $onboarding->id,
            'school_name' => $onboarding->school_name,
            'revenue_online' => 2000.0,
            'expenses' => 500.0,
        ]);

        $response = $this->get("/schulen/{$onboarding->id}");

        $response->assertOk();
        $response->assertSee('2.000,00 €');
        $response->assertSee('Weiteren Auftrag anlegen');
    }

    /*
     * ---------------------------------------------------------------
     *  Die Auftragsliste
     * ---------------------------------------------------------------
     */

    /**
     * Am Telefon blieb von der 15-spaltigen Tabelle nur die fixierte
     * Namensspalte übrig — eine Liste ohne eine einzige Zahl. Die Karten
     * beziehen ihre Beschriftung aus `data-label`, es gibt also weiterhin
     * nur EINE Auszeichnung im Blade.
     */
    public function test_auftragszeilen_tragen_beschriftungen_fuer_die_telefonkarte(): void
    {
        $this->order(['revenue_online' => 1000.0, 'expenses' => 400.0]);

        $response = $this->get('/auftragsbilanz');

        $response->assertOk();
        $response->assertSee('data-label="Gewinn"', false);
        $response->assertSee('data-label="Einnahmen ges."', false);
        $response->assertSee('class="data cards"', false);
    }

    /**
     * Beides gab es schon, nur nicht auffindbar: „Bearbeiten" stand in der
     * 15. Spalte (am Desktop außerhalb des Sichtfelds, am Telefon ausgeblendet),
     * „Löschen" ausschließlich unten auf der Bearbeiten-Seite.
     */
    public function test_jede_zeile_traegt_bearbeiten_und_loeschen(): void
    {
        $order = $this->order(['revenue_online' => 1000.0]);

        $response = $this->get('/auftragsbilanz');

        $response->assertOk();
        $response->assertSee('✎ Bearbeiten');
        $response->assertSee('🗑 Löschen');
        $response->assertSee(route('balance.destroy', $order), false);
    }

    /** Sie stehen in der fixierten Spalte — nur die bleibt beim Scrollen stehen. */
    public function test_die_aktionen_stehen_in_der_fixierten_spalte(): void
    {
        $this->order(['revenue_online' => 1000.0]);

        $html = $this->get('/auftragsbilanz')->assertOk()->getContent();
        $cell = substr($html, (int) strpos($html, '<td class="stickycol">'));
        $cell = substr($cell, 0, (int) strpos($cell, '</td>'));

        $this->assertStringContainsString('rowactions', $cell);
        $this->assertStringContainsString('✎ Bearbeiten', $cell);
        $this->assertStringContainsString('🗑 Löschen', $cell);
    }

    /** Der Name für die Rückfrage steht als Attribut, nicht im onsubmit. */
    public function test_loeschen_traegt_den_auftragsnamen_fuer_die_rueckfrage(): void
    {
        $this->order(['school_name' => "O'Brien Gymnasium", 'number' => '007']);

        $response = $this->get('/auftragsbilanz');

        $response->assertOk();
        // Der Apostroph im Schulnamen muss escaped ankommen und darf das
        // Attribut nicht zerreißen.
        $response->assertSee('data-confirm="007 - O&#039;Brien Gymnasium"', false);
    }

    public function test_loeschen_aus_der_liste_entfernt_den_auftrag(): void
    {
        $order = $this->order(['revenue_online' => 1000.0]);

        $response = $this->delete(route('balance.destroy', $order));

        $response->assertRedirect();
        $this->assertDatabaseMissing('balance_orders', ['id' => $order->id]);
    }

    /** Ohne Ausgaben ist die Marge rechnerisch richtig und inhaltlich falsch. */
    public function test_ohne_ausgaben_bleibt_die_marge_leer(): void
    {
        $this->order(['revenue_online' => 1000.0, 'expenses' => 0.0, 'vat' => 0.0]);

        $response = $this->get('/auftragsbilanz');

        $response->assertOk();
        // -1 ist der Sortierwert der leeren Marge: unten statt oben.
        $response->assertSee('data-label="Marge" data-value="-1"', false);
        $response->assertSee('ohne eingetragene Ausgaben');
    }

    public function test_mit_ausgaben_steht_die_marge_wieder_da(): void
    {
        $this->order(['revenue_online' => 1000.0, 'expenses' => 400.0, 'vat' => 0.0]);

        $response = $this->get('/auftragsbilanz');

        $response->assertOk();
        $response->assertSee('60 %');
        $response->assertDontSee('ohne eingetragene Ausgaben');
    }

    /** Die übernommenen Aufträge tragen alle dasselbe geschätzte Datum. */
    public function test_geschaetzte_daten_werden_als_solche_beschriftet(): void
    {
        $this->order(['date_is_estimate' => true]);

        $response = $this->get('/auftragsbilanz');

        $response->assertOk();
        $response->assertSee('Schuljahresende (geschätzt)');
    }

    /*
     * ---------------------------------------------------------------
     *  Was die Statistik über sich selbst sagt
     * ---------------------------------------------------------------
     */

    /** „Datenstand: unbekannt Uhr" war kein Satz. */
    public function test_datenstand_ohne_geladene_monate_ist_ein_satz(): void
    {
        $this->order(['revenue_cash' => 500.0]);

        $response = $this->get('/statistiken?shop=0');

        $response->assertOk();
        $response->assertDontSee('unbekannt Uhr');
        $response->assertSee('Auftragsbilanz, laufend gepflegt');
    }

    /**
     * Mit abgeschalteter Shop-Quelle sind Produkt-, Farb- und Schulrangliste
     * zwangsläufig leer. Das ist keine Aussage über die Daten.
     */
    public function test_leere_ranglisten_nennen_den_abgeschalteten_schalter(): void
    {
        $this->order(['revenue_cash' => 500.0]);

        $response = $this->get('/statistiken?shop=0');

        $response->assertOk();
        $response->assertSee('Die Shop-Quelle ist gerade ausgeschaltet');
        $response->assertDontSee('Für diesen Zeitraum sind keine Farben erfasst.');
    }

    /** Ein frisch begonnenes Schuljahr ist nicht kaputt, sondern jung. */
    public function test_leeres_laufendes_schuljahr_erklaert_sich(): void
    {
        $this->order(['ordered_on' => '2025-11-04', 'school_year' => 2025, 'revenue_cash' => 500.0]);

        $response = $this->get('/statistiken?shop=0');

        $response->assertOk();
        $response->assertSee('hat gerade erst begonnen');
        $response->assertSee('ansehen');
    }

    /**
     * Der Monatsverlauf der Altdaten ist ein einziger Balken im Juli, weil
     * alle übernommenen Aufträge auf dem Schuljahresende sitzen.
     */
    public function test_monatsverlauf_weist_auf_geschaetzte_daten_hin(): void
    {
        $this->order([
            'ordered_on' => '2026-07-31',
            'school_year' => 2025,
            'date_is_estimate' => true,
            'revenue_cash' => 500.0,
        ]);

        $response = $this->get('/statistiken?shop=0');

        $response->assertOk();
        $response->assertSee('Sie sitzen alle am 31. Juli');
    }

    /** Eintragen und Auswerten sind ein Paar — das muss man sehen. */
    public function test_beide_module_verweisen_aufeinander(): void
    {
        $this->order(['revenue_cash' => 500.0]);

        $this->get('/auftragsbilanz')->assertOk()->assertSee('Hier wird eingetragen.');
        $this->get('/statistiken?shop=0')->assertOk()->assertSee('Hier wird ausgewertet.');
    }

    /*
     * ---------------------------------------------------------------
     *  Hilfsmittel
     * ---------------------------------------------------------------
     */

    private function filters(bool $shop, bool $other): StatisticsFilters
    {
        return new StatisticsFilters(
            year: SchoolYear::current(),
            deliveryType: 'all',
            schoolId: null,
            paddingBefore: 7,
            paddingAfter: 21,
            statuses: config('ordersuite.woocommerce.default_statuses'),
            fresh: false,
            sourceShop: $shop,
            sourceOther: $other,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function order(array $attributes = []): BalanceOrder
    {
        return BalanceOrder::create($attributes + [
            'number' => '001',
            'school_name' => 'HTL Testheim',
            'school_year' => SchoolYear::current()->startYear,
            'ordered_on' => '2026-09-01',
            'date_is_estimate' => false,
            'online_source' => 'manual',
            'revenue_online' => 0.0,
            'revenue_cash' => 0.0,
            'commission' => 0.0,
            'expenses' => 0.0,
            'vat' => 0.0,
            'products' => ['hoodies' => 10],
            'individual' => 0,
            'source' => 'manual',
        ]);
    }

    private function onboarding(): SchoolOnboarding
    {
        return SchoolOnboarding::create([
            'school_name' => 'HTL Testheim',
            'delivery_type' => 'collective',
            'status' => 'angelegt',
            'window_start' => '2026-09-01',
            'window_end' => '2026-09-21',
            'products' => [],
        ]);
    }

    /**
     * Das Nötigste, was RevenueForecast und SeasonPlan aus einer Auswertung
     * brauchen — ohne Shop und ohne Zwischenspeicher.
     *
     * @return array<string, mixed>
     */
    private function yearData(SchoolYear $year, float $revenue): array
    {
        $months = [];
        foreach ($year->months() as $key => $month) {
            $months[$key] = $month + ['revenue' => 0.0, 'quantity' => 0];
        }
        $months[array_key_first($months)]['revenue'] = $revenue;

        $box = ['count' => 0, 'done' => 0, 'running' => 0, 'upcoming' => 0, 'revenue' => 0.0, 'doneRevenue' => 0.0, 'avg' => null];

        return [
            'year' => $year,
            'label' => $year->label(),
            'revenue' => $revenue,
            'months' => $months,
            'collective' => $box,
            'ondemand' => $box,
        ];
    }
}
