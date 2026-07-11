<?php

namespace Tests\Feature;

use App\Services\OrderTransformer;
use App\Services\ShopOrderFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Weg 1 (WooCommerce-API-Import): repliziert den Export des Plugins
 * "Advanced Order Export For WooCommerce" — getestet gegen simulierte
 * API-Antworten, deren Struktur den echten Referenz-Exports entspricht
 * (AHS-Korneuburg-Stil mit pa_*-Attributen, St.-Johannis-Stil mit Colors/Sizes).
 */
class ShopExportApiTest extends TestCase
{
    private const PIF_LABEL = "Individualisierungstext \n(falls \"Ja\" ausgewählt)";

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ordersuite.woocommerce.store_url' => 'https://shop.example',
            'ordersuite.woocommerce.consumer_key' => 'ck_test',
            'ordersuite.woocommerce.consumer_secret' => 'cs_test',
        ]);
    }

    private function fakeShop(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products/categories*' => Http::response([
                ['id' => 7, 'name' => 'AHS Korneuburg', 'count' => 12],
                ['id' => 9, 'name' => 'St.-Johannis-Schule Bremen', 'count' => 8],
            ], 200, ['X-WP-TotalPages' => '1']),
            'shop.example/wp-json/wc/v3/products*' => Http::response([
                ['id' => 101], ['id' => 102],
            ], 200, ['X-WP-TotalPages' => '1']),
            'shop.example/wp-json/wc/v3/orders*' => Http::response([
                // Neueste Bestellung zuerst (Order-ID absteigend, wie die API mit order=desc liefert)
                [
                    'id' => 2002,
                    'status' => 'completed',
                    'total' => '50.98',
                    'customer_note' => '',
                    'billing' => ['first_name' => 'Jakob', 'last_name' => 'Jäger'],
                    'meta_data' => [],
                    'line_items' => [
                        [
                            'product_id' => 101,
                            'name' => 'AHS Korneuburg STICK-Hoodie + Backprint - Blau, M',
                            'parent_name' => 'AHS Korneuburg STICK-Hoodie + Backprint',
                            'quantity' => 2,
                            'meta_data' => [
                                ['key' => 'pa_size', 'display_key' => 'Größe', 'value' => 'm', 'display_value' => 'M'],
                                ['key' => 'pa_color', 'display_key' => 'Farbe', 'value' => 'blau', 'display_value' => 'Blau'],
                                ['key' => 'klasse', 'display_key' => 'Klasse', 'value' => '1a', 'display_value' => '1a'],
                                ['key' => 'pa_individualisierung', 'display_key' => 'Individualisierung', 'value' => 'ja', 'display_value' => 'Ja'],
                                ['key' => self::PIF_LABEL, 'display_key' => self::PIF_LABEL, 'value' => 'Jakob', 'display_value' => 'Jakob'],
                                ['key' => '_internal_meta', 'display_key' => '_internal_meta', 'value' => 'x', 'display_value' => 'x'],
                            ],
                        ],
                        [
                            // Produkt einer anderen Kategorie -> muss herausgefiltert werden
                            'product_id' => 999,
                            'name' => 'Andere Schule Shirt',
                            'quantity' => 1,
                            'meta_data' => [],
                        ],
                    ],
                ],
                [
                    'id' => 2001,
                    'status' => 'processing',
                    'total' => '45.59',
                    'customer_note' => 'Bitte klingeln',
                    'billing' => ['first_name' => 'Femke', 'last_name' => 'Backenköhler'],
                    'meta_data' => [
                        ['key' => '_additional_wooccm4', 'value' => 'Checkout-Text'],
                    ],
                    'line_items' => [
                        [
                            'product_id' => 102,
                            'name' => 'St.-Johannis-Schule Bremen Schulhoodie - Jet Black, S',
                            'parent_name' => 'St.-Johannis-Schule Bremen Schulhoodie',
                            'quantity' => 1,
                            'meta_data' => [
                                ['key' => 'colors', 'display_key' => 'Colors', 'value' => 'jet-black', 'display_value' => 'Jet Black'],
                                ['key' => 'sizes', 'display_key' => 'Sizes', 'value' => 's', 'display_value' => 'S'],
                            ],
                        ],
                    ],
                ],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);
    }

    public function test_fetcher_replicates_plugin_export_rows(): void
    {
        $this->fakeShop();
        $table = app(ShopOrderFetcher::class)->fetch(7, ['processing', 'on-hold', 'completed']);

        $this->assertSame(ShopOrderFetcher::COLUMNS, $table['columns']);
        $this->assertCount(2, $table['rows']);
        $this->assertSame(2, $table['orderCount']);

        $ahs = $table['rows'][0];
        $this->assertSame('AHS Korneuburg STICK-Hoodie + Backprint', $ahs['Item Name(löschen)']);
        $this->assertSame('x', $ahs['Karton']);
        $this->assertSame('Jakob', $ahs['Vorname']);
        $this->assertSame('Jäger', $ahs['Nachnahme (Rechnungsadresse)']);
        $this->assertSame(2, $ahs['Anzahl']);
        $this->assertSame('M', $ahs['Größe']);
        $this->assertSame('Blau', $ahs['Farbe']);
        $this->assertSame('1a', $ahs['Klasse']);
        $this->assertSame('Ja', $ahs['Individualisierung']);
        // Exakt das Plugin-Format "\n{Label}: {Wert}" — Grundlage für den 50-Zeichen-Schnitt
        $this->assertSame("\n".self::PIF_LABEL.': Jakob', $ahs['Input Fields']);
        // Product Variation ohne Eingabefelder und ohne _-Metas
        $this->assertSame('Größe: M | Farbe: Blau | Klasse: 1a | Individualisierung: Ja', $ahs['Product Variation']);
        $this->assertSame(50.98, $ahs['Bestellung Gesamtsumme(löschen)']);

        $bremen = $table['rows'][1];
        $this->assertSame('St.-Johannis-Schule Bremen Schulhoodie', $bremen['Item Name(löschen)']);
        $this->assertNull($bremen['Größe']);
        $this->assertNull($bremen['Klasse']);
        $this->assertSame('Colors: Jet Black | Sizes: S', $bremen['Product Variation']);
        $this->assertSame('Bitte klingeln', $bremen['Bestellnotiz']);
        $this->assertSame('Checkout-Text', $bremen['Individualisierungstext(zählt nur wenn Individualisierung Ja)']);
    }

    public function test_api_input_fields_survive_legacy_50_char_cut(): void
    {
        $this->fakeShop();
        $table = app(ShopOrderFetcher::class)->fetch(7, ['completed']);

        $result = (new OrderTransformer)->transform(['columns' => $table['columns'], 'rows' => $table['rows']]);
        $texts = array_column($result->ordersRows, OrderTransformer::INDIV_TEXT_COLUMN);
        $this->assertContains('Jakob', $texts, 'Individualisierungstext muss nach dem 50-Zeichen-Schnitt exakt erhalten bleiben');
    }

    public function test_full_flow_from_api_to_documents(): void
    {
        $this->fakeShop();

        $this->get('/shop-export')->assertOk()->assertSee('AHS Korneuburg')->assertSee('In Wartestellung');

        $response = $this->post('/shop-export', [
            'category' => 7,
            'category_name' => 'AHS Korneuburg',
            'statuses' => ['processing', 'on-hold', 'completed'],
        ]);
        $response->assertRedirect();
        $jobUrl = $response->headers->get('Location');

        $this->get($jobUrl)->assertOk()->assertSee('Aus dem Shop geladen: AHS Korneuburg');

        $this->post($jobUrl.'/generate', ['ordername' => 'AHS Korneuburg', 'orderinformation' => ''])->assertRedirect();
        $this->get($jobUrl.'/result')->assertOk()->assertSee('Rohdaten (wie Plugin-Export)');
        $this->get($jobUrl.'/download/input.xlsx')->assertOk();
        $this->get($jobUrl.'/download/AHS_Korneuburg_orderreport_internal.xlsx')->assertOk();
    }

    public function test_unauthorized_key_shows_friendly_error(): void
    {
        Http::fake(['shop.example/*' => Http::response(['code' => 'woocommerce_rest_authentication_error'], 401)]);

        $this->get('/shop-export')
            ->assertOk()
            ->assertSee('Der Shop hat den API-Schlüssel abgelehnt')
            ->assertSee('Technische Details');
    }

    public function test_cannot_view_falls_back_to_query_param_auth(): void
    {
        // Server verwirft den Authorization-Header (Basic Auth -> 401
        // cannot_view); der Fallback mit consumer_key/secret als
        // Query-Parameter muss durchgehen (nur über HTTPS).
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'consumer_key=ck_test')) {
                return Http::response(
                    [['id' => 7, 'name' => 'AHS Korneuburg', 'count' => 12]],
                    200,
                    ['X-WP-TotalPages' => '1'],
                );
            }

            return Http::response(['code' => 'woocommerce_rest_cannot_view'], 401);
        });

        $this->get('/shop-export')->assertOk()->assertSee('AHS Korneuburg');
    }

    public function test_cannot_view_after_fallback_hints_at_user_role(): void
    {
        Http::fake(['shop.example/*' => Http::response(['code' => 'woocommerce_rest_cannot_view'], 401)]);

        $this->get('/shop-export')
            ->assertOk()
            ->assertSee('Administrator-/Shop-Manager-Rolle');
    }

    public function test_unreachable_shop_shows_friendly_error_on_fetch(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: shop.example');
        });

        $response = $this->post('/shop-export', [
            'category' => 7,
            'statuses' => ['completed'],
        ]);
        $response->assertRedirect();
        $this->assertSame('Der Shop ist gerade nicht erreichbar.', session('apiFetchError')['user']);
    }

    public function test_missing_configuration_disables_way_one(): void
    {
        config(['ordersuite.woocommerce.store_url' => '']);

        $this->get('/')->assertOk()->assertSee('Die Shop-Verbindung ist noch nicht eingerichtet');
        $this->get('/shop-export')->assertOk()->assertSee('noch nicht eingerichtet');
    }

    public function test_pagination_fetches_all_pages(): void
    {
        config(['ordersuite.woocommerce.per_page' => 2]);
        $page1 = [
            ['id' => 5, 'billing' => [], 'total' => '1', 'line_items' => [['product_id' => 1, 'name' => 'A', 'quantity' => 1, 'meta_data' => []]]],
            ['id' => 4, 'billing' => [], 'total' => '1', 'line_items' => [['product_id' => 1, 'name' => 'B', 'quantity' => 1, 'meta_data' => []]]],
        ];
        $page2 = [
            ['id' => 3, 'billing' => [], 'total' => '1', 'line_items' => [['product_id' => 1, 'name' => 'C', 'quantity' => 1, 'meta_data' => []]]],
        ];
        Http::fake([
            'shop.example/wp-json/wc/v3/orders?*page=1*' => Http::response($page1, 200, ['X-WP-TotalPages' => '2']),
            'shop.example/wp-json/wc/v3/orders?*page=2*' => Http::response($page2, 200, ['X-WP-TotalPages' => '2']),
        ]);

        $table = app(ShopOrderFetcher::class)->fetch(null, ['completed']);
        $this->assertCount(3, $table['rows']);
        $this->assertSame(['A', 'B', 'C'], array_column($table['rows'], 'Item Name(löschen)'));
    }
}
