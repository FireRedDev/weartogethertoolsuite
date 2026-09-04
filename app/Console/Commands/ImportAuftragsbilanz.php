<?php

namespace App\Console\Commands;

use App\Models\BalanceOrder;
use App\Services\Balance\AuftragsbilanzImporter;
use Illuminate\Console\Command;

/**
 * Die Altdaten aus der bisherigen Excel übernehmen.
 *
 * Beim Deploy passiert das von selbst (Migration). Dieser Befehl ist für den
 * Fall gedacht, dass die Übernahme wiederholt werden soll — etwa nachdem
 * `database/data/auftragsbilanz.json` berichtigt wurde.
 */
class ImportAuftragsbilanz extends Command
{
    protected $signature = 'auftragsbilanz:import
        {--file= : Abweichende Importdatei}
        {--overwrite : Bereits vorhandene Aufträge auf den Stand der Datei zurücksetzen}
        {--force : Ohne Rückfrage}
        {--dry-run : Nur anzeigen, was passieren würde}';

    protected $description = 'Die Aufträge aus der bisherigen Excel-Auftragsbilanz übernehmen';

    public function handle(AuftragsbilanzImporter $importer): int
    {
        $path = $this->option('file') ?: database_path('data/auftragsbilanz.json');

        if (! is_file($path)) {
            $this->error("Importdatei nicht gefunden: {$path}");

            return self::FAILURE;
        }

        $overwrite = (bool) $this->option('overwrite');
        $dryRun = (bool) $this->option('dry-run');
        $existing = BalanceOrder::query()->count();

        if ($overwrite && $existing > 0 && ! $dryRun && ! $this->option('force')
            && ! $this->confirm("Es sind bereits {$existing} Aufträge gespeichert. Übereinstimmende Zeilen werden auf den Stand der Datei zurückgesetzt — von Hand geänderte Beträge gehen dabei verloren. Fortfahren?")) {
            $this->info('Abgebrochen.');

            return self::SUCCESS;
        }

        try {
            $result = $importer->import($path, overwrite: $overwrite, dryRun: $dryRun);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $vorsilbe = $dryRun ? 'Probelauf: ' : '';
        $this->info($vorsilbe."{$result['created']} Aufträge angelegt, {$result['updated']} aktualisiert, "
            ."{$result['skipped']} unverändert gelassen, {$result['linked']} mit einem Antrag verknüpft.");

        return self::SUCCESS;
    }
}
