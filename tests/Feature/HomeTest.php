<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\OnboardingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    // Die Startseite ist jetzt eine Aufgabenübersicht und liest die Anträge.
    use RefreshDatabase;

    private function onboarding(array $attributes): SchoolOnboarding
    {
        return SchoolOnboarding::create([
            'school_name' => 'AHS Testschule',
            'source' => 'manuell',
            'delivery_type' => 'collective',
            'products' => [],
            ...$attributes,
        ]);
    }

    public function test_homepage_links_to_all_modules_and_explains_them(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Auftragsdokumente')
            ->assertSee('Schul-Onboarding')
            ->assertSee('Bestellfenster schließen')
            ->assertSee('Admin-Informationen')
            // Erklärungen und Ablauf bleiben erhalten, nicht nur die Aufgabenliste
            ->assertSee('Der Ablauf einer Schule')
            ->assertSee('Im Shop anlegen')
            ->assertSee(route('tool.index'), false)
            ->assertSee(route('schools.index'), false);
    }

    public function test_order_tool_lives_under_auftragsdokumente(): void
    {
        $this->get('/auftragsdokumente')->assertOk()->assertSee('Weg 2: Datei hochladen');
    }

    public function test_dashboard_is_empty_when_nothing_is_pending(): void
    {
        $this->get('/')->assertOk()->assertSee('Nichts offen');
    }

    public function test_dashboard_flags_a_window_that_expired_while_still_open(): void
    {
        // Ohne automatische Nachfrist — sonst würde sie beim Seitenaufruf greifen
        $this->onboarding([
            'school_name' => 'BG Abgelaufen',
            'status' => OnboardingStatus::ANGELEGT,
            'window_start' => now()->subMonth(),
            'window_end' => now()->subDays(3),
            'woo_category_id' => 77,
            'auto_extend' => false,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('abgelaufen, im Shop aber noch offen')
            ->assertSee('BG Abgelaufen')
            ->assertDontSee('Nichts offen');
    }

    /** Die Nachfrist greift beim Aufruf der Startseite, auch ohne eingerichteten Cron. */
    public function test_expired_window_is_extended_when_the_dashboard_is_opened(): void
    {
        $onboarding = $this->onboarding([
            'school_name' => 'BG Nachfrist',
            'status' => OnboardingStatus::ANGELEGT,
            'window_start' => now()->subMonth(),
            'window_end' => now()->subDay(),
            'woo_category_id' => 77,
            'auto_extend' => true,
            'auto_extend_days' => 7,
        ]);

        // Die Verlängerung läuft NACH der Antwort — die Startseite darf nicht
        // je fälliger Schule auf WordPress warten. Der erste Aufruf stößt sie
        // an, der zweite zeigt, was verlängert wurde.
        $this->get('/')->assertOk();
        $this->get('/')->assertOk()->assertSee('Automatisch verlängert');

        $onboarding->refresh();
        $this->assertTrue($onboarding->window_end->isFuture());
        $this->assertNotNull($onboarding->auto_extended_at);
        // Danach steht der Antrag nicht mehr als „abgelaufen" da
        $this->assertFalse($onboarding->windowExpiredButOpen());
    }

    public function test_dashboard_warns_before_a_window_closes(): void
    {
        $this->onboarding([
            'school_name' => 'BG Bald',
            'status' => OnboardingStatus::ANGELEGT,
            'window_start' => now()->subWeek(),
            'window_end' => now()->addDays(3),
            'woo_category_id' => 77,
        ]);

        $this->get('/')->assertOk()->assertSee('läuft in den nächsten')->assertSee('BG Bald');
    }

    public function test_dashboard_lists_new_and_unprovisioned_requests(): void
    {
        $this->onboarding(['school_name' => 'BG Neu', 'status' => OnboardingStatus::NEU]);
        $this->onboarding(['school_name' => 'BG Offen', 'status' => OnboardingStatus::IN_BEARBEITUNG]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Neue Anträge')
            ->assertSee('BG Neu')
            ->assertSee('noch nicht im Shop angelegt')
            ->assertSee('BG Offen');
    }

    public function test_dashboard_reminds_of_missing_documents_after_closing(): void
    {
        $this->onboarding([
            'school_name' => 'BG Fertig',
            'status' => OnboardingStatus::ABGESCHLOSSEN,
            'window_start' => now()->subMonth(),
            'window_end' => now()->subWeek(),
            'woo_category_id' => 77,
        ]);

        $this->get('/')->assertOk()->assertSee('Auftragsdokumente fehlen noch')->assertSee('BG Fertig');
    }
}
