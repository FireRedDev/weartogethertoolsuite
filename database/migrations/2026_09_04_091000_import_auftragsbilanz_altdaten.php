<?php

use App\Models\BalanceOrder;
use App\Services\Balance\AuftragsbilanzImporter;
use Illuminate\Database\Migrations\Migration;

/**
 * Die 384 Altaufträge aus der bisherigen Excel übernehmen.
 *
 * Bewusst als Migration und nicht als Handgriff beim Deploy: Ein Modul, das
 * nach dem Deploy leer dasteht, bis jemand einen Befehl ausführt, sieht kaputt
 * aus — und der Befehl wird vergessen. Das Deploy-Script führt ohnehin
 * `migrate --force` aus.
 *
 * Die Daten selbst liegen in `database/data/auftragsbilanz.json`, nicht hier:
 * So bleiben sie lesbar, versionierbar und wiederverwendbar (derselbe Importer
 * steht hinter `php artisan auftragsbilanz:import`).
 *
 * Bereits vorhandene Zeilen werden NICHT angefasst — sonst setzte ein erneuter
 * Durchlauf von Hand nachgetragene Beträge auf den Excel-Stand zurück.
 */
return new class extends Migration
{
    public function up(): void
    {
        // In der Testumgebung abgeschaltet (siehe config/auftragsbilanz.php):
        // 384 Altaufträge in jedem Test würden jede Umsatz- und Stückzahlprüfung
        // gegen einen fremden Datenberg rechnen lassen.
        if (! config('auftragsbilanz.import_on_migrate')) {
            return;
        }

        app(AuftragsbilanzImporter::class)->import();
    }

    public function down(): void
    {
        // Nur die übernommenen Zeilen zurücknehmen. Was im Tool selbst
        // eingetragen wurde, bleibt — es kam nie aus dieser Migration.
        BalanceOrder::query()->where('source', 'excel')->delete();
    }
};
