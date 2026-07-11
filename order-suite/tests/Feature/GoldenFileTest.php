<?php

namespace Tests\Feature;

use App\Services\OrderReportGenerator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Golden-File-Abnahme (Definition of Done 9.1): Die erzeugten Excel-Reports
 * müssen zell-für-zell den mit dem Legacy-Skript (HEAD cff1227) erzeugten
 * Referenzdateien entsprechen — Werte, Verbundzellen, Spaltenbreiten, Sheets.
 */
class GoldenFileTest extends TestCase
{
    public static function fixtureProvider(): array
    {
        return [
            'AHS Korneuburg Original' => ['orders_ahs_korneuburg.xlsx', 'reference', 'Bitte bis Ende Juni liefern'],
            'Große Bestellung (>50 Stück)' => ['orders_large.xlsx', 'reference_large', 'Info groß'],
            'Edge Cases (unbekannte Größe, fehlende Klasse)' => ['orders_edgecases.xlsx', 'reference_edge', 'Info edge'],
        ];
    }

    #[DataProvider('fixtureProvider')]
    public function test_output_matches_legacy_reference(string $fixture, string $referenceDir, string $info): void
    {
        $goldenDir = base_path('tests/golden');
        $outDir = sys_get_temp_dir().'/golden_'.uniqid();

        $generated = app(OrderReportGenerator::class)->generate(
            $goldenDir.'/fixtures/'.$fixture,
            'AHS_Korneuburg',
            $info,
            $outDir,
        );

        foreach (['supplier', 'internal', 'customer'] as $type) {
            $file = "AHS_Korneuburg_orderreport_{$type}.xlsx";
            $this->assertWorkbooksEqual("{$goldenDir}/{$referenceDir}/{$file}", "{$outDir}/{$file}", $file);
        }
        $this->assertFileExists($outDir.'/'.$generated['files']['pdf']);

        array_map('unlink', glob($outDir.'/*'));
        rmdir($outDir);
    }

    private function assertWorkbooksEqual(string $referencePath, string $actualPath, string $label): void
    {
        $reference = IOFactory::load($referencePath);
        $actual = IOFactory::load($actualPath);

        $this->assertSame($reference->getSheetNames(), $actual->getSheetNames(), "{$label}: Sheet-Namen/-Reihenfolge");

        foreach ($reference->getSheetNames() as $sheetName) {
            $refSheet = $reference->getSheetByName($sheetName);
            $actSheet = $actual->getSheetByName($sheetName);

            $maxRow = max($refSheet->getHighestRow(), $actSheet->getHighestRow());
            $maxCol = max(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($refSheet->getHighestColumn()),
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($actSheet->getHighestColumn()),
            );

            for ($row = 1; $row <= $maxRow; $row++) {
                for ($col = 1; $col <= $maxCol; $col++) {
                    $refValue = $this->normalizedValue($refSheet, $col, $row);
                    $actValue = $this->normalizedValue($actSheet, $col, $row);
                    $this->assertSame(
                        $refValue,
                        $actValue,
                        "{$label} [{$sheetName}] Zeile {$row} Spalte {$col}",
                    );
                }
            }

            $refMerges = array_values($refSheet->getMergeCells());
            $actMerges = array_values($actSheet->getMergeCells());
            sort($refMerges);
            sort($actMerges);
            $this->assertSame($refMerges, $actMerges, "{$label} [{$sheetName}] Verbundzellen");

            $this->assertSame(
                $this->definedWidths($refSheet),
                $this->definedWidths($actSheet),
                "{$label} [{$sheetName}] Spaltenbreiten",
            );
        }
    }

    private function normalizedValue(Worksheet $sheet, int $col, int $row): string|float|null
    {
        if (! $sheet->cellExists([$col, $row])) {
            return null;
        }
        $value = $sheet->getCell([$col, $row])->getValue();
        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_numeric($value) && ! is_string($value)) {
            return (float) $value;
        }

        return (string) $value;
    }

    /** @return array<string, float> */
    private function definedWidths(Worksheet $sheet): array
    {
        $widths = [];
        foreach ($sheet->getColumnDimensions() as $letter => $dimension) {
            if ($dimension->getWidth() > 0) {
                $widths[$letter] = (float) $dimension->getWidth();
            }
        }
        ksort($widths);

        return $widths;
    }
}
