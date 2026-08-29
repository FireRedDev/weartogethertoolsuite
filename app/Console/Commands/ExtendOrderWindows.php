<?php

namespace App\Console\Commands;

use App\Services\SchoolShop\OrderWindowExtender;
use Illuminate\Console\Command;

/**
 * Täglich per Cron aufzurufen:
 *   0 6 * * *  cd /pfad/zur/app && php artisan windows:extend >> /dev/null 2>&1
 *
 * Ohne Cron passiert dasselbe gedrosselt beim Aufruf der Startseite — der Cron
 * sorgt nur dafür, dass es auch dann läuft, wenn tagelang niemand das Tool öffnet.
 */
class ExtendOrderWindows extends Command
{
    protected $signature = 'windows:extend {--dry-run : Nur anzeigen, was verlängert würde}';

    protected $description = 'Abgelaufene Sammelbestellfenster automatisch verlängern';

    public function handle(OrderWindowExtender $extender): int
    {
        $due = $extender->due();

        if ($due->isEmpty()) {
            $this->info('Kein Bestellfenster fällig.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($due as $onboarding) {
                $this->line(sprintf(
                    '%s — Ende %s, würde um %d Tage verlängert',
                    $onboarding->school_name,
                    $onboarding->window_end->format('d.m.Y'),
                    $onboarding->auto_extend_days,
                ));
            }
            $this->info($due->count().' Fenster wären fällig (Testlauf, nichts geändert).');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($extender->runDue() as $entry) {
            $entry['ok'] ? $this->info('✓ '.$entry['detail']) : $this->error('✖ '.$entry['detail']);
            $failed += $entry['ok'] ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
