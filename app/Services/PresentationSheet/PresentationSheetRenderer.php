<?php

namespace App\Services\PresentationSheet;

use App\Models\SchoolOnboarding;
use App\Services\SchoolShop\ProductConfigurator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Baut das Präsentationsblatt einer Schule (A4) aus den Onboarding-Daten:
 * Schulname, Produkte, Farben, Bestellzeitraum und Shop-Adresse kommen
 * automatisch aus dem Antrag, hochgeladen werden nur die beiden Mockups.
 *
 * Die Koordinaten stammen 1:1 aus der InDesign-Vorlage (config/presentation_sheet.php).
 */
class PresentationSheetRenderer
{
    /** Wochentage ausgeschrieben — die App-Locale steht auf 'en', darauf ist kein Verlass. */
    private const WEEKDAYS = [
        1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag',
        5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag',
    ];

    public function __construct(private readonly SheetImages $images) {}

    /** Fehlt etwas, ohne das kein Blatt entstehen kann? */
    public function missingRequirements(SchoolOnboarding $onboarding): array
    {
        $missing = [];
        if (! $onboarding->sheet_back_path) {
            $missing[] = 'Mockup Rückenansicht (oben rechts)';
        }
        if (! $onboarding->sheet_front_path) {
            $missing[] = 'Mockup Vorderansicht (unten links)';
        }
        if (! $onboarding->window_start || ! $onboarding->window_end) {
            $missing[] = 'Bestellfenster (Von-/Bis-Datum im Konfigurator)';
        }
        if ($this->productRows($onboarding) === []) {
            $missing[] = 'mindestens ein aktiviertes Produkt';
        }

        return $missing;
    }

    public function html(SchoolOnboarding $onboarding): string
    {
        return view('presentation-sheet.sheet', $this->data($onboarding))->render();
    }

