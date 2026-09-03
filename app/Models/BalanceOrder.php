<?php

namespace App\Models;

use App\Services\Statistics\SchoolYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein Auftrag der Auftragsbilanz — eine Zeile, wie sie bisher in der Excel
 * stand.
 *
 * Die Rechnung ist absichtlich hier und nicht in der Datenbank abgelegt: In
 * der Excel waren „Einnahmen Ges.", „o. Mwst.", „Gewinn/Verlust" und „%-Gewinn"
 * Formeln über den vier eingetragenen Werten (Online, Bar, Provision,
 * Ausgaben). Wer sie speichern würde, bekäme Zeilen, deren Summe nicht zu ihren
 * Teilen passt, sobald jemand einen Teil ändert.
 */
class BalanceOrder extends Model
{
    protected $guarded = [];

    public const ONLINE_SOURCES = [
        'shop' => 'Aus dem Webshop',
        'manual' => 'Händisch eingetragen',
    ];

    public const DELIVERY_TYPES = [
        'collective' => 'Sammelbestellfenster',
        'ondemand' => 'On-Demand-Shop',
    ];

    protected function casts(): array
    {
        return [
            'ordered_on' => 'date',
            'date_is_estimate' => 'boolean',
            'school_year' => 'integer',
            'products' => 'array',
            'individual' => 'integer',
            'revenue_online' => 'float',
            'revenue_cash' => 'float',
            'revenue_online_excel' => 'float',
            'commission' => 'float',
            'expenses' => 'float',
            'vat' => 'float',
        ];
    }

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(SchoolOnboarding::class, 'school_onboarding_id');
    }

    // ---------------------------------------------------------------- Rechnung

    /** Einnahmen gesamt, brutto — „Einnahmen Ges." der Excel. */
    public function revenueTotal(): float
    {
        return round($this->revenue_online + $this->revenue_cash, 2);
    }

    /** Einnahmen ohne Umsatzsteuer — „Einnahmen o. Mwst." der Excel. */
    public function revenueNet(): float
    {
        return round($this->revenueTotal() - $this->vat, 2);
    }

    /**
     * Gewinn/Verlust: Einnahmen minus Umsatzsteuer, Provision und Ausgaben.
     *
     * Die Umsatzsteuer gehört abgezogen, weil sie durchläuft und nie dem
     * Unternehmen gehört. In den Altdaten steht sie je Auftrag — vor der
     * GmbH-Gründung war sie 0,00 €, und genau dadurch stimmen die alten Zeilen
     * weiter mit der Excel überein.
     */
    public function profit(): float
    {
        return round($this->revenueTotal() - $this->vat - $this->commission - $this->expenses, 2);
    }

    /** Gewinnanteil am Bruttoumsatz — „%-Gewinn" der Excel. Ohne Umsatz: null. */
    public function marginShare(): ?float
    {
        $total = $this->revenueTotal();

        return $total > 0 ? round($this->profit() / $total, 4) : null;
    }

    /** Umsatzsteuer aus einem Bruttobetrag — brutto × 20/120. */
    public static function vatFromGross(float $gross): float
    {
        $rate = (float) config('auftragsbilanz.vat_rate');

        return round($gross * $rate / (1 + $rate), 2);
    }

    /** Verkaufte Kleidungsstücke — „Produkte" der Excel, ohne Individualisierungen. */
    public function productCount(): int
    {
        return array_sum(array_map('intval', $this->products ?? []));
    }

    /** Stückzahl einer Produktart. */
    public function productQuantity(string $type): int
    {
        return (int) ($this->products[$type] ?? 0);
    }

    // ------------------------------------------------------------- Zuordnungen

    public function schoolYear(): SchoolYear
    {
        return new SchoolYear($this->school_year);
    }

    /**
     * Werden die Online-Einnahmen dieses Auftrags aus dem Webshop gefüllt?
     *
     * Das entscheidet, ob die Statistik sie mitzählt: Was schon als
     * Shop-Bestellung in der Auswertung steckt, darf hier nicht ein zweites Mal
     * dazukommen.
     */
    public function onlineFromShop(): bool
    {
        return $this->online_source === 'shop';
    }

    /**
     * Umsätze, die es im Webshop NICHT gibt — Bargeld immer, Online-Einnahmen
     * nur dann, wenn sie von Hand gepflegt werden. Das ist der Betrag, den die
     * Statistik zusätzlich zur Shop-Welt zeigt.
     */
    public function revenueOutsideShop(): float
    {
        return round($this->revenue_cash + ($this->onlineFromShop() ? 0.0 : $this->revenue_online), 2);
    }

    public function isLinked(): bool
    {
        return $this->school_onboarding_id !== null || $this->woo_category_id !== null;
    }

    public function deliveryTypeLabel(): ?string
    {
        return self::DELIVERY_TYPES[$this->delivery_type] ?? null;
    }

    /** Bezeichnung wie in der Excel: „348 - HLW Freistadt". */
    public function label(): string
    {
        return trim(($this->number !== null && $this->number !== '' ? $this->number.' - ' : '').$this->school_name);
    }

    // ----------------------------------------------------------------- Anfragen

    public function scopeOfYear(Builder $query, SchoolYear $year): Builder
    {
        return $query->where('school_year', $year->startYear);
    }

    /** Nächste freie Auftragsnummer — die Excel zählt einfach hoch. */
    public static function nextNumber(): string
    {
        $highest = self::query()
            ->selectRaw('MAX(CAST(number AS INTEGER)) as n')
            ->value('n');

        return str_pad((string) ((int) $highest + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Das Datum, mit dem ein neuer Auftrag vorbefüllt wird: das Ende des
     * Bestellfensters, weil dann der Auftrag zusammengestellt und bestellt
     * wird. On-Demand-Anträge tragen ein Scheinfenster bis 2099 — dort ist das
     * heutige Datum die ehrlichere Antwort.
     */
    public static function defaultDate(?SchoolOnboarding $onboarding = null): Carbon
    {
        $end = $onboarding?->window_end;

        if ($end === null || $onboarding?->delivery_type !== 'collective') {
            return Carbon::today();
        }

        return Carbon::parse($end)->startOfDay();
    }
}
