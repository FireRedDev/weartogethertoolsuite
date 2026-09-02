<?php

namespace App\Services;

/**
 * Provisionsstaffel — exakt wie provision_ausrechnen() im Legacy-Skript
 * (AGENTIC_INTENT_SPEC.md Kapitel 4.4).
 */
class CommissionCalculator
{
    public function calculate(int $pieces): float|int
    {
        $config = config('ordersuite.commission');
        $commission = 0.0;

        // Je Staffel die Anzahl der hineinfallenden Stück ausrechnen, statt
        // Stück für Stück zu zählen. Bei einer unplausibel großen Menge im
        // Export (Tippfehler, kaputte Datei) lief die alte Schleife sonst
        // praktisch endlos.
        $pieces = max(0, $pieces);
        foreach ($config['tiers'] as $tier) {
            $from = max(0, (int) $tier['from']);
            $to = $tier['to'] === null ? $pieces - 1 : min($pieces - 1, (int) $tier['to']);
            if ($to >= $from) {
                $commission += ($to - $from + 1) * $tier['amount'];
            }
        }
        if ($commission < $config['minimum'] && $pieces >= $config['minimum_from_pieces']) {
            $commission = $config['minimum'];
        }

        return $commission;
    }
}
