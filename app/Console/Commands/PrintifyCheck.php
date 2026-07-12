<?php

namespace App\Console\Commands;

use App\Exceptions\WooCommerceApiException;
use App\Services\SchoolShop\PrintifyClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class PrintifyCheck extends Command
{
    protected $signature = 'printify:check {--blueprints= : Katalog nach Blueprint suchen (z.B. "JH001")}';

    protected $description = 'Prüft die Printify-Verbindung, zeigt Shops (inkl. Shop-ID für die .env) und sucht optional Blueprints.';

    public function handle(PrintifyClient $printify): int
    {
        $token = (string) config('schoolshop.printify.api_token');
        if ($token === '') {
            $this->error('PRINTIFY_API_TOKEN fehlt in der .env-Datei.');

            return self::FAILURE;
        }

        // Shops direkt abrufen (funktioniert auch ohne PRINTIFY_SHOP_ID in der .env)
        $response = Http::withToken($token)->timeout(30)->acceptJson()
            ->get('https://api.printify.com/v1/shops.json');
        if (! $response->successful()) {
            $this->error("Printify hat mit HTTP {$response->status()} geantwortet: ".mb_substr($response->body(), 0, 200));
            if ($response->status() === 401) {
                $this->line('Der Token ist ungültig oder abgelaufen — bitte in Printify (My Profile → Connections) prüfen.');
            }

            return self::FAILURE;
        }

        $this->info('Verbindung OK. Deine Shops:');
        foreach ($response->json() as $shop) {
            $this->line(sprintf('  Shop-ID %s: %s (%s)  →  PRINTIFY_SHOP_ID=%s', $shop['id'], $shop['title'] ?? '?', $shop['sales_channel'] ?? '?', $shop['id']));
        }

        $search = (string) $this->option('blueprints');
        if ($search !== '') {
            try {
                $blueprints = $printify->searchBlueprints($search);
            } catch (WooCommerceApiException $e) {
                $this->error($e->userMessage().' — '.$e->getMessage());

                return self::FAILURE;
            }
            $this->info("Blueprints zu \"{$search}\":");
            foreach (array_slice($blueprints, 0, 15) as $blueprint) {
                $this->line(sprintf('  Blueprint %d: %s %s (%s)', $blueprint['id'], $blueprint['brand'] ?? '', $blueprint['model'] ?? '', $blueprint['title'] ?? ''));
            }
            if ($blueprints === []) {
                $this->line('  Keine Treffer.');
            }
        }

        return self::SUCCESS;
    }
}
