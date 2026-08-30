<?php

namespace App\Services\Statistics\Charts;

/**
 * Gruppierte Säulen: Monatsumsatz laufendes Schuljahr gegen Vorjahr.
 *
 * Liefert fertig positionierte Elemente — die Blade-Vorlage zeichnet nur noch,
 * sie rechnet nichts. (Rechnerei in Blade ist in diesem Projekt schon einmal
 * teuer geworden, siehe CLAUDE.md.)
 */
class ColumnChart
{
    private const WIDTH = 720.0;

    private const PLOT_TOP = 18.0;

    private const PLOT_HEIGHT = 210.0;

    private const MARGIN_LEFT = 52.0;

    private const MARGIN_RIGHT = 10.0;

    /** Achsenband unter der Grundlinie (Monatsnamen). */
    private const AXIS_BAND = 26.0;

    private const MAX_BAR = 24.0;

    private const GAP = 2.0;

    /**
     * @param  list<array{short: string, label: string, current: float, previous: float}>  $rows
     * @return array<string, mixed>
     */
    public function build(array $rows, string $currentLabel, string $previousLabel): array
    {
        $baseline = self::PLOT_TOP + self::PLOT_HEIGHT;
        $height = $baseline + self::AXIS_BAND;
        $plotWidth = self::WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;

        $max = 0.0;
        foreach ($rows as $row) {
            $max = max($max, $row['current'], $row['previous']);
        }
        $scale = Shapes::scale($max);

        $gridlines = [];
        foreach ($scale['ticks'] as $tick) {
            $gridlines[] = [
                'y' => $baseline - ($tick / $scale['max']) * self::PLOT_HEIGHT,
                'label' => Palette::axisNumber($tick),
            ];
        }

        $band = count($rows) > 0 ? $plotWidth / count($rows) : $plotWidth;
        $barWidth = min(self::MAX_BAR, max(4.0, ($band - self::GAP) / 2 - 4));
        $groupWidth = $barWidth * 2 + self::GAP;

        // Direktbeschriftung nur am größten Monat — eine Zahl auf jeder Säule
        // wäre Lärm und wird ohnehin nicht gelesen.
        $peak = null;
        foreach ($rows as $index => $row) {
            if ($peak === null || $row['current'] > $rows[$peak]['current']) {
                $peak = $index;
            }
        }

        $columns = [];
        $ticks = [];
        $labels = [];
        foreach ($rows as $index => $row) {
            $center = self::MARGIN_LEFT + $band * ($index + 0.5);
            $left = $center - $groupWidth / 2;
            $ticks[] = ['x' => $center, 'label' => $row['short']];

            foreach ([
                ['value' => $row['current'], 'color' => Palette::SERIES_1, 'series' => $currentLabel, 'x' => $left],
                ['value' => $row['previous'], 'color' => Palette::SERIES_2, 'series' => $previousLabel, 'x' => $left + $barWidth + self::GAP],
            ] as $bar) {
                $top = $baseline - ($scale['max'] > 0 ? ($bar['value'] / $scale['max']) * self::PLOT_HEIGHT : 0);
                $path = Shapes::column($bar['x'], $barWidth, $top, $baseline);
                if ($path === '') {
                    continue;
                }
                $columns[] = [
                    'path' => $path,
                    'color' => $bar['color'],
                    'title' => $row['label'].' · '.$bar['series'].': '.Palette::euro($bar['value']),
                ];
            }

            if ($index === $peak && $row['current'] > 0) {
                $top = $baseline - ($row['current'] / $scale['max']) * self::PLOT_HEIGHT;
                $labels[] = ['x' => $left + $barWidth / 2, 'y' => $top - 6, 'text' => Palette::euroShort($row['current'])];
            }
        }

        return [
            'width' => self::WIDTH,
            'height' => $height,
            'baseline' => $baseline,
            'plotLeft' => self::MARGIN_LEFT,
            'plotRight' => self::WIDTH - self::MARGIN_RIGHT,
            'gridlines' => $gridlines,
            'columns' => $columns,
            'ticks' => $ticks,
            'labels' => $labels,
            'legend' => [
                ['label' => $currentLabel, 'color' => Palette::SERIES_1],
                ['label' => $previousLabel, 'color' => Palette::SERIES_2],
            ],
            'empty' => $max <= 0,
        ];
    }
}
