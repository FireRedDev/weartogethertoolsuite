<?php

namespace App\Http\Controllers;

use App\Services\Statistics\Charts\BarChart;
use App\Services\Statistics\Charts\ColumnChart;
use App\Services\Statistics\Charts\LineChart;
use App\Services\Statistics\RevenueForecast;
use App\Services\Statistics\RevenueReport;
use App\Services\Statistics\SchoolYear;
use App\Services\Statistics\StatisticsFilters;
use App\Services\Statistics\StatisticsWarmer;
use App\Services\WooCommerceClient;
use Illuminate\Http\JsonResponse;
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
    ): View {
        $filters = StatisticsFilters::fromRequest($request);

        if (! $client->isConfigured()) {
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

        if (! $progress['done']) {
            $this->warmAfterResponse($warmer, $filters, $progress);

            return view('statistics.loading', [
                'filters' => $filters,
                'years' => SchoolYear::recent(),
                'progress' => $progress,
            ]);
        }

        $data = $report->build($filters);

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

        $projection = $forecast->build($data['current'], $data['history'], $filters->target);

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
