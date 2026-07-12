<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\WooCommerceWriteClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Absicherung gegen kahle 500-Seiten: jeder unerwartete Fehler muss verständlich
 * erklärt werden (Anlass: 500er bei /schulen/{id}/anlegen ohne erkennbare Ursache).
 */
class FriendlyErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unexpected_exception_anywhere_in_the_app_shows_friendly_page(): void
    {
        Route::get('/__test-crash', fn () => throw new \RuntimeException('Absichtlicher Testfehler'))
            ->middleware('web');

        $response = $this->get('/__test-crash');

        $response->assertStatus(500);
        $response->assertSee('Es ist ein unerwarteter Fehler aufgetreten');
        $response->assertSee('RuntimeException');
        $response->assertSee('Absichtlicher Testfehler');
        $response->assertSee('Technische Details');
    }

    public function test_provisioning_error_outside_a_tracked_step_is_still_shown_not_500(): void
    {
        // Simuliert einen Fehler, der nicht von einer WooCommerceApiException
        // kommt (z. B. TypeError durch eine unerwartete API-Antwort) — muss
        // trotzdem als erklärte Fehlermeldung ankommen, nicht als 500er.
        $this->mock(WooCommerceWriteClient::class, function ($mock) {
            $mock->shouldReceive('ensureCategory')->andThrow(new \TypeError('Unerwarteter Typ in API-Antwort'));
        });

        $onboarding = SchoolOnboarding::create([
            'school_name' => 'Testschule',
            'status' => 'in_bearbeitung',
            'delivery_type' => 'collective',
            'products' => [['key' => 'schulshirt', 'label' => 'Schulshirt', 'enabled' => true, 'base_price' => 24.99, 'indiv_surcharge' => 7.99, 'sizes' => ['M'], 'colors' => ['schwarz']]],
        ]);

        $response = $this->post("/schulen/{$onboarding->id}/anlegen");
        $response->assertRedirect();

        $provisionError = session('provisionError');
        $this->assertNotNull($provisionError, 'provisionError muss immer gesetzt sein, auch bei unerwarteten Fehlern');
        $this->assertStringContainsString('unerwarteten technischen Fehler', $provisionError['user']);
        $this->assertStringContainsString('TypeError', $provisionError['technical']);
        $this->assertStringContainsString('Unerwarteter Typ in API-Antwort', $provisionError['technical']);

        // Und sichtbar auf der Seite (nicht nur im Log)
        $page = $this->get("/schulen/{$onboarding->id}");
        // Session-Flash ist nach dem redirect-Follow nicht mehr da, daher direkt im redirect prüfen
        $this->followingRedirects()->post("/schulen/{$onboarding->id}/anlegen")
            ->assertSee('TypeError')
            ->assertSee('Unerwarteter Typ in API-Antwort')
            ->assertSee('Technische Details');
    }
}
