<?php

namespace App\Http\Controllers;

use App\Services\OrderJobFactory;
use App\Services\OrderReportGenerator;
use App\Services\WooCommerceClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderToolController extends Controller
{
    public function __construct(
        private readonly OrderJobFactory $jobFactory,
        private readonly OrderReportGenerator $generator,
        private readonly WooCommerceClient $wooCommerce,
    ) {}

    public function index(): View
    {
        return view('tool.step1', [
            'apiConfigured' => $this->wooCommerce->isConfigured(),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate(
            ['export' => ['required', 'file', 'mimes:xlsx,xltx', 'max:20480']],
            [
                'export.required' => 'Bitte eine Datei auswählen.',
                'export.mimes' => 'Nur .xlsx/.xltx-Dateien werden unterstützt (Standard-Shop-Export).',
                'export.max' => 'Die Datei ist größer als 20 MB.',
            ],
        );

        $jobId = $this->jobFactory->newJobId();
        $originalFilename = $request->file('export')?->getClientOriginalName();
        $request->file('export')->move($this->jobFactory->jobDir($jobId), 'input.xlsx');

        try {
            $this->jobFactory->createFromInputFile($jobId, [
                'source' => 'upload',
                'original_filename' => $originalFilename,
            ]);
        } catch (\Throwable) {
            Storage::disk('local')->deleteDirectory('jobs/'.$jobId);

            return back()->withErrors(['export' => 'Die Datei konnte nicht als Excel-Datei gelesen werden. Bitte den Standard-Shop-Export verwenden.']);
        }

        return redirect()->route('job.show', $jobId);
    }

    public function show(string $jobId): View
    {
        $meta = $this->readMeta($jobId);

        return view('tool.step2', ['jobId' => $jobId, 'meta' => $meta]);
    }

    public function generate(Request $request, string $jobId): RedirectResponse
    {
        $meta = $this->readMeta($jobId);
        if ($meta['validation']['errors'] !== []) {
            return redirect()->route('job.show', $jobId);
        }

        $validated = $request->validate(
            [
                'ordername' => ['required', 'string', 'max:120'],
                'orderinformation' => ['nullable', 'string', 'max:2000'],
            ],
            ['ordername.required' => 'Bitte den Namen der Schule/Organisation eingeben.'],
        );

        $orderName = OrderReportGenerator::sanitizeOrderName($validated['ordername']);
        $orderInformation = (string) ($validated['orderinformation'] ?? '');
        $dir = $this->jobDir($jobId);

        try {
            $generated = $this->generator->generate($dir.'/input.xlsx', $orderName, $orderInformation, $dir);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['generate' => 'Fehler bei der Verarbeitung: '.$e->getMessage()])->withInput();
        }

        $result = $generated['result'];
        $preview = [
            'orders_columns' => $result->ordersColumns,
            'orders_rows' => array_slice($result->ordersRows, 0, 500),
            'pivot_columns' => $result->pivotListColumns,
            'pivot_rows' => $result->pivotListRows,
        ];
        file_put_contents($dir.'/preview.json', json_encode($preview, JSON_UNESCAPED_UNICODE));

        $meta = array_merge($meta, [
            'generated' => true,
            'generated_at' => now()->toIso8601String(),
            'ordername' => $orderName,
            'orderinformation' => $orderInformation,
            'files' => $generated['files'],
            'stats' => [
                'pieces' => $result->pieceCount(),
                'kartons' => $result->kartonCount(),
                'personalized' => $result->personalizedCount(),
                'commission' => $generated['commission'],
            ],
        ]);
        $this->writeMeta($jobId, $meta);

        return redirect()->route('job.result', $jobId);
    }

    public function result(string $jobId): View
    {
        $meta = $this->readMeta($jobId);
        abort_unless($meta['generated'] ?? false, 404);
        $preview = json_decode((string) file_get_contents($this->jobDir($jobId).'/preview.json'), true);

        return view('tool.step3', ['jobId' => $jobId, 'meta' => $meta, 'preview' => $preview]);
    }

    public function download(string $jobId, string $file): BinaryFileResponse
    {
        $meta = $this->readMeta($jobId);
        $allowed = array_values($meta['files'] ?? []);
        $allowed[] = 'input.xlsx'; // Rohdaten (Plugin-Export bzw. API-Abzug)
        abort_unless(in_array($file, $allowed, true), 404);

        return response()->download($this->jobDir($jobId).'/'.$file);
    }

    public function zip(string $jobId): BinaryFileResponse
    {
        $meta = $this->readMeta($jobId);
        abort_unless($meta['generated'] ?? false, 404);
        $dir = $this->jobDir($jobId);
        $zipName = ($meta['ordername'] ?? 'Auftrag').'_orderreports.zip';
        $zipPath = $dir.'/'.$zipName;

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($meta['files'] as $filename) {
            $zip->addFile($dir.'/'.$filename, $filename);
        }
        $zip->close();

        return response()->download($zipPath);
    }

    private function jobDir(string $jobId): string
    {
        abort_unless(Str::isUuid($jobId), 404);

        return Storage::disk('local')->path('jobs/'.$jobId);
    }

    private function readMeta(string $jobId): array
    {
        $path = $this->jobDir($jobId).'/meta.json';
        abort_unless(is_file($path), 404);

        return json_decode((string) file_get_contents($path), true);
    }

    private function writeMeta(string $jobId, array $meta): void
    {
        file_put_contents($this->jobDir($jobId).'/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
    }
}
