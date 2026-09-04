<?php

namespace App\Http\Controllers;

use App\Services\Balance\BalanceReport;
use App\Services\Balance\ShopComparison;
use App\Services\Statistics\Charts\BarChart;
use App\Services\Statistics\Charts\ColumnChart;
use App\Services\Statistics\Charts\LineChart;
use App\Services\Statistics\RevenueForecast;
use App\Services\Statistics\RevenueReport;
use App\Models\SeasonGoal;
use App\Services\Statistics\SchoolYear;
use App\Services\Statistics\StatisticsFilters;
use App\Services\Statistics\SeasonPlan;
use App\Services\Statistics\StatisticsWarmer;
use App\Services\WooCommerceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Modul „Statistiken": Umsatzauswertung nach österreichischem Schuljahr.
 *
 * **Diese Seite ruft den Shop nie selbst auf.** Sie zeigt entweder die fertige
 * Auswertung (alle Monate im Zwischenspeicher) oder eine Ladeseite mit
 * Fortschrittsbalken — Zahlen und Diagramme bleiben verborgen, solange sie
 * unvollständig wären. Der eigentliche Abruf läuft über StatisticsWarmer,
 * angestoßen erst NACHDEM die Antwort beim Browser ist: so wartet kein
 * Seitenaufruf auf den Shop, und der Aufbau wird auch dann fertig, wenn jemand
 * die Seite verlässt.
 */
