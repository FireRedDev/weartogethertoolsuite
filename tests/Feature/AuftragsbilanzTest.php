<?php

namespace Tests\Feature;

use App\Models\BalanceOrder;
use App\Models\SchoolOnboarding;
use App\Services\Balance\BalanceReport;
use App\Services\Statistics\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Modul „Auftragsbilanz" — Pflege der Aufträge, Nachfolgerin der Excel.
 *
 * Alle Tests laufen ohne Shop: Das Modul muss vollständig bedienbar bleiben,
 * wenn WooCommerce klemmt. `preventStrayRequests()` macht einen versehentlich
 * eingebauten Aufruf sichtbar, statt ihn stillschweigend hinausgehen zu lassen.
 */
class AuftragsbilanzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-02-15 10:00:00'));
        Http::preventStrayRequests();
        config(['ordersuite.password' => '']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(array $attributes = []): BalanceOrder
    {
        return BalanceOrder::create(array_merge([
            'number' => '001',
            'school_name' => 'HTL Musterstadt',
            'school_year' => 2025,
            'ordered_on' => '2025-11-30',
            'online_source' => 'manual',
            'revenue_online' => 1200.00,
            'revenue_cash' => 300.00,
            'commission' => 50.00,
            'expenses' => 700.00,
            'vat' => 250.00,
            'products' => ['hoodies' => 30, 'tshirts' => 10],
            'individual' => 12,
            'source' => 'manual',
        ], $attributes));
    }

    // ------------------------------------------------------------------ Rechnen

    public function test_die_zeile_rechnet_wie_die_excel(): void
    {
        $order = $this->order();

        // Einnahmen Ges. = Online + Bar
        $this->assertSame(1500.00, $order->revenueTotal());
        // Einnahmen o. Mwst. = Ges. − USt.
        $this->assertSame(1250.00, $order->revenueNet());
        // Gewinn = Ges. − USt. − Provision − Ausgaben
        $this->assertSame(500.00, $order->profit());
        $this->assertSame(0.3333, $order->marginShare());
        // „Produkte" zählt nur Kleidungsstücke, nicht die Individualisierungen
        $this->assertSame(40, $order->productCount());
    }

    public function test_umsatzsteuer_wird_aus_dem_bruttobetrag_herausgerechnet(): void
    {
        // 20 % USt. auf brutto: 120 € brutto enthalten 20 € Steuer, nicht 24 €.
        $this->assertSame(20.00, BalanceOrder::vatFromGross(120.00));
    }

    public function test_ohne_umsatz_gibt_es_keine_marge_statt_einer_division_durch_null(): void
    {
        $order = $this->order(['revenue_online' => 0, 'revenue_cash' => 0, 'vat' => 0]);

        $this->assertNull($order->marginShare());
    }

    /**
     * Der entscheidende Punkt beim Verheiraten beider Welten: Was schon als
     * Shop-Bestellung in der Auswertung steckt, darf hier nicht ein zweites Mal
     * dazukommen.
     */
    public function test_online_umsatz_aus_dem_shop_zaehlt_nicht_noch_einmal_als_sonstiger_umsatz(): void
    {
        $ausDemShop = $this->order(['online_source' => 'shop']);
        $haendisch = $this->order(['online_source' => 'manual']);

        $this->assertSame(300.00, $ausDemShop->revenueOutsideShop(), 'Nur das Bargeld darf zusätzlich zählen.');
        $this->assertSame(1500.00, $haendisch->revenueOutsideShop());
    }

    // ------------------------------------------------------------ Auswertungen

    public function test_die_jahresbilanz_summiert_wie_die_kopfzeile_der_excel(): void
    {
        $this->order(['number' => '001']);
        $this->order(['number' => '002', 'revenue_online' => 500.00, 'revenue_cash' => 0,
            'commission' => 0, 'expenses' => 200.00, 'vat' => 83.33]);

        $summary = app(BalanceReport::class)->forYear(new SchoolYear(2025));

        $this->assertSame(2, $summary['orders']);
        $this->assertSame(2000.00, $summary['revenue']);
        $this->assertSame(1700.00, $summary['revenueOnline']);
        $this->assertSame(300.00, $summary['revenueCash']);
        $this->assertSame(716.67, $summary['profit']);
        $this->assertSame(1000.00, $summary['avgRevenue']);
    }

    public function test_auftraege_landen_im_schuljahr_ihres_datums(): void
    {
        // Das Geschäftsjahr läuft 1.8.–31.7.: Der 31. Juli gehört noch zu
        // 2025/26, der 1. August schon zu 2026/27.
        $this->order(['number' => '010', 'ordered_on' => '2026-07-31', 'school_year' => 2025]);
        $this->order(['number' => '011', 'ordered_on' => '2026-08-01', 'school_year' => 2026]);

        $this->assertSame(1, app(BalanceReport::class)->forYear(new SchoolYear(2025))['orders']);
        $this->assertSame(1, app(BalanceReport::class)->forYear(new SchoolYear(2026))['orders']);
    }

    public function test_monatsverlauf_zeigt_nur_die_umsaetze_ausserhalb_des_shops(): void
    {
        $this->order(['number' => '001', 'ordered_on' => '2025-11-30', 'online_source' => 'shop']);
        $this->order(['number' => '002', 'ordered_on' => '2026-01-15', 'online_source' => 'manual']);

        $months = app(BalanceReport::class)->monthlyOutsideShop(new SchoolYear(2025));

        // November: nur das Bargeld des Shop-Auftrags
        $this->assertSame(300.00, $months['2025-11']);
        // Jänner: der ganze händische Auftrag
        $this->assertSame(1500.00, $months['2026-01']);
        $this->assertSame(0.0, $months['2025-08']);
    }

    // -------------------------------------------------------------------- Seite

    public function test_die_seite_zeigt_die_auftraege_des_schuljahres(): void
    {
        $this->order(['number' => '042', 'school_name' => 'BORG Beispielstadt']);

        $response = $this->get(route('balance.index', ['schuljahr' => 2025]));

        $response->assertOk();
        $response->assertSee('042 - BORG Beispielstadt');
        $response->assertSee('1.500,00 €');
    }

    public function test_ein_auftrag_laesst_sich_anlegen_und_landet_im_richtigen_schuljahr(): void
    {
        $response = $this->post(route('balance.store'), [
            'number' => '500',
            'school_name' => 'HAK Neustadt',
            'ordered_on' => '2026-03-10',
            'online_source' => 'manual',
            'revenue_online' => '2000',
            'revenue_cash' => '0',
            'expenses' => '900',
            'products' => ['hoodies' => '50'],
            'individual' => '20',
        ]);

        $response->assertRedirect();
        $order = BalanceOrder::firstWhere('number', '500');

        $this->assertSame(2025, $order->school_year);
        $this->assertFalse($order->date_is_estimate);
        // USt. leer gelassen: wird aus dem Bruttobetrag herausgerechnet
        $this->assertSame(333.33, $order->vat);
        $this->assertSame(766.67, $order->profit());
    }

    public function test_ohne_datum_wird_der_auftrag_nicht_gespeichert(): void
    {
        $response = $this->post(route('balance.store'), [
            'school_name' => 'Ohne Datum',
            'online_source' => 'manual',
        ]);

        $response->assertSessionHasErrors('ordered_on');
        $this->assertSame(0, BalanceOrder::count());
    }

    public function test_beim_verknuepfen_wird_die_shop_kategorie_mit_uebernommen(): void
    {
        $onboarding = SchoolOnboarding::create([
            'school_name' => 'HTL Musterstadt',
            'delivery_type' => 'collective',
            'status' => 'angelegt',
            'woo_category_id' => 4711,
            'window_start' => '2025-10-01',
            'window_end' => '2025-11-30',
        ]);

        $this->post(route('balance.store'), [
            'school_name' => 'HTL Musterstadt',
            'ordered_on' => '2025-11-30',
            'school_onboarding_id' => (string) $onboarding->id,
            'online_source' => 'shop',
            'revenue_online' => '1000',
        ]);

        $order = BalanceOrder::first();
        $this->assertSame($onboarding->id, $order->school_onboarding_id);
        $this->assertSame(4711, (int) $order->woo_category_id);
    }

    // ------------------------------------------------------------------ Import

    public function test_der_import_uebernimmt_die_altdaten_und_legt_nichts_doppelt_an(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ab').'.json';
        file_put_contents($file, json_encode([[
            'number' => '005', 'school_name' => 'HTL Steyr', 'school_year' => 2019,
            'revenue_online' => 2458.00, 'revenue_cash' => 9104.00, 'commission' => 1013.30,
            'expenses' => 6583.23, 'vat' => 0.0,
            'products' => ['hoodies' => 373, 'tshirts' => 62], 'individual' => 35,
            'note' => '2 Designs',
        ]]));

        $this->artisan('auftragsbilanz:import', ['--file' => $file, '--force' => true])->assertSuccessful();
        $this->artisan('auftragsbilanz:import', ['--file' => $file, '--force' => true])->assertSuccessful();

        $this->assertSame(1, BalanceOrder::count());
        $order = BalanceOrder::first();

        // Ohne Datum in der Excel: Ende des Schuljahres, ausdrücklich als
        // Schätzung gekennzeichnet.
        $this->assertTrue($order->date_is_estimate);
        $this->assertSame('2020-07-31', $order->ordered_on->toDateString());
        // Vor dem eigenen Webshop: Die Online-Einnahmen sind die einzige Quelle
        // und müssen deshalb in der Statistik mitzählen.
        $this->assertSame('manual', $order->online_source);
        $this->assertSame(2458.00, $order->revenue_online_excel);
        // Keine Umsatzsteuer vor der GmbH-Gründung — die 0 bleibt eine 0.
        $this->assertSame(0.0, $order->vat);
        $this->assertSame(3965.47, $order->profit());

        unlink($file);
    }

    public function test_ab_dem_webshop_jahr_gilt_die_shop_zahl(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ab').'.json';
        file_put_contents($file, json_encode([[
            'number' => '350', 'school_name' => 'Gymnasium Dachsberg', 'school_year' => 2025,
            'revenue_online' => 4864.58, 'revenue_cash' => 0.0, 'commission' => 0.0,
            'expenses' => 2117.18, 'vat' => 810.76,
            'products' => ['hoodies' => 54], 'individual' => 34, 'note' => null,
        ]]));

        $this->artisan('auftragsbilanz:import', ['--file' => $file, '--force' => true])->assertSuccessful();

        $order = BalanceOrder::first();
        $this->assertSame('shop', $order->online_source);
        // Nur das (hier nicht vorhandene) Bargeld dürfte zusätzlich zählen.
        $this->assertSame(0.0, $order->revenueOutsideShop());

        unlink($file);
    }

    public function test_die_mitgelieferte_importdatei_ist_vollstaendig_und_stimmig(): void
    {
        $rows = json_decode((string) file_get_contents(database_path('data/auftragsbilanz.json')), true);

        $this->assertCount(384, $rows, 'Die Altdaten aus der Excel: 384 Aufträge.');

        $years = array_count_values(array_map(static fn ($r) => (int) $r['school_year'], $rows));
        ksort($years);
        // Die kaputte Schuljahr-Fortschreibung der Excel („2025-27" …) ist beim
        // Extrahieren begradigt worden — sonst fehlten 18 Aufträge der Saison.
        $this->assertSame([2019 => 25, 2020 => 74, 2021 => 69, 2022 => 61, 2023 => 79, 2024 => 41, 2025 => 35], $years);
    }
}
