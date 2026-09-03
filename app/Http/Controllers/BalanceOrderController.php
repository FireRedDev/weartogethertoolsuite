<?php

namespace App\Http\Controllers;

use App\Models\BalanceOrder;
use App\Models\SchoolOnboarding;
use App\Services\Balance\BalanceReport;
use App\Services\Balance\OnlineRevenueSync;
use App\Services\Balance\ShopComparison;
use App\Services\Statistics\SchoolYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Modul „Auftragsbilanz": die gepflegte Auftragsliste, Nachfolgerin der Excel.
 *
 * Hier wird ausschließlich EINGETRAGEN und ANGEZEIGT. Ausgewertet wird im
 * Statistikmodul — die Trennung ist gewollt: Wer Zahlen pflegt, will eine
 * Tabelle und keine Diagramme, und wer auswertet, will nicht versehentlich
 * etwas verändern.
 *
 * Die Seite lädt ausschließlich aus der eigenen Datenbank. Der Abgleich mit dem
 * Webshop nutzt nur bereits geladene Monate und hält die Seite nie auf.
 */
class BalanceOrderController extends Controller
{
    public function index(Request $request, BalanceReport $report, ShopComparison $comparison, OnlineRevenueSync $sync): View
    {
        $years = $report->years();
        $year = SchoolYear::parse($request->query('schuljahr'))
            ?? ($years[0] ?? SchoolYear::current());

        // Auch das laufende Schuljahr anbieten, selbst wenn noch kein Auftrag
        // darin steht — sonst ließe sich der erste nicht anlegen.
        $keys = array_map(static fn (SchoolYear $y) => $y->key(), $years);
        foreach ([SchoolYear::current(), $year] as $extra) {
            if (! in_array($extra->key(), $keys, true)) {
                $years[] = $extra;
                $keys[] = $extra->key();
            }
        }
        usort($years, static fn (SchoolYear $a, SchoolYear $b) => $b->startYear <=> $a->startYear);

        // Die Online-Einnahmen verknüpfter Aufträge NACH der Antwort nachtragen.
        // Angezeigt wird, was gespeichert ist; der Nachtrag wirkt beim nächsten
        // Aufruf. Diese Seite wartet nie auf den Shop.
        app()->terminating(static fn () => $sync->syncAfterResponse($year));

        return view('balance.index', [
            'year' => $year,
            'years' => $years,
            'summary' => $report->forYear($year),
            'comparison' => $comparison->forYear($year),
            'productTypes' => (array) config('auftragsbilanz.product_types'),
        ]);
    }