class StatisticsController extends Controller
{
    public function index(
        Request $request,
        WooCommerceClient $client,
        StatisticsWarmer $warmer,
        RevenueReport $report,
        RevenueForecast $forecast,
        BalanceReport $balance,
        ShopComparison $comparison,
    ): View {
        $filters = StatisticsFilters::fromRequest($request);

        // Ohne Shop-Zugang geht nur, was ohne Shop auskommt. Ist die Shop-Quelle
        // abgeschaltet, ist genau das der Fall: Dann beruht die Auswertung
        // allein auf der Auftragsbilanz — und das ist der Ausweg, wenn der Shop
        // gerade nicht erreichbar ist.
        if (! $client->isConfigured() && $filters->sourceShop) {
            return view('statistics.unavailable', [
                'filters' => $filters,
                'years' => SchoolYear::recent(),
                'error' => 'Die Verbindung zum Shop ist nicht eingerichtet (WC_STORE_URL / WC_CONSUMER_KEY / WC_CONSUMER_SECRET). '
                    .'Ohne Shop-Zugang gibt es keine Bestelldaten zum Auswerten.',
                'technical' => null,
            ]);
        }

        // „↻ Daten neu laden": alles verwerfen und den Aufbau neu starten.
        if ($filters->fresh) {
            $warmer->reset($filters);
        }

        $progress = $warmer->progress($filters);

        // Ist die Shop-Quelle abgeschaltet, braucht die Seite den Shop nicht:
        // Sie zeigt dann allein die Auftragsbilanz und steht sofort. Das ist
        // zugleich der Ausweg, wenn der Shop gerade nicht erreichbar ist.
        if (! $filters->sourceShop) {
            $progress = ['done' => true] + $progress;
        }

        if (! $progress['done']) {
            $this->warmAfterResponse($warmer, $filters, $progress);

            return view('statistics.loading', [
                'filters' => $filters,
                'years' => SchoolYear::recent(),
                'progress' => $progress,
            ]);
        }

        // Ohne Nachladen: Die Auswertung nutzt ausschließlich, was im
        // Zwischenspeicher liegt. Fehlt etwas, kommt die Ladeseite — dieser
        // Seitenaufruf wartet nie auf den Shop.
        $data = $report->build($filters, allowFetching: false);

        // Sonderfall: ein Monat ist zwischen Prüfung und Auswertung abgelaufen.
        // Dann lieber wieder die Ladeseite als halbe Zahlen.
        if (! $data['complete']) {
            $progress = $warmer->progress($filters);
            $this->warmAfterResponse($warmer, $filters, $progress);

            return view('statistics.loading', [
                'filters' => $filters,
                'years' => SchoolYear::recent(),
                'progress' => $progress,
            ]);
        }

        $goal = SeasonGoal::forYear($filters->year);
        $projection = $forecast->build($data['current'], $data['history'], $goal);
        $plan = (new SeasonPlan)->build($data['current'], $data['previous'], $projection, $goal);

        return view('statistics.index', [
            'filters' => $filters,
            'years' => SchoolYear::recent(),
            'schools' => $data['schools'],
            'current' => $data['current'],
            'previous' => $data['previous'],
            'previousAtSamePoint' => $data['previousAtSamePoint'],
            'productRanking' => $data['products'],
            'colorRanking' => $data['colors'],
            'schoolRanking' => $data['schoolRanking'],
            'forecast' => $projection,
            'goal' => $goal,
            'plan' => $plan,
            'fetchedAt' => $data['fetchedAt'],
            /*
             * Die Auswertungen aus der bisherigen Excel. Sie beruhen auf der
             * Auftragsbilanz und nicht auf dem Shop — Ausgaben, Provision und
             * damit jeder Gewinn stehen nur dort. Deshalb hängen sie auch nicht
             * an den Quellenschaltern: Ohne Auftragsbilanz gäbe es sie gar nicht.
             */
            'balance' => $balance->forYear($filters->year),
            'balanceYears' => $balance->byYear(),
            'balanceSchools' => array_slice($balance->bySchool($filters->year), 0, (int) config('statistics.ranking_limit')),
            'balanceOrders' => array_slice($balance->byOrder($filters->year), 0, (int) config('statistics.ranking_limit')),
            'balanceProducts' => $balance->products(),
            // Shop gegen Eintrag, je Schuljahr. Reine Zwischenspeicher-Abfrage
            // — für Jahre, die noch nicht geladen sind, bleibt die Spalte leer.
            'balanceComparison' => $comparison->forYears(
                array_map(static fn (array $row) => $row['year'], $balance->byYear()),
            ),
            'monthChart' => (new ColumnChart)->build(
                $this->monthRows($data['current'], $data['previous']),
                $data['current']['label'],
                $data['previous']['label'],
            ),
            'curveChart' => (new LineChart)->build(
                $projection['curve'],
                $data['current']['label'],
                $data['previous']['label'],
                $projection['target'],
            ),
            'productChart' => (new BarChart)->build(
                $this->rankingRows($data['products'], withSwatch: false),
                $data['current']['label'],
                $data['previous']['label'],
                'Stk.',
            ),
            'colorChart' => (new BarChart)->build(
                $this->rankingRows($data['colors'], withSwatch: true),
                $data['current']['label'],
                $data['previous']['label'],
                'Stk.',
            ),
            // Schulen werden nach UMSATZ gereiht — dort ist die Frage „welche
            // Schule bringt am meisten", nicht „wie viele Teile".
            'schoolChart' => (new BarChart)->build(
                array_map(static fn (array $row) => [
                    'name' => $row['name'],
                    'value' => (float) $row['revenue'],
                    'previous' => (float) $row['previousRevenue'],
                    'note' => null,
                    'swatch' => null,
                ], $data['schoolRanking']),
                $data['current']['label'],
                $data['previous']['label'],
                '€',
            ),
        ]);
    }

