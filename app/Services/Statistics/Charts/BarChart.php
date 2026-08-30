<?php

namespace App\Services\Statistics\Charts;

/**
 * Waagrechte Balken für die Ranglisten (Produkte, Farben) — waagrecht, weil
 * die Bezeichnungen lang sind und senkrecht gekippte Achsenbeschriftung
 * schlecht lesbar wäre.
 *
 * Zwei Serien: laufendes Schuljahr und Vorjahr. Beschriftet wird der Wert am
 * Balkenende des laufenden Jahres — passt er nicht in den Balken, steht er
 * dahinter.
 */
class BarChart
{
    private const WIDTH = 720.0;

    private const MARGIN_LEFT = 168.0;

    private const MARGIN_RIGHT = 76.0;

    private const TOP = 14.0;

    private const ROW_GAP = 14.0;

    private const BAR = 13.0;

    private const GAP = 2.0;

    /**
     * @param  list<array{name: string, value: float, previous: float, note: ?string, swatch: ?string}>  $rows
     * @return array<string, mixed>
     */
    public function build(array $rows, string $currentLabel, string $previousLabel, string $unit = '€'): array
    {
        $rowHeight = self::BAR * 2 + self::GAP + self::ROW_GAP;
        $height = self::TOP + max(1, count($rows)) * $rowHeight + 6;
        $plotWidth = self::WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;

        $max = 0.0;
        foreach ($rows as $row) {
            $max = max($max, $row['value'], $row['previous']);
        }

        $bars = [];
        $labels = [];
        $axis = [];
        foreach ($rows as $index => $row) {
            $top = self::TOP + $index * $rowHeight;
            $axis[] = [
                'y' => $top + self::BAR + self::GAP / 2,
                'name' => $row['name'],
                'note' => $row['note'],
                'swatch' => $row['swatch'],
            ];

            foreach ([
                ['value' => $row['value'], 'color' => Palette::SERIES_1, 'series' => $currentLabel, 'y' => $top],
                ['value' => $row['previous'], 'color' => Palette::SERIES_2, 'series' => $previousLabel, 'y' => $top + self::BAR + self::GAP],
            ] as $bar) {
                $x1 = self::MARGIN_LEFT + ($max > 0 ? ($bar['value'] / $max) * $plotWidth : 0);
                $path = Shapes::bar(self::MARGIN_LEFT, $x1, $bar['y'], self::BAR);
                if ($path === '') {
                    continue;
                }
                $bars[] = [
                    'path' => $path,
                    'color' => $bar['color'],
                    'title' => $row['name'].' · '.$bar['series'].': '.$this->format($bar['value'], $unit),
                ];
            }

            // Wert am Ende des Balkens für das laufende Jahr — nur dort, sonst
            // stünde neben jedem Balken eine Zahl.
            $x1 = self::MARGIN_LEFT + ($max > 0 ? ($row['value'] / $max) * $plotWidth : 0);
            $labels[] = [
                'x' => $x1 + 6,
                'y' => $top + self::BAR - 2,
                'text' => $this->format($row['value'], $unit),
            ];
        }

        return [
            'width' => self::WIDTH,
            'height' => $height,
            'plotLeft' => self::MARGIN_LEFT,
            'bars' => $bars,
            'labels' => $labels,
            'axis' => $axis,
            'legend' => [
                ['label' => $currentLabel, 'color' => Palette::SERIES_1],
                ['label' => $previousLabel, 'color' => Palette::SERIES_2],
            ],
            'empty' => $max <= 0,
        ];
    }

    private function format(float $value, string $unit): string
    {
        return $unit === '€'
            ? Palette::euroShort($value)
            : number_format($value, 0, ',', '.').' '.$unit;
    }
}
