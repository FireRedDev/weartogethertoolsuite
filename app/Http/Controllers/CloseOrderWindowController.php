<?php

namespace App\Http\Controllers;

use App\Exceptions\WooCommerceApiException;
use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\OnboardingStatus;
use App\Services\SchoolShop\ShopProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Modul 3 „Bestellfenster schließen": setzt für eine ausgewählte Schule alle
 * Shop-Produkte auf privat und stellt im CPT „schule" das Feld
 * „Bestellfenster offen" auf NEIN.
 */
class CloseOrderWindowController extends Controller
{
    public function index(): View
    {
        // Nur bereits angelegte Schulen kommen infrage — bei anderen gibt es
        // keine Shop-Produkte/CPT-Einträge, die man schließen könnte.
        $provisioned = SchoolOnboarding::orderBy('school_name')->get()
            ->filter(fn (SchoolOnboarding $s) => $s->isProvisioned());

        return view('close-window.index', [
            'schools' => $provisioned->values(),
            // Für das Wieder-Öffnen kommen nur bereits geschlossene infrage
            'closedSchools' => $provisioned
                ->filter(fn (SchoolOnboarding $s) => $s->status === OnboardingStatus::ABGESCHLOSSEN)
                ->values(),
        ]);
    }

    /** Umkehrung: geschlossenes Fenster wieder öffnen, mit neuem Enddatum. */
    public function reopen(Request $request, SchoolOnboarding $onboarding, ShopProvisioner $provisioner): RedirectResponse
    {
        $validated = $request->validate(
            ['new_end' => ['required', 'date', 'after:today']],
            ['new_end.after' => 'Das neue Ende muss in der Zukunft liegen — sonst wäre das Fenster sofort wieder abgelaufen.'],
        );

        if (! $onboarding->isProvisioned()) {
            return redirect()->route('close-window.index')
                ->withErrors(['school' => 'Für diese Schule wurde noch kein Shop angelegt — es gibt nichts zu öffnen.']);
        }

        try {
            $log = $provisioner->reopenOrderWindow($onboarding, new \DateTimeImmutable($validated['new_end']));

            return redirect()->route('close-window.index')
                ->with('closeLog', $log)
                ->with('reopenedSchool', $onboarding->school_name);
        } catch (\Throwable $e) {
            return redirect()->route('close-window.index')->with('closeError', $this->describeError($e));
        }
    }

    public function close(SchoolOnboarding $onboarding, ShopProvisioner $provisioner): RedirectResponse
    {
        if (! $onboarding->isProvisioned()) {
            return redirect()->route('close-window.index')
                ->withErrors(['school' => 'Für diese Schule wurde noch kein Shop angelegt — es gibt nichts zu schließen.']);
        }

        try {
            $log = $provisioner->closeOrderWindow($onboarding);

            return redirect()->route('close-window.index')
                ->with('closeLog', $log)
                ->with('closedSchool', $onboarding->school_name);
        } catch (\Throwable $e) {
            return redirect()->route('close-window.index')->with('closeError', $this->describeError($e));
        }
    }

    /** @return array{user: string, hint: ?string, technical: string} */
    private function describeError(\Throwable $e): array
    {
        report($e);

        if ($e instanceof WooCommerceApiException) {
            return ['user' => $e->userMessage(), 'hint' => $e->hint(), 'technical' => $e->getMessage()];
        }

        return [
            'user' => 'Der Vorgang wurde durch einen unerwarteten technischen Fehler abgebrochen.',
            'hint' => 'Bitte die technischen Details unten an den Support weitergeben.',
            'technical' => get_class($e).': '.$e->getMessage().' in '.basename($e->getFile()).':'.$e->getLine(),
        ];
    }
}
