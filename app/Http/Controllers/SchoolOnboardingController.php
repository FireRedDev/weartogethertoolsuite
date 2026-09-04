<?php

namespace App\Http\Controllers;

use App\Exceptions\WooCommerceApiException;
use App\Models\SchoolOnboarding;
use App\Services\PresentationSheet\PresentationSheetRenderer;
use App\Services\SchoolShop\LogoManager;
use App\Services\SchoolShop\OnboardingStatus;
use App\Services\SchoolShop\OrderEmailGenerator;
use App\Services\SchoolShop\OrderWindowExtender;
use App\Services\SchoolShop\PrintifyClient;
use App\Services\SchoolShop\PrintifyProvisioner;
use App\Services\SchoolShop\ProductConfigurator;
use App\Services\SchoolShop\ProvisionAbortedException;
use App\Services\SchoolShop\SchoolInfoMailGenerator;
use App\Services\SchoolShop\SchoolOrderStats;
use App\Services\SchoolShop\ShopPageChecker;
use App\Services\SchoolShop\ShopProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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

    public function show(
        SchoolOnboarding $onboarding,
        PrintifyProvisioner $printifyProvisioner,
        PresentationSheetRenderer $sheet,
        SchoolOrderStats $orderStats,
        SchoolInfoMailGenerator $schoolMail,
    ): View {
        // Produktzeilen des Präsentationsblatts: gespeicherte Fassung, sonst der
        // Vorschlag aus dem Konfigurator — immer auf die Zeilenzahl aufgefüllt,
        // damit auch leere Zeilen bearbeitbar sind.
        // Bestellzahlen nach der Antwort nachladen — der Abruf braucht eine
        // eigene Abfrage je Produkt und darf die Seite nicht aufhalten.
        if ($onboarding->delivery_type === 'collective') {
            app()->terminating(static function () use ($orderStats, $onboarding) {
                @ignore_user_abort(true);
                $orderStats->warm($onboarding);
            });
        }

        $rows = $sheet->productRows($onboarding);
        $rowCount = max(count($rows), (int) config('presentation_sheet.products.max_products'));
        $sheetRows = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $sheetRows[] = [
                'name' => $rows[$i]['name'] ?? '',
                'sub' => $rows[$i]['sub'] ?? '',
                'iconName' => $rows[$i]['icon'] ?? '',
            ];
        }

        return view('schools.show', [
            'onboarding' => $onboarding,
            'emailBody' => $onboarding->delivery_type === 'collective'
                ? app(OrderEmailGenerator::class)->body($onboarding)
                : null,
            'emailSubject' => app(OrderEmailGenerator::class)->subject($onboarding),
            'printifyEconomics' => $onboarding->delivery_type === 'ondemand'
                ? $this->printifyEconomics($onboarding, $printifyProvisioner)
                : [],
            // Bestellzahlen nur bei angelegten Sammelbestellungen — sonst gibt es
            // keine Kategorie, gegen die sich zählen ließe.
            'orderStats' => $onboarding->delivery_type === 'collective' ? $orderStats->for($onboarding) : null,
            'schoolMailSubject' => $schoolMail->subject($onboarding),
            'schoolMailBody' => $schoolMail->body($onboarding),
            'statusOptions' => OnboardingStatus::manualOptions($onboarding),
            'statusActions' => OnboardingStatus::actionOnly($onboarding),
            // Die Aufträge dieses Bestellfensters aus der Auftragsbilanz.
            // Reine Datenbankabfrage, kein Schnittstellenaufruf — und der Weg
            // zurück in Modul 4, den es bisher nur in eine Richtung gab.
            'balanceOrders' => \App\Models\BalanceOrder::query()
                ->where('school_onboarding_id', $onboarding->id)
                ->orderByDesc('ordered_on')
                ->get(),
            'sheetMissing' => $sheet->missingRequirements($onboarding),
            'sheetRows' => $sheetRows,
            'sheetIcons' => $sheet->availableIcons(),
            'sheetShopUrl' => $sheet->shopUrl($onboarding),
            // Seitenverhältnis der drei Bildfenster für den Ausschnitt-Wähler
            'sheetWindows' => [
                'back' => config('presentation_sheet.windows.mockup_back'),
                'front' => config('presentation_sheet.windows.mockup_front'),
                'detail' => config('presentation_sheet.windows.detail_circle'),
            ],
        ]);
    }

    /**
     * Einkaufspreis, Versand, Region und Marge je Produkt für die
     * Konfigurator-Anzeige (Blueprint/Provider muss gesetzt sein;
     * Printify-Fehler blocken die Seite nicht, die Zelle bleibt dann leer).
     *
     * @return array<string, array<string, mixed>>
     */
    private function printifyEconomics(SchoolOnboarding $onboarding, PrintifyProvisioner $printifyProvisioner): array
    {
        $info = [];
        foreach ($onboarding->products ?? [] as $product) {
            if (empty($product['key'])) {
                continue;
            }
            try {
                $economics = $printifyProvisioner->economics($product);
            } catch (\Throwable $e) {
                report($e);

                continue;
            }
            if ($economics !== null) {
                $info[$product['key']] = $economics;
            }
        }

        return $info;
    }

    public function update(Request $request, SchoolOnboarding $onboarding): RedirectResponse
    {
        $validated = $request->validate([
            'school_name' => ['required', 'string', 'max:150'],
            'delivery_type' => ['required', 'in:collective,ondemand,list'],
            // Nur Wechsel, die von hier aus überhaupt sinnvoll sind. „Angelegt"
            // und „Abgeschlossen" entstehen ausschließlich durch die jeweilige
            // Aktion, damit der Status nie etwas behauptet, was im Shop fehlt.
            'status' => ['required', Rule::in(array_keys(OnboardingStatus::manualOptions($onboarding)))],
            'class_list' => ['nullable', 'string', 'max:2000'],
            'window_start' => ['nullable', 'date'],
            'window_end' => ['nullable', 'date', 'after_or_equal:window_start'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'products' => ['nullable', 'array'],
            'auto_extend' => ['nullable', 'boolean'],
            'auto_extend_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'mockups_enabled' => ['nullable', 'boolean'],
            'print_slots_submitted' => ['nullable', 'boolean'],
            'print_front' => ['nullable', 'boolean'],
            'print_back' => ['nullable', 'boolean'],
            'logo_front_position' => ['nullable', 'in:'.implode(',', array_keys(config('schoolshop.logo_positions')))],
            'logo_back_position' => ['nullable', 'in:'.implode(',', array_keys(config('schoolshop.logo_positions')))],
            'logo_front_size' => ['nullable', 'in:'.implode(',', array_keys(config('schoolshop.logo_sizes')))],
            'logo_back_size' => ['nullable', 'in:'.implode(',', array_keys(config('schoolshop.logo_sizes')))],
        ]);

        // On-Demand: Produkte werden laufend einzeln verschickt, es gibt kein
        // Bestellfenster und keine Klassenliste (Lieferung an die Privatadresse
        // der Kund:innen) — beide Felder sind im Konfigurator daher ausgeblendet.
        $isOndemand = $validated['delivery_type'] === 'ondemand';
        $previousEnd = $onboarding->window_end?->toDateString();

        $onboarding->fill([
            'school_name' => $validated['school_name'],
            'delivery_type' => $validated['delivery_type'],
            'status' => $validated['status'],
            'class_list' => $isOndemand ? null : ($validated['class_list'] ?? null),
            'window_start' => $isOndemand ? SchoolOnboarding::ONDEMAND_WINDOW_START : ($validated['window_start'] ?? null),
            'window_end' => $isOndemand ? SchoolOnboarding::ONDEMAND_WINDOW_END : ($validated['window_end'] ?? null),
            'notes' => $validated['notes'] ?? null,
            'auto_extend' => $isOndemand ? false : $request->boolean('auto_extend'),
            'auto_extend_days' => $validated['auto_extend_days'] ?? $onboarding->auto_extend_days,
            'mockups_enabled' => $request->boolean('mockups_enabled'),
            'logo_front_position' => $validated['logo_front_position'] ?? $onboarding->logoPositionKey('front'),
            'logo_front_size' => $validated['logo_front_size'] ?? $onboarding->logoSizeKey('front'),
            'logo_back_position' => $validated['logo_back_position'] ?? $onboarding->logoPositionKey('back'),
            'logo_back_size' => $validated['logo_back_size'] ?? $onboarding->logoSizeKey('back'),
        ]);

        // Ein nicht angehaktes Kästchen wird gar nicht mitgeschickt — ohne den
        // Marker ließe sich „aus" nicht von „gar nicht im Formular enthalten"
        // unterscheiden, und ein Speichern ohne den Logo-Bereich würde beide
        // Drucke abschalten. Ab dem ersten Speichern mit Marker sind die Drucke
        // explizit gesetzt und lösen sich vom Formularwunsch (print_areas).
        if ($request->boolean('print_slots_submitted')) {
            $onboarding->fill([
                'print_front' => $request->boolean('print_front'),
                'print_back' => $request->boolean('print_back'),
            ]);
        }
        // Wird das Enddatum von Hand geändert, ist die automatische Verlängerung
        // für dieses Fenster wieder frei — sonst bliebe sie nach einmaligem
        // Verlängern für immer verbraucht.
        if ($previousEnd !== $onboarding->window_end?->toDateString()) {
            OrderWindowExtender::resetFor($onboarding);
        }

        // Wer speichert, bearbeitet — ein Antrag bleibt danach nicht „neu".
        if ($onboarding->status === OnboardingStatus::NEU) {
            $onboarding->status = OnboardingStatus::IN_BEARBEITUNG;
        }
        $onboarding->products = ProductConfigurator::applyInput($onboarding->products ?? [], $validated['products'] ?? []);
        $onboarding->save();

        return redirect()->route('schools.show', $onboarding)->with('saved', true);
    }

    /**
     * Logo für einen Druck hochladen bzw. austauschen. Das Logo ist im
     * FluentForms-Formular kein Pflichtfeld — ohne diese Möglichkeit ließe sich
     * für solche Anträge weder ein Printify-Produkt noch ein Mockup erzeugen.
     */
    public function logoUpload(Request $request, SchoolOnboarding $onboarding, string $slot, LogoManager $logos): RedirectResponse
    {
        abort_unless(array_key_exists($slot, SchoolOnboarding::PRINT_SLOTS), 404);

        $request->validate(
            ['logo' => ['required', 'file', 'mimes:'.implode(',', LogoManager::ALLOWED_EXTENSIONS), 'max:5120']],
            [
                'logo.required' => 'Bitte eine Logo-Datei auswählen.',
                'logo.mimes' => 'Erlaubt sind PNG, JPG und WebP — Printify und die Mockup-Erzeugung brauchen ein Pixelformat (kein SVG/PDF).',
                'logo.max' => 'Die Datei ist zu groß (maximal 5 MB).',
            ],
        );

        $quality = $logos->qualityWarnings($request->file('logo'));
        $warning = $logos->store($onboarding, $slot, $request->file('logo'));

        $messages = array_values(array_filter([$warning, ...$quality]));
        $redirect = redirect()->route('schools.show', $onboarding);

        return $messages === []
            ? $redirect->with('saved', true)
            : $redirect->withErrors(['logo' => $messages]);
    }

    /** Hochgeladenes Logo entfernen — danach gilt wieder der Formular-Upload. */
    public function logoReset(SchoolOnboarding $onboarding, string $slot, LogoManager $logos): RedirectResponse
    {
        abort_unless(array_key_exists($slot, SchoolOnboarding::PRINT_SLOTS), 404);
        $logos->reset($onboarding, $slot);

        return redirect()->route('schools.show', $onboarding)->with('saved', true);
    }

    /**
     * Liefert ein im Tool hochgeladenes Logo aus (Vorschaubild und Download).
     * Bewusst ohne Zugangsschutz: Schullogos sind nicht vertraulich, und externe
     * Dienste (Printify/Dynamic Mockups) müssen die Datei notfalls selbst laden
     * können, wenn der Upload in die WordPress-Mediathek gescheitert ist.
     */
    public function logoShow(Request $request, SchoolOnboarding $onboarding, string $slot, LogoManager $logos): Response
    {
        abort_unless(array_key_exists($slot, SchoolOnboarding::PRINT_SLOTS), 404);
        $file = $logos->read($onboarding, $slot);
        abort_if($file === null, 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => $disposition.'; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
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

    /** Ruft die Bestellseite der Schule ab — der QR-Code darf nicht ins Leere führen. */
    public function checkShopPage(SchoolOnboarding $onboarding, ShopPageChecker $checker): RedirectResponse
    {
        return redirect()->route('schools.show', $onboarding)
            ->with('shopPageCheck', $checker->check($onboarding))
            ->withFragment('praesentationsblatt');
    }

    /**
     * Antrag für ein neues Bestellfenster derselben Schule anlegen — Schulen
     * bestellen jährlich wieder. Übernommen werden Stammdaten, Produkte samt
     * Preisen/Farben, Logos, Druckeinstellungen und die Blatt-Vorgaben; alles
     * Fensterbezogene (Zeitraum, Klassenliste, Shop-IDs, Protokoll) beginnt neu.
     */
    public function duplicate(SchoolOnboarding $onboarding): RedirectResponse
    {
        $copy = $onboarding->replicate([
            // Fensterbezogen — muss neu erfasst werden
            'window_start', 'window_end', 'class_list',
            'auto_extended_at', 'auto_extend_from', 'documents_exported_at',
            // Im Shop Angelegtes gehört zum alten Fenster
            'woo_category_id', 'pods_post_id', 'woo_product_ids', 'printify_product_ids',
            'provision_log', 'mockup_images',
            // Mockups des alten Blatts: neue Fotos, neue Saison
            'sheet_back_path', 'sheet_front_path', 'sheet_detail_path',
        ]);
        $copy->status = OnboardingStatus::IN_BEARBEITUNG;
        $copy->source = 'folgejahr';
        $copy->notes = trim(($onboarding->notes ? $onboarding->notes."\n\n" : '')
            .'Übernommen aus Antrag #'.$onboarding->id.' vom '.$onboarding->created_at->format('d.m.Y').'.');
        $copy->save();

        return redirect()->route('schools.show', $copy)
            ->with('saved', true)
            ->withErrors(['duplicate' => 'Kopie angelegt. Bitte Bestellfenster und Klassenliste neu setzen — Mockups fürs Präsentationsblatt sind bewusst nicht übernommen.']);
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
