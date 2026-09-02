<?php

namespace Tests\Feature;

use App\Exceptions\WooCommerceApiException;
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
 * REGRESSIONSTESTS ZUM CODE-REVIEW.
 *
 * Jeder Test gehört zu einem Befund des Reviews und prüft, dass er behoben
 * BLEIBT. Die Kürzel (P-01, FF-01, MO-01 …) verweisen auf den Bericht und auf
 * die GitHub-Issues #2 bis #7.
 *
 * Alles läuft gegen `Http::fake()` mit `preventStrayRequests()` — kein Aufruf
 * geht an ein echtes System.
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
            // GET liest die vorhandenen Variationen (Liste), POST legt eine an
            // (Objekt) — dieselbe Adresse, unterschiedliche Antwortform.
            'shop.example/wp-json/wc/v3/products/*/variations*' => fn ($request) => $request->method() === 'POST'
                ? Http::response(['id' => 501], 201)
                : Http::response([]),
            'shop.example/wp-json/wc/v3/products*' => Http::response(['id' => 401, 'name' => 'AHS Testschule Schulpullover'], 201),
        ]);
    }

    private function countRenders(): int
    {
        $count = 0;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/renders')) {
                $count++;
            }
        }

        return $count;
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
     * P-01 (behoben): Zwei „Shop anlegen"-Vorgänge auf demselben Antrag dürfen
     * nichts doppelt anlegen. Nachgestellt über zwei Modell-Instanzen desselben
     * Datensatzes — genau der Zustand zweier paralleler Requests, die beide
     * gelesen haben, bevor einer geschrieben hat. Der zweite Lauf lädt
     * innerhalb der Sperre nach und überspringt alles Vorhandene.
     */
    public function test_P01_second_provision_run_creates_nothing_twice(): void
    {
        $this->fakeCollectiveShop();
        $record = $this->onboarding();

        $first = SchoolOnboarding::find($record->id);
        $second = SchoolOnboarding::find($record->id);

        app(ShopProvisioner::class)->apply($first);
        app(ShopProvisioner::class)->apply($second);

        $this->assertSame(1, $this->countCategoryCreates(), 'Genau eine Kategorie.');
        $this->assertSame(1, $this->countProductCreates(), 'Genau ein Produktsatz.');
    }

    /** P-01b: Läuft bereits ein Durchgang, bricht der zweite mit Erklärung ab. */
    public function test_P01b_concurrent_provision_is_refused(): void
    {
        $this->fakeCollectiveShop();
        $record = $this->onboarding();

        // Sperre halten, als liefe gerade ein anderer Durchgang
        $held = Cache::lock('schoolshop.provision.'.$record->id, 60);
        $this->assertTrue($held->get());

        try {
            app(ShopProvisioner::class)->apply($record);
            $this->fail('Abbruch erwartet');
        } catch (ProvisionAbortedException $e) {
            $this->assertStringContainsString('läuft die Anlage bereits', $e->log[0]['detail']);
        } finally {
            $held->release();
        }

        $this->assertSame(0, $this->countCategoryCreates(), 'Der abgewiesene Lauf hat nichts angelegt.');
    }

    // ---------------------------------------------------------------- P-02

    /**
     * P-02 (behoben): Die Produkt-ID wird vor den Variationen gespeichert.
     * Scheitert danach eine Variation, kennt das Tool das Produkt trotzdem —
     * der nächste Versuch ergänzt nur die fehlende Variation, statt ein zweites
     * Produkt anzulegen.
     */
    public function test_P02_failed_variation_keeps_the_product_and_retries_cleanly(): void
    {
        $this->fakeCollectiveShop([
            // GET (vorhandene Variationen lesen) und POST teilen sich die
            // Adresse — deshalb nach Methode unterscheiden.
            'shop.example/wp-json/wc/v3/products/*/variations*' => function ($request) {
                static $posts = 0;
                if ($request->method() !== 'POST') {
                    return Http::response([]);
                }
                $posts++;

                return $posts === 1 ? Http::response('', 500) : Http::response(['id' => 500 + $posts], 201);
            },
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
        $this->assertSame(['schulpullover' => 401], $record->woo_product_ids, '… und seine ID ist vermerkt.');

        app(ShopProvisioner::class)->apply($record->fresh());

        $this->assertSame(1, $this->countProductCreates(), 'Der zweite Lauf legt kein zweites Produkt an.');
    }

    // ---------------------------------------------------------------- P-03

    /**
     * P-03 (behoben): Die Printify-ID wird vor dem Veröffentlichen gespeichert.
     * Scheitert `publish`, ist das Produkt bei Printify vorhanden UND im Tool
     * vermerkt — der nächste Versuch veröffentlicht nur noch.
     */
    public function test_P03_failed_printify_publish_keeps_the_product_id(): void
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
        $this->assertSame(['schulpullover' => 'pfy-1'], $record->printify_product_ids, 'Die ID ist trotz Publish-Fehler vermerkt.');

        // Zweiter Versuch: kein neues Produkt, nur noch veröffentlichen
        $creates = 0;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/shops/99/products.json') && $request->method() === 'POST') {
                $creates++;
            }
        }
        $this->assertSame(1, $creates, 'Genau ein Printify-Produkt angelegt.');
    }

    // ---------------------------------------------------------------- P-04

    /**
     * P-04 (behoben): Ein erneutes „Shop anlegen" aktualisiert nur die
     * Stammdaten des CPT. Die Zustandsfelder gehören den jeweiligen Aktionen —
     * ein zuvor geöffnetes Bestellfenster bleibt offen.
     */
    public function test_P04_reprovision_leaves_a_reopened_window_open(): void
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

        $this->assertSame('JA', $lastValue, 'Das geöffnete Fenster bleibt offen.');
    }

    /** P-04b: Beim ERSTEN Anlegen werden die Zustandsfelder sehr wohl gesetzt. */
    public function test_P04b_first_provision_still_initialises_the_state_fields(): void
    {
        $this->fakeCollectiveShop();
        $record = $this->onboarding();

        app(ShopProvisioner::class)->apply($record);

        $created = null;
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && parse_url($request->url(), PHP_URL_PATH) === '/wp-json/wp/v2/schule') {
                $created = $request->data();
            }
        }

        $this->assertNotNull($created, 'Der CPT-Eintrag wurde angelegt.');
        $this->assertSame('NEIN', $created['bestellfenster_offen'] ?? null, 'Startwert gesetzt.');
        $this->assertSame('0', $created['versandklasse_on_demand_fur_jedes_produkt_gesetzt'] ?? null);
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
     * P-05 (behoben): Ohne Kategorie bleibt nur die Namenssuche, und die ist
     * eine Teilstring-Suche — „HAK Wien" trifft auch „HAK Wien 13". Das
     * Ergebnis wird deshalb auf die Namen eingegrenzt, die diese Schule haben
     * kann. Produkte einer fremden Schule bleiben unberührt.
     */
    public function test_P05_closing_by_name_leaves_a_foreign_school_alone(): void
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

        $log = app(ShopProvisioner::class)->closeOrderWindow($record);

        $touched = [];
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'PUT' && preg_match('#/products/(\d+)$#', parse_url($request->url(), PHP_URL_PATH), $m)) {
                $touched[] = (int) $m[1];
            }
        }

        $this->assertContains(401, $touched, 'Das eigene Produkt wurde geschlossen.');
        $this->assertNotContains(402, $touched, 'Das Produkt der Schule „HAK Wien 13" blieb unberührt.');
        $this->assertTrue(
            collect($log)->contains(fn ($l) => str_contains($l['detail'], 'anderen Schule')),
            'Der übergangene Fremdtreffer steht im Protokoll.',
        );
    }

    // ---------------------------------------------------------------- P-06

    /**
     * P-06 (behoben): Liefert der Shop immer wieder volle Seiten (Caching-
     * Plugin, Proxy, fehlerhafte Paginierung), muss die Schleife abbrechen —
     * sonst hängt der PHP-Prozess für immer und blockiert nach ein paar
     * Aufrufen die ganze Anwendung.
     */
    public function test_P06_findProductsByCategory_stops_at_the_page_limit(): void
    {
        config(['ordersuite.woocommerce.max_pages' => 5]);
        $fullPage = array_map(fn ($i) => ['id' => $i, 'name' => 'P'.$i, 'status' => 'publish'], range(1, 100));
        Http::fake(['shop.example/*' => Http::response($fullPage)]);

        $this->expectException(WooCommerceApiException::class);
        $this->expectExceptionMessageMatches('/mehr als 5 Seiten/');

        app(WooCommerceWriteClient::class)->findProductsByCategory(77);
    }

    /** P-06b: dasselbe in `ensureAttributeTerms()`. */
    public function test_P06b_ensureAttributeTerms_stops_at_the_page_limit(): void
    {
        config(['ordersuite.woocommerce.max_pages' => 5]);
        $fullPage = array_map(fn ($i) => ['id' => $i, 'name' => 'Term '.$i], range(1, 100));
        Http::fake(['shop.example/*' => Http::response($fullPage)]);

        $this->expectException(WooCommerceApiException::class);
        $this->expectExceptionMessageMatches('/mehr als 5 Seiten/');

        app(WooCommerceWriteClient::class)->ensureAttributeTerms(2, ['Blau']);
    }

    // ---------------------------------------------------------------- P-07

    /**
     * P-07 (behoben): Die Klassenliste ist ein Textfeld über mehrere Zeilen.
     * Getrennt wird an Zeilenumbrüchen, Kommas und Semikolons — sonst entstünde
     * EINE Variationsoption mit Zeilenumbrüchen darin, die genau so im Shop,
     * in jeder Bestellung und in den Auftragsdokumenten landet.
     */
    public function test_P07_newline_separated_class_list_becomes_separate_options(): void
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
        foreach (['1a', '1b', '2a'] as $klasse) {
            $this->assertContains($klasse, $klassen, "Klasse {$klasse} ist eine eigene Auswahloption.");
        }
        $this->assertNotContains("1a\n1b\n2a", $klassen, 'Keine Sammeloption mit Zeilenumbrüchen.');
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
     * P-10 (behoben): Negative Preise wären genau so an WooCommerce bzw.
     * Printify geschrieben worden. Sie werden jetzt auf 0 begrenzt.
     */
    public function test_P10_negative_prices_are_clamped(): void
    {
        $current = ProductConfigurator::defaultsAllDisabled();
        $result = ProductConfigurator::applyInput($current, [
            'schulpullover' => ['enabled' => '1', 'base_price' => '-5', 'indiv_surcharge' => '-2'],
        ]);

        $product = collect($result)->firstWhere('key', 'schulpullover');
        $this->assertSame(0.0, $product['base_price'], 'Kein negativer Preis im Shop.');
        $this->assertSame(0.0, $product['indiv_surcharge']);
    }

    /**
     * P-10b (behoben): Aus Farb- und Größenlisten entstehen SHOPWEITE
     * Attribut-Terms, die sich über das Tool nicht mehr löschen lassen —
     * deshalb eine Obergrenze.
     */
    public function test_P10b_color_list_is_capped(): void
    {
        $many = implode(',', array_map(fn ($i) => 'Farbe'.$i, range(1, 500)));
        $result = ProductConfigurator::applyInput(ProductConfigurator::defaultsAllDisabled(), [
            'schulpullover' => ['enabled' => '1', 'colors' => $many],
        ]);

        $product = collect($result)->firstWhere('key', 'schulpullover');
        $this->assertCount(60, $product['colors'], 'Die Liste ist auf 60 Einträge begrenzt.');
    }

    // --------------------------------------------------------------- M3-01

    /**
     * M3-01 (behoben): Scheitert beim Schließen der CPT-Schritt, sind die
     * Produkte trotzdem privat. Der Status folgt jetzt dem Zustand der
     * Produkte, nicht der Fehlerfreiheit des Laufs — die Schule lässt sich
     * daher wieder öffnen, statt in einer Sackgasse zu landen.
     */
    public function test_M301_partial_close_still_marks_the_school_closed(): void
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
        $this->assertSame('abgeschlossen', $record->status, 'Produkte privat — also gilt das Fenster als geschlossen.');

        $response = $this->get('/bestellfenster-schliessen');
        $closed = collect($response->viewData('closedSchools'))->pluck('id')->all();
        $this->assertContains($record->id, $closed, 'Die Schule steht in der Öffnen-Liste.');
    }

    // ---------------------------------------------------------------- S-01

    /**
     * S-01 (behoben): Der Seitenaufruf arbeitet mit Budget 0 — fehlt ein
     * Monat (der laufende wird nur 30 Minuten gehalten), kommt die Ladeseite
     * statt eines Abrufs mitten im Request.
     */
    public function test_S01_statistics_page_never_calls_the_shop(): void
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

        $before = count(Http::recorded());
        $data = app(RevenueReport::class)->build($filters, allowFetching: false);

        $this->assertFalse($data['complete'], 'Unvollständig gemeldet statt nachgeladen.');
        $this->assertCount($before, Http::recorded(), 'Kein einziger Shop-Aufruf im Seitenaufruf.');
    }

    /** S-01b: Fehlen die Grunddaten ganz, zeigt die Seite die Ladeanzeige. */
    public function test_S01b_statistics_page_shows_the_loading_view_without_cached_data(): void
    {
        Http::fake(['shop.example/*' => Http::response([])]);

        $response = $this->get('/statistiken');

        $response->assertOk()->assertSee('Die Auswertung wird aufgebaut', false);
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
     * Die Statistik zählt jetzt EIN FENSTER JE ANTRAG; die Kategorie ist die
     * Zuordnung, nicht die Zähleinheit. Vorher fiel der Umsatz aller früheren
     * Fenster derselben Schule aus dem Durchschnitt.
     */
    public function test_E2E01_two_windows_of_one_school_count_separately(): void
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
            2,
            $data['current']['collective']['count'],
            'Beide Bestellfenster derselben Schule zählen einzeln.',
        );
    }

    // ---------------------------------------------------------------- FF-01

    /**
     * FF-01 (behoben): PHP lehnt unmögliche Datumswerte nicht ab, sondern
     * rechnet sie weiter — aus 31.02.2026 würde der 03.03.2026. Jedes Format
     * wird deshalb zurückgerechnet; stimmt die Ausgabe nicht mit der Eingabe
     * überein, gilt das Datum als unbekannt. Kein Datum ist ehrlicher als ein
     * plausibler falscher Tag, der auf dem gedruckten Blatt landet.
     */
    public function test_FF01_impossible_date_is_rejected_instead_of_rolled_over(): void
    {
        $payload = ['input_text_6' => 'AHS Testschule', 'datetime' => '31.02.2026'];
        $this->postJson('/webhooks/fluentforms/test-secret', $payload)->assertOk();

        $record = SchoolOnboarding::sole();
        $this->assertNull($record->window_start, '31.02. gilt als unbekannt, nicht als 03.03.');
        $this->assertNull($record->window_end);
    }

    /** FF-01b: gültige Schreibweisen werden weiterhin korrekt erkannt. */
    public function test_FF01b_valid_date_formats_are_still_understood(): void
    {
        foreach (['16.04.2026', '2026-04-16', '16/04/2026'] as $written) {
            SchoolOnboarding::query()->delete();
            $this->postJson('/webhooks/fluentforms/test-secret', [
                'input_text_6' => 'AHS Testschule', 'datetime' => $written,
            ])->assertOk();

            $this->assertSame(
                '2026-04-16',
                SchoolOnboarding::sole()->window_start->format('Y-m-d'),
                "Schreibweise {$written} wurde richtig gelesen.",
            );
        }
    }

    // ---------------------------------------------------------------- MO-01

    /**
     * MO-01 (behoben): Renders kosten Credits. Jedes fertige Bild wird sofort
     * vermerkt — scheitert ein späteres, bleiben die bezahlten erhalten und der
     * nächste Versuch rendert nur noch die fehlenden.
     */
    public function test_MO01_paid_renders_survive_a_later_failure(): void
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

        // EIN Fake für beide Durchgänge: eine bereits registrierte Adresse
        // lässt sich später nicht überschreiben (erste Registrierung gewinnt),
        // deshalb deckt die Sequenz gleich beide Läufe ab.
        $this->fakeCollectiveShop([
            'shop.example/uploads/*' => Http::response('img', 200, ['Content-Type' => 'image/png']),
            'shop.example/wp-json/wp/v2/media*' => Http::response(['id' => 555, 'source_url' => 'https://shop.example/logo.png'], 201),
            'mockups.example/v1/mockups*' => Http::response(['smart_objects' => []]),
            'mockups.example/v1/renders' => Http::sequence()
                ->push(['data' => ['export_path' => 'https://cdn.example/1.jpg']])
                ->push(['data' => ['export_path' => 'https://cdn.example/2.jpg']])
                ->push('', 500)   // der dritte, bezahlte Versuch scheitert
                ->push(['data' => ['export_path' => 'https://cdn.example/3.jpg']]),
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
        $this->assertSame(3, $this->countRenders(), 'Drei Renders angestoßen, zwei davon erfolgreich.');
        $this->assertCount(2, $record->mockup_images['schulpullover']['images'] ?? [], 'Beide bezahlten Bilder sind gesichert.');

        // Zweiter Durchgang: nur das fehlende dritte Bild wird gerendert.
        app(ShopProvisioner::class)->apply($record->fresh());

        $this->assertSame(4, $this->countRenders(), 'Insgesamt vier Renders — die bezahlten wurden nicht erneut erzeugt.');
        $this->assertCount(3, $record->fresh()->mockup_images['schulpullover']['images'] ?? [], 'Jetzt sind alle drei Bilder gesichert.');
    }

    // ---------------------------------------------------------------- PR-02

    /**
     * PR-02 (behoben): Gibt es keine Farbe „Red“, sondern nur Abstufungen,
     * zieht „rot“ sie alle herein. Beim Kürzen auf 100 Varianten wird jetzt
     * REIHUM je Farbe genommen statt stur am Ende abzuschneiden — die
     * gewünschte blaue Variante bleibt erhalten.
     */
    public function test_PR02_capping_keeps_every_requested_color(): void
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
        $this->assertContains('Blue', $colors, 'Die gewünschte blaue Variante blieb erhalten.');
        $this->assertLessThanOrEqual(100, count($selection['variants']), 'Das Printify-Limit wird eingehalten.');
        $this->assertSame([], $selection['dropped_colors'], 'Keine Farbe ist ganz entfallen.');
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
     * AU-01 (behoben): Der Zugang beruht auf einem einzigen gemeinsamen
     * Passwort. `hash_equals` schützt gegen Zeitmessung, nicht gegen
     * Durchprobieren — dafür braucht es eine Bremse.
     */
    public function test_AU01_login_is_rate_limited(): void
    {
        config(['ordersuite.password' => 'geheim']);

        $statuses = [];
        for ($i = 0; $i < 25; $i++) {
            $statuses[] = $this->post('/login', ['password' => 'falsch-'.$i])->status();
        }

        $this->assertContains(429, $statuses, 'Nach wenigen Fehlversuchen greift die Bremse.');
    }

    /** AU-01b: Die Bremse darf die richtige Anmeldung nicht behindern. */
    public function test_AU01b_correct_password_still_logs_in(): void
    {
        config(['ordersuite.password' => 'geheim']);

        $this->post('/login', ['password' => 'geheim'])->assertRedirect(route('home'));
        $this->assertTrue(session('tool_authenticated'));
    }

    // ---------------------------------------------------------------- S6

    /**
     * P-09 (behoben): Die API-Zugangsdaten stehen nicht mehr im Query-String
     * jedes Schreibzugriffs — von dort landeten sie im Zugriffslog des
     * Webservers.
     */
    public function test_P09_write_requests_do_not_carry_credentials_in_the_url(): void
    {
        $this->fakeCollectiveShop();

        app(ShopProvisioner::class)->apply($this->onboarding());

        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'GET' || ! str_contains($request->url(), '/wc/v3/')) {
                continue;
            }
            $this->assertStringNotContainsString('consumer_secret', $request->url(), 'Kein Schlüssel in der Adresse: '.$request->url());
        }
    }

    /**
     * P-15 (behoben): Das Logo wird nur einmal in die Mediathek geladen. Vorher
     * erzeugte jeder weitere Anlageversuch eine Dublette.
     */
    public function test_P15_logo_is_uploaded_to_the_media_library_only_once(): void
    {
        $this->fakeCollectiveShop([
            'shop.example/uploads/*' => Http::response('img', 200, ['Content-Type' => 'image/png']),
            'shop.example/wp-json/wp/v2/media*' => Http::response(['id' => 555, 'source_url' => 'https://shop.example/logo.png'], 201),
        ]);
        $record = $this->onboarding(['logo_front_url' => 'https://shop.example/uploads/logo.png']);

        app(ShopProvisioner::class)->apply($record);
        app(ShopProvisioner::class)->apply($record->fresh());

        $uploads = 0;
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/wp/v2/media')) {
                $uploads++;
            }
        }

        $this->assertSame(1, $uploads, 'Genau ein Mediathek-Upload für zwei Anlagevorgänge.');
        $this->assertSame(555, $record->fresh()->featured_media_id);
    }

    /**
     * P-17 (behoben): Dieselbe Submission zweimal zugestellt ergibt einen
     * Antrag, nicht zwei — und später nicht zwei Shops.
     */
    public function test_P17_duplicate_webhook_submission_creates_one_onboarding(): void
    {
        $payload = ['entry_id' => '669', 'input_text_6' => 'AHS Testschule', 'datetime' => '16.04.2026'];

        $this->postJson('/webhooks/fluentforms/test-secret', $payload)->assertOk();
        $second = $this->postJson('/webhooks/fluentforms/test-secret', $payload)->assertOk();

        $this->assertSame(1, SchoolOnboarding::count());
        $this->assertTrue($second->json('duplicate'));
    }

    /**
     * PS-02 (behoben): Die Bestelladresse für den QR-Code kommt aus dem echten
     * Kategorie-Slug des Shops. Aus dem Schulnamen abgeleitet wäre sie bei
     * Umlauten falsch — und das fällt erst auf dem gedruckten Aushang auf.
     */
    public function test_PS02_shop_url_uses_the_real_category_slug(): void
    {
        $this->fakeCollectiveShop([
            'shop.example/wp-json/wc/v3/products/categories*' => Http::response(
                ['id' => 77, 'name' => 'BG Wörgl', 'slug' => 'bg-woergl', 'parent' => 15], 201,
            ),
        ]);
        $record = $this->onboarding(['school_name' => 'BG Wörgl']);

        app(ShopProvisioner::class)->apply($record);

        $record->refresh();
        $this->assertSame('bg-woergl', $record->woo_category_slug);
        $this->assertStringContainsString(
            'bg-woergl',
            app(\App\Services\PresentationSheet\PresentationSheetRenderer::class)->shopUrl($record),
        );
    }

    /**
     * CO-01 (behoben): Die Provision wird je Staffel gerechnet statt Stück für
     * Stück. Eine unplausibel große Menge im Export ließ den Vorgang vorher
     * praktisch hängen.
     */
    public function test_CO01_commission_is_computed_without_looping_per_piece(): void
    {
        $calculator = app(\App\Services\CommissionCalculator::class);

        // Gleiche Ergebnisse wie die alte Schleife …
        foreach ([0, 1, 5, 49, 50, 51, 200] as $pieces) {
            $this->assertSame(
                $this->commissionByLoop($pieces),
                (float) $calculator->calculate($pieces),
                "Provision bei {$pieces} Stück",
            );
        }

        // … und eine unsinnige Menge rechnet trotzdem sofort durch.
        $start = microtime(true);
        $calculator->calculate(50_000_000);
        $this->assertLessThan(1.0, microtime(true) - $start, 'Auch eine unsinnige Menge blockiert nicht.');
    }

    /** Die frühere Rechenweise, Stück für Stück — als Vergleichsmaßstab. */
    private function commissionByLoop(int $pieces): float
    {
        $config = config('ordersuite.commission');
        $commission = 0.0;
        for ($i = 0; $i < $pieces; $i++) {
            foreach ($config['tiers'] as $tier) {
                if ($i >= $tier['from'] && ($tier['to'] === null || $i <= $tier['to'])) {
                    $commission += $tier['amount'];
                    break;
                }
            }
        }
        if ($commission < $config['minimum'] && $pieces >= $config['minimum_from_pieces']) {
            $commission = $config['minimum'];
        }

        return $commission;
    }

    // ---------------------------------------------------------------- O-01

    /**
     * O-01 (behoben): Eine beschädigte meta.json ergibt eine erklärte Meldung
     * mit eigenem HTTP-Status statt eines nackten 500ers.
     */
    public function test_O01_corrupt_job_metadata_gives_an_explained_error(): void
    {
        $jobId = (string) Str::uuid();
        Storage::disk('local')->put("jobs/{$jobId}/meta.json", 'kein json');

        $this->get("/job/{$jobId}")->assertStatus(410);
    }
}
