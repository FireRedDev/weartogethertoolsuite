<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomeTest extends TestCase
{
    public function test_homepage_links_to_both_modules(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Auftragsdokumente')
            ->assertSee('Schul-Onboarding')
            ->assertSee(route('tool.index'), false)
            ->assertSee(route('schools.index'), false);
    }

    public function test_order_tool_lives_under_auftragsdokumente(): void
    {
        $this->get('/auftragsdokumente')->assertOk()->assertSee('Weg 2: Datei hochladen');
    }
}
