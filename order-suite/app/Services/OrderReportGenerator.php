<?php

namespace App\Services;

/**
 * Orchestriert die Dokumenterzeugung: Transformation, Provision,
 * drei Excel-Reports und das Verteil-PDF (Dateinamen wie im Legacy-Tool).
 */
class OrderReportGenerator
{
    public function __construct(
        private readonly ShopExportReader $reader,
        private readonly OrderTransformer $transformer,
        private readonly CommissionCalculator $commission,
        private readonly ExcelReportWriter $excelWriter,
        private readonly PdfReportWriter $pdfWriter,
    ) {}

    /**
     * @return array{result: TransformResult, commission: float|int, files: array<string, string>}
     */
    public function generate(string $inputPath, string $orderName, string $orderInformation, string $outputDir): array
    {
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $table = $this->reader->read($inputPath);
        $result = $this->transformer->transform($table);
        $commission = $this->commission->calculate($result->pieceCount());

        $files = [];
        foreach (['supplier', 'internal', 'customer'] as $type) {
            $filename = "{$orderName}_orderreport_{$type}.xlsx";
            $this->excelWriter->write($outputDir.'/'.$filename, $type, $result, $orderInformation, $commission);
            $files[$type] = $filename;
        }

        $pdfName = "{$orderName}_orderreport.pdf";
        $this->pdfWriter->write($outputDir.'/'.$pdfName, $result);
        $files['pdf'] = $pdfName;

        return ['result' => $result, 'commission' => $commission, 'files' => $files];
    }

    public static function sanitizeOrderName(string $name): string
    {
        $name = trim($name);
        $name = str_replace(' ', '_', $name);
        $name = preg_replace('/[^\p{L}\p{N}_\-.]/u', '', $name) ?? '';

        return $name !== '' ? $name : 'Auftrag';
    }
}
