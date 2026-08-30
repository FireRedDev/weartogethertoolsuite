<?php

namespace App\Services\Statistics;

use Illuminate\Support\Carbon;

/**
 * Ein österreichisches Schuljahr als Zeitraum.
 *
 * Beginn ist ein fester Stichtag im September (`config('statistics.school_year')`),
 * Ende der Tag davor im Folgejahr. Die Sommerferien fallen damit ans
 * ablaufende Schuljahr — bewusst so, weil Nachzügler- und Ferienbestellungen
 * zu dem Bestellfenster gehören, das im Juni endete, und nicht zum nächsten.
 *
 * Der Wert `$startYear` ist das Kalenderjahr des Schuljahresbeginns:
 * `new SchoolYear(2025)` = 01.09.2025 bis 31.08.2026, Bezeichnung „2025/26".
 */
class SchoolYear
{
    public function __construct(public readonly int $startYear) {}

    /** Das Schuljahr, in dem ein Datum liegt. */
    public static function forDate(\DateTimeInterface $date): self
    {
        $carbon = Carbon::instance(\DateTimeImmutable::createFromInterface($date));
        $boundary = Carbon::create(
            $carbon->year,
            (int) config('statistics.school_year.start_month'),
            (int) config('statistics.school_year.start_day'),
        )->startOfDay();

        return new self($carbon->lt($boundary) ? $carbon->year - 1 : $carbon->year);
    }

    public static function current(): self
    {
        return self::forDate(Carbon::today());
    }

    /** Schuljahr aus der Bezeichnung („2025/26") oder dem Startjahr („2025"). */
    public static function parse(?string $value): ?self
    {
        if ($value === null || ! preg_match('/^(\d{4})/', trim($value), $match)) {
            return null;
        }

        return new self((int) $match[1]);
    }

    public function previous(): self
    {
        return new self($this->startYear - 1);
    }

    public function next(): self
    {
        return new self($this->startYear + 1);
    }

    public function start(): Carbon
    {
        return Carbon::create(
            $this->startYear,
            (int) config('statistics.school_year.start_month'),
            (int) config('statistics.school_year.start_day'),
        )->startOfDay();
    }

    /** Letzter Tag (23:59:59) — der Tag vor dem Beginn des nächsten Schuljahres. */
    public function end(): Carbon
    {
        return $this->next()->start()->subDay()->endOfDay();
    }

    public function label(): string
    {
        return $this->startYear.'/'.substr((string) ($this->startYear + 1), -2);
    }

    /** Für Formulare und Cache-Schlüssel. */
    public function key(): string
    {
        return (string) $this->startYear;
    }

    public function contains(\DateTimeInterface $date): bool
    {
        $carbon = Carbon::instance(\DateTimeImmutable::createFromInterface($date));

        return $carbon->betweenIncluded($this->start(), $this->end());
    }

    /** Ist das Schuljahr bereits vorbei? Dann ändern sich seine Zahlen nicht mehr. */
    public function isComplete(): bool
    {
        return $this->end()->isPast();
    }

    public function isCurrent(): bool
    {
        return $this->startYear === self::current()->startYear;
    }

    /**
     * Die zwölf Monate des Schuljahres, September zuerst.
     *
     * @return list<array{start: Carbon, end: Carbon, label: string, short: string}>
     */
    public function months(): array
    {
        $months = [];
        $cursor = $this->start()->copy()->startOfMonth();
        $last = $this->end()->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $months[] = [
                'start' => $cursor->copy(),
                'end' => $cursor->copy()->endOfMonth(),
                'label' => self::MONTH_NAMES[$cursor->month].' '.$cursor->year,
                'short' => self::MONTH_SHORT[$cursor->month],
            ];
            $cursor = $cursor->copy()->addMonth();
        }

        return $months;
    }

    /**
     * Wie weit ist das Schuljahr heute (nach Tagen) durch? 0.0 vor dem Beginn,
     * 1.0 nach dem Ende. Reiner Kalenderwert — die Prognose rechnet stattdessen
     * mit dem saisonalen Verlauf der Vorjahre.
     */
    public function elapsedShare(?Carbon $today = null): float
    {
        $today = $today ?? Carbon::today();
        $start = $this->start();
        $end = $this->end();

        if ($today->lt($start)) {
            return 0.0;
        }
        if ($today->gt($end)) {
            return 1.0;
        }

        $total = max(1, $start->diffInDays($end) + 1);

        return min(1.0, ($start->diffInDays($today) + 1) / $total);
    }

    /**
     * Die letzten `history_years` Schuljahre, das laufende zuerst.
     *
     * @return list<self>
     */
    public static function recent(): array
    {
        $current = self::current();
        $years = [];
        for ($i = 0; $i < (int) config('statistics.school_year.history_years'); $i++) {
            $years[] = new self($current->startYear - $i);
        }

        return $years;
    }

    private const MONTH_NAMES = [
        1 => 'Jänner', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    private const MONTH_SHORT = [
        1 => 'Jän', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez',
    ];
}
