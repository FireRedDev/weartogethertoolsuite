<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;

/**
 * Bereitet die Bestellemail an die Partnerdruckerei vor (Sammelbestellfenster),
 * nach der Vorlage "Email Vorlage für Sammelbestellungen".
 */
class OrderEmailGenerator
{
    public function subject(SchoolOnboarding $onboarding): string
    {
        return 'Auftrag '.($onboarding->id + 0).' – '.$onboarding->school_name;
    }

    public function body(SchoolOnboarding $onboarding, string $liefertermin = ''): string
    {
        $address = $onboarding->address ?? [];
        $addressLines = array_filter([
            $onboarding->school_name,
            trim(($address['line1'] ?? '').' '.($address['line2'] ?? '')),
            trim(($address['zip'] ?? '').' '.($address['city'] ?? '')),
            $address['country'] ?? 'Austria',
        ]);

        $productLines = [];
        foreach ($onboarding->enabledProducts() as $product) {
            $preset = config("schoolshop.catalog.{$product['key']}");
            $code = $preset['supplier_code'] !== '' ? ' – '.$preset['supplier_code'] : '';
            $productLines[] = $preset['name_suffix'].$code.' in '.implode(', ', $product['colors']);
        }

        $printAreas = $onboarding->print_areas ?? [];
        $veredelung = [];
        $veredelung[] = in_array('Frontprint', $printAreas, true)
            ? 'Frontprint in einer proportionalen Breite von 8cm auf linker Brust'
            : 'Kein Frontprint.';
        $veredelung[] = in_array('Backprint', $printAreas, true)
            ? 'Backprint lt. Vorlage'
            : 'Kein Backprint.';
        $veredelung[] = 'Individualisierungen im Flexdruck 3cm unter Logo vorne auf der linken Brust.';
        $veredelung[] = 'Farbwechsel je nach Produktfarbe lt. Einzelansichten beachten.';
        if ($onboarding->logo_notes) {
            $veredelung[] = 'Hinweis Logo-Positionierung: '.$onboarding->logo_notes;
        }

        return implode("\n", [
            'Guten Tag,',
            '',
            'anbei die Infos zum Auftrag '.$onboarding->id.' – '.$onboarding->school_name.':',
            '',
            'Lieferadresse:',
            ...$addressLines,
            '',
            'Lieferdatum: '.($liefertermin !== '' ? $liefertermin : 'bestmöglich'),
            '',
            'Produkte:',
            ...$productLines,
            '',
            'Veredelung:',
            ...$veredelung,
            '',
            'Mengen bitte aus Excelliste entnehmen. Bitte um Bearbeitung, vielen Dank!',
            '',
            'Liebe Grüße, das wear Together Team',
        ]);
    }
}
