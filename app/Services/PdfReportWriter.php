<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Verteil-PDF gemäß AGENTIC_INTENT_SPEC.md Kapitel 4.8:
 * Paginierungsformel des Legacy-Skripts, Kopfzeile #b1dce4, ID-Spalte #b1dce4,
 * Zebra-Zeilen (Neustart je Seite), Fußzeile "Seite X von Y".
 *
 * Die Seitengröße wächst wie beim Legacy-Tool (matplotlib bbox_inches="tight")
 * mit dem Tabelleninhalt, damit alle Zellen einzeilig bleiben.
 */
class PdfReportWriter
{
    private const FONT_SIZE_PT = 8.0;

    private const CHAR_WIDTH_FACTOR = 0.58; // DejaVu Sans, inkl. Reserve

    private const CELL_PADDING_PT = 8.0;

    private const ROW_HEIGHT_PT = 17.0;

    private const PAGE_MARGIN_PT = 24.0;

    private const FOOTER_HEIGHT_PT = 26.0;

    public function write(string $path, TransformResult $result): void
    {
        $dropColumns = config('ordersuite.pdf_drop_columns');
        $columns = array_values(array_diff($result->ordersColumns, $dropColumns));

        $n = count($result->ordersRows);
        $basis = (int) config('ordersuite.pdf_rows_basis');
        $pageCount = max(1, (int) ceil($n / $basis));
        $rowsPerPage = $n > 0 ? intdiv($n, $pageCount) + 1 : 0;

        $pages = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $pages[] = array_slice($result->ordersRows, $i * $rowsPerPage, $rowsPerPage);
        }

        [$paperWidth, $paperHeight] = $this->paperSize($columns, $result->ordersRows, $rowsPerPage);

        $pdf = Pdf::loadView('pdf.orderreport', [
            'columns' => $columns,
            'pages' => $pages,
            'pageCount' => $pageCount,
        ])->setPaper([0, 0, $paperWidth, $paperHeight]);

        $pdf->save($path);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: float, 1: float}
     */
    private function paperSize(array $columns, array $rows, int $rowsPerPage): array
    {
        $width = 2 * self::PAGE_MARGIN_PT;
        foreach ($columns as $column) {
            $maxChars = mb_strlen($column);
            foreach ($rows as $row) {
                $maxChars = max($maxChars, mb_strlen((string) ($row[$column] ?? '')));
            }
            $width += $maxChars * self::CHAR_WIDTH_FACTOR * self::FONT_SIZE_PT + self::CELL_PADDING_PT;
        }

        $height = ($rowsPerPage + 1) * self::ROW_HEIGHT_PT
            + self::FOOTER_HEIGHT_PT
            + 2 * self::PAGE_MARGIN_PT;

        return [max(400.0, $width), max(200.0, $height)];
    }
}
