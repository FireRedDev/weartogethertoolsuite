<?php

namespace Tests\Unit;

use App\Services\OrderTransformer;
use Tests\TestCase;

class OrderTransformerTest extends TestCase
{
    private function makeRow(array $overrides = []): array
    {
        return array_merge([
            'Item Name(löschen)' => 'Schulshirt Deluxe',
            'Vorname' => 'Max',
            'Nachnahme (Rechnungsadresse)' => 'Muster',
            'Anzahl' => 1,
            'Größe' => 'M',
            'Farbe' => 'Blau',
            'Individualisierung' => 'Nein',
            'Input Fields' => null,
            'Product Variation' => 'Größe: M | Farbe: Blau | Klasse: 1a | Individualisierung: Nein',
            'Bestellnotiz' => null,
            'Bestellung Gesamtsumme(löschen)' => 19.99,
        ], $overrides);
    }

    private function columns(): array
    {
        return array_keys($this->makeRow());
    }

    private function transform(array $rows): \App\Services\TransformResult
    {
        return (new OrderTransformer)->transform(['columns' => $this->columns(), 'rows' => $rows]);
    }

    public function test_expansion_creates_one_row_per_piece(): void
    {
        $result = $this->transform([$this->makeRow(['Anzahl' => 3])]);
        $this->assertCount(3, $result->ordersRows);
        $this->assertSame([1, 2, 3], array_column($result->ordersRows, 'ID'));
        $this->assertSame([1, 1, 1], array_column($result->ordersRows, 'Anzahl'));
    }

    public function test_karton_changes_at_piece_21_and_41(): void
    {
        $result = $this->transform([$this->makeRow(['Anzahl' => 45])]);
        $kartons = array_column($result->ordersRows, 'Karton');
        $this->assertSame(1, $kartons[19]);
        $this->assertSame(2, $kartons[20]);
        $this->assertSame(2, $kartons[39]);
        $this->assertSame(3, $kartons[40]);
        $this->assertSame(3, $result->kartonCount());
    }

    public function test_klasse_extracted_without_trim_like_legacy(): void
    {
        $result = $this->transform([$this->makeRow()]);
        $this->assertSame('  1a ', $result->ordersRows[0]['Klasse']);
    }

    public function test_indiv_text_cut_after_50_chars_and_warning_flag(): void
    {
        $prefix = "\nIndividualisierungstext \n(falls \"Ja\" ausgewählt): ";
        $rows = [
            $this->makeRow(['Individualisierung' => 'Ja', 'Input Fields' => $prefix.'Anna']),
            $this->makeRow(['Individualisierung' => 'Ja', 'Input Fields' => null, 'Farbe' => 'Rot']),
            $this->makeRow(['Individualisierung' => 'Nein', 'Input Fields' => $prefix.'Ignoriert', 'Farbe' => 'Grün']),
        ];
        $result = $this->transform($rows);
        $byColor = [];
        foreach ($result->ordersRows as $row) {
            $byColor[$row['Farbe']] = $row;
        }
        $this->assertSame('Anna', $byColor['Blau'][OrderTransformer::INDIV_TEXT_COLUMN]);
        $this->assertSame('', $byColor['Blau'][OrderTransformer::WARNING_COLUMN]);
        $this->assertSame('', $byColor['Rot'][OrderTransformer::INDIV_TEXT_COLUMN]);
        $this->assertSame('TRUE', $byColor['Rot'][OrderTransformer::WARNING_COLUMN]);
        $this->assertSame('', $byColor['Grün'][OrderTransformer::INDIV_TEXT_COLUMN]);
        $this->assertSame('', $byColor['Grün'][OrderTransformer::WARNING_COLUMN]);
    }

    public function test_unknown_size_excluded_from_pivot_including_grand_totals(): void
    {
        $rows = [
            $this->makeRow(),
            $this->makeRow(['Größe' => '134/140', 'Farbe' => 'Rot']),
        ];
        $result = $this->transform($rows);
        $this->assertCount(2, $result->ordersRows);
        $this->assertCount(1, $result->pivotRows);
        $this->assertSame(1, $result->grandTotals['count']);
        // Unbekannte Größe sortiert ans Ende (null wie NaN)
        $this->assertNull($result->ordersRows[1]['Größe']);
    }

    public function test_supplier_map_replaces_substring(): void
    {
        $result = $this->transform([$this->makeRow()]);
        $pivotListRows = $result->pivotListRows;
        $lastRowIsGrandTotals = end($pivotListRows);
        $this->assertSame('Grand Totals', $lastRowIsGrandTotals['Produktname']);
        $this->assertSame('B&C #E150 Deluxe', $result->pivotListRows[0]['Produktname-Lieferant']);
        $this->assertSame('Schulshirt Deluxe', $result->pivotListRows[0]['Produktname']);
    }

    public function test_size_sort_order_is_categorical(): void
    {
        $rows = [
            $this->makeRow(['Größe' => 'XXL']),
            $this->makeRow(['Größe' => 'XS']),
            $this->makeRow(['Größe' => 'L']),
        ];
        $result = $this->transform($rows);
        $this->assertSame(['XS', 'L', 'XXL'], array_column($result->ordersRows, 'Größe'));
    }
}
