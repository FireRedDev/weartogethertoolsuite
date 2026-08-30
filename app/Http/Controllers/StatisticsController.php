<?php

namespace App\Http\Controllers;

use App\Exceptions\WooCommerceApiException;
use App\Services\Statistics\Charts\BarChart;
use App\Services\Statistics\Charts\ColumnChart;
use App\Services\Statistics\Charts\LineChart;
use App\Services\Statistics\RevenueForecast;
use App\Services\Statistics\RevenueReport;
use App\Services\Statistics\SchoolYear;
use App\Services\Statistics\StatisticsFilters;
use App\Services\WooCommerceClient;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Modul „Statistiken": Umsatzauswertung nach österreichischem Schuljahr.
 *
 * Anders als die Startseite darf diese Seite auf die WooCommerce-API warten —
 * sie ist der Zweck des Aufrufs. Ist der Shop nicht erreichbar, erscheint eine
 * erklärte Meldung samt technischer Details statt eines 500ers.
 */
class StatisticsController extends Controller
{
    public function index(
        Request $request,
        WooCommerceClient $client,
        RevenueReport $report,
        RevenueForecast $forecast,
    ): View {
        $filters = StatisticsFilters::fromRequest($request);

        if (! $client->isConfigured()) {
            return view('statistics.index', [
                'filters' => $filters,
                'years' => SchoolYear::recent(),
                'schools' => collect(),
                'error' => 'Die Verbindung zum Shop ist nicht eingerichtet (WC_STORE_URL / WC_CONSUMER_KEY / WC_CONSUMER_SECRET). '
                    .'Ohne Shop-Zugang gibt es keine Bestelldaten zum Auswerten.',
                'technical' => null,
            ]);
        }

        try {
            $data = $report->build($filters);
        } catch (WooCommerceApiException $e) {
            return view('statistics.index', [
                'filters' => $filters,
                'years' => SchoolYear::recent(),
                'schools' => collect(),
                'error' => $e->userMessage().($e->hint() ? ' '.$e->hint() : ''),
                'technical' => $e->getMessage(),
            ]);
        }

        $projection = $forecast->build($data['current'], $data['history'], $filters->target);

        return view('statistics.index', [
            'filters' => $filters,
            'years' => SchoolYear::recent(),
            'schools' => $data['schools'],
            'error' => null,
            'technical' => null,
            'current' => $data['current'],
            'previous' => $data['previous'],
            'previousAtSamePoint' => $data['previousAtSamePoint'],
            'productRanking' => $data['products'],
            'colorRanking' => $data['colors'],
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
        ]);
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
