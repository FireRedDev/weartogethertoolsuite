<?php

namespace App\Http\Controllers;

use App\Exceptions\WooCommerceApiException;
use App\Models\SchoolOnboarding;
use App\Services\OrderJobFactory;
use App\Services\ShopOrderFetcher;
use App\Services\WooCommerceClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Weg 1: Bestellungen direkt aus dem Shop laden (WooCommerce REST API).
 */
class ShopExportController extends Controller
{
    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly ShopOrderFetcher $fetcher,
        private readonly OrderJobFactory $jobFactory,
    ) {}

    public function form(Request $request): View
    {
        $categories = [];
        $apiError = null;

        // Vorbelegung, wenn aus einem Schul-Antrag heraus aufgerufen: Kategorie
        // und Bestellzeitraum stehen dort schon — das spart den Schritt und die
        // häufigste Fehlerquelle (falsch gewählter Zeitraum).
        $onboarding = $request->filled('onboarding')
            ? SchoolOnboarding::find((int) $request->query('onboarding'))
            : null;
        $prefill = [
            'onboarding' => $onboarding,
            'category' => $onboarding?->woo_category_id,
            'date_from' => $onboarding?->window_start?->toDateString(),
            'date_to' => $onboarding?->window_end?->copy()->addDay()->toDateString(),
        ];

        if (! $this->client->isConfigured()) {
            $apiError = WooCommerceApiException::notConfigured();
        } else {
            try {
                $categories = $this->client->productCategories();
            } catch (WooCommerceApiException $e) {
                report($e);
                $apiError = $e;
            } catch (\Throwable $e) {
                report($e);
                $apiError = new WooCommerceApiException(
                    'Beim Laden der Schulen ist ein unerwarteter Fehler aufgetreten.',
                    get_class($e).': '.$e->getMessage().' in '.basename($e->getFile()).':'.$e->getLine(),
                    'Bitte die technischen Details an den Support weitergeben.',
                );
            }
        }

        return view('tool.shop-export', [
            'categories' => $categories,
            'apiError' => $apiError,
            'prefill' => $prefill,
            'statuses' => config('ordersuite.woocommerce.statuses'),
            'defaultStatuses' => config('ordersuite.woocommerce.default_statuses'),
        ]);
    }

    public function fetch(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            [
                'category' => ['required', 'integer'],
                'statuses' => ['required', 'array', 'min:1'],
                'statuses.*' => ['string', 'in:'.implode(',', array_keys(config('ordersuite.woocommerce.statuses')))],
                'date_from' => ['nullable', 'date'],
                'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
                'onboarding_id' => ['nullable', 'integer'],
            ],
            [
                'category.required' => 'Bitte eine Schule/Organisation auswählen.',
                'statuses.required' => 'Bitte mindestens einen Bestellstatus auswählen.',
                'date_to.after_or_equal' => 'Das Bis-Datum liegt vor dem Von-Datum.',
            ],
        );

        try {
            // Mehrere API-Roundtrips (ein Abruf je Produkt der Schule) können
            // zusammen länger dauern als das PHP-Standardlimit von 30 s;
            // große Abrufe brauchen zudem mehr Speicher als das FPM-Default.
            if (function_exists('set_time_limit')) {
                @set_time_limit(180);
            }
            @ini_set('memory_limit', '512M');

            $table = $this->fetcher->fetch(
                (int) $validated['category'],
                array_values($validated['statuses']),
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null,
            );
        } catch (WooCommerceApiException $e) {
            report($e);

            return back()->withInput()->with('apiFetchError', [
                'user' => $e->userMessage(),
                'hint' => $e->hint(),
                'technical' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            // Kein nackter 500er: jeden unerwarteten Fehler transparent zeigen.
            report($e);

            return back()->withInput()->with('apiFetchError', [
                'user' => 'Beim Verarbeiten der Shop-Daten ist ein unerwarteter Fehler aufgetreten.',
                'hint' => 'Bitte die technischen Details an den Support weitergeben. Oft hilft es, den Zeitraum einzugrenzen und es erneut zu versuchen.',
                'technical' => get_class($e).': '.$e->getMessage().' in '.basename($e->getFile()).':'.$e->getLine(),
            ]);
        }

        if ($table['rows'] === []) {
            return back()->withInput()->withErrors([
                'category' => 'Für diese Auswahl wurden keine Bestellpositionen gefunden. Bitte Schule, Status und Zeitraum prüfen.',
            ]);
        }

        // Am Antrag vermerken, dass die Dokumente erzeugt wurden — die
        // Startseite erinnert sonst weiter daran.
        if (! empty($validated['onboarding_id'])) {
            SchoolOnboarding::whereKey($validated['onboarding_id'])->update(['documents_exported_at' => now()]);
        }

        try {
            $jobId = $this->jobFactory->newJobFromTable($table);
            $this->jobFactory->createFromInputFile($jobId, [
                'source' => 'api',
                'source_details' => [
                    'category_id' => (int) $validated['category'],
                    'category_name' => $request->input('category_name') ?: null,
                    'statuses' => array_values($validated['statuses']),
                    'date_from' => $validated['date_from'] ?? null,
                    'date_to' => $validated['date_to'] ?? null,
                    'order_count' => $table['orderCount'],
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('apiFetchError', [
                'user' => 'Die geladenen Bestellungen ('.count($table['rows']).' Positionen) konnten nicht als Auftrag gespeichert werden.',
                'hint' => 'Bitte die technischen Details an den Support weitergeben. Oft hilft es, den Zeitraum einzugrenzen und es erneut zu versuchen.',
                'technical' => get_class($e).': '.$e->getMessage().' in '.basename($e->getFile()).':'.$e->getLine(),
            ]);
        }

        return redirect()->route('job.show', $jobId);
    }
}
