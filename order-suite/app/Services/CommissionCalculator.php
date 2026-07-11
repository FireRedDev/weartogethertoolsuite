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
        for ($i = 0; $i < $pieces; $i++) {
            foreach ($config['tiers'] as $tier) {
                if ($i >= $tier['from'] && ($tier['to'] === null || $i <= $tier['to'])) {
                    $commission += $tier['amount'];
                    break;
                }
            }
        }
        if ($commission < $config['minimum'] && $pieces >= $config['minimum_from_pieces']) {
            $commission = $config['minimum'];
        }

        return $commission;
    }
}
