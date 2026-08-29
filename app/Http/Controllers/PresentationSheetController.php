<?php

namespace App\Http\Controllers;

use App\Models\SchoolOnboarding;
use App\Services\PresentationSheet\PresentationSheetRenderer;
use App\Services\PresentationSheet\SheetImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Präsentationsblatt je Bestellfenster: zwei Mockups hochladen, alles andere
 * kommt aus dem Onboarding-Datensatz. Vorschau im Browser, Download als PDF.
 */
class PresentationSheetController extends Controller
{
    public function __construct(
        private readonly PresentationSheetRenderer $renderer,
        private readonly SheetImages $images,
    ) {}

    /** Mockup hochladen bzw. austauschen. */
    public function upload(Request $request, SchoolOnboarding $onboarding, string $slot): RedirectResponse
    {
        abort_unless(array_key_exists($slot, SheetImages::SLOTS), 404);

        $request->validate(
            ['mockup' => ['required', 'file', 'mimes:'.implode(',', SheetImages::ALLOWED_EXTENSIONS), 'max:12288']],
            [
                'mockup.required' => 'Bitte eine Bilddatei auswählen.',
                'mockup.mimes' => 'Erlaubt sind PNG, JPG und WebP.',
                'mockup.max' => 'Die Datei ist zu groß (maximal 12 MB).',
            ],
        );

        $this->images->store($onboarding, $slot, $request->file('mockup'));

        return $this->back($onboarding);
    }

    public function deleteUpload(SchoolOnboarding $onboarding, string $slot): RedirectResponse
    {
        abort_unless(array_key_exists($slot, SheetImages::SLOTS), 404);
        $this->images->delete($onboarding, $slot);

        return $this->back($onboarding);
    }

    /** Vorname, Shop-Adresse, Detailausschnitt und die Produktzeilen speichern. */
    public function update(Request $request, SchoolOnboarding $onboarding): RedirectResponse
    {
        $validated = $request->validate([
            'sheet_first_name' => ['nullable', 'string', 'max:40'],
            'sheet_shop_url' => ['nullable', 'url', 'max:255'],
            'sheet_back_focus_x' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'sheet_back_focus_y' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'sheet_back_zoom' => ['nullable', 'numeric', 'min:1', 'max:6'],
            'sheet_front_focus_x' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'sheet_front_focus_y' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'sheet_front_zoom' => ['nullable', 'numeric', 'min:1', 'max:6'],
            'sheet_detail_focus_x' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'sheet_detail_focus_y' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'sheet_detail_zoom' => ['nullable', 'numeric', 'min:1', 'max:12'],
            'rows' => ['nullable', 'array'],
            'rows.*.name' => ['nullable', 'string', 'max:60'],
            'rows.*.sub' => ['nullable', 'string', 'max:80'],
            'rows.*.icon' => ['nullable', 'string', 'max:40'],
        ]);

        $onboarding->fill([
            'sheet_first_name' => $validated['sheet_first_name'] ?? null,
            'sheet_shop_url' => $validated['sheet_shop_url'] ?? null,
        ]);
        foreach (['back', 'front', 'detail'] as $slot) {
            foreach (["sheet_{$slot}_focus_x", "sheet_{$slot}_focus_y", "sheet_{$slot}_zoom"] as $field) {
                if (isset($validated[$field])) {
                    $onboarding->{$field} = (float) $validated[$field];
                }
            }
        }

        $rows = array_values(array_filter(
            $validated['rows'] ?? [],
            fn ($row) => trim((string) ($row['name'] ?? '')) !== '',
        ));
        $onboarding->sheet_products = $rows === [] ? null : $rows;
        $onboarding->save();

        return $this->back($onboarding);
    }

    /** Produktzeilen wieder aus dem Konfigurator übernehmen. */
    public function resetRows(SchoolOnboarding $onboarding): RedirectResponse
    {
        $onboarding->forceFill(['sheet_products' => null])->save();

        return $this->back($onboarding);
    }

    /** Hochgeladenes Mockup ausliefern (Vorschaubild im Tool). */
    public function image(SchoolOnboarding $onboarding, string $slot): Response
    {
        abort_unless(array_key_exists($slot, SheetImages::SLOTS), 404);
        $path = $onboarding->{"sheet_{$slot}_path"};
        abort_if(! $path || ! Storage::disk(SheetImages::DISK)->exists($path), 404);

        return response(Storage::disk(SheetImages::DISK)->get($path), 200, [
            'Content-Type' => Storage::disk(SheetImages::DISK)->mimeType($path) ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** Vorschau im Browser — dieselbe Vorlage, die auch die PDF erzeugt. */
    public function preview(SchoolOnboarding $onboarding): Response
    {
        $missing = $this->renderer->missingRequirements($onboarding);
        abort_if($missing !== [], 409, 'Es fehlt noch: '.implode(', ', $missing));

        return response($this->renderer->html($onboarding));
    }

    public function pdf(SchoolOnboarding $onboarding): SymfonyResponse
    {
        $missing = $this->renderer->missingRequirements($onboarding);
        if ($missing !== []) {
            return redirect()->route('schools.show', $onboarding)
                ->withErrors(['sheet' => 'Präsentationsblatt noch nicht erzeugbar — es fehlt: '.implode(', ', $missing).'.'])
                ->withFragment('praesentationsblatt');
        }

        return $this->renderer->pdf($onboarding)->download($this->renderer->filename($onboarding));
    }

    private function back(SchoolOnboarding $onboarding): RedirectResponse
    {
        return redirect()->route('schools.show', $onboarding)->with('saved', true)->withFragment('praesentationsblatt');
    }
}
