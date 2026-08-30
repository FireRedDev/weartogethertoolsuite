<?php

namespace App\Services\Statistics\Charts;

/**
 * Farben und Formatierung der Diagramme.
 *
 * Die beiden Serienfarben sind gegen Rot-Grün- und Blau-Gelb-Sehschwäche
 * geprüft (Blau ↔ Orange: ΔE 24,7 protan / 32,7 tritan auf weißem Grund,
 * Zielwert ≥ 8) und liegen beide über 3:1 Kontrast zur Kartenfläche. Wer die
 * Farben ändert, muss diese Prüfung wiederholen — sonst sind die Diagramme für
 * einen Teil der Leser:innen nicht mehr unterscheidbar. Zusätzlich trägt jedes
 * Diagramm eine Legende und eine Tabellenansicht, damit die Farbe nie der
 * einzige Informationsträger ist.
 */
class Palette
{
    /** Laufendes Schuljahr. */
    public const SERIES_1 = '#2a78d6';

    /** Vergleichsjahr. */
    public const SERIES_2 = '#eb6834';

    /** Prognose/Fortschreibung — dieselbe Serie, nur strichliert gezeichnet. */
    public const FORECAST = '#2a78d6';

    /** Zielmarke. */
    public const TARGET = '#1d2733';

    public const SURFACE = '#ffffff';

    public const GRID = '#e2e8f0';

    public const TEXT = '#1d2733';

    public const TEXT_MUTED = '#64748b';

    /** Vollständiger Betrag mit zwei Nachkommastellen. */
    public static function euro(?float $value, bool $withSign = false): string
    {
        if ($value === null) {
            return '—';
        }
        $sign = $withSign && $value > 0 ? '+' : '';

        return $sign.number_format($value, 2, ',', '.').' €';
    }

    /** Kurzform für Achsen und Direktbeschriftungen. */
    public static function euroShort(?float $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (abs($value) >= 10000) {
            return number_format($value / 1000, 1, ',', '.').' Tsd. €';
        }

        return number_format($value, 0, ',', '.').' €';
    }

    /** Achsenbeschriftung ohne Währungszeichen (das steht am Achsentitel). */
    public static function axisNumber(float $value): string
    {
        if (abs($value) >= 10000) {
            return number_format($value / 1000, 0, ',', '.').'k';
        }

        return number_format($value, 0, ',', '.');
    }
}
