<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Liest den Shop-Export (erstes Sheet, Kopfzeile in Zeile 1) in eine
 * Tabellenstruktur ['columns' => string[], 'rows' => array<int, array<string, mixed>>].
 *
 * Leere Zellen werden zu null (Äquivalent zu pandas NaN).
 */
class ShopExportReader
{
    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public function read(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheet(0);

        $highestRow = $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();
        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

        $columns = [];
        for ($c = 1; $c <= $highestColIndex; $c++) {
            $value = $sheet->getCell([$c, 1])->getValue();
            if ($value === null || $value === '') {
                continue; // Spalten ohne Header ignorieren
            }
            $columns[$c] = (string) $value;
        }

        $rows = [];
        for ($r = 2; $r <= $highestRow; $r++) {
            $row = [];
            $hasValue = false;
            foreach ($columns as $c => $name) {
                $cell = $sheet->getCell([$c, $r]);
                $value = $this->normalize($cell);
                if ($value !== null) {
                    $hasValue = true;
                }
                $row[$name] = $value;
            }
            if ($hasValue) {
                $rows[] = $row;
            }
        }

        $spreadsheet->disconnectWorksheets();

        return ['columns' => array_values($columns), 'rows' => $rows];
    }

    private function normalize(Cell $cell): mixed
    {
        $value = $cell->getValue();
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            return $value->getPlainText();
        }
        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            // pandas liest ganzzahlige Werte als int64
            return (int) $value;
        }

        return $value;
    }
}
