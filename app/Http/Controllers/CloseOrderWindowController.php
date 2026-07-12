<?php

namespace App\Http\Controllers;

use App\Exceptions\WooCommerceApiException;
use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\ShopProvisioner;
use Illuminate\Http\RedirectResponse;
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
        $schools = SchoolOnboarding::orderBy('school_name')->get()
            ->filter(fn (SchoolOnboarding $s) => $s->isProvisioned())
            ->values();

        return view('close-window.index', ['schools' => $schools]);
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
        } catch (WooCommerceApiException $e) {
            report($e);

            return redirect()->route('close-window.index')->with('closeError', [
                'user' => $e->userMessage(), 'hint' => $e->hint(), 'technical' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('close-window.index')->with('closeError', [
                'user' => 'Das Schließen wurde durch einen unerwarteten technischen Fehler abgebrochen.',
                'hint' => 'Bitte die technischen Details unten an den Support weitergeben.',
                'technical' => get_class($e).': '.$e->getMessage().' in '.basename($e->getFile()).':'.$e->getLine(),
            ]);
        }
    }
}
