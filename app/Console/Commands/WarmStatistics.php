<?php

namespace App\Console\Commands;

use App\Services\Statistics\StatisticsFilters;
use App\Services\Statistics\StatisticsWarmer;
use App\Services\WooCommerceClient;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Baut den Datenbestand der Statistik vor, damit die Seite sofort da ist.
 *
 * Optional, aber empfohlen als nächtlicher Cron:
 *
 *     15 4 * * * cd /pfad/zur/app && php artisan statistics:warm
 *
 * Ohne Cron passiert dasselbe beim Aufruf der Seite im Hintergrund — dann
 * dauert der erste Aufruf des Tages eben etwas.
 */
class WarmStatistics extends Command
{
    protected $signature = 'statistics:warm
        {--seconds= : Wie lange höchstens geladen wird (Standard: statistics.warm_budget_seconds)}
        {--runs=1 : Wie viele Durchgänge nacheinander}';

    protected $description = 'Bestelldaten für die Statistik vorab laden (monatsweise, mit Pausen)';

    public function handle(WooCommerceClient $client, StatisticsWarmer $warmer): int
    {
        if (! $client->isConfigured()) {
            $this->error('Die Shop-Verbindung ist nicht eingerichtet (WC_STORE_URL / WC_CONSUMER_KEY / WC_CONSUMER_SECRET).');

            return self::FAILURE;
        }

        // Standardeinstellungen — genau das, was die Seite ohne Filter zeigt.
        $filters = StatisticsFilters::fromRequest(Request::create('/statistiken', 'GET'));
        $seconds = $this->option('seconds') !== null ? (float) $this->option('seconds') : null;

        for ($run = 1; $run <= max(1, (int) $this->option('runs')); $run++) {
            $before = $warmer->progress($filters);
            if ($before['done']) {
                $this->info("Alles geladen ({$before['total']} Datenpakete).");

                return self::SUCCESS;
            }

            $result = $warmer->warm($filters, $seconds);
            if (! $result['ran']) {
                $this->warn('Es läuft bereits ein Durchgang — übersprungen.');

                return self::SUCCESS;
            }

            $after = $warmer->progress($filters);
            $this->line(sprintf(
                'Durchgang %d: %d Datenpakete geholt — %d von %d (%d %%).',
                $run,
                $result['fetched'],
                $after['loaded'],
                $after['total'],
                $after['percent'],
            ));

            if ($after['error'] !== null) {
                $this->error($after['error']['message']);
                $this->line($after['error']['technical']);

                return self::FAILURE;
            }
            if ($after['done']) {
                $this->info('Fertig.');

                return self::SUCCESS;
            }
        }

        $this->comment('Noch nicht vollständig — der nächste Lauf macht dort weiter.');

        return self::SUCCESS;
    }
}
