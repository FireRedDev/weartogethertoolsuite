<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\PrintifyProvisioner;
use App\Services\SchoolShop\ProductConfigurator;
use App\Services\SchoolShop\ProvisionAbortedException;
use App\Services\SchoolShop\ShopProvisioner;
use App\Services\SchoolShop\WooCommerceWriteClient;
use App\Services\Statistics\RevenueReport;
use App\Services\Statistics\StatisticsFilters;
use App\Services\Statistics\StatisticsWarmer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PRÜFHARNISCH ZUM CODE-REVIEW — kein Bestandteil der Suite.
 *
 * Jeder Test belegt EINEN Befund aus Phase 1. Die Tests bestätigen das
 * IST-Verhalten; im Kommentar steht jeweils, was das SOLL wäre. Ein grüner
 * Test heißt hier also: der Mangel ist vorhanden.
 */
class ReviewAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Nicht gefakte Adressen sollen als klarer Testfehler auffallen und
        // nicht als echter Aufruf hinausgehen.
        Http::preventStrayRequests();
        config([
            'schoolshop.webhook_secret' => 'test-secret',
            'ordersuite.woocommerce.store_url' => 'https://shop.example',
            'ordersuite.woocommerce.consumer_key' => 'ck',
            'ordersuite.woocommerce.consumer_secret' => 'cs',
            'schoolshop.woocommerce_write.consumer_key' => 'ck_rw',
            'schoolshop.woocommerce_write.consumer_secret' => 'cs_rw',
            'schoolshop.wordpress.user' => 'admin',
            'schoolshop.wordpress.password' => 'app-password',
            'schoolshop.printify.api_token' => 'pfy_token',
            'schoolshop.printify.shop_id' => '99',
        ]);
    }

    /** Onboarding mit genau einem aktiven Produkt. */
    private function onboarding(array $attributes = [], string $productKey = 'schulpullover'): SchoolOnboarding
    {
        $products = collect(ProductConfigurator::defaultsAllDisabled())
            ->map(fn ($p) => [...$p, 'enabled' => ($p['key'] ?? '') === $productKey])
            ->all();

        return SchoolOnboarding::create([
            'status' => 'in_bearbeitung',
            'source' => 'manuell',
            'school_name' => 'AHS Testschule',
            'delivery_type' => 'collective',
            'products' => $products,
            'print_areas' => ['Frontprint'],
            'class_list' => '1a,1b',
            'window_start' => '2026-04-01',
            'window_end' => '2026-05-01',
            ...$attributes,
        ]);
    }

    /** Standard-Fakes für den Sammelbestell-Weg. */
    private function fakeCollectiveShop(array $overrides = []): void
    {
        // `+` statt Spread: bei gleichem Schlüssel gewinnt die linke Seite —
        // beim Spread überschriebe der spätere Standardwert den Aufruf.
        Http::fake($overrides + [
            'shop.example/wp-json/wc/v3/products/categories?*search=Schulen*' => Http::response([['id' => 15, 'name' => 'Schulen', 'parent' => 0]]),
            'shop.example/wp-json/wc/v3/products/categories?*search=AHS*' => Http::response([]),
            'shop.example/wp-json/wc/v3/products/categories*' => Http::response(['id' => 77, 'name' => 'AHS Testschule', 'parent' => 15], 201),
            'shop.example/wp-json/wc/v3/products/attributes/*/terms*' => Http::response([]),
            'shop.example/wp-json/wc/v3/products/attributes*' => Http::response([
                ['id' => 1, 'name' => 'Größe'], ['id' => 2, 'name' => 'Farbe'],
                ['id' => 3, 'name' => 'Klasse'], ['id' => 4, 'name' => 'Individualisierung'],
            ]),
            'shop.example/wp-json/wp/v2/schule*' => Http::response([
                'id' => 900,
                'bestellfensterstart' => '2026-04-01 00:00:00',
                'bestellfensterende' => '2026-05-01 23:59:59',
                'produkte_shortcode' => 'ahs testschule',
                'bestellfenster_offen' => 'NEIN',
                'on-demand' => '0',
                'woocommerce_produkt_kategorie' => 77,
            ], 201),
            'shop.example/wp-json/wc/v3/products/*/variations*' => Http::response(['id' => 501], 201),
            'shop.example/wp-json/wc/v3/products*' => Http::response(['id' => 401, 'name' => 'AHS Testschule Schulpullover'], 201),
        ]);
    }

    private function countProductCreates(): int
    {
        $count = 0;
        foreach (Http::recorded() as [$request]) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && $path === '/wp-json/wc/v3/products') {
                $count++;
            }
        }

        return $count;
    }

    private function countCategoryCreates(): int
    {
        $count = 0;
        foreach (Http::recorded() as [$request]) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && $path === '/wp-json/wc/v3/products/categories') {
                $count++;
            }
        }

        return $count;
    }

    // ---------------------------------------------------------------- P-01

    /**
     * P-01 (kritisch): Zwei gleichzeitige "Shop anlegen"-Vorgänge legen alles
     * doppelt an. Nachgestellt über zwei Modell-Instanzen desselben Datensatzes
     * — genau der Zustand zweier paralleler Requests, die beide gelesen haben,
     * bevor einer geschrieben hat.
     *
     * SOLL: der zweite Lauf bricht ab oder wartet (Sperre) — eine Kategorie.
     */
    public function test_P01_parallel_provision_creates_everything_twice(): void
    {
        $this->fakeCollectiveShop();
        $record = $this->onboarding();

        $first = SchoolOnboarding::find($record->id);
        $second = SchoolOnboarding::find($record->id);

        app(ShopProvisioner::class)->apply($first);
        app(ShopProvisioner::class)->apply($second);

        $this->assertSame(2, $this->countCategoryCreates(), 'IST: zwei Kategorien. SOLL: eine.');
        $this->assertSame(2, $this->countProductCreates(), 'IST: zwei Produktsätze. SOLL: einer.');
    }

    // ---------------------------------------------------------------- P-02

    /**
     * P-02 (hoch): Scheitert eine Variation, wird die bereits vergebene
     * Produkt-ID nie gespeichert — der nächste Versuch legt das Produkt erneut an.
     *
     * SOLL: ID direkt nach dem Anlegen speichern, Variationen wiederholbar.
     */
    public function test_P02_failed_variation_orphans_the_product_and_duplicates_on_retry(): void
    {
        $this->fakeCollectiveShop([
            'shop.example/wp-json/wc/v3/products/*/variations*' => Http::sequence()
                ->push('', 500)                       // erster Versuch: Shop-Fehler
                ->push(['id' => 501], 201)            // zweiter Versuch: klappt
                ->push(['id' => 502], 201),
        ]);
        $record = $this->onboarding();

        try {
            app(ShopProvisioner::class)->apply($record);
            $this->fail('Abbruch erwartet');
        } catch (ProvisionAbortedException) {
            // erwartet
        }

        $record->refresh();
        $this->assertSame(1, $this->countProductCreates(), 'Produkt wurde im Shop angelegt …');
        $this->assertEmpty($record->woo_product_ids ?? [], '… seine ID ist im Tool aber unbekannt.');

        app(ShopProvisioner::class)->apply($record);

        $this->assertSame(2, $this->countProductCreates(), 'IST: zweites Produkt im Shop. SOLL: das vorhandene weiterverwenden.');
    }

    // ---------------------------------------------------------------- P-03

    /**
     * P-03 (hoch): Dasselbe Muster bei Printify — scheitert `publish`, ist das
     * bereits angelegte Produkt im Tool unbekannt.
     *
     * SOLL: ID nach `createProduct` speichern, `publish` als eigener Schritt.
     */
    public function test_P03_failed_printify_publish_orphans_the_product(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products/categories?*search=Schulen*' => Http::response([['id' => 15, 'name' => 'Schulen', 'parent' => 0]]),
            'shop.example/wp-json/wc/v3/products/categories*' => Http::response(['id' => 77, 'name' => 'AHS Testschule', 'parent' => 15], 201),
            'shop.example/wp-json/wc/v3/products/shipping_classes*' => Http::response([['id' => 9, 'slug' => 'on-demand']]),
            'shop.example/wp-json/wp/v2/schule*' => Http::response(['id' => 900], 201),
            'api.printify.com/v1/uploads/images.json' => Http::response(['id' => 'img-1']),
            'api.printify.com/v1/catalog/print_providers/27.json' => Http::response(['id' => 27, 'title' => 'Textildruck Europa', 'location' => ['country' => 'DE']]),
            'api.printify.com/v1/catalog/blueprints/6/print_providers/27/variants.json' => Http::response(['variants' => [['id' => 101, 'cost' => 1500]]]),
            'api.printify.com/v1/catalog/blueprints/6/print_providers/27/shipping.json' => Http::response(['profiles' => [['countries' => ['AT'], 'first_item' => ['cost' => 450]]]]),
            'api.printify.com/v1/shops/99/products.json' => Http::response(['id' => 'pfy-1']),
            'api.printify.com/v1/shops/99/products/pfy-1/publish.json' => Http::response('', 500),
        ]);

        $record = $this->onboarding([
            'delivery_type' => 'ondemand',
            'logo_front_url' => 'https://shop.example/uploads/logo.png',
            'print_front' => true,
        ]);
        $record->products = collect($record->products)->map(fn ($p) => [
            ...$p, 'base_price' => 39.99, 'printify_blueprint_id' => 6, 'printify_provider_id' => 27,
        ])->all();
        $record->save();

        try {
            app(ShopProvisioner::class)->apply($record);
            $this->fail('Abbruch erwartet');
        } catch (ProvisionAbortedException) {
            // erwartet
        }

        $record->refresh();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/shops/99/products.json') && $r->method() === 'POST');
        $this->assertEmpty($record->printify_product_ids ?? [], 'IST: Printify-Produkt existiert, ID im Tool unbekannt.');
    }

    // ---------------------------------------------------------------- P-04

    /**
     * P-04 (hoch): Ein erneutes "Shop anlegen" schreibt die Zustandsfelder des
     * CPT zurück — ein zuvor geöffnetes Bestellfenster wird stumm wieder
     * geschlossen.
     *
     * SOLL: Zustandsfelder nur beim erstmaligen Anlegen setzen.
     */
    public function test_P04_reprovision_silently_closes_a_reopened_window(): void
    {
        // GET /products liefert eine Liste, POST /products das neue Objekt —
        // mit einem reinen Mustervergleich nicht unterscheidbar.
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $isPost = $request->method() === 'POST';

            return match (true) {
                str_contains($path, '/products/categories') => Http::response(
                    $isPost ? ['id' => 77, 'name' => 'AHS Testschule', 'parent' => 15] : [['id' => 15, 'name' => 'Schulen', 'parent' => 0]],
                ),
                str_contains($path, '/products/attributes') => Http::response([
                    ['id' => 1, 'name' => 'Größe'], ['id' => 2, 'name' => 'Farbe'],
                    ['id' => 3, 'name' => 'Klasse'], ['id' => 4, 'name' => 'Individualisierung'],
                ]),
                str_contains($path, '/variations') => Http::response(['id' => 501]),
                $path === '/wp-json/wc/v3/products' => Http::response($isPost
                    ? ['id' => 401, 'name' => 'AHS Testschule Schulpullover']
                    : [['id' => 401, 'name' => 'AHS Testschule Schulpullover', 'status' => 'private', 'categories' => [['id' => 77]]]]),
                str_starts_with($path, '/wp-json/wc/v3/products/') => Http::response(['id' => 401]),
                default => Http::response(['id' => 900, 'bestellfenster_offen' => 'JA']),
            };
        });
        $record = $this->onboarding(['woo_category_id' => 77, 'pods_post_id' => 900, 'status' => 'abgeschlossen']);

        // Schritt 1: Fenster wieder öffnen -> CPT bekommt JA
        app(ShopProvisioner::class)->reopenOrderWindow($record, new \DateTimeImmutable('+30 days'));
        $this->assertTrue($this->cptFieldWasSetTo('bestellfenster_offen', 'JA'));

        // Schritt 2: irgendwer legt erneut an (z. B. um ein Produkt zu ergänzen)
        app(ShopProvisioner::class)->apply($record->fresh());

        $lastValue = null;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/wp/v2/schule') && array_key_exists('bestellfenster_offen', $request->data())) {
                $lastValue = $request->data()['bestellfenster_offen'];
            }
        }

        $this->assertSame('NEIN', $lastValue, 'IST: das geöffnete Fenster ist im Shop wieder zu. SOLL: unverändert JA.');
    }

    private function cptFieldWasSetTo(string $field, string $value): bool
    {
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/wp/v2/schule') && ($request->data()[$field] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- P-05

    /**
     * P-05 (hoch): Ohne Kategorie sucht das Schließen die Produkte über den
     * Schulnamen — eine Teilstring-Suche. Produkte einer FREMDEN Schule mit
     * ähnlichem Namen werden mit auf privat gesetzt.
     *
     * SOLL: exakter Abgleich oder Abbruch ohne Kategorie.
     */
    public function test_P05_closing_by_name_also_hits_a_foreign_school(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products?*search=*' => Http::response([
                ['id' => 401, 'name' => 'HAK Wien Schulpullover', 'status' => 'publish'],
                ['id' => 402, 'name' => 'HAK Wien 13 Schulpullover', 'status' => 'publish'],
            ]),
            'shop.example/wp-json/wc/v3/products/*' => Http::response(['id' => 401], 200),
            'shop.example/wp-json/wp/v2/schule*' => Http::response(['id' => 900], 200),
        ]);

        $record = $this->onboarding(['school_name' => 'HAK Wien', 'pods_post_id' => 900]);

        app(ShopProvisioner::class)->closeOrderWindow($record);

        $touched = [];
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'PUT' && preg_match('#/products/(\d+)$#', parse_url($request->url(), PHP_URL_PATH), $m)) {
                $touched[] = (int) $m[1];
            }
        }

        $this->assertContains(402, $touched, 'IST: Produkt der Schule „HAK Wien 13" wurde mit geschlossen. SOLL: unberührt.');
    }

    // ---------------------------------------------------------------- P-06

    /**
     * P-06 (hoch): `findProductsByCategory()` blättert ohne Obergrenze weiter,
     * solange eine volle Seite kommt. Genau die Bauart, die die Anwendung schon
     * einmal lahmgelegt hat und für die es in `fetchAllPages()` eine Notbremse
     * gibt. Hier wird nach 50 Seiten abgebrochen, damit der Test nicht hängt.
     *
     * SOLL: Abbruch mit `WooCommerceApiException::tooManyPages()`.
     */
    public function test_P06_findProductsByCategory_pages_without_a_limit(): void
    {
        $calls = 0;
        $fullPage = array_map(fn ($i) => ['id' => $i, 'name' => 'P'.$i, 'status' => 'publish'], range(1, 100));

        Http::fake(function () use (&$calls, $fullPage) {
            $calls++;
            if ($calls > 50) {
                throw new \RuntimeException('NOTBREMSE-TEST: 50 Seiten ohne Abbruch');
            }

            return Http::response($fullPage);
        });

        try {
            app(WooCommerceWriteClient::class)->findProductsByCategory(77);
            $this->fail('Die Schleife hat von sich aus aufgehört — Befund wäre entkräftet.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('NOTBREMSE-TEST', $e->getMessage());
        }

        $this->assertGreaterThan(50, $calls, 'IST: unbegrenzte Seitenschleife.');
    }

    /** P-06b: dasselbe in `ensureAttributeTerms()`. */
    public function test_P06b_ensureAttributeTerms_pages_without_a_limit(): void
    {
        $calls = 0;
        $fullPage = array_map(fn ($i) => ['id' => $i, 'name' => 'Term '.$i], range(1, 100));

        Http::fake(function () use (&$calls, $fullPage) {
            $calls++;
            if ($calls > 50) {
                throw new \RuntimeException('NOTBREMSE-TEST');
            }

            return Http::response($fullPage);
        });

        try {
            app(WooCommerceWriteClient::class)->ensureAttributeTerms(2, ['Blau']);
            $this->fail('Die Schleife hat von sich aus aufgehört — Befund wäre entkräftet.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('NOTBREMSE-TEST', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- P-07

    /**
     * P-07 (hoch): Die Klassenliste ist ein Textfeld über mehrere Zeilen, wird
     * aber nur an Kommas getrennt. Zeilenweise Eingabe erzeugt EINE
     * Variationsoption mit Zeilenumbrüchen — die so im Shop landet.
     *
     * SOLL: an Zeilenumbrüchen, Kommas und Semikolons trennen.
     */
    public function test_P07_newline_separated_class_list_becomes_one_garbage_option(): void
    {
        $this->fakeCollectiveShop();
        $record = $this->onboarding(['class_list' => "1a\n1b\n2a"]);

        app(ShopProvisioner::class)->apply($record);

        $klassen = null;
        foreach (Http::recorded() as [$request]) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && $path === '/wp-json/wc/v3/products') {
                foreach ($request->data()['attributes'] ?? [] as $attribute) {
                    if (($attribute['id'] ?? null) === 3) {
                        $klassen = $attribute['options'];
                    }
                }
            }
        }

        $this->assertNotNull($klassen, 'Klassen-Attribut wurde übertragen');
        $this->assertContains("1a\n1b\n2a", $klassen, 'IST: eine Option mit Zeilenumbrüchen. SOLL: 1a, 1b, 2a.');
        $this->assertNotContains('1b', $klassen);
    }

    // ---------------------------------------------------------------- P-08

    /**
     * P-08 (hoch): Die Startseite ruft WordPress synchron auf, obwohl sie laut
     * eigener Regel an keiner Schnittstelle hängen darf.
     *
     * SOLL: nach der Antwort (`app()->terminating()`) oder nur im Cron.
     */
    public function test_P08_home_page_calls_wordpress_synchronously(): void
    {
        Http::fake(['shop.example/*' => Http::response(['id' => 900], 200)]);
        Cache::forget('order_windows.last_auto_extend');

        $this->onboarding([
            'status' => 'angelegt',
            'pods_post_id' => 900,
            'auto_extend' => true,
            'auto_extend_days' => 7,
            'window_end' => now()->subDays(3)->toDateString(),
        ]);

        $this->get('/')->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/wp/v2/schule'));
    }

    // ---------------------------------------------------------------- P-10

    /**
     * P-10 (mittel): Negative Preise passieren die serverseitige Prüfung und
     * werden so an WooCommerce/Printify geschrieben.
     *
     * SOLL: auf 0 begrenzen bzw. im Controller ablehnen.
     */
    public function test_P10_negative_prices_pass_validation(): void
    {
        $current = ProductConfigurator::defaultsAllDisabled();
        $result = ProductConfigurator::applyInput($current, [
            'schulpullover' => ['enabled' => '1', 'base_price' => '-5', 'indiv_surcharge' => '-2'],
        ]);

        $product = collect($result)->firstWhere('key', 'schulpullover');
        $this->assertSame(-5.0, $product['base_price'], 'IST: negativer Preis übernommen.');
        $this->assertSame(-2.0, $product['indiv_surcharge']);
    }

    /** P-10b: keine Begrenzung der Farb-/Größenlisten (werden zu Shop-weiten Terms). */
    public function test_P10b_unbounded_color_list_is_accepted(): void
    {
        $many = implode(',', array_map(fn ($i) => 'Farbe'.$i, range(1, 500)));
        $result = ProductConfigurator::applyInput(ProductConfigurator::defaultsAllDisabled(), [
            'schulpullover' => ['enabled' => '1', 'colors' => $many],
        ]);

        $product = collect($result)->firstWhere('key', 'schulpullover');
        $this->assertCount(500, $product['colors'], 'IST: 500 Farben werden als Attribut-Terms angelegt.');
    }

    // --------------------------------------------------------------- M3-01

    /**
     * M3-01 (hoch): Scheitert beim Schließen der CPT-Schritt, sind die Produkte
     * trotzdem privat — die Schule erreicht aber nie den Status „abgeschlossen"
     * und lässt sich im Tool nicht wieder öffnen. Sackgasse.
     *
     * SOLL: am tatsächlichen Zustand festmachen, nicht am fehlerfreien Lauf.
     */
    public function test_M301_partial_close_leaves_school_unreopenable(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products?*' => Http::response([
                ['id' => 401, 'name' => 'AHS Testschule Schulpullover', 'status' => 'publish'],
            ]),
            'shop.example/wp-json/wc/v3/products/*' => Http::response(['id' => 401], 200),
        ]);

        // Kein pods_post_id -> CPT-Schritt meldet ok = false
        $record = $this->onboarding(['status' => 'angelegt', 'woo_category_id' => 77]);

        $this->post("/bestellfenster-schliessen/{$record->id}")->assertRedirect();

        $record->refresh();
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && ($r->data()['status'] ?? null) === 'private');
        $this->assertSame('angelegt', $record->status, 'IST: Produkte privat, Status aber nicht „abgeschlossen".');

        $response = $this->get('/bestellfenster-schliessen');
        $closed = collect($response->viewData('closedSchools'))->pluck('id')->all();
        $this->assertNotContains($record->id, $closed, 'IST: nicht wieder zu öffnen — Sackgasse.');
    }

    // ---------------------------------------------------------------- S-01

    /**
     * S-01 (mittel): `RevenueReport::build()` ruft den Shop synchron auf, wenn
     * ein Monat fehlt — obwohl Klassenkommentar und CLAUDE.md zusichern, die
     * Seite rufe den Shop nie auf. Im Request-Pfad ist das erreichbar, sobald
     * ein Monat zwischen Fortschrittsprüfung und Auswertung abläuft (der
     * laufende Monat wird nur 30 Minuten gehalten).
     *
     * SOLL: im Request-Pfad mit Budget 0 arbeiten.
     */
    public function test_S01_revenue_report_fetches_from_the_shop_inside_the_request(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products/categories*' => Http::response([]),
            'shop.example/wp-json/wc/v3/products*' => Http::response([]),
            'shop.example/wp-json/wc/v3/orders*' => Http::response([]),
        ]);

        $filters = StatisticsFilters::fromRequest(Request::create('/statistiken'));
        app(StatisticsWarmer::class)->warm($filters, 60.0);
        $this->assertTrue(app(StatisticsWarmer::class)->progress($filters)['done'], 'Aufbau abgeschlossen');

        // Ein Monat fällt weg — wie beim Ablaufen des laufenden Monats.
        $months = app(StatisticsWarmer::class)->years($filters);
        app(\App\Services\Statistics\OrderRepository::class)->forget($months[0], $filters->statuses, $filters->fetchPadding());

        Http::fake([
            'shop.example/wp-json/wc/v3/orders*' => Http::response([]),
        ]);

        app(RevenueReport::class)->build($filters);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/wc/v3/orders'));
    }

    // -------------------------------------------------------------- E2E-01

    /**
     * E2E-01 (hoch): Roter Faden „Folgejahr".
     *
     * `duplicate()` legt für das nächste Bestellfenster einen zweiten Antrag an
     * und lässt die Shop-IDs bewusst leer. Beim Anlegen findet
     * `ensureCategory()` die vorhandene Kategorie wieder — beide Anträge zeigen
     * danach auf DIESELBE Kategorie. Genau das ist der Normalfall, nicht der
     * Sonderfall.
     *
     * Die Statistik ordnet jeder Kategorie aber nur EINEN Antrag zu
     * (`RevenueReport::schools()`, `$byCategory[...] ??=`, sortiert nach
     * `window_end` absteigend) und baut je Kategorie nur EIN Fenster
     * (`windows()`, `$windows[$categoryId]`). Zwei Bestellfenster derselben
     * Schule im selben Schuljahr zählen deshalb als eines — der Umsatz des
     * früheren Fensters fehlt im Durchschnitt.
     *
     * SOLL: je Antrag ein Fenster; Kategorie ist die Zuordnung, nicht die Einheit.
     */
    public function test_E2E01_two_windows_of_one_school_count_as_one(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products/categories*' => Http::response([
                ['id' => 15, 'name' => 'Schulen', 'parent' => 0],
                ['id' => 77, 'name' => 'AHS Testschule', 'parent' => 15],
            ]),
            'shop.example/wp-json/wc/v3/orders*' => Http::response([]),
            'shop.example/wp-json/wc/v3/products*' => Http::response([
                ['id' => 401, 'name' => 'AHS Testschule Schulpullover', 'categories' => [['id' => 77]]],
            ]),
        ]);

        // Zwei Bestellfenster derselben Schule im laufenden Schuljahr
        $year = \App\Services\Statistics\SchoolYear::current();
        $this->onboarding([
            'status' => 'abgeschlossen', 'woo_category_id' => 77,
            'window_start' => $year->start()->copy()->addDays(10)->toDateString(),
            'window_end' => $year->start()->copy()->addDays(30)->toDateString(),
        ]);
        $this->onboarding([
            'status' => 'angelegt', 'woo_category_id' => 77,
            'window_start' => $year->start()->copy()->addDays(120)->toDateString(),
            'window_end' => $year->start()->copy()->addDays(140)->toDateString(),
        ]);

        $filters = StatisticsFilters::fromRequest(Request::create('/statistiken'));
        app(StatisticsWarmer::class)->warm($filters, 60.0);
        $data = app(RevenueReport::class)->build($filters);

        $this->assertSame(
            1,
            $data['current']['collective']['count'],
            'IST: ein Fenster gezählt. SOLL: zwei — es gab zwei Bestellfenster.',
        );
    }

    // ---------------------------------------------------------------- FF-01

    /**
     * FF-01 (hoch): Die Datumsauswertung des Webhooks probiert drei Formate der
     * Reihe nach durch. PHP wertet dabei unmögliche Werte still aus, statt
     * abzulehnen: Aus dem US-Format 04/16/2026 wird über `d/m/Y` der Tag 4 im
     * Monat 16 — und daraus der 04.04.2027. Aus dem Tippfehler 31.02.2026 wird
     * der 03.03.2026. Beides sieht danach wie ein gültiges Datum aus.
     *
     * Folge: falscher Bestellfensterstart im Tool, im Schule-Eintrag, auf dem
     * Präsentationsblatt und in der Fensterzuordnung der Statistik.
     *
     * SOLL: unmögliche Datumswerte ablehnen und den Antrag als prüfbedürftig
     * kennzeichnen, statt still ein plausibles falsches Datum zu erzeugen.
     */
    public function test_FF01_impossible_dates_are_silently_rolled_over(): void
    {
        $payload = ['input_text_6' => 'AHS Testschule', 'datetime' => '31.02.2026'];
        $this->postJson('/webhooks/fluentforms/test-secret', $payload)->assertOk();

        $record = SchoolOnboarding::sole();
        $this->assertSame(
            '2026-03-03',
            $record->window_start->format('Y-m-d'),
            'IST: 31.02. wird zum 03.03. SOLL: als ungültig erkannt.',
        );
    }

    /** FF-01b: Datum im US-Format landet ein Jahr daneben. */
    public function test_FF01b_us_format_date_lands_a_year_off(): void
    {
        $payload = ['input_text_6' => 'AHS Testschule', 'datetime' => '04/16/2026'];
        $this->postJson('/webhooks/fluentforms/test-secret', $payload)->assertOk();

        $record = SchoolOnboarding::sole();
        $this->assertSame(
            '2027-04-04',
            $record->window_start->format('Y-m-d'),
            'IST: 16.04.2026 wird zum 04.04.2027. SOLL: erkannt oder abgelehnt.',
        );
    }

    // ---------------------------------------------------------------- MO-01

    /**
     * MO-01 (hoch, Kosten): Ein Produkt kann bis zu sechs kostenpflichtige
     * Renders auslösen. Der Merker gegen doppelte Abrechnung
     * (`mockup_images`) wird aber erst gesetzt, wenn ALLE Renders eines
     * Produkts fertig sind. Scheitert der letzte, sind die bereits bezahlten
     * verloren und werden beim nächsten Versuch erneut bezahlt.
     *
     * SOLL: jedes fertige Bild sofort vermerken.
     */
    public function test_MO01_paid_renders_are_lost_when_a_later_one_fails(): void
    {
        config([
            'schoolshop.mockups.api_key' => 'dm_key',
            'schoolshop.mockups.base_url' => 'https://mockups.example/v1',
            'schoolshop.mockups.templates.schulpullover' => [
                'lifestyle' => [
                    ['model' => 'female', 'mockup_uuid' => 'm-1', 'smart_object_uuid' => 's-1'],
                    ['model' => 'male', 'mockup_uuid' => 'm-2', 'smart_object_uuid' => 's-2'],
                ],
                'detail' => [
                    ['color' => 'blau', 'mockup_uuid' => 'm-3', 'smart_object_uuid' => 's-3'],
                ],
            ],
        ]);

        $this->fakeCollectiveShop([
            'shop.example/uploads/*' => Http::response('img', 200, ['Content-Type' => 'image/png']),
            'shop.example/wp-json/wp/v2/media*' => Http::response(['id' => 555, 'source_url' => 'https://shop.example/logo.png'], 201),
            'mockups.example/v1/mockups*' => Http::response(['smart_objects' => []]),
            'mockups.example/v1/renders' => Http::sequence()
                ->push(['data' => ['export_path' => 'https://cdn.example/1.jpg']])
                ->push(['data' => ['export_path' => 'https://cdn.example/2.jpg']])
                ->push('', 500),   // der dritte, bezahlte Versuch scheitert
        ]);

        $record = $this->onboarding([
            'mockups_enabled' => true,
            'logo_front_url' => 'https://shop.example/uploads/logo.png',
        ]);
        $record->products = collect($record->products)
            ->map(fn ($p) => [...$p, 'colors' => ['blau']])->all();
        $record->save();

        app(ShopProvisioner::class)->apply($record);

        $record->refresh();
        $renders = 0;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/renders')) {
                $renders++;
            }
        }

        $this->assertSame(3, $renders, 'Drei Renders angestoßen, zwei davon erfolgreich abgerechnet …');
        $this->assertEmpty($record->mockup_images ?? [], '… vermerkt ist keines. Der nächste Versuch zahlt erneut.');
    }

    // ---------------------------------------------------------------- PR-02

    /**
     * PR-02 (hoch): Der Teilstring-Vergleich greift nur, wenn es KEINEN exakten
     * Treffer gibt — das ist gut gelöst. Gibt es aber keine Farbe „Red“,
     * sondern nur Abstufungen, zieht „rot“ sie alle herein. Zusammen mit der
     * 100-Varianten-Grenze wird danach stur am Ende abgeschnitten: Eine
     * gewünschte Farbe kann dabei vollständig herausfallen, ohne unter
     * `missing_colors` aufzutauchen — gemeldet wird nur „gekürzt“.
     *
     * SOLL: erst je gewünschter Farbe/Größe gleichmäßig auswählen, dann kürzen;
     * herausgefallene Wünsche benennen.
     */
    public function test_PR02_capping_silently_drops_a_requested_color(): void
    {
        $variants = [];
        // Vier Rot-Abstufungen, aber kein schlichtes „Red“ — der Teilstring-
        // Vergleich zieht deshalb alle vier herein (100 Varianten) …
        foreach (['Dark Red', 'Red Heather', 'Fire Red', 'Deep Red'] as $shade) {
            for ($i = 1; $i <= 25; $i++) {
                $variants[] = ['id' => count($variants) + 1, 'title' => "{$shade} / S{$i}", 'options' => ['color' => $shade, 'size' => "S{$i}"]];
            }
        }
        // … und danach die gewünschte blaue Farbe
        $variants[] = ['id' => 999, 'title' => 'Blue / M', 'options' => ['color' => 'Blue', 'size' => 'M']];

        $selection = app(PrintifyProvisioner::class)->selectVariants($variants, [
            'colors' => ['rot', 'blau'],
            'sizes' => [],
        ]);

        $colors = array_values(array_unique(array_map(
            fn ($v) => $v['options']['color'],
            $selection['variants'],
        )));

        $this->assertTrue($selection['capped'], 'Die Auswahl wurde gekürzt.');
        $this->assertNotContains('Blue', $colors, 'IST: Blau ist komplett herausgefallen …');
        $this->assertNotContains('blau', $selection['missing_colors'], '… wird aber nicht als fehlend gemeldet.');
        $this->assertContains('Dark Red', $colors, 'IST: nicht gewünschte Rot-Abstufungen wurden stattdessen angelegt.');
    }

    // ---------------------------------------------------------------- SO-01

    /**
     * SO-01 (hoch): Die Antragsseite ruft für die Bestellzahlen den Shop
     * synchron ab — und zwar mit einer eigenen, seitenweisen Abfrage JE
     * PRODUKT der Schule. Bei zehn Produkten sind das zehn Abfragefolgen mit je
     * 30 Sekunden Zeitablauf, bevor die Seite überhaupt erscheint.
     *
     * SOLL: wie bei der Statistik nach der Antwort laden, oder nur auf Klick.
     */
    public function test_SO01_school_page_queries_the_shop_once_per_product(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products?*' => Http::response([
                ['id' => 401, 'name' => 'A'], ['id' => 402, 'name' => 'B'], ['id' => 403, 'name' => 'C'],
            ]),
            'shop.example/wp-json/wc/v3/orders*' => Http::response([]),
        ]);

        $record = $this->onboarding(['woo_category_id' => 77, 'status' => 'angelegt']);

        $this->get("/schulen/{$record->id}")->assertOk();

        $orderCalls = 0;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/wc/v3/orders')) {
                $orderCalls++;
            }
        }

        $this->assertGreaterThanOrEqual(3, $orderCalls, 'IST: eine Bestellabfrage je Produkt, synchron im Seitenaufruf.');
    }

    // ---------------------------------------------------------------- AU-01

    /**
     * AU-01 (mittel): Der Zugang beruht auf einem einzigen gemeinsamen
     * Passwort, und die Anmeldung ist unbegrenzt oft versuchbar. `hash_equals`
     * schützt gegen Zeitmessung, nicht gegen Durchprobieren.
     *
     * SOLL: `throttle:5,1` auf der Anmelderoute.
     */
    public function test_AU01_login_has_no_rate_limit(): void
    {
        config(['ordersuite.password' => 'geheim']);

        $statuses = [];
        for ($i = 0; $i < 25; $i++) {
            $statuses[] = $this->post('/login', ['password' => 'falsch-'.$i])->status();
        }

        $this->assertNotContains(429, $statuses, 'IST: 25 Fehlversuche ohne jede Bremse.');
    }

    // ---------------------------------------------------------------- O-01

    /**
     * O-01 (mittel): Eine beschädigte meta.json führt zum nackten 500er statt
     * zur erklärten Meldung — entgegen der eigenen Konvention.
     *
     * SOLL: verständliche Meldung, zurück zu Schritt 1.
     */
    public function test_O01_corrupt_job_metadata_produces_a_bare_500(): void
    {
        $jobId = (string) Str::uuid();
        Storage::disk('local')->put("jobs/{$jobId}/meta.json", 'kein json');

        $this->get("/job/{$jobId}")->assertStatus(500);
    }
}
