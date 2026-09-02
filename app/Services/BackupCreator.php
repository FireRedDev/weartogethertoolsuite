<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Sicherung von Datenbank und hochgeladenen Dateien als ZIP.
 *
 * Die SQLite-Datei und die Uploads (Schullogos, Mockups) liegen nur auf dem
 * Server. Beim RunCloud-Deployment überleben sie zwar, weil `storage` und die
 * `.env` als persistente Symlinks eingehängt sind — gegen versehentliches
 * Löschen, einen Festplattenfehler oder einen missglückten Serverumzug hilft
 * das aber nicht.
 *
 * Bewusst NICHT enthalten: die `.env` (Zugangsdaten gehören nicht in eine
 * Datei, die per Browser heruntergeladen wird) sowie Zwischenstände wie
 * gerenderte Blätter und Auftragsjobs, die sich jederzeit neu erzeugen lassen.
 */
class BackupCreator
{
    /** Verzeichnisse unter storage/app/public, die mitgesichert werden. */
    private const UPLOAD_DIRS = ['school-logos', 'presentation-sheets'];

    /** Zwischenstände, die sich neu erzeugen lassen. */
    private const SKIP_SEGMENTS = ['/render/'];

    /**
     * Legt die Sicherung an und gibt den absoluten Pfad zurück.
     *
     * @return array{path: string, filename: string, files: int, bytes: int}
     */
    public function create(): array
    {
        $filename = 'ordersuite-sicherung-'.now()->format('Y-m-d-Hi').'.zip';
        $path = storage_path('app/backups/'.$filename);
        File::ensureDirectoryExists(dirname($path));
        $this->assertEnoughFreeSpace(dirname($path));

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Sicherung konnte nicht angelegt werden: {$path}");
        }

        $files = 0;
        $bytes = 0;

        $database = config('database.connections.sqlite.database');
        if (is_string($database) && is_file($database)) {
            $zip->addFile($database, 'datenbank/'.basename($database));
            $files++;
            $bytes += (int) filesize($database);
        }

        $disk = Storage::disk('public');
        foreach (self::UPLOAD_DIRS as $dir) {
            if (! $disk->exists($dir)) {
                continue;
            }
            foreach ($disk->allFiles($dir) as $relative) {
                if (str_contains('/'.$relative, self::SKIP_SEGMENTS[0])) {
                    continue;
                }
                $contents = (string) $disk->get($relative);
                $zip->addFromString('uploads/'.$relative, $contents);
                $files++;
                $bytes += strlen($contents);
            }
        }

        $zip->addFromString('LIESMICH.txt', $this->readme($files, $bytes));
        $zip->close();

        return ['path' => $path, 'filename' => $filename, 'files' => $files, 'bytes' => $bytes];
    }

    /** Räumt ältere Sicherungen weg, damit die Platte nicht volläuft. */
    /**
     * Bricht ab, wenn zu wenig Platz frei ist.
     *
     * Eine halb geschriebene Sicherung ist wertlos, und ein vollgelaufener
     * Datenträger legt die ganze Anwendung lahm — dann lieber vorher mit
     * klarer Ansage aufhören. Verlangt wird das Doppelte dessen, was die
     * Quellen belegen (das ZIP entsteht neben ihnen), mindestens aber 50 MB.
     */
    private function assertEnoughFreeSpace(string $directory): void
    {
        $free = @disk_free_space($directory);
        if ($free === false) {
            return; // Nicht ermittelbar — dann eben ohne Prüfung
        }

        $needed = max(50 * 1024 * 1024, $this->estimatedSize() * 2);
        if ($free < $needed) {
            throw new \RuntimeException(sprintf(
                'Zu wenig freier Speicherplatz für eine Sicherung: %s frei, benötigt werden etwa %s. '
                .'Bitte alte Sicherungen unter storage/app/backups löschen oder Platz schaffen.',
                $this->humanBytes((int) $free),
                $this->humanBytes($needed),
            ));
        }
    }

    private function estimatedSize(): int
    {
        $bytes = 0;
        $database = config('database.connections.sqlite.database');
        if (is_string($database) && is_file($database)) {
            $bytes += (int) filesize($database);
        }
        $disk = Storage::disk('public');
        foreach (self::UPLOAD_DIRS as $dir) {
            if (! $disk->exists($dir)) {
                continue;
            }
            foreach ($disk->allFiles($dir) as $relative) {
                if (str_contains('/'.$relative, self::SKIP_SEGMENTS[0])) {
                    continue;
                }
                $bytes += (int) $disk->size($relative);
            }
        }

        return $bytes;
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024 * 1024
            ? round($bytes / 1024 / 1024 / 1024, 1).' GB'
            : round($bytes / 1024 / 1024).' MB';
    }

    public function pruneOlderThan(int $keep = 5): void
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            return;
        }
        $backups = collect(File::files($dir))
            ->filter(fn ($f) => $f->getExtension() === 'zip')
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        foreach ($backups->slice($keep) as $old) {
            File::delete($old->getRealPath());
        }
    }

    private function readme(int $files, int $bytes): string
    {
        return implode("\n", [
            'Wear Together Order Suite — Sicherung vom '.now()->format('d.m.Y H:i'),
            '',
            'Enthalten:',
            '  datenbank/  die SQLite-Datenbank (alle Anträge, Konfigurationen, Protokolle)',
            '  uploads/    Schullogos und Mockups für die Präsentationsblätter',
            '',
            sprintf('%d Dateien, %.1f MB unkomprimiert.', $files, $bytes / 1048576),
            '',
            'NICHT enthalten: die .env mit den Zugangsdaten (bewusst) sowie erzeugte',
            'Zwischenstände (gerenderte Blätter, Auftragsjobs) — die entstehen neu.',
            '',
            'Zurückspielen: Datenbankdatei an ihren Platz kopieren (Pfad siehe .env,',
            'üblicherweise database/database.sqlite) und den Ordner uploads/ nach',
            'storage/app/public/ zurücklegen.',
        ]);
    }
}
