<?php

namespace App\Services\Statistics;

use Illuminate\Http\Request;

/**
 * Die Filter der Statistik-Seite, aus der Adresszeile gelesen und auf gültige
 * Werte gebracht. Die Seite ist damit als Lesezeichen speicherbar und teilbar.
 *
 * Der Zielumsatz gehört bewusst NICHT hierher: Er ist kein Blickwinkel auf die
 * Daten, sondern eine Vereinbarung im Team — gespeichert in `SeasonGoal` und
 * für alle gleich.
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
        public readonly bool $fresh,
        /*
         * Die beiden Quellen der Auswertung, umschaltbar über die Schalter
         * oben auf der Seite:
         *
         *  - `sourceShop`  — was der Webshop meldet (Bestellungen aus WooCommerce)
         *  - `sourceOther` — alles andere aus der Auftragsbilanz: Bargeld,
         *    Direktverkäufe und die Online-Einnahmen der Jahre vor dem eigenen
         *    Shop. Doppelt gezählt wird nichts: Was als Shop-Bestellung in der
         *    Auswertung steckt, ist in der Auftragsbilanz als „aus dem Webshop"
         *    gekennzeichnet und bleibt hier außen vor.
         */
        public readonly bool $sourceShop = true,
        public readonly bool $sourceOther = true,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $max = (int) config('statistics.window_padding.max');

        // Mindestens eine Quelle muss an sein — eine Auswertung ohne Datenquelle
        // wäre eine leere Seite ohne Aussage. Die Schalter auf der Seite
        // verhindern das bereits; das hier ist die Absicherung für von Hand
        // zusammengebaute Adressen.
        $shop = self::flag($request->query('shop'));
        $other = self::flag($request->query('sonstige'));
        if (! $shop && ! $other) {
            $shop = true;
            $other = true;
        }

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

        return new self(
            year: SchoolYear::parse($request->query('schuljahr')) ?? SchoolYear::current(),
            deliveryType: $deliveryType,
            schoolId: is_numeric($school) && (int) $school > 0 ? (int) $school : null,
            paddingBefore: self::clamp($request->query('vorlauf'), (int) config('statistics.window_padding.before'), $max),
            paddingAfter: self::clamp($request->query('nachlauf'), (int) config('statistics.window_padding.after'), $max),
            statuses: $statuses,
            fresh: $request->boolean('neu'),
            sourceShop: $shop,
            sourceOther: $other,
        );
    }

    /** Ein Schalter ist an, solange nicht ausdrücklich „0" in der Adresse steht. */
    private static function flag(mixed $value): bool
    {
        return ! in_array((string) $value, ['0', 'false', 'aus'], true);
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
            || ! $this->sourceShop
            || ! $this->sourceOther
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
            // Nur die AUSgeschalteten Quellen landen in der Adresse — ein Link
            // ohne diese Parameter zeigt damit immer alles.
            'shop' => $this->sourceShop ? null : '0',
            'sonstige' => $this->sourceOther ? null : '0',
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
