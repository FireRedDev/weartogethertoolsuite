<?php

namespace App\Models;

use App\Services\Statistics\SchoolYear;
use Illuminate\Database\Eloquent\Model;

/**
 * Die Saisonvorgabe eines Schuljahres: Zielumsatz und die Umsätze außerhalb
 * des Webshops.
 *
 * Bewusst gespeichert statt als Filter in der Adresszeile: Ein Ziel ist keine
 * Ansicht, sondern eine Vereinbarung. Einmal eingetragen gilt es für alle im
 * Team und bleibt stehen, bis jemand es ändert.
 */
class SeasonGoal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_revenue' => 'float',
            'manual_revenue' => 'float',
            'manual_forecast' => 'float',
        ];
    }

    /**
     * Die Vorgabe eines Schuljahres — immer ein Objekt, auch wenn noch nichts
     * eingetragen wurde (dann mit leeren Werten und ohne Datensatz).
     */
    public static function forYear(SchoolYear $year): self
    {
        return self::query()->firstOrNew(
            ['school_year' => $year->key()],
            ['manual_revenue' => 0.0, 'manual_forecast' => 0.0],
        );
    }

    /** Ist überhaupt etwas hinterlegt? */
    public function isSet(): bool
    {
        return $this->target_revenue !== null
            || (float) $this->manual_revenue !== 0.0
            || (float) $this->manual_forecast !== 0.0;
    }

    /** Bereits erzielter Umsatz außerhalb des Webshops. */
    public function manualRevenue(): float
    {
        return round((float) $this->manual_revenue, 2);
    }

    /** Zusätzlich erwarteter Umsatz außerhalb des Webshops (nur Hochrechnung). */
    public function manualForecast(): float
    {
        return round((float) $this->manual_forecast, 2);
    }
}
