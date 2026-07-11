<?php

namespace App\Services;

class TransformResult
{
    /**
     * @param  list<string>  $ordersColumns
     * @param  list<array<string, mixed>>  $ordersRows
     * @param  list<array<string, mixed>>  $pivotRows
     * @param  array{count: int, personalized: int}  $grandTotals
     * @param  list<string>  $pivotListColumns
     * @param  list<array<string, mixed>>  $pivotListRows
     */
    public function __construct(
        public readonly array $ordersColumns,
        public readonly array $ordersRows,
        public readonly array $pivotRows,
        public readonly array $grandTotals,
        public readonly array $pivotListColumns,
        public readonly array $pivotListRows,
    ) {}

    public function pieceCount(): int
    {
        return count($this->ordersRows);
    }

    public function kartonCount(): int
    {
        return $this->ordersRows === [] ? 0 : max(array_column($this->ordersRows, 'Karton'));
    }

    public function personalizedCount(): int
    {
        return count(array_filter(
            $this->ordersRows,
            fn (array $row): bool => ($row['Individualisierung'] ?? null) === 'Ja',
        ));
    }
}
