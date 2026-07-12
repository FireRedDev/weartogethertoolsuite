<?php

namespace App\Console\Commands;

use App\Exceptions\WooCommerceApiException;
use App\Services\SchoolShop\DynamicMockupsClient;
use Illuminate\Console\Command;

class MockupsCheck extends Command
{
    protected $signature = 'mockups:check {--mockup= : Smart-Objects/Presets einer Mockup-UUID anzeigen}';

    protected $description = 'Prüft die Dynamic-Mockups-Verbindung und listet Vorlagen (UUIDs für config/schoolshop.php → mockups.templates).';

    public function handle(DynamicMockupsClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('DYNAMIC_MOCKUPS_API_KEY fehlt in der .env-Datei (app.dynamicmockups.com → API).');

            return self::FAILURE;
        }

        try {
            $mockupUuid = (string) $this->option('mockup');
            if ($mockupUuid !== '') {
                $mockup = $client->getMockup($mockupUuid);
                $this->info(($mockup['name'] ?? '?')." ({$mockupUuid})");
                foreach ($mockup['smart_objects'] ?? [] as $so) {
                    $size = isset($so['size']['width']) ? "{$so['size']['width']}x{$so['size']['height']}" : 'Größe unbekannt';
                    $this->line("  Smart-Object {$so['uuid']}: ".($so['name'] ?? '?')." ({$size})");
                    foreach ($so['print_area_presets'] ?? [] as $preset) {
                        $this->line("    Preset {$preset['uuid']}: ".($preset['name'] ?? '?'));
                    }
                }

                return self::SUCCESS;
            }

            $mockups = $client->listMockups();
            $this->info('Verbindung OK. Vorlagen ('.count($mockups).'):');
            foreach ($mockups as $mockup) {
                $soCount = count($mockup['smart_objects'] ?? []);
                $this->line(sprintf('  %s: %s (%d Smart-Object%s)', $mockup['uuid'] ?? '?', $mockup['name'] ?? '?', $soCount, $soCount === 1 ? '' : 's'));
            }
            if ($mockups === []) {
                $this->line('  Keine Vorlagen unter "My Templates". Im Dynamic-Mockups-Dashboard Vorlagen aus der Bibliothek zu den eigenen Templates hinzufügen, dann erscheinen sie hier.');
            }
            $this->line('');
            $this->line('Details einer Vorlage: php artisan mockups:check --mockup=UUID');
        } catch (WooCommerceApiException $e) {
            $this->error($e->userMessage().' — '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
