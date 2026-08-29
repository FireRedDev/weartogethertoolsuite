<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Schullogos je Druck (Frontprint/Backprint) verwalten.
 *
 * Das Logo ist im Formular kein Pflichtfeld — es muss sich deshalb im Tool
 * nachträglich hochladen und austauschen lassen. Jede Datei wird zweifach
 * abgelegt:
 *
 *  1. lokal auf der "public"-Platte — Grundlage für Vorschaubild und Download
 *     im Tool, funktioniert immer (auch ohne WordPress-Verbindung);
 *  2. zusätzlich in der WordPress-Mediathek — nur diese Adresse ist von außen
 *     erreichbar, und nur so können Printify und Dynamic Mockups das Logo
 *     selbst herunterladen (die Toolsuite liegt hinter dem Zugangsschutz).
 *
 * Scheitert Schritt 2, bleibt die lokale Kopie bestehen; der Aufrufer bekommt
 * die Fehlermeldung zurück und kann sie anzeigen.
 */
class LogoManager
{
    public const DISK = 'public';

    /** Erlaubte Dateiendungen (Printify/Dynamic Mockups brauchen ein Pixelformat). */
    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** Ab dieser Kantenlänge (px) reicht die Auflösung für den Textildruck. */
    private const MIN_EDGE = 1000;

    public function __construct(private readonly WordPressClient $wordpress) {}

    /**
     * Legt ein hochgeladenes Logo für einen Druck ab und ersetzt dabei ein
     * eventuell vorhandenes.
     *
     * @return ?string Fehlermeldung des Mediathek-Uploads (null = alles ok)
     */
    public function store(SchoolOnboarding $onboarding, string $slot, UploadedFile $file): ?string
    {
        $this->deleteStoredFile($onboarding, $slot);

        $extension = mb_strtolower($file->getClientOriginalExtension() ?: 'png');
        $path = $file->storeAs(
            "school-logos/{$onboarding->id}",
            $slot.'-'.Str::random(10).'.'.$extension,
            self::DISK,
        );

        $onboarding->forceFill([
            "logo_{$slot}_path" => $path,
            "logo_{$slot}_url" => null,
        ])->save();

        // Öffentlich erreichbare Kopie in der WordPress-Mediathek
        try {
            $media = $this->wordpress->uploadMedia(
                $onboarding->id.'-'.$slot.'-logo.'.$extension,
                (string) Storage::disk(self::DISK)->get($path),
                $file->getMimeType() ?: 'image/png',
            );
        } catch (\Throwable $e) {
            report($e);

            return 'Das Logo wurde im Tool gespeichert, konnte aber nicht in die WordPress-Mediathek geladen werden: '
                .$e->getMessage()
                .' — Printify und die Mockup-Erzeugung brauchen eine öffentlich erreichbare Adresse. Bitte die WordPress-Verbindung prüfen (Admin-Informationen) und das Logo danach erneut hochladen.';
        }

        $onboarding->forceFill(["logo_{$slot}_url" => $media['source_url']])->save();

        return null;
    }

    /**
     * Hinweise zur Druckqualität. Blockieren nichts — beides sieht man im Tool
     * nicht, auf dem Textil aber sofort: ein zu kleines Logo wird beim Druck
     * unscharf, ein nicht freigestellter Hintergrund druckt als weißer Kasten.
     *
     * @return list<string>
     */
    public function qualityWarnings(UploadedFile $file): array
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($image === false) {
            return [];
        }

        $warnings = [];
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) < self::MIN_EDGE) {
            $warnings[] = sprintf(
                'Das Logo ist mit %d × %d Pixeln klein — für den Druck sollte die längere Kante mindestens %d Pixel haben, sonst wird es unscharf.',
                $width, $height, self::MIN_EDGE,
            );
        }

        if (! $this->hasTransparentCorners($image)) {
            $warnings[] = 'Das Logo hat keinen freigestellten Hintergrund (die Ecken sind deckend). '
                .'Auf dunklen Textilien druckt es dann als sichtbarer Kasten — besser ein PNG mit transparentem Hintergrund verwenden.';
        }

        imagedestroy($image);

        return $warnings;
    }

    /** Sind die vier Ecken durchsichtig? Zuverlässiger Anhaltspunkt für „freigestellt". */
    private function hasTransparentCorners($image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $corners = [[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]];

        foreach ($corners as [$x, $y]) {
            // Bit 24–31 des Farbwerts sind der Alphakanal (0 = deckend, 127 = ganz durchsichtig)
            $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;
            if ($alpha < 64) {
                return false;
            }
        }

        return true;
    }

    /** Entfernt das im Tool hochgeladene Logo — danach gilt wieder der Formular-Upload. */
    public function reset(SchoolOnboarding $onboarding, string $slot): void
    {
        $this->deleteStoredFile($onboarding, $slot);
        $onboarding->forceFill(["logo_{$slot}_path" => null, "logo_{$slot}_url" => null])->save();
    }

    /**
     * Inhalt + MIME-Typ eines hochgeladenen Logos (Vorschau/Download im Tool).
     *
     * @return ?array{contents: string, mime: string, filename: string}
     */
    public function read(SchoolOnboarding $onboarding, string $slot): ?array
    {
        $path = $onboarding->logoPath($slot);
        if (! $path || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return [
            'contents' => (string) Storage::disk(self::DISK)->get($path),
            'mime' => Storage::disk(self::DISK)->mimeType($path) ?: 'application/octet-stream',
            'filename' => basename($path),
        ];
    }

    private function deleteStoredFile(SchoolOnboarding $onboarding, string $slot): void
    {
        $path = $onboarding->logoPath($slot);
        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