    public function pdf(SchoolOnboarding $onboarding): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('presentation-sheet.sheet', $this->data($onboarding))
            ->setPaper('a4', 'portrait');
    }

    public function filename(SchoolOnboarding $onboarding): string
    {
        return 'Praesentationsblatt_'.Str::of($onboarding->school_name)->ascii()->replace(' ', '_')
            ->replaceMatches('/[^A-Za-z0-9_\-]/', '')->value().'.pdf';
    }

    /**
     * Alles, was die Blade-Vorlage braucht: eine flache Liste fertig
     * positionierter Elemente in Zeichenreihenfolge. Die Vorlage enthält
     * dadurch keinerlei Rechnerei.
     *
     * @return array{page: array, fontDir: string, elements: list<array<string, mixed>>}
     */
    public function data(SchoolOnboarding $onboarding): array
    {
        $cfg = config('presentation_sheet');
        $windows = $cfg['windows'];
        $url = $this->shopUrl($onboarding);

        $this->images->forgetRendered($onboarding);
        $elements = [];

        // 1. Fotos — sie liegen unter dem Hintergrund
        $photos = [
            'mockup_back' => $onboarding->sheet_back_path
                ? $this->images->cropToWindow($onboarding, $onboarding->sheet_back_path, 'back', $windows['mockup_back'])
                : null,
            'mockup_front' => $onboarding->sheet_front_path
                ? $this->images->cropToWindow($onboarding, $onboarding->sheet_front_path, 'front', $windows['mockup_front'])
                : null,
            'detail_circle' => $this->detailImage($onboarding, $windows['detail_circle']),
        ];
        foreach ($photos as $window => $path) {
            if ($path !== null) {
                $elements[] = $this->image($path, $windows[$window]);
            }
        }

        // 2. Statischer Hintergrund — stellt die Fotos frei
        $elements[] = $this->image($cfg['background'], [
            'left' => 0, 'top' => 0, 'width' => $cfg['page']['width'], 'height' => $cfg['page']['height'],
        ]);

        // 3. Variable Inhalte
        $elements = [
            ...$elements,
            ...$this->headlineElements($onboarding, $cfg),
            ...$this->productElements($onboarding, $cfg),
            ...$this->rightColumnElements($onboarding, $cfg, $url),
        ];

        return [
            'page' => $cfg['page'],
            'fontDir' => $cfg['font_dir'],
            'elements' => $elements,
        ];
    }

    /** Zweizeilige Überschrift, zentriert; verkleinert sich bei langen Schulnamen. */
    private function headlineElements(SchoolOnboarding $onboarding, array $cfg): array
    {
        $h = $cfg['headline'];
        $lines = ['Schulmerchandise', (string) $onboarding->school_name];
        $longest = max(array_map('mb_strlen', $lines));
        // ~0,52 em mittlere Zeichenbreite in Source Sans 3 Bold
        $size = max($h['min_size'], min($h['size'], $h['width'] / max(1, $longest * 0.52)));

        $elements = [];
        foreach ($lines as $i => $line) {
            $elements[] = $this->text($line, [
                'left' => $h['left'], 'top' => $h['top'] + $i * $h['line_height'], 'width' => $h['width'],
                'size' => round($size, 1), 'weight' => 700, 'color' => $cfg['colors']['red'], 'align' => 'center',
            ]);
        }

        return $elements;
    }

    /** Produktzeilen links: Icon, Name, Farbzeile — plus die feste Baum-Zeile. */
    private function productElements(SchoolOnboarding $onboarding, array $cfg): array
    {
        $p = $cfg['products'];
        $elements = [];
        foreach ($this->rowsWithTreeRow($onboarding) as $i => $row) {
            $top = $p['first_top'] + $i * $p['row_height'];
            $iconFile = $this->iconFile($row['icon']);
            if ($iconFile !== null) {
                $elements[] = $this->image($iconFile, [
                    'left' => $p['icon']['left'], 'top' => $top + $p['icon']['offset'],
                    'width' => $p['icon']['size'], 'height' => $p['icon']['size'],
                ]);
            }
            $elements[] = $this->text($row['name'], [
                'left' => $p['name']['left'], 'top' => $top, 'width' => 220,
                'size' => $p['name']['size'], 'weight' => 700, 'color' => $cfg['colors']['dark_grey'],
            ]);
            if ($row['sub'] !== '') {
                $elements[] = $this->text($row['sub'], [
                    'left' => $p['sub']['left'], 'top' => $top + $p['sub']['offset'], 'width' => 200,
                    'size' => $p['sub']['size'], 'italic' => true, 'color' => $cfg['colors']['mid_grey'],
                ]);
            }
        }

        return $elements;
    }

    /** Rechte Spalte: Bestellzeitraum, QR-Code, Adresse — und der Vorname im Kreis. */
    private function rightColumnElements(SchoolOnboarding $onboarding, array $cfg, string $url): array
    {
        $elements = [];

        $d = $cfg['dates'];
        foreach ($this->dateLines($onboarding) as $i => $line) {
            $elements[] = $this->text($line, [
                'left' => $d['left'], 'top' => $d['top'] + $i * $d['line_height'], 'width' => $d['width'],
                'size' => $d['size'], 'weight' => 700, 'color' => $cfg['colors']['red'], 'align' => 'center',
            ]);
        }

        $q = $cfg['qr'];
        $elements[] = $this->image($this->images->qrCode($onboarding, $url), [
            'left' => $q['left'], 'top' => $q['top'], 'width' => $q['size'], 'height' => $q['size'],
        ]);

        $u = $cfg['url'];
        foreach ($this->urlLines($url) as $i => $line) {
            $elements[] = $this->text($line, [
                'left' => $u['left'], 'top' => $u['top'] + $i * $u['line_height'], 'width' => $u['width'],
                'size' => $u['size'], 'weight' => 700, 'color' => $cfg['colors']['mid_grey'], 'align' => 'center',
            ]);
        }

        if (filled($onboarding->sheet_first_name)) {
            $n = $cfg['name_badge'];
            $elements[] = $this->text($onboarding->sheet_first_name, [
                'left' => $n['left'], 'top' => $n['top'], 'width' => $n['width'],
                'size' => $n['size'], 'color' => $n['color'], 'align' => 'center',
            ]);
        }

        return $elements;
    }

    /** @param array{left: float|int, top: float|int, width: float|int, height: float|int} $box */
    private function image(string $src, array $box): array
    {
        return [
            'type' => 'image', 'src' => $src,
            'left' => round($box['left'], 2), 'top' => round($box['top'], 2),
            'width' => round($box['width'], 2), 'height' => round($box['height'], 2),
        ];
    }

    /**
     * Textelement. 'top' ist — wie in der InDesign-Vorlage — die Oberkante der
     * Buchstaben; dompdf setzt eine Zeile um einen festen Anteil der
     * Schriftgröße tiefer, das wird hier herausgerechnet.
     */
    private function text(string $text, array $style): array
    {
        $size = (float) $style['size'];
        $c = config('presentation_sheet.text_top_correction');

        return [
            'type' => 'text',
            'text' => $text,
            'left' => round($style['left'], 2),
            'top' => round($style['top'] - $c['factor'] * $size + $c['offset'], 2),
            'width' => round($style['width'], 2),
            'size' => $size,
            'weight' => $style['weight'] ?? 400,
            'italic' => $style['italic'] ?? false,
            'color' => $style['color'],
            'align' => $style['align'] ?? 'left',
        ];
    }

    /** Die Shop-Adresse der Schule — überschreibbar, sonst aus dem Namen abgeleitet. */
    public function shopUrl(SchoolOnboarding $onboarding): string
    {
        if ($onboarding->sheet_shop_url) {
            return $onboarding->sheet_shop_url;
        }

        return str_replace(
            '{slug}',
            Str::slug($onboarding->school_name),
            (string) config('presentation_sheet.shop_url_pattern'),
        );
    }

    /**
     * Produktzeilen, wie sie auf dem Blatt stehen. Vorbelegt aus dem
     * Konfigurator, im Tool aber frei überschreibbar (sheet_products).
     *
     * @return list<array{name: string, sub: string, icon: ?string}>
     */
    public function productRows(SchoolOnboarding $onboarding): array
    {
        if (is_array($onboarding->sheet_products) && $onboarding->sheet_products !== []) {
            return array_values(array_filter(
                array_map(fn ($row) => [
                    'name' => trim((string) ($row['name'] ?? '')),
                    'sub' => trim((string) ($row['sub'] ?? '')),
                    'icon' => ($row['icon'] ?? '') ?: null,
                ], $onboarding->sheet_products),
                fn ($row) => $row['name'] !== '',
            ));
        }

        return $this->defaultProductRows($onboarding);
    }

    /** @return list<string> Namen der vorhandenen Icon-Dateien (für die Auswahl im Tool). */
    public function availableIcons(): array
    {
        $files = glob(rtrim((string) config('presentation_sheet.icon_dir'), '/').'/*.png') ?: [];

        return array_values(array_map(fn ($f) => pathinfo($f, PATHINFO_FILENAME), $files));
    }

    /**
     * Vorschlag aus dem Konfigurator: Marketing-Name + Farbliste je aktiviertem
     * Produkt, begrenzt auf die Anzahl Zeilen, die das Layout hergibt.
     *
     * @return list<array{name: string, sub: string, icon: ?string}>
     */
    public function defaultProductRows(SchoolOnboarding $onboarding): array
    {
        $names = config('presentation_sheet.product_names');
        $rows = [];
        foreach (array_slice($onboarding->enabledProducts(), 0, (int) config('presentation_sheet.products.max_products')) as $product) {
            $key = $product['key'] ?? '';
            $rows[] = [
                'name' => $names[$key] ?? ProductConfigurator::preset($product)['label'],
                'sub' => $this->colorLine($product['colors'] ?? []),
                'icon' => config("presentation_sheet.icons.{$key}"),
            ];
        }

        return $rows;
    }

    /** „- in schwarz, weiß und burgundy" */
    private function colorLine(array $colors): string
    {
        $colors = array_values(array_filter(array_map('trim', $colors)));
        if ($colors === []) {
            return '';
        }
        if (count($colors) === 1) {
            return '- in '.$colors[0];
        }
        $last = array_pop($colors);

        return '- in '.implode(', ', $colors).' und '.$last;
    }

    /**
     * Produktzeilen plus die feste Baum-Zeile am Ende.
     *
     * @return list<array{name: string, sub: string, icon: ?string}>
     */
    private function rowsWithTreeRow(SchoolOnboarding $onboarding): array
    {
        $tree = config('presentation_sheet.products.tree_row');

        return [...$this->productRows($onboarding), [
            'name' => $tree['name'],
            'sub' => $tree['sub'],
            'icon' => $tree['icon'],
        ]];
    }

    /**
     * Icon-Datei zu einem Namen. Fehlt sie, greift die Ersatzzuordnung; gibt es
     * auch die nicht, bleibt der Platz leer — lieber kein Icon als ein falsches.
     */
    private function iconFile(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }
        $dir = rtrim((string) config('presentation_sheet.icon_dir'), '/');
        foreach ([$name, config("presentation_sheet.icon_fallbacks.{$name}")] as $candidate) {
            if ($candidate && is_file("{$dir}/{$candidate}.png")) {
                return "{$dir}/{$candidate}.png";
            }
        }

        return null;
    }

    /**
     * „Montag, 19.01. -" / „Montag, 09.02."
     *
     * @return list<string>
     */
    private function dateLines(SchoolOnboarding $onboarding): array
    {
        if (! $onboarding->window_start || ! $onboarding->window_end) {
            return [];
        }
        $format = fn (Carbon $date) => self::WEEKDAYS[(int) $date->isoWeekday()].', '.$date->format('d.m.');

        return [$format($onboarding->window_start).' -', $format($onboarding->window_end)];
    }

    /**
     * Die Adresse wird nach dem letzten Schrägstrich vor dem Slug umbrochen —
     * genau wie in der Vorlage.
     *
     * @return list<string>
     */
    private function urlLines(string $url): array
    {
        $trimmed = rtrim($url, '/');
        $cut = mb_strrpos($trimmed, '/');
        if ($cut === false) {
            return [$url];
        }

        return [mb_substr($trimmed, 0, $cut + 1), mb_substr($trimmed, $cut + 1).'/'];
    }

    /** Detailkreis: eigenes Bild, sonst herangezoomter Ausschnitt der Vorderansicht. */
    private function detailImage(SchoolOnboarding $onboarding, array $window): ?string
    {
        $source = $onboarding->sheet_detail_path ?: $onboarding->sheet_front_path;

        return $source ? $this->images->cropDetail($onboarding, $source, $window) : null;
    }
}