    public function create(Request $request): View
    {
        $onboarding = $request->filled('antrag')
            ? SchoolOnboarding::find((int) $request->query('antrag'))
            : null;

        $order = new BalanceOrder([
            'number' => BalanceOrder::nextNumber(),
            'school_name' => $onboarding?->school_name ?? '',
            'ordered_on' => BalanceOrder::defaultDate($onboarding),
            'school_onboarding_id' => $onboarding?->id,
            'delivery_type' => $onboarding?->delivery_type === 'ondemand' ? 'ondemand' : 'collective',
            'online_source' => $onboarding !== null ? 'shop' : 'manual',
            'products' => [],
            'source' => 'manual',
        ]);
        $order->school_year = SchoolYear::forDate($order->ordered_on)->startYear;

        return $this->form($order, 'balance.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $order = new BalanceOrder(['source' => 'manual']);
        $this->fill($order, $this->validated($request));
        $order->save();

        return redirect()
            ->route('balance.index', ['schuljahr' => $order->school_year])
            ->with('balanceSaved', $order->label());
    }

    public function edit(BalanceOrder $order): View
    {
        return $this->form($order, 'balance.edit');
    }

    public function update(Request $request, BalanceOrder $order): RedirectResponse
    {
        $this->fill($order, $this->validated($request));
        $order->save();

        return redirect()
            ->route('balance.index', ['schuljahr' => $order->school_year])
            ->with('balanceSaved', $order->label());
    }

    public function destroy(BalanceOrder $order): RedirectResponse
    {
        $year = $order->school_year;
        $label = $order->label();
        $order->delete();

        return redirect()
            ->route('balance.index', ['schuljahr' => $year])
            ->with('balanceDeleted', $label);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * Achtung: `validate()` liefert nur die Felder zurück, die auch geschickt
     * wurden — ein leeres Zahlenfeld fehlt im Ergebnis komplett. Jeder Zugriff
     * hier braucht deshalb einen Standardwert.
     */
    private function fill(BalanceOrder $order, array $data): void
    {
        $data += [
            'number' => null, 'ordered_on' => null, 'school_onboarding_id' => null,
            'delivery_type' => null, 'vat' => null, 'note' => null, 'products' => [],
        ];

        $products = [];
        foreach (array_keys((array) config('auftragsbilanz.product_types')) as $type) {
            $products[$type] = (int) ($data['products'][$type] ?? 0);
        }

        $online = round((float) ($data['revenue_online'] ?? 0), 2);
        $cash = round((float) ($data['revenue_cash'] ?? 0), 2);

        $order->fill([
            'number' => $data['number'] ?: null,
            'school_name' => $data['school_name'],
            'ordered_on' => $data['ordered_on'],
            // Sobald jemand ein Datum von Hand setzt, ist es keine Schätzung
            // mehr — auch dann nicht, wenn zufällig dasselbe herauskommt.
            'date_is_estimate' => false,
            'school_year' => SchoolYear::forDate(new \DateTimeImmutable($data['ordered_on']))->startYear,
            'school_onboarding_id' => $data['school_onboarding_id'] ?: null,
            'delivery_type' => $data['delivery_type'] ?: null,
            'online_source' => $data['online_source'],
            'revenue_online' => $online,
            'revenue_cash' => $cash,
            'commission' => round((float) ($data['commission'] ?? 0), 2),
            'expenses' => round((float) ($data['expenses'] ?? 0), 2),
            // Leer gelassen heißt „normal besteuert": aus dem Bruttobetrag
            // herausrechnen. Ein ausdrückliches 0 bleibt 0 — das brauchen die
            // Jahre vor der GmbH-Gründung.
            'vat' => $data['vat'] === null || $data['vat'] === ''
                ? BalanceOrder::vatFromGross($online + $cash)
                : round((float) $data['vat'], 2),
            'products' => $products,
            'individual' => (int) ($data['individual'] ?? 0),
            'note' => $data['note'] ?: null,
        ]);

        // Die Shop-Kategorie kommt immer vom verknüpften Antrag — von Hand
        // eingetippt wäre sie nur eine weitere Fehlerquelle.
        $order->woo_category_id = $order->school_onboarding_id !== null
            ? $order->onboarding()->first()?->woo_category_id
            : null;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'number' => ['nullable', 'string', 'max:20'],
            'school_name' => ['required', 'string', 'max:200'],
            'ordered_on' => ['required', 'date'],
            'school_onboarding_id' => ['nullable', 'integer', Rule::exists('school_onboardings', 'id')],
            'delivery_type' => ['nullable', Rule::in(array_keys(BalanceOrder::DELIVERY_TYPES))],
            'online_source' => ['required', Rule::in(array_keys(BalanceOrder::ONLINE_SOURCES))],
            'revenue_online' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'revenue_cash' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'commission' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'expenses' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'products' => ['nullable', 'array'],
            'products.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'individual' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'school_name.required' => 'Ohne Schul- oder Kundennamen lässt sich der Auftrag später nicht zuordnen.',
            'ordered_on.required' => 'Das Auftragsdatum entscheidet, in welches Schuljahr und in welchen Monat der Umsatz zählt.',
        ]);
    }

    private function form(BalanceOrder $order, string $view): View
    {
        return view($view, [
            'order' => $order,
            'productTypes' => (array) config('auftragsbilanz.product_types'),
            // Anträge zur Auswahl — neueste zuerst, weil ein neuer Auftrag fast
            // immer zu einem der letzten Bestellfenster gehört.
            'onboardings' => SchoolOnboarding::query()
                ->orderByDesc('window_end')->orderBy('school_name')
                ->get(['id', 'school_name', 'delivery_type', 'window_start', 'window_end', 'woo_category_id']),
        ]);
    }
}
