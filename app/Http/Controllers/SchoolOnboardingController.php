<?php

namespace App\Http\Controllers;

use App\Exceptions\WooCommerceApiException;
use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\OrderEmailGenerator;
use App\Services\SchoolShop\ProductConfigurator;
use App\Services\SchoolShop\ProvisionAbortedException;
use App\Services\SchoolShop\ShopProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SchoolOnboardingController extends Controller
{
    public function index(): View
    {
        return view('schools.index', [
            'onboardings' => SchoolOnboarding::orderByDesc('created_at')->get(),
        ]);
    }

    public function create(): View
    {
        return view('schools.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            [
                'school_name' => ['required', 'string', 'max:150'],
                'delivery_type' => ['required', 'in:collective,ondemand,list'],
                'contact_name' => ['nullable', 'string', 'max:150'],
                'contact_email' => ['nullable', 'email', 'max:150'],
            ],
            ['school_name.required' => 'Bitte den Namen der Schule/Organisation eingeben.'],
        );

        $onboarding = SchoolOnboarding::create([
            ...$validated,
            'status' => 'neu',
            'source' => 'manuell',
            'products' => ProductConfigurator::defaultsAllDisabled(),
            'print_areas' => ['Frontprint'],
        ]);

        return redirect()->route('schools.show', $onboarding);
    }

    public function show(SchoolOnboarding $onboarding): View
    {
        return view('schools.show', [
            'onboarding' => $onboarding,
            'emailBody' => $onboarding->delivery_type === 'collective'
                ? app(OrderEmailGenerator::class)->body($onboarding)
                : null,
            'emailSubject' => app(OrderEmailGenerator::class)->subject($onboarding),
        ]);
    }

    public function update(Request $request, SchoolOnboarding $onboarding): RedirectResponse
    {
        $validated = $request->validate([
            'school_name' => ['required', 'string', 'max:150'],
            'delivery_type' => ['required', 'in:collective,ondemand,list'],
            'status' => ['required', 'in:'.implode(',', array_keys(SchoolOnboarding::STATUSES))],
            'class_list' => ['nullable', 'string', 'max:2000'],
            'window_start' => ['nullable', 'date'],
            'window_end' => ['nullable', 'date', 'after_or_equal:window_start'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'products' => ['nullable', 'array'],
        ]);

        $onboarding->fill([
            'school_name' => $validated['school_name'],
            'delivery_type' => $validated['delivery_type'],
            'status' => $validated['status'],
            'class_list' => $validated['class_list'] ?? null,
            'window_start' => $validated['window_start'] ?? null,
            'window_end' => $validated['window_end'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);
        if ($onboarding->status === 'neu') {
            $onboarding->status = 'in_bearbeitung';
        }
        $onboarding->products = ProductConfigurator::applyInput($onboarding->products ?? [], $validated['products'] ?? []);
        $onboarding->save();

        return redirect()->route('schools.show', $onboarding)->with('saved', true);
    }

    public function preview(SchoolOnboarding $onboarding, ShopProvisioner $provisioner): RedirectResponse
    {
        return redirect()->route('schools.show', $onboarding)->with('plan', $provisioner->plan($onboarding));
    }

    public function provision(SchoolOnboarding $onboarding, ShopProvisioner $provisioner): RedirectResponse
    {
        if ($onboarding->enabledProducts() === []) {
            return redirect()->route('schools.show', $onboarding)
                ->withErrors(['provision' => 'Kein Produkt aktiviert — bitte zuerst im Konfigurator Produkte auswählen und speichern.']);
        }

        try {
            $log = $provisioner->apply($onboarding);

            return redirect()->route('schools.show', $onboarding)->with('provisionLog', $log);
        } catch (ProvisionAbortedException $e) {
            $previous = $e->getPrevious();
            $hint = $previous instanceof WooCommerceApiException ? $previous->hint() : null;

            return redirect()->route('schools.show', $onboarding)
                ->with('provisionLog', $e->log)
                ->withErrors(['provision' => 'Die Shop-Anlage wurde abgebrochen: '.(
                    $previous instanceof WooCommerceApiException ? $previous->userMessage() : $e->getMessage()
                ).($hint ? ' — '.$hint : '')]);
        }
    }
}
