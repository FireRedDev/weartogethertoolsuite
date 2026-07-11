<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Legt Auftrags-Jobs an (Upload wie API-Import) und hält beide Wege
 * downstream identisch: Beide erzeugen eine input.xlsx im Job-Verzeichnis,
 * die anschließend denselben Weg (Parsen, Prüfbericht, Generierung) geht.
 */
class OrderJobFactory
{
    public function __construct(
        private readonly ShopExportReader $reader,
        private readonly OrderValidator $validator,
    ) {}

    /**
     * Erzeugt einen Job aus einer bereits im Job-Verzeichnis liegenden input.xlsx.
     *
     * @param  array<string, mixed>  $extraMeta
     */
    public function createFromInputFile(string $jobId, array $extraMeta = []): array
    {
        $dir = $this->jobDir($jobId);
        $table = $this->reader->read($dir.'/input.xlsx');
        $validation = $this->validator->validate($table);

        $pieces = 0;
        if ($validation['errors'] === []) {
            $anzahlKey = in_array('Anzahl', $table['columns'], true) ? 'Anzahl' : 'Anzahl ';
            foreach ($table['rows'] as $row) {
                $pieces += max(0, (int) ($row[$anzahlKey] ?? 0));
            }
        }

        $meta = array_merge([
            'created_at' => now()->toIso8601String(),
            'positions' => count($table['rows']),
            'pieces' => $pieces,
            'validation' => $validation,
            'generated' => false,
        ], $extraMeta);

        file_put_contents($dir.'/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));

        return $meta;
    }

    /**
     * Schreibt eine per API geholte Tabelle als input.xlsx (Rohdaten wie der
     * bisherige Plugin-Export) in ein neues Job-Verzeichnis.
     *
     * @param  array{columns: list<string>, rows: list<array<string, mixed>>}  $table
     */
    public function newJobFromTable(array $table): string
    {
        $jobId = (string) Str::uuid();
        $dir = $this->jobDir($jobId);
        mkdir($dir, 0775, true);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Orders');
        foreach ($table['columns'] as $c => $name) {
            $sheet->getCell([$c + 1, 1])->setValueExplicit($name, DataType::TYPE_STRING);
        }
        foreach ($table['rows'] as $r => $row) {
            foreach ($table['columns'] as $c => $name) {
                $value = $row[$name] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $cell = $sheet->getCell([$c + 1, $r + 2]);
                if (is_int($value) || is_float($value)) {
                    $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);
                } else {
                    $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
                }
            }
        }
        (new XlsxWriter($spreadsheet))->save($dir.'/input.xlsx');
        $spreadsheet->disconnectWorksheets();

        return $jobId;
    }

    public function newJobId(): string
    {
        $jobId = (string) Str::uuid();
        mkdir($this->jobDir($jobId), 0775, true);

        return $jobId;
    }

    public function jobDir(string $jobId): string
    {
        return Storage::disk('local')->path('jobs/'.$jobId);
    }
}
