<?php

namespace App\Console\Commands;

use App\Services\BackupCreator;
use Illuminate\Console\Command;

/**
 * Nächtliche Sicherung, z. B. per Cron:
 *   30 3 * * *  cd /pfad/zur/app && php artisan backup:create >> /dev/null 2>&1
 *
 * Die Dateien landen unter storage/app/backups; die letzten fünf bleiben liegen.
 */
class CreateBackup extends Command
{
    protected $signature = 'backup:create {--keep=5 : Wie viele Sicherungen aufbewahrt werden}';

    protected $description = 'Datenbank und hochgeladene Dateien als ZIP sichern';

    public function handle(BackupCreator $backups): int
    {
        $result = $backups->create();
        $backups->pruneOlderThan(max(1, (int) $this->option('keep')));

        $this->info(sprintf(
            'Sicherung angelegt: %s (%d Dateien, %.1f MB)',
            $result['path'],
            $result['files'],
            filesize($result['path']) / 1048576,
        ));

        return self::SUCCESS;
    }
}
