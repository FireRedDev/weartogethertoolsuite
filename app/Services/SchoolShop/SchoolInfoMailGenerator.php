<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;
use App\Services\PresentationSheet\PresentationSheetRenderer;
use Illuminate\Support\Carbon;

/**
 * Vorlage für die E-Mail an die Schule, mit der das Bestellfenster startet:
 * Link zur Bestellseite, Zeitraum, Produkte und der Hinweis auf das
 * Präsentationsblatt im Anhang.
 *
 * Gegenstück zum OrderEmailGenerator (der geht an die Druckerei). Verschickt
 * wird auch hier nichts — die Toolsuite hat bewusst keinen Mailer; der Text
 * ist zum Kopieren bzw. für den mailto-Link gedacht.
 */
class SchoolInfoMailGenerator
{
    private const WEEKDAYS = [
        1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag',
        5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag',
    ];

    public function __construct(private readonly PresentationSheetRenderer $sheet) {}

    public function subject(SchoolOnboarding $onboarding): string
    {
        return 'Euer Schulmerch-Bestellfenster: '.$onboarding->school_name;
    }

    public function body(SchoolOnboarding $onboarding): string
    {
        $url = $this->sheet->shopUrl($onboarding);
        $greeting = $onboarding->contact_name
            ? 'Hallo '.$onboarding->contact_name.','
            : 'Hallo zusammen,';

        $lines = [
            $greeting,
            '',
            'euer Schulmerch ist online — ab sofort kann bestellt werden:',
            $url,
            '',
        ];

        if ($onboarding->window_start && $onboarding->window_end) {
            $lines[] = 'Bestellzeitraum: '.$this->date($onboarding->window_start).' bis '.$this->date($onboarding->window_end);
            if ($onboarding->auto_extend) {
                $lines[] = '(Nachzügler haben danach noch ein paar Tage Zeit — verlasst euch aber bitte nicht darauf.)';
            }
            $lines[] = '';
        }

        $products = $this->sheet->productRows($onboarding);
        if ($products !== []) {
            $lines[] = 'Im Shop gibt es:';
            foreach ($products as $row) {
                $lines[] = '– '.$row['name'].($row['sub'] !== '' ? ' '.$row['sub'] : '');
            }
            $lines[] = '– Pro verkauftem Produkt pflanzen wir einen Baum.';
            $lines[] = '';
        }

        $lines[] = 'Im Anhang findet ihr ein A4-Blatt zum Aushängen und Weiterschicken — mit QR-Code direkt';
        $lines[] = 'zur Bestellseite. Gerne auch in die Klassengruppen stellen.';
        $lines[] = '';

        if (filled($onboarding->class_list)) {
            $lines[] = 'Wichtig beim Bestellen: Bitte die Klasse auswählen — danach sortieren wir die Lieferung.';
            $lines[] = '';
        }

        $lines[] = 'Bei Fragen einfach auf diese Mail antworten.';
        $lines[] = '';
        $lines[] = 'Liebe Grüße';
        $lines[] = 'Wear Together';

        return implode("\n", $lines);
    }

    private function date(Carbon $date): string
    {
        return self::WEEKDAYS[(int) $date->isoWeekday()].', '.$date->format('d.m.Y');
    }
}
