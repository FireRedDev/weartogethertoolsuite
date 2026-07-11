<?php

namespace App\Console\Commands;

use App\Services\OrderReportGenerator;
use Illuminate\Console\Command;

class GenerateOrders extends Command
{
    protected $signature = 'orders:generate {input : Pfad zum Shop-Export (.xlsx)}
                            {ordername : Name der Schule/Organisation}
                            {outdir : Zielverzeichnis}
                            {--info= : Informationen für den Lieferanten}';

    protected $description = 'Erzeugt die drei Excel-Reports und das Verteil-PDF direkt über die Kommandozeile.';

    public function handle(OrderReportGenerator $generator): int
    {
        $input = (string) $this->argument('input');
        if (! is_file($input)) {
            $this->error("Datei nicht gefunden: {$input}");

            return self::FAILURE;
        }

        $orderName = OrderReportGenerator::sanitizeOrderName((string) $this->argument('ordername'));
        $generated = $generator->generate($input, $orderName, (string) ($this->option('info') ?? ''), (string) $this->argument('outdir'));

        $result = $generated['result'];
        $this->info(sprintf(
            'Fertig: %d Stück, %d Kartons, %d Personalisierungen, Provision %s',
            $result->pieceCount(),
            $result->kartonCount(),
            $result->personalizedCount(),
            $generated['commission'],
        ));
        foreach ($generated['files'] as $file) {
            $this->line('  '.$file);
        }

        return self::SUCCESS;
    }
}
