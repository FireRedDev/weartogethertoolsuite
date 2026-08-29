<?php

namespace App\Services\PresentationSheet;

use App\Models\SchoolOnboarding;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bildaufbereitung für das Präsentationsblatt.
 *
 * Die schrägen Rahmen und der Kreis werden NICHT hier maskiert — das übernimmt
 * der Hintergrund, der an diesen Stellen transparente Fenster hat. Hier werden
 * die Fotos nur exakt auf das jeweilige Fenster-Rechteck zugeschnitten
 * (verlustfrei zentriert, wie "object-fit: cover", das dompdf nicht kennt).
 *
 * Alle erzeugten Dateien liegen unter storage/app/public — dompdf darf aus
 * Sicherheitsgründen nur innerhalb des Projektverzeichnisses lesen.
 */
class SheetImages
{
    public const DISK = 'public';

    public const SLOTS = ['back' => 'Rückenansicht', 'front' => 'Vorderansicht', 'detail' => 'Detailaufnahme'];

    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** Legt ein hochgeladenes Mockup ab und ersetzt ein vorhandenes. */
    public function store(SchoolOnboarding $onboarding, string $slot, UploadedFile $file): void
    {
        $this->delete($onboarding, $slot);

        $path = $file->storeAs(
            "presentation-sheets/{$onboarding->id}",
            $slot.'-'.Str::random(10).'.'.mb_strtolower($file->getClientOriginalExtension() ?: 'jpg'),
            self::DISK,
        );
        $onboarding->forceFill(["sheet_{$slot}_path" => $path])->save();
    }

    public function delete(SchoolOnboarding $onboarding, string $slot): void
    {
        $path = $onboarding->{"sheet_{$slot}_path"};
        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
        $onboarding->forceFill(["sheet_{$slot}_path" => null])->save();
    }

    /**
     * Schneidet ein Mockup auf ein Fenster-Rechteck zu (mittig, ohne Verzerrung)
     * und gibt den absoluten Pfad der erzeugten Datei zurück.
     *
     * @param  array{left: float, top: float, width: float, height: float}  $window
     */
    public function cropToWindow(SchoolOnboarding $onboarding, string $sourcePath, string $slot, array $window): ?string
    {
        $source = $this->load($sourcePath);
        if ($source === null) {
            return null;
        }

        $dpi = (int) config('presentation_sheet.image_dpi');
        $canvas = $this->coverCrop(
            $source,
            (int) round($window['width'] / 72 * $dpi),
            (int) round($window['height'] / 72 * $dpi),
            (float) ($onboarding->{"sheet_{$slot}_focus_x"} ?? 0.5),
            (float) ($onboarding->{"sheet_{$slot}_focus_y"} ?? 0.5),
            max(1.0, (float) ($onboarding->{"sheet_{$slot}_zoom"} ?? 1.0)),
        );
        imagedestroy($source);

        return $this->save($canvas, $onboarding, "{$slot}-window");
    }

    /**
     * Detailaufnahme für den Kreis: entweder ein eigenes Bild oder ein
     * herangezoomter Ausschnitt der Vorderansicht (Brustdruck). Fokus und Zoom
     * sind im Tool einstellbar, weil die Brust je nach Mockup woanders sitzt.
     *
     * @param  array{left: float, top: float, width: float, height: float}  $window
     */
    public function cropDetail(SchoolOnboarding $onboarding, string $sourcePath, array $window): ?string
    {
        $source = $this->load($sourcePath);
        if ($source === null) {
            return null;
        }

        $dpi = (int) config('presentation_sheet.image_dpi');
        $size = (int) round($window['width'] / 72 * $dpi);

        $canvas = $this->coverCrop(
            $source,
            $size,
            $size,
            (float) ($onboarding->sheet_detail_focus_x ?? 0.5),
            (float) ($onboarding->sheet_detail_focus_y ?? 0.35),
            max(1.0, (float) ($onboarding->sheet_detail_zoom ?? 3.0)),
        );
        imagedestroy($source);

        // Runde Maske: der Kreis im Hintergrund gibt zwar nur die Kreisfläche
        // frei, die Ecken des Quadrats würden aber das darunterliegende
        // Mockup-Foto überdecken, weil beide unter dem Hintergrund liegen.
        $this->maskToCircle($canvas);

        return $this->save($canvas, $onboarding, 'detail-window');
    }

