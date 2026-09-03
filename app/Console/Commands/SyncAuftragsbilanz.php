<?php

namespace App\Console\Commands;

use App\Services\Balance\BalanceReport;
use App\Services\Balance\OnlineRevenueSync;
use App\Services\Statistics\SchoolYear;
use Illuminate\Console\Command;

/**
 * Trägt die Online-Einnahmen verknüpfter Aufträge aus dem Webshop nach.
 *
 * Dasselbe passiert gedrosselt nach jedem Aufruf der Auftragsbilanz — dieser
 * Befehl ist für den nächtlichen Cron gedacht, damit die Zahlen auch dann
 * aktuell sind, wenn tagsüber niemand die Seite geöffnet hat.
 */
class SyncAuftragsbilanz extends Command
{
    protected $signature = 'auftragsbilanz:sync {--jahr= : Nur dieses Schuljahr (Startjahr, z. B. 2025)}';

    protected $description = 'Online-Einnahmen der verknüpften Aufträge aus dem Webshop nachtragen';

    public function handle(OnlineRevenueSync $sync, BalanceReport $report): int
    {
        $years = $this->option('jahr') !== null
            ? array_filter([SchoolYear::parse((string) $this->option('jahr'))])
            : $report->years();

        if ($years === []) {
            $this->info('Keine Aufträge vorhanden — nichts nachzutragen.');

            return self::SUCCESS;
        }

        foreach ($years as $year) {
            $result = $sync->sync($year);

            if (! $result['complete']) {
                $this->warn("{$year->label()}: Shop-Daten unvollständig — übersprungen. "
                    .'Erst die Statistik für dieses Jahr fertig aufbauen lassen.');

                continue;
            }

            $this->line("{$year->label()}: {$result['updated']} von {$result['checked']} verknüpften Aufträgen aktualisiert.");
        }

        return self::SUCCESS;
    }
}
