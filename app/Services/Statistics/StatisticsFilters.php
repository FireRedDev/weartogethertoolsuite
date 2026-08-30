<?php

namespace App\Services\Statistics;

use Illuminate\Http\Request;

/**
 * Die Einstellungen der Statistik-Seite, aus der Adresszeile gelesen und auf
 * gültige Werte gebracht. Die Seite ist damit als Lesezeichen speicherbar und
 * teilbar.
 */
class StatisticsFilters
{
    public const DELIVERY_TYPES = [
        'all' => 'Alle',
        'collective' => 'Sammelbestellfenster',
        'ondemand' => 'On-Demand (Printify)',
    ];

    /** @param list<string> $statuses */
    public function __construct(
        public readonly SchoolYear $year,
        public readonly string $deliveryType,
        public readonly ?int $schoolId,
        public readonly int $paddingBefore,
        public readonly int $paddingAfter,
        public readonly array $statuses,
        public readonly ?float $target,
        public readonly bool $fresh,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $max = (int) config('statistics.window_padding.max');

        $deliveryType = (string) $request->query('lieferart', 'all');
        if (! array_key_exists($deliveryType, self::DELIVERY_TYPES)) {
            $deliveryType = 'all';
        }

        $statuses = $request->query('status');
        $allowed = array_keys(config('ordersuite.woocommerce.statuses'));
        $statuses = is_array($statuses)
            ? array_values(array_intersect(array_map('strval', $statuses), $allowed))
            : [];
        if ($statuses === []) {
            $statuses = config('ordersuite.woocommerce.default_statuses');
        }

        $school = $request->query('schule');
        $target = $request->query('ziel');

        return new self(
            year: SchoolYear::parse($request->query('schuljahr')) ?? SchoolYear::current(),
            deliveryType: $deliveryType,
            schoolId: is_numeric($school) && (int) $school > 0 ? (int) $school : null,
            paddingBefore: self::clamp($request->query('vorlauf'), (int) config('statistics.window_padding.before'), $max),
            paddingAfter: self::clamp($request->query('nachlauf'), (int) config('statistics.window_padding.after'), $max),
            statuses: $statuses,
            target: is_numeric($target) && (float) $target >= 0 ? round((float) $target, 2) : null,
            fresh: $request->boolean('neu'),
        );
    }

    /** Wie viele Tage der Abruf über den Schuljahresrand hinausgreifen muss. */
    public function fetchPadding(): int
    {
        return max($this->paddingBefore, $this->paddingAfter);
    }

    public function deliveryTypeLabel(): string
    {
        return self::DELIVERY_TYPES[$this->deliveryType];
    }

    /** Ist überhaupt eine Einschränkung aktiv (für „Filter zurücksetzen")? */
    public function isFiltered(): bool
    {
        return $this->deliveryType !== 'all'
            || $this->schoolId !== null
            || $this->paddingBefore !== (int) config('statistics.window_padding.before')
            || $this->paddingAfter !== (int) config('statistics.window_padding.after')
            || $this->statuses !== config('ordersuite.woocommerce.default_statuses');
    }

    /**
     * Die Filter als Adressparameter — für Links, die nur einen Wert ändern.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function query(array $overrides = []): array
    {
        return array_filter([
            'schuljahr' => $this->year->key(),
            'lieferart' => $this->deliveryType,
            'schule' => $this->schoolId,
            'vorlauf' => $this->paddingBefore,
            'nachlauf' => $this->paddingAfter,
            'status' => $this->statuses,
            'ziel' => $this->target,
        ] + $overrides, static fn ($value) => $value !== null);
    }

    private static function clamp(mixed $value, int $default, int $max): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(0, min($max, (int) $value));
    }
}
