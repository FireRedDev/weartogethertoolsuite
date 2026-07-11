<?php

namespace Tests\Unit;

use App\Services\CommissionCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommissionCalculatorTest extends TestCase
{
    /**
     * Stützwerte aus AGENTIC_INTENT_SPEC.md Kapitel 4.4 (gegen Legacy verifiziert).
     */
    public static function commissionProvider(): array
    {
        return [
            [0, 0.0],
            [32, 0.0],
            [49, 0.0],
            [50, 20.0],
            [120, 45.0],
            [250, 187.5],
            [600, 750.0],
        ];
    }

    #[DataProvider('commissionProvider')]
    public function test_commission_matches_legacy(int $pieces, float $expected): void
    {
        $this->assertSame($expected, (float) (new CommissionCalculator)->calculate($pieces));
    }
}
