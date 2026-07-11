<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Schreibt die drei Excel-Reports strukturell identisch zum Legacy-Output
 * (AGENTIC_INTENT_SPEC.md Kapitel 4.5–4.7):
 * Sheets Übersicht_Tabelle (mit vertikal verbundenen Gruppenzellen),
 * Übersicht_Liste, Orders und Provisions-/Auftragsinformationen.
 */
class ExcelReportWriter
{
    public function write(
        string $path,
        string $reportType,
        TransformResult $result,
        string $orderInformation,
        float|int $commission,
    ): void {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $this->writePivotTable($spreadsheet->createSheet(), $result);
        $this->writePivotList($spreadsheet->createSheet(), $result);
        $this->writeOrders($spreadsheet->createSheet(), $result, $reportType);

        $infoSheet = $spreadsheet->createSheet();
        if ($reportType === 'customer') {
            $infoSheet->setTitle('Provisionsinformationen');
            $infoSheet->getCell('A1')->setValueExplicit($commission, DataType::TYPE_NUMERIC);
        } else {
            $infoSheet->setTitle('Auftragsinformationen');
            if ($orderInformation !== '') {
                $infoSheet->getCell('A1')->setValueExplicit($orderInformation, DataType::TYPE_STRING);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private function writePivotTable(Worksheet $sheet, TransformResult $result): void
    {
        $sheet->setTitle('Übersicht_Tabelle');
        $columns = ['Produktname', 'Farbe', 'Größe', 'Anzahl gesamt', 'Davon Personalisierungen', 'Kartonnummer', 'Ausschuss', 'Anmerkungen'];
        $this->writeHeader($sheet, $columns);

        $rows = $result->pivotRows;
        $n = count($rows);
        foreach ($rows as $i => $row) {
            $excelRow = $i + 2;
            $this->setCell($sheet, 3, $excelRow, $row['Größe']);
            $this->setCell($sheet, 4, $excelRow, $row['Anzahl gesamt']);
            $this->setCell($sheet, 5, $excelRow, $row['Davon Personalisierungen']);
        }

        // Gruppenwerte wie pandas merge_cells=True: Wert nur in der ersten Zeile,
        // vertikale Verbundzelle bei Gruppen > 1 Zeile.
        foreach ($this->groupSpans($rows, ['Produktname']) as [$start, $end]) {
            $this->writeGroupCell($sheet, 1, $start, $end, $rows[$start]['Produktname']);
        }
        foreach ($this->groupSpans($rows, ['Produktname', 'Farbe']) as [$start, $end]) {
            $this->writeGroupCell($sheet, 2, $start, $end, $rows[$start]['Farbe']);
        }

        // Grand-Totals-Zeile
        $totalRow = $n + 2;
        $this->setCell($sheet, 1, $totalRow, 'Grand Totals');
        $this->setCell($sheet, 4, $totalRow, $result->grandTotals['count']);
        $this->setCell($sheet, 5, $totalRow, $result->grandTotals['personalized']);

        $this->setColumnWidths($sheet, count($columns), 22);
    }

    private function writePivotList(Worksheet $sheet, TransformResult $result): void
    {
        $sheet->setTitle('Übersicht_Liste');
        $this->writeHeader($sheet, $result->pivotListColumns);
        foreach ($result->pivotListRows as $i => $row) {
            foreach ($result->pivotListColumns as $c => $name) {
                $this->setCell($sheet, $c + 1, $i + 2, $row[$name]);
            }
        }
        $this->setColumnWidths($sheet, count($result->pivotListColumns), 22);
    }

    private function writeOrders(Worksheet $sheet, TransformResult $result, string $reportType): void
    {
        $sheet->setTitle('Orders');
        $columns = $result->ordersColumns;
        if ($reportType !== 'internal') {
            $columns = array_values(array_diff($columns, [OrderTransformer::WARNING_COLUMN]));
        }
        $this->writeHeader($sheet, $columns);
        foreach ($result->ordersRows as $i => $row) {
            foreach ($columns as $c => $name) {
                $this->setCell($sheet, $c + 1, $i + 2, $row[$name] ?? null);
            }
        }
        $this->setColumnWidths($sheet, count($columns), 20);
    }

    /**
     * Zusammenhängende Bereiche gleicher Schlüsselwerte (hierarchisch), 0-basiert inklusive.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return list<array{0: int, 1: int}>
     */
    private function groupSpans(array $rows, array $keys): array
    {
        $spans = [];
        $start = 0;
        $n = count($rows);
        for ($i = 1; $i <= $n; $i++) {
            $boundary = $i === $n;
            if (! $boundary) {
                foreach ($keys as $key) {
                    if ($rows[$i][$key] !== $rows[$i - 1][$key]) {
                        $boundary = true;
                        break;
                    }
                }
            }
            if ($boundary) {
                $spans[] = [$start, $i - 1];
                $start = $i;
            }
        }

        return $spans;
    }

    private function writeGroupCell(Worksheet $sheet, int $col, int $startIndex, int $endIndex, mixed $value): void
    {
        $excelStart = $startIndex + 2;
        $excelEnd = $endIndex + 2;
        $this->setCell($sheet, $col, $excelStart, $value);
        if ($excelEnd > $excelStart) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->mergeCells("{$letter}{$excelStart}:{$letter}{$excelEnd}");
        }
    }

    /** @param  list<string>  $columns */
    private function writeHeader(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $c => $name) {
            $sheet->getCell([$c + 1, 1])->setValueExplicit($name, DataType::TYPE_STRING);
        }
    }

    /**
     * Leere Werte (null/"") werden wie im Legacy-Output als leere Zellen belassen.
     */
    private function setCell(Worksheet $sheet, int $col, int $row, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $cell = $sheet->getCell([$col, $row]);
        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);
        } else {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
        }
    }

    private function setColumnWidths(Worksheet $sheet, int $columnCount, float $width): void
    {
        for ($c = 1; $c <= $columnCount; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($width);
        }
    }
}