    /**
     * Macht alles außerhalb des einbeschriebenen Kreises transparent.
     * Zeilenweise über zwei Rechtecke statt pixelweise — bei 1500 px sind das
     * gut 3.000 Aufrufe statt über zwei Millionen. Die Treppchen an der Kante
     * verdeckt der 7 pt breite Ring, den der Hintergrund darüber zeichnet.
     *
     * @param  \GdImage  $image
     */
    private function maskToCircle($image): void
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $radius = min($w, $h) / 2;
        $cx = $w / 2;
        $cy = $h / 2;

        imagealphablending($image, false);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        for ($y = 0; $y < $h; $y++) {
            $dy = $y + 0.5 - $cy;
            $half = $radius * $radius - $dy * $dy;
            if ($half <= 0) {
                imagefilledrectangle($image, 0, $y, $w - 1, $y, $transparent);

                continue;
            }
            $dx = sqrt($half);
            imagefilledrectangle($image, 0, $y, (int) floor($cx - $dx) - 1, $y, $transparent);
            imagefilledrectangle($image, (int) ceil($cx + $dx), $y, $w - 1, $y, $transparent);
        }
        imagealphablending($image, true);
    }

    /** QR-Code als PNG in der Markenfarbe. */
    public function qrCode(SchoolOnboarding $onboarding, string $url): string
    {
        $matrix = Encoder::encode($url, \BaconQrCode\Common\ErrorCorrectionLevel::M())->getMatrix();
        $modules = $matrix->getWidth();

        // 4 Module Ruhezone, wie es die QR-Spezifikation verlangt
        $quiet = 4;
        $scale = 8;
        $side = ($modules + 2 * $quiet) * $scale;

        $image = imagecreatetruecolor($side, $side);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $side, $side, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagealphablending($image, true);

        [$r, $g, $b] = $this->rgb((string) config('presentation_sheet.qr_color'));
        $dark = imagecolorallocate($image, $r, $g, $b);
        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $left = ($x + $quiet) * $scale;
                    $top = ($y + $quiet) * $scale;
                    imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $dark);
                }
            }
        }

        return $this->save($image, $onboarding, 'qr');
    }

    /** Räumt alle erzeugten Zwischenbilder eines Antrags weg. */
    public function forgetRendered(SchoolOnboarding $onboarding): void
    {
        foreach (Storage::disk(self::DISK)->files($this->renderDir($onboarding)) as $file) {
            Storage::disk(self::DISK)->delete($file);
        }
    }

    /**
     * Zuschnitt „cover": füllt das Zielrechteck vollständig, schneidet den
     * Überstand ab und verzerrt nie. Der Fokuspunkt (0..1) bestimmt, welcher
     * Teil erhalten bleibt, der Zoom vergrößert den Ausschnitt zusätzlich.
     *
     * @param  \GdImage  $source
     */
    private function coverCrop($source, int $targetW, int $targetH, float $focusX, float $focusY, float $zoom)
    {
        $sourceW = imagesx($source);
        $sourceH = imagesy($source);

        // Größe des Quellausschnitts, der auf das Ziel abgebildet wird
        $scale = max($targetW / $sourceW, $targetH / $sourceH) * $zoom;
        $cropW = min($sourceW, (int) round($targetW / $scale));
        $cropH = min($sourceH, (int) round($targetH / $scale));

        $cropX = (int) round($focusX * $sourceW - $cropW / 2);
        $cropY = (int) round($focusY * $sourceH - $cropH / 2);
        $cropX = max(0, min($cropX, $sourceW - $cropW));
        $cropY = max(0, min($cropY, $sourceH - $cropH));

        $canvas = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);

        return $canvas;
    }

    /** @return \GdImage|null */
    private function load(string $relativePath)
    {
        if (! Storage::disk(self::DISK)->exists($relativePath)) {
            return null;
        }
        $image = @imagecreatefromstring((string) Storage::disk(self::DISK)->get($relativePath));

        return $image === false ? null : $image;
    }

    /**
     * @param  \GdImage  $image
     * @return string absoluter Pfad
     */
    private function save($image, SchoolOnboarding $onboarding, string $name): string
    {
        $relative = $this->renderDir($onboarding)."/{$name}.png";
        $absolute = Storage::disk(self::DISK)->path($relative);
        @mkdir(dirname($absolute), 0775, true);
        imagepng($image, $absolute, 6);
        imagedestroy($image);

        return $absolute;
    }

    private function renderDir(SchoolOnboarding $onboarding): string
    {
        return "presentation-sheets/{$onboarding->id}/render";
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
