<?php

namespace App\Services\Statistics\Charts;

/**
 * Kumulierter Umsatzverlauf über das Schuljahr: laufendes Jahr (durchgezogen),
 * Vorjahr (durchgezogen, zweite Serie), Hochrechnung (strichliert — Strichelung
 * bedeutet hier tatsächlich „geschätzt", anders als bei Gitterlinien) und eine
 * waagrechte Zielmarke.
 */
class LineChart
{
    private const WIDTH = 720.0;

    private const PLOT_TOP = 18.0;

    private const PLOT_HEIGHT = 220.0;

    private const MARGIN_LEFT = 52.0;

    private const MARGIN_RIGHT = 10.0;

    private const AXIS_BAND = 26.0;

    /**
     * @param  list<array{short: string, label: string, current: ?float, previous: ?float, forecast: ?float}>  $curve
     * @return array<string, mixed>
     */
    public function build(array $curve, string $currentLabel, string $previousLabel, ?float $target): array
    {
        $baseline = self::PLOT_TOP + self::PLOT_HEIGHT;
        $height = $baseline + self::AXIS_BAND;
        $plotWidth = self::WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;
        $count = max(1, count($curve));

        $max = $target ?? 0.0;
        foreach ($curve as $point) {
            $max = max($max, (float) ($point['current'] ?? 0), (float) ($point['previous'] ?? 0), (float) ($point['forecast'] ?? 0));
        }
        $scale = Shapes::scale($max);

        $x = static fn (int $index) => self::MARGIN_LEFT + ($count > 1 ? $plotWidth * $index / ($count - 1) : $plotWidth / 2);
        $y = static fn (?float $value) => $value === null
            ? null
            : $baseline - ($scale['max'] > 0 ? ($value / $scale['max']) * self::PLOT_HEIGHT : 0);

        $gridlines = [];
        foreach ($scale['ticks'] as $tick) {
            $gridlines[] = ['y' => $y($tick), 'label' => Palette::axisNumber($tick)];
        }

        $series = [];
        foreach ([
            ['key' => 'previous', 'color' => Palette::SERIES_2, 'label' => $previousLabel, 'dashed' => false],
            ['key' => 'forecast', 'color' => Palette::FORECAST, 'label' => 'Hochrechnung', 'dashed' => true],
            ['key' => 'current', 'color' => Palette::SERIES_1, 'label' => $currentLabel, 'dashed' => false],
        ] as $definition) {
            $points = [];
            foreach ($curve as $index => $point) {
                $points[] = ['x' => $x($index), 'y' => $y($point[$definition['key']])];
            }
            $path = Shapes::line($points);
            if ($path === '') {
                continue;
            }
            $series[] = $definition + ['path' => $path];
        }

        // Punkte und Direktbeschriftung nur am jeweils letzten bekannten Wert.
        $markers = [];
        foreach ([
            ['key' => 'current', 'color' => Palette::SERIES_1, 'label' => $currentLabel],
            ['key' => 'forecast', 'color' => Palette::FORECAST, 'label' => 'Hochrechnung'],
        ] as $definition) {
            $lastIndex = null;
            foreach ($curve as $index => $point) {
                if ($point[$definition['key']] !== null) {
                    $lastIndex = $index;
                }
            }
            if ($lastIndex === null) {
                continue;
            }
            $value = (float) $curve[$lastIndex][$definition['key']];
            $markers[] = [
                'x' => $x($lastIndex),
                'y' => $y($value),
                'color' => $definition['color'],
                'text' => Palette::euroShort($value),
                // Am rechten Rand die Beschriftung nach innen ziehen
                'anchor' => $lastIndex >= $count - 2 ? 'end' : 'start',
                'dx' => $lastIndex >= $count - 2 ? -8 : 8,
                'title' => $definition['label'].' · '.$curve[$lastIndex]['label'].': '.Palette::euro($value),
            ];
        }

        $ticks = [];
        foreach ($curve as $index => $point) {
            $ticks[] = ['x' => $x($index), 'label' => $point['short']];
        }

        $legend = [
            ['label' => $currentLabel, 'color' => Palette::SERIES_1],
            ['label' => $previousLabel, 'color' => Palette::SERIES_2],
        ];
        if (collect($series)->contains(fn ($s) => $s['key'] === 'forecast')) {
            $legend[] = ['label' => 'Hochrechnung', 'color' => Palette::FORECAST, 'dashed' => true];
        }

        return [
            'width' => self::WIDTH,
            'height' => $height,
            'baseline' => $baseline,
            'plotLeft' => self::MARGIN_LEFT,
            'plotRight' => self::WIDTH - self::MARGIN_RIGHT,
            'gridlines' => $gridlines,
            'series' => $series,
            'markers' => $markers,
            'ticks' => $ticks,
            'legend' => $legend,
            'target' => $target === null || $target <= 0 ? null : [
                'y' => $y($target),
                'label' => 'Ziel '.Palette::euroShort($target),
            ],
            'empty' => $max <= 0,
        ];
    }
}
