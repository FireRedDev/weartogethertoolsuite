<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloseOrderWindowTest extends TestCase
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

    private function provisionedSchool(): SchoolOnboarding
    {
        return SchoolOnboarding::create([
            'school_name' => 'AHS Testschule',
            'delivery_type' => 'collective',
            'status' => 'angelegt',
            'source' => 'manuell',
            'products' => [],
            'woo_category_id' => 77,
            'pods_post_id' => 900,
        ]);
    }

    public function test_index_lists_only_provisioned_schools(): void
    {
        $this->provisionedSchool();
        SchoolOnboarding::create([
            'school_name' => 'Noch nicht angelegt',
            'delivery_type' => 'collective',
            'status' => 'neu',
            'source' => 'manuell',
            'products' => [],
        ]);

        $this->get('/bestellfenster-schliessen')
            ->assertOk()
            ->assertSee('AHS Testschule')
            ->assertDontSee('Noch nicht angelegt');
    }

    public function test_close_sets_products_private_and_cpt_field(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products/601*' => Http::response(['id' => 601], 200),
            'shop.example/wp-json/wc/v3/products?*' => Http::response([
                ['id' => 601, 'name' => 'AHS Testschule Schulpullover', 'status' => 'publish'],
                ['id' => 602, 'name' => 'AHS Testschule Schulshirt', 'status' => 'private'],
            ]),
            'shop.example/wp-json/wp/v2/schule/900' => Http::response(['id' => 900], 200),
        ]);

        $school = $this->provisionedSchool();

        $this->post("/bestellfenster-schliessen/{$school->id}")->assertRedirect();

        // Produkt 601 (publish) wird privat gesetzt, 602 (bereits privat) übersprungen
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wc/v3/products/601')
            && $r->method() === 'PUT'
            && ($r->data()['status'] ?? null) === 'private'
            && ($r->data()['catalog_visibility'] ?? null) === 'hidden');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/wc/v3/products/602') && $r->method() === 'PUT');

        // CPT-Feld "Bestellfenster offen" wird auf NEIN gesetzt
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wp/v2/schule/900')
            && ($r->data()['bestellfenster_offen'] ?? null) === 'NEIN');

        $school->refresh();
        $this->assertSame('abgeschlossen', $school->status);
    }

    public function test_close_finds_products_by_category(): void
    {
        Http::fake([
            'shop.example/wp-json/wc/v3/products/601*' => Http::response(['id' => 601], 200),
            'shop.example/wp-json/wc/v3/products?*' => Http::response([
                ['id' => 601, 'name' => 'AHS Testschule Schulpullover', 'status' => 'publish'],
            ]),
            'shop.example/wp-json/wp/v2/schule/900' => Http::response(['id' => 900], 200),
        ]);

        $school = $this->provisionedSchool();
        $this->post("/bestellfenster-schliessen/{$school->id}")->assertRedirect();

        // Die Produktsuche filtert serverseitig nach der Schul-Kategorie 77
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wc/v3/products?')
            && $r->method() === 'GET'
            && str_contains($r->url(), 'category=77'));
    }

    public function test_close_rejects_unprovisioned_school(): void
    {
        $school = SchoolOnboarding::create([
            'school_name' => 'Ohne Shop',
            'delivery_type' => 'collective',
            'status' => 'neu',
            'source' => 'manuell',
            'products' => [],
        ]);

        $this->post("/bestellfenster-schliessen/{$school->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('school');
    }
}
