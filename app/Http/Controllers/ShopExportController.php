<?php

namespace App\Http\Controllers;

use App\Exceptions\WooCommerceApiException;
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

    public function form(): View
    {
        $categories = [];
        $apiError = null;

        if (! $this->client->isConfigured()) {
            $apiError = WooCommerceApiException::notConfigured();
        } else {
            try {
                $categories = $this->client->productCategories();
            } catch (WooCommerceApiException $e) {
                report($e);
                $apiError = $e;
            }
        }

        return view('tool.shop-export', [
            'categories' => $categories,
            'apiError' => $apiError,
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
            ],
            [
                'category.required' => 'Bitte eine Schule/Organisation auswählen.',
                'statuses.required' => 'Bitte mindestens einen Bestellstatus auswählen.',
                'date_to.after_or_equal' => 'Das Bis-Datum liegt vor dem Von-Datum.',
            ],
        );

        try {
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
        }

        if ($table['rows'] === []) {
            return back()->withInput()->withErrors([
                'category' => 'Für diese Auswahl wurden keine Bestellpositionen gefunden. Bitte Schule, Status und Zeitraum prüfen.',
            ]);
        }

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

        return redirect()->route('job.show', $jobId);
    }
}
