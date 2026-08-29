<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Nimmt den Hintergrund-Export des Grafikers entgegen und macht daraus die
 * Datei, die der Renderer erwartet.
 *
 * Der Grafiker liefert eine ganz normale PNG-Datei: alles Statische wie
 * gewohnt, aber die drei Bildplätze (beide Mockup-Rahmen und der Kreis) mit
 * reinem Magenta gefüllt. Dieser Befehl stanzt das Magenta heraus, sodass die
 * Fotos später von unten durchscheinen und der Hintergrund die schrägen
 * Rahmen und den Kreis übernimmt.
 */
class PresentationSheetBackground extends Command
{
    protected $signature = 'sheet:background {file : PNG-Export des Grafikers (Magenta an den Bildplätzen)}
                            {--keep-size : Abweichende Pixelmaße übernehmen statt auf A4 @ 300 dpi zu skalieren}';

    protected $description = 'Hintergrund des Präsentationsblatts aus einem Grafik-Export erzeugen (Magenta wird freigestellt)';

    /** A4 bei 300 dpi */
    private const TARGET_WIDTH = 2481;

    private const TARGET_HEIGHT = 3508;

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("Datei nicht gefunden: {$file}");

            return self::FAILURE;
        }

        $source = @imagecreatefromstring((string) file_get_contents($file));
        if ($source === false) {
            $this->error('Die Datei ist kein lesbares Bild. Bitte als PNG exportieren.');

            return self::FAILURE;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $this->line("Eingang: {$width} × {$height} px");

        if (! $this->option('keep-size') && ($width !== self::TARGET_WIDTH || $height !== self::TARGET_HEIGHT)) {
            $ratio = $width / $height;
            $expected = self::TARGET_WIDTH / self::TARGET_HEIGHT;
            if (abs($ratio - $expected) > 0.01) {
                $this->warn(sprintf(
                    'Seitenverhältnis %.4f statt %.4f (A4 hochkant) — bitte prüfen, ob das Dokument wirklich A4 ist.',
                    $ratio,
                    $expected,
                ));
            }
            $resized = imagecreatetruecolor(self::TARGET_WIDTH, self::TARGET_HEIGHT);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $source, 0, 0, 0, 0, self::TARGET_WIDTH, self::TARGET_HEIGHT, $width, $height);
            imagedestroy($source);
            $source = $resized;
            $width = self::TARGET_WIDTH;
            $height = self::TARGET_HEIGHT;
            $this->line("Skaliert auf {$width} × {$height} px (A4 @ 300 dpi)");
        }

        imagealphablending($source, false);
        imagesavealpha($source, true);

        $punched = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($source, $x, $y);
                $r = ($color >> 16) & 0xFF;
                $g = ($color >> 8) & 0xFF;
                $b = $color & 0xFF;
                if ($r > 200 && $g < 80 && $b > 200) {
                    imagesetpixel($source, $x, $y, imagecolorallocatealpha($source, 0, 0, 0, 127));
                    $punched++;
                }
            }
        }

        $share = $punched / ($width * $height) * 100;
        if ($punched === 0) {
            $this->error('Kein Magenta gefunden — die drei Bildplätze müssen mit reinem Magenta (#FF00FF) gefüllt sein.');
            imagedestroy($source);

            return self::FAILURE;
        }

        $target = config('presentation_sheet.background');
        if (is_file($target)) {
            $backup = $target.'.bak';
            copy($target, $backup);
            $this->line('Bisherige Fassung gesichert: '.$backup);
        }
        imagepng($source, $target, 6);
        imagedestroy($source);

        $this->info(sprintf('Hintergrund geschrieben: %s (%.1f %% freigestellt)', $target, $share));
        $this->line('Zum Prüfen ein Präsentationsblatt im Tool erzeugen — die Fotos müssen exakt in den Rahmen sitzen.');

        return self::SUCCESS;
    }
}
