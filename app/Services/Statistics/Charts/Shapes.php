<?php

namespace App\Services\Statistics\Charts;

/**
 * Geometrie der Diagrammformen. Balken bekommen ein abgerundetes Datenende und
 * bleiben an der Grundlinie eckig — so ist auf einen Blick klar, wo die Skala
 * beginnt. Trennung zwischen benachbarten Balken macht ein Abstand in der
 * Flächenfarbe, nie eine Kontur (Kontur wäre Farbgewicht ohne Datenwert).
 */
class Shapes
{
    /** Radius des Datenendes. */
    private const RADIUS = 4.0;

    /** Säule (wächst nach oben von $baseline auf $top). */
    public static function column(float $x, float $width, float $top, float $baseline): string
    {
        $height = $baseline - $top;
        if ($height <= 0.5) {
            return '';
        }
        $r = min(self::RADIUS, $width / 2, $height);

        return sprintf(
            'M %s %s L %s %s Q %s %s %s %s L %s %s Q %s %s %s %s L %s %s Z',
            self::n($x), self::n($baseline),
            self::n($x), self::n($top + $r),
            self::n($x), self::n($top), self::n($x + $r), self::n($top),
            self::n($x + $width - $r), self::n($top),
            self::n($x + $width), self::n($top), self::n($x + $width), self::n($top + $r),
            self::n($x + $width), self::n($baseline),
        );
    }

    /** Waagrechter Balken (wächst nach rechts von $x0 auf $x1). */
    public static function bar(float $x0, float $x1, float $y, float $height): string
    {
        $width = $x1 - $x0;
        if ($width <= 0.5) {
            return '';
        }
        $r = min(self::RADIUS, $height / 2, $width);

        return sprintf(
            'M %s %s L %s %s Q %s %s %s %s L %s %s Q %s %s %s %s L %s %s Z',
            self::n($x0), self::n($y),
            self::n($x1 - $r), self::n($y),
            self::n($x1), self::n($y), self::n($x1), self::n($y + $r),
            self::n($x1), self::n($y + $height - $r),
            self::n($x1), self::n($y + $height), self::n($x1 - $r), self::n($y + $height),
            self::n($x0), self::n($y + $height),
        );
    }

    /**
     * Linienzug durch die übergebenen Punkte; `null`-Werte unterbrechen sie.
     *
     * @param  list<array{x: float, y: ?float}>  $points
     */
    public static function line(array $points): string
    {
        $path = '';
        $pen = false;
        foreach ($points as $point) {
            if ($point['y'] === null) {
                $pen = false;

                continue;
            }
            $path .= sprintf('%s %s %s ', $pen ? 'L' : 'M', self::n($point['x']), self::n($point['y']));
            $pen = true;
        }

        return trim($path);
    }

    /**
     * „Schöne" Achsenschritte: 1, 2, 2.5 oder 5 mal eine Zehnerpotenz, damit
     * die Beschriftung runde Zahlen trägt.
     *
     * @return array{max: float, step: float, ticks: list<float>}
     */
    public static function scale(float $max, int $targetTicks = 4): array
    {
        if ($max <= 0) {
            return ['max' => 1.0, 'step' => 1.0, 'ticks' => [0.0, 1.0]];
        }

        $rough = $max / max(1, $targetTicks);
        $magnitude = 10 ** floor(log10($rough));
        $step = $magnitude;
        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            if ($magnitude * $factor >= $rough) {
                $step = $magnitude * $factor;
                break;
            }
        }

        $top = ceil($max / $step) * $step;
        $ticks = [];
        for ($value = 0.0; $value <= $top + $step / 2; $value += $step) {
            $ticks[] = round($value, 6);
        }

        return ['max' => $top, 'step' => $step, 'ticks' => $ticks];
    }

    /** Zahl fürs SVG: höchstens zwei Nachkommastellen, immer mit Punkt. */
    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
