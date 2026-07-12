<?php

namespace App\Http\Controllers;

use App\Exceptions\WooCommerceApiException;
use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\OrderEmailGenerator;
use App\Services\SchoolShop\PrintifyClient;
use App\Services\SchoolShop\PrintifyProvisioner;
use App\Services\SchoolShop\ProductConfigurator;
use App\Services\SchoolShop\ProvisionAbortedException;
use App\Services\SchoolShop\ShopProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SchoolOnboardingController extends Controller
{
    public function index(): View
    {
        return view('schools.index', [
            'onboardings' => SchoolOnboarding::orderByDesc('created_at')->get(),
            'webhookLogs' => \App\Models\WebhookLog::orderByDesc('id')->limit(20)->get(),
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

    public function show(SchoolOnboarding $onboarding, PrintifyProvisioner $printifyProvisioner): View
    {
        return view('schools.show', [
            'onboarding' => $onboarding,
            'emailBody' => $onboarding->delivery_type === 'collective'
                ? app(OrderEmailGenerator::class)->body($onboarding)
                : null,
            'emailSubject' => app(OrderEmailGenerator::class)->subject($onboarding),
            'printifyShippingInfo' => $onboarding->delivery_type === 'ondemand'
                ? $this->printifyShippingInfo($onboarding, $printifyProvisioner)
                : [],
        ]);
    }

    /**
     * Provider-Region + Versandkosten je Produkt für die Konfigurator-Anzeige
     * (Blueprint/Provider muss gesetzt sein; Printify-Fehler blocken die
     * Seite nicht, das Feld bleibt dann einfach leer).
     *
     * @return array<string, array{provider_title: string, country: ?string, is_eu: bool, shipping_eur: ?float}>
     */
    private function printifyShippingInfo(SchoolOnboarding $onboarding, PrintifyProvisioner $printifyProvisioner): array
    {
        $info = [];
        foreach ($onboarding->products ?? [] as $product) {
            $blueprintId = $product['printify_blueprint_id'] ?? null;
            $providerId = $product['printify_provider_id'] ?? null;
            if ($blueprintId === null || $providerId === null) {
                continue;
            }
            try {
                $info[$product['key']] = $printifyProvisioner->shippingInfo((int) $blueprintId, (int) $providerId);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $info;
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

        // On-Demand: Produkte werden laufend einzeln verschickt, es gibt kein
        // Bestellfenster und keine Klassenliste (Lieferung an die Privatadresse
        // der Kund:innen) — beide Felder sind im Konfigurator daher ausgeblendet.
        $isOndemand = $validated['delivery_type'] === 'ondemand';

        $onboarding->fill([
            'school_name' => $validated['school_name'],
            'delivery_type' => $validated['delivery_type'],
            'status' => $validated['status'],
            'class_list' => $isOndemand ? null : ($validated['class_list'] ?? null),
            'window_start' => $isOndemand ? SchoolOnboarding::ONDEMAND_WINDOW_START : ($validated['window_start'] ?? null),
            'window_end' => $isOndemand ? SchoolOnboarding::ONDEMAND_WINDOW_END : ($validated['window_end'] ?? null),
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
            $previous = $e->getPrevious() ?? $e;
            report($previous);

            return redirect()->route('schools.show', $onboarding)
                ->with('provisionLog', $e->log)
                ->with('provisionError', $this->describeError($previous));
        } catch (\Throwable $e) {
            // Letztes Sicherheitsnetz: sollte durch ShopProvisioner eigentlich
            // nie erreicht werden, verhindert aber in jedem Fall einen
            // unerklärten 500er.
            report($e);

            return redirect()->route('schools.show', $onboarding)->with('provisionError', $this->describeError($e));
        }
    }

    /** On-Demand-Nachbearbeitung: Versandklasse/Kategorie auf Printify-Produkten. */
    public function ondemandSync(SchoolOnboarding $onboarding, ShopProvisioner $provisioner): RedirectResponse
    {
        try {
            $log = $provisioner->ondemandSync($onboarding);

            return redirect()->route('schools.show', $onboarding)->with('provisionLog', $log);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('schools.show', $onboarding)->with('provisionError', $this->describeError($e));
        }
    }

    /** Blueprint-Suche für den Konfigurator (🔍-Button neben Blueprint-ID) — Alternative zu printify:check am Server. */
    public function printifyBlueprintSearch(Request $request, PrintifyClient $printify): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        try {
            $blueprints = $printify->searchBlueprints($query);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => $this->describeError($e)['user']], 502);
        }

        return response()->json(['results' => collect($blueprints)->take(30)->map(fn ($b) => [
            'id' => $b['id'],
            'title' => trim(($b['brand'] ?? '').' '.($b['model'] ?? '').' ('.($b['title'] ?? '').')'),
        ])->values()]);
    }

    /** Provider-Suche für den Konfigurator (🔍-Button neben Provider-ID). */
    public function printifyProviderSearch(Request $request, PrintifyClient $printify): JsonResponse
    {
        $blueprintId = (int) $request->query('blueprint_id', 0);
        $query = mb_strtolower(trim((string) $request->query('q', '')));
        if ($blueprintId <= 0) {
            return response()->json(['error' => 'Bitte zuerst eine Blueprint-ID eintragen (oder über die Blueprint-Suche wählen).'], 422);
        }

        try {
            $providers = $printify->printProviders($blueprintId);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => $this->describeError($e)['user']], 502);
        }

        if ($query !== '') {
            $providers = array_values(array_filter($providers, fn ($p) => str_contains(mb_strtolower($p['title'] ?? ''), $query)));
        }

        return response()->json(['results' => collect($providers)->map(fn ($p) => [
            'id' => $p['id'],
            'title' => $p['title'] ?? '?',
        ])->values()]);
    }

    public function destroy(SchoolOnboarding $onboarding): RedirectResponse
    {
        // Löscht nur den Antrag im Tool — bereits im Shop angelegte
        // Kategorien/Produkte/CPT-Einträge bleiben unberührt.
        $onboarding->delete();

        return redirect()->route('schools.index')->with('deleted', $onboarding->school_name);
    }

    /**
     * Baut eine einheitliche, immer verständliche Fehlerbeschreibung — mit
     * Klartext-Erklärung (falls bekannt) und immer sichtbaren technischen
     * Details zum Kopieren/Weiterleiten an den Support.
     *
     * @return array{user: string, hint: ?string, technical: string}
     */
    private function describeError(\Throwable $e): array
    {
        if ($e instanceof WooCommerceApiException) {
            return ['user' => $e->userMessage(), 'hint' => $e->hint(), 'technical' => $e->getMessage()];
        }

        return [
            'user' => 'Die Shop-Anlage wurde durch einen unerwarteten technischen Fehler abgebrochen.',
            'hint' => 'Bitte die technischen Details unten an den Support weitergeben.',
            'technical' => get_class($e).': '.$e->getMessage().' in '.basename($e->getFile()).':'.$e->getLine(),
        ];
    }
}
