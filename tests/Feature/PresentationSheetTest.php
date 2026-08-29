<?php

namespace Tests\Feature;

use App\Models\SchoolOnboarding;
use App\Services\PresentationSheet\PresentationSheetRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Präsentationsblatt: Inhalte kommen aus dem Onboarding, das Layout aus
 * config/presentation_sheet.php. Die Tests prüfen vor allem, dass die
 * Elemente dort landen, wo sie in der InDesign-Vorlage stehen — sonst fällt
 * eine verrutschte Koordinate erst am fertigen Blatt auf.
 */
class PresentationSheetTest extends TestCase
{
    use RefreshDatabase;

    /** Buchstaben-Oberkanten aus der Original-Vorlage (PDF vermessen). */
    private const TEMPLATE_TOPS = [
        'Schulmerchandise' => 107.2,
        'Premium Zip-Hoodie' => 178.5,
        '- in schwarz und navy' => 202.9,
        'Premium Poloshirt' => 237.0,
        'Casual T-Shirt' => 295.4,
        '1 Produkt = 1 Baum' => 353.9,
        '- Regenwaldaufforstung' => 378.2,
        'Montag, 19.01. -' => 488.4,
        'Montag, 09.02.' => 510.4,
        'Julian' => 641.1,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function onboarding(array $attributes = []): SchoolOnboarding
    {
        return SchoolOnboarding::create([
            'school_name' => 'Schigymnasium Stams',
            'status' => 'in_bearbeitung',
            'source' => 'manuell',
            'delivery_type' => 'collective',
            'window_start' => '2026-01-19',
            'window_end' => '2026-02-09',
            'sheet_first_name' => 'Julian',
            'products' => [
                $this->product('schulzoodie', 'Schulzoodie'),
                $this->product('schulpolo', 'Schulpolo'),
                $this->product('schulshirt', 'Schulshirt'),
            ],
            ...$attributes,
        ]);
    }

    private function product(string $key, string $label, array $colors = ['schwarz', 'navy']): array
    {
        return [
            'key' => $key, 'label' => $label, 'enabled' => true, 'base_price' => 30.0,
            'indiv_surcharge' => 7.99, 'sizes' => ['S', 'M'], 'colors' => $colors,
        ];
    }

    private function withMockups(SchoolOnboarding $onboarding): SchoolOnboarding
    {
        foreach (['back', 'front'] as $slot) {
            $path = "presentation-sheets/{$onboarding->id}/{$slot}.png";
            Storage::disk('public')->put($path, (string) UploadedFile::fake()->image("{$slot}.png", 900, 1200)->get());
            $onboarding->forceFill(["sheet_{$slot}_path" => $path])->save();
        }

        return $onboarding->fresh();
    }

    /** @return array<string, array{left: float, top: float}> Textelemente nach Inhalt */
    private function texts(SchoolOnboarding $onboarding): array
    {
        $out = [];
        foreach (app(PresentationSheetRenderer::class)->data($onboarding)['elements'] as $e) {
            // Erster Treffer gewinnt: gleiche Farbzeile kommt in mehreren Zeilen vor
            if ($e['type'] === 'text' && ! isset($out[$e['text']])) {
                $out[$e['text']] = $e;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Inhalte aus dem Onboarding
    // ------------------------------------------------------------------

    public function test_content_is_taken_from_the_onboarding_record(): void
    {
        $texts = $this->texts($this->withMockups($this->onboarding()));

        $this->assertArrayHasKey('Schigymnasium Stams', $texts);
        $this->assertArrayHasKey('Premium Zip-Hoodie', $texts);      // Marketing-Name, nicht der Katalogname
        $this->assertArrayHasKey('- in schwarz und navy', $texts);
        $this->assertArrayHasKey('Montag, 19.01. -', $texts);        // deutscher Wochentag trotz Locale 'en'
        $this->assertArrayHasKey('Montag, 09.02.', $texts);
        $this->assertArrayHasKey('1 Produkt = 1 Baum', $texts);      // feste Zeile hängt immer hinten dran
        $this->assertArrayHasKey('https://wear-together.at/schule/', $texts);
        $this->assertArrayHasKey('schigymnasium-stams/', $texts);
        $this->assertArrayHasKey('Julian', $texts);
    }

    public function test_colour_line_is_written_out_in_german(): void
    {
        $onboarding = $this->onboarding(['products' => [
            $this->product('schulshirt', 'Schulshirt', ['schwarz']),
            $this->product('schulpolo', 'Schulpolo', ['schwarz', 'weiß', 'burgundy']),
        ]]);

        $texts = $this->texts($this->withMockups($onboarding));

        $this->assertArrayHasKey('- in schwarz', $texts);
        $this->assertArrayHasKey('- in schwarz, weiß und burgundy', $texts);
    }

    public function test_layout_matches_the_indesign_template(): void
    {
        $texts = $this->texts($this->withMockups($this->onboarding()));
        $c = config('presentation_sheet.text_top_correction');

        foreach (self::TEMPLATE_TOPS as $text => $templateTop) {
            $this->assertArrayHasKey($text, $texts, "Text „{$text}“ kommt im Blatt nicht vor");
            // Der Renderer speichert die um den dompdf-Versatz korrigierte
            // Oberkante — zurückgerechnet muss die Vorlagenkoordinate herauskommen.
            $glyphTop = $texts[$text]['top'] + $c['factor'] * $texts[$text]['size'] - $c['offset'];
            // 0,2 pt Toleranz: die Vorlage selbst arbeitet mit gerundeten
            // Zeilenabständen (58,5 / 58,4 pt), das ist rund 0,07 mm.
            $this->assertEqualsWithDelta($templateTop, $glyphTop, 0.2, "Text „{$text}“ sitzt nicht auf der Vorlagenhöhe");
        }

        // Linke Kanten der Produktspalte
        $this->assertEqualsWithDelta(81.6, $texts['Premium Zip-Hoodie']['left'], 0.05);
        $this->assertEqualsWithDelta(117.6, $texts['- in schwarz und navy']['left'], 0.05);
    }

    public function test_photos_are_placed_under_the_background_and_fill_their_windows(): void
    {
        $data = app(PresentationSheetRenderer::class)->data($this->withMockups($this->onboarding()));
        $images = array_values(array_filter($data['elements'], fn ($e) => $e['type'] === 'image'));
        $windows = config('presentation_sheet.windows');

        // Reihenfolge: die drei Fotos, dann der Hintergrund, dann Icons/QR
        $this->assertSame($windows['mockup_back']['left'], $images[0]['left']);
        $this->assertSame($windows['mockup_front']['left'], $images[1]['left']);
        $this->assertSame($windows['detail_circle']['left'], $images[2]['left']);
        $this->assertSame(config('presentation_sheet.background'), $images[3]['src']);
        $this->assertSame(595.28, $images[3]['width']);

        foreach (['mockup_back', 'mockup_front', 'detail_circle'] as $i => $window) {
            $this->assertSame($windows[$window]['width'], $images[$i]['width']);
            $this->assertSame($windows[$window]['height'], $images[$i]['height']);
        }
    }

    public function test_long_school_names_shrink_instead_of_overflowing(): void
    {
        $short = $this->texts($this->withMockups($this->onboarding()));
        $long = $this->texts($this->withMockups($this->onboarding([
            'school_name' => 'Bundesrealgymnasium Wien Zehnergasse Standort Nord',
        ])));

        $this->assertSame(24.0, $short['Schigymnasium Stams']['size']);
        $longName = 'Bundesrealgymnasium Wien Zehnergasse Standort Nord';
        $this->assertLessThan(24.0, $long[$longName]['size']);
        $this->assertGreaterThanOrEqual(config('presentation_sheet.headline.min_size'), $long[$longName]['size']);
    }

    // ------------------------------------------------------------------
    // Bedienung
    // ------------------------------------------------------------------

    public function test_sheet_is_blocked_until_both_mockups_are_uploaded(): void
    {
        $onboarding = $this->onboarding();
        $renderer = app(PresentationSheetRenderer::class);

        $missing = $renderer->missingRequirements($onboarding);
        $this->assertCount(2, $missing);

        $this->get("/schulen/{$onboarding->id}")->assertOk()->assertSee('Noch nicht erzeugbar');
        $this->get("/schulen/{$onboarding->id}/blatt.pdf")->assertRedirect();

        $this->assertSame([], $renderer->missingRequirements($this->withMockups($onboarding)));
    }

    public function test_upload_replaces_the_previous_file(): void
    {
        $onboarding = $this->onboarding();

        $this->post("/schulen/{$onboarding->id}/blatt/front", ['mockup' => UploadedFile::fake()->image('a.png')])
            ->assertRedirect();
        $first = $onboarding->fresh()->sheet_front_path;
        Storage::disk('public')->assertExists($first);

        $this->post("/schulen/{$onboarding->id}/blatt/front", ['mockup' => UploadedFile::fake()->image('b.png')]);
        $second = $onboarding->fresh()->sheet_front_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_product_rows_can_be_overridden_and_reset(): void
    {
        $onboarding = $this->withMockups($this->onboarding());

        $this->put("/schulen/{$onboarding->id}/blatt", [
            'rows' => [
                ['name' => 'Kapuzenjacke deluxe', 'sub' => '- nur in bordeaux', 'icon' => 'zoodie'],
                ['name' => '', 'sub' => 'wird verworfen', 'icon' => ''],
            ],
            'sheet_first_name' => 'Mara',
        ])->assertRedirect();

        $texts = $this->texts($onboarding->fresh());
        $this->assertArrayHasKey('Kapuzenjacke deluxe', $texts);
        $this->assertArrayHasKey('- nur in bordeaux', $texts);
        $this->assertArrayHasKey('Mara', $texts);
        $this->assertArrayNotHasKey('Premium Zip-Hoodie', $texts);   // Vorbelegung ist überschrieben
        $this->assertArrayHasKey('1 Produkt = 1 Baum', $texts);      // Baum-Zeile bleibt
        $this->assertCount(1, $onboarding->fresh()->sheet_products); // leere Zeile verworfen

        $this->post("/schulen/{$onboarding->id}/blatt-zuruecksetzen")->assertRedirect();
        $this->assertNull($onboarding->fresh()->sheet_products);
        $this->assertArrayHasKey('Premium Zip-Hoodie', $this->texts($onboarding->fresh()));
    }

    public function test_shop_url_can_be_overridden(): void
    {
        $onboarding = $this->withMockups($this->onboarding());
        $renderer = app(PresentationSheetRenderer::class);
        $this->assertSame('https://wear-together.at/schule/schigymnasium-stams/', $renderer->shopUrl($onboarding));

        $this->put("/schulen/{$onboarding->id}/blatt", ['sheet_shop_url' => 'https://wear-together.at/schule/anders/'])
            ->assertRedirect();

        $texts = $this->texts($onboarding->fresh());
        $this->assertArrayHasKey('anders/', $texts);
    }

    public function test_pdf_is_generated_with_the_school_name_in_the_filename(): void
    {
        $onboarding = $this->withMockups($this->onboarding());

        $response = $this->get("/schulen/{$onboarding->id}/blatt.pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('Praesentationsblatt_Schigymnasium_Stams.pdf', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_preview_renders_the_same_template_as_the_pdf(): void
    {
        $onboarding = $this->withMockups($this->onboarding());

        $this->get("/schulen/{$onboarding->id}/blatt-vorschau")
            ->assertOk()
            ->assertSee('Schigymnasium Stams')
            ->assertSee('Montag, 19.01. -');
    }

    public function test_only_the_first_three_products_appear(): void
    {
        $onboarding = $this->withMockups($this->onboarding(['products' => [
            $this->product('schulzoodie', 'Zoodie'),
            $this->product('schulpolo', 'Polo'),
            $this->product('schulshirt', 'Shirt'),
            $this->product('schultasche', 'Tasche'),
        ]]));

        $texts = $this->texts($onboarding);

        $this->assertArrayHasKey('Premium Zip-Hoodie', $texts);
        $this->assertArrayNotHasKey('Umhängetasche', $texts);
        // Die Baum-Zeile rückt dahinter, nicht darüber
        $this->assertGreaterThan($texts['Casual T-Shirt']['top'], $texts['1 Produkt = 1 Baum']['top']);
    }
}
