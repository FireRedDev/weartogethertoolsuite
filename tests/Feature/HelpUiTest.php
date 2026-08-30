<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Erklärtexte sollen nicht dauerhaft sichtbar sein, sondern in antippbaren
 * Info-Symbolen bzw. ausklappbaren Blöcken stecken. Reine title="…"-Tooltips
 * zeigt ein Telefon nicht an — die dürfen für Erklärungen nicht mehr vorkommen.
 */
class HelpUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_info_symbole_haben_eine_bedienbare_schaltflaeche(): void
    {
        $onboarding = SchoolOnboarding::create([
            'school_name' => 'BG Testschule',
            'delivery_type' => 'sammelbestellung',
            'status' => 'neu',
        ]);

        $response = $this->get(route('schools.show', $onboarding));

        $response->assertOk();
        // <button> statt title="…" — sonst gibt es auf Telefonen kein Mouseover.
        $response->assertSee('class="info-toggle"', false);
        $response->assertSee('aria-expanded="false"', false);
    }

    public function test_lange_erklaerungen_stecken_in_ausklappbaren_bloecken(): void
    {
        $onboarding = SchoolOnboarding::create([
            'school_name' => 'BG Testschule',
            'delivery_type' => 'sammelbestellung',
            'status' => 'neu',
        ]);

        $response = $this->get(route('schools.show', $onboarding));

        $response->assertOk();
        $response->assertSee('<details class="explain"', false);
    }

    public function test_admin_seite_klappt_die_ausfall_erklaerung_ein(): void
    {
        $response = $this->get(route('admin.status'));

        $response->assertOk();
        $response->assertSee('Was passiert, wenn eine Schnittstelle ausfällt?', false);
        $response->assertSee('<details class="explain"', false);
    }

    public function test_die_startseite_erklaert_weiterhin_offen_was_die_module_koennen(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        // Bewusste Ausnahme: hier sollen die Modulbeschreibungen sichtbar bleiben.
        $response->assertSee('Auftragsdokumente');
        $response->assertSee('Schul-Onboarding');
        $response->assertSee('class="lead"', false);
    }

    public function test_erklaertexte_verwenden_keine_title_tooltips_mehr(): void
    {
        $views = [
            resource_path('views/schools/show.blade.php'),
            resource_path('views/schools/index.blade.php'),
            resource_path('views/admin/status.blade.php'),
            resource_path('views/close-window/index.blade.php'),
            resource_path('views/partials/webhook-log.blade.php'),
        ];

        foreach ($views as $view) {
            foreach (file($view) as $number => $line) {
                // <x-explain title="…"> ist die Überschrift des Ausklappblocks, kein Tooltip.
                if (str_contains($line, 'x-explain')) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    ' title="',
                    $line,
                    basename($view).' Zeile '.($number + 1).' enthält noch einen title="…"-Tooltip — bitte durch <x-info> ersetzen.'
                );
            }
        }
    }
}