    /**
     * Saisonziel und die Umsätze außerhalb des Webshops speichern.
     *
     * Bewusst eine eigene Aktion und kein Filter: Das Ziel ist eine
     * Vereinbarung im Team, keine Ansicht. Es bleibt stehen, bis es jemand
     * ändert, und gilt für alle gleich.
     */
    public function saveGoal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'schuljahr' => ['required', 'string'],
            'target_revenue' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'manual_revenue' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'manual_forecast' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'manual_note' => ['nullable', 'string', 'max:200'],
        ], [
            'target_revenue.numeric' => 'Der Zielumsatz muss eine Zahl sein.',
            'target_revenue.min' => 'Der Zielumsatz kann nicht negativ sein.',
        ]);

        $year = SchoolYear::parse($validated['schuljahr']) ?? SchoolYear::current();
        $goal = SeasonGoal::forYear($year);
        $goal->fill([
            'target_revenue' => $validated['target_revenue'] === null || $validated['target_revenue'] === ''
                ? null
                : round((float) $validated['target_revenue'], 2),
            'manual_revenue' => round((float) ($validated['manual_revenue'] ?? 0), 2),
            'manual_forecast' => round((float) ($validated['manual_forecast'] ?? 0), 2),
            'manual_note' => $validated['manual_note'] ?? null,
        ])->save();

        return redirect()
            ->to(route('statistics.index', $request->except(['_token', 'target_revenue', 'manual_revenue', 'manual_forecast', 'manual_note'])).'#saisonziel')
            ->with('goalSaved', true);
    }

    /**
     * Fortschritt des Hintergrund-Aufbaus. Die Ladeseite fragt das im Takt von
     * `statistics.poll_seconds` ab. Reine Zwischenspeicher-Abfrage — antwortet
     * immer sofort — und stößt nebenbei den nächsten Durchgang an.
     */
    public function progress(Request $request, WooCommerceClient $client, StatisticsWarmer $warmer): JsonResponse
    {
        $filters = StatisticsFilters::fromRequest($request);

        if (! $client->isConfigured()) {
            return response()->json([
                'done' => false, 'percent' => 0, 'loaded' => 0, 'total' => 0, 'running' => false,
                'error' => [
                    'message' => 'Die Verbindung zum Shop ist nicht eingerichtet.',
                    'technical' => 'WC_STORE_URL / WC_CONSUMER_KEY / WC_CONSUMER_SECRET fehlen.',
                ],
            ]);
        }

        $progress = $warmer->progress($filters);
        $this->warmAfterResponse($warmer, $filters, $progress);

        return response()->json($progress);
    }

    /**
     * Den nächsten Durchgang anstoßen — aber erst, NACHDEM die Antwort beim
     * Browser ist. Dadurch wartet kein Seitenaufruf auf den Shop, und der
     * Aufbau läuft weiter, wenn jemand den Tab schließt. Läuft bereits ein
     * Durchgang, tut der Warmer von sich aus nichts (Sperre).
     *
     * @param  array<string, mixed>  $progress
     */
    private function warmAfterResponse(StatisticsWarmer $warmer, StatisticsFilters $filters, array $progress): void
    {
        if ($progress['done'] || $progress['running'] || $progress['error'] !== null) {
            return;
        }

        app()->terminating(static fn () => $warmer->warm($filters));
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return list<array{short: string, label: string, current: float, previous: float}>
     */
    private function monthRows(array $current, array $previous): array
    {
        $previousMonths = array_values($previous['months']);
        $rows = [];
        foreach (array_values($current['months']) as $index => $month) {
            $rows[] = [
                'short' => $month['short'],
                'label' => $month['label'],
                'current' => (float) $month['revenue'],
                'previous' => (float) ($previousMonths[$index]['revenue'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Ranglisten werden nach STÜCK gezeichnet („meistverkauft" heißt Stückzahl,
     * nicht Umsatz) — der Umsatz steht daneben in der Tabelle.
     *
     * @param  list<array{name: string, revenue: float, quantity: int, previousRevenue: float, previousQuantity: int}>  $ranking
     * @return list<array{name: string, value: float, previous: float, note: ?string, swatch: ?string}>
     */
    private function rankingRows(array $ranking, bool $withSwatch): array
    {
        return array_map(fn (array $row) => [
            'name' => $row['name'],
            'value' => (float) $row['quantity'],
            'previous' => (float) $row['previousQuantity'],
            'note' => null,
            'swatch' => $withSwatch ? $this->swatch($row['name']) : null,
        ], $ranking);
    }

    /**
     * Farbmuster neben dem Namen einer Produktfarbe. Nur ein Wiedererkennungs-
     * zeichen — die Balkenfarbe bleibt die Serienfarbe, damit die Diagramme
     * lesbar bleiben (eine schwarze Fläche als Balken wäre keine Skala mehr).
     */
    private function swatch(string $name): ?string
    {
        $key = mb_strtolower(trim($name));
        foreach (config('statistics.color_swatches') as $needle => $hex) {
            if ($key === $needle || str_contains($key, (string) $needle)) {
                return $hex;
            }
        }

        return null;
    }
}
