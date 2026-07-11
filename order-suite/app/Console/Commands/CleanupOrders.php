<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupOrders extends Command
{
    protected $signature = 'orders:cleanup';

    protected $description = 'Löscht Upload-/Report-Verzeichnisse, die älter als die Aufbewahrungsfrist sind (DSGVO).';

    public function handle(): int
    {
        $retentionHours = (int) config('ordersuite.retention_hours');
        $cutoff = now()->subHours($retentionHours)->getTimestamp();
        $disk = Storage::disk('local');
        $deleted = 0;

        foreach ($disk->directories('jobs') as $dir) {
            $metaPath = $dir.'/meta.json';
            $timestamp = $disk->exists($metaPath)
                ? $disk->lastModified($metaPath)
                : $disk->lastModified($dir.'/input.xlsx');
            if ($timestamp < $cutoff) {
                $disk->deleteDirectory($dir);
                $deleted++;
            }
        }

        $this->info("{$deleted} Auftragsverzeichnisse gelöscht (Aufbewahrung: {$retentionHours} h).");

        return self::SUCCESS;
    }
}
