<?php

namespace App\Services;

/**
 * Prüfbericht (Spezifikation K6): blockierende Fehler + Warnungen.
 * Warnungen blockieren die Generierung nie — sie machen sichtbar, was das
 * Legacy-Verhalten still verändert hätte (z. B. Pivot-Ausschluss unbekannter Größen).
 */
class OrderValidator
{
    private const INDIV_PREFIX = 'Individualisierungstext';

    /**
     * @param  array{columns: list<string>, rows: list<array<string, mixed>>}  $table
     * @return array{errors: list<string>, warnings: list<array{type: string, message: string, rows: list<int>}>}
     */
    public function validate(array $table): array
    {
        $columns = $table['columns'];
        $rows = $table['rows'];
        $errors = [];
        $warnings = [];

        $required = [
            ['Item Name(löschen)', 'Produktname'],
            ['Anzahl', 'Anzahl '],
            ['Größe'],
            ['Farbe'],
            ['Individualisierung'],
            ['Input Fields'],
            ['Product Variation'],
            ['Bestellnotiz'],
            ['Bestellung Gesamtsumme(löschen)'],
        ];
        foreach ($required as $alternatives) {
            if (array_intersect($alternatives, $columns) === []) {
                $errors[] = "Pflichtspalte '{$alternatives[0]}' fehlt im Shop-Export. Bitte den Standard-Export des Shops verwenden.";
            }
        }
        if ($rows === []) {
            $errors[] = 'Die Datei enthält keine Bestellpositionen.';
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'warnings' => []];
        }

        $sizes = config('ordersuite.sizes');
        $anzahlKey = in_array('Anzahl', $columns, true) ? 'Anzahl' : 'Anzahl ';
        $nameKey = in_array('Produktname', $columns, true) ? 'Produktname' : 'Item Name(löschen)';

        $unknownSizes = [];
        $missingIndivText = [];
        $unexpectedPrefix = [];
        $emptyClass = [];
        $unknownIndiv = [];
        $invalidAnzahl = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // Excel-Zeilennummer

            $size = $row['Größe'] ?? null;
            if ($size !== null && ! in_array((string) $size, $sizes, true)) {
                $unknownSizes[] = $line;
            }

            $anzahl = $row[$anzahlKey] ?? null;
            if (! is_numeric($anzahl) || (int) $anzahl < 1) {
                $invalidAnzahl[] = $line;
            }

            $indiv = $row['Individualisierung'] ?? null;
            if ($indiv !== null && ! in_array($indiv, ['Ja', 'Nein'], true)) {
                $unknownIndiv[] = $line;
            }
            if ($indiv === 'Ja') {
                $inputFields = $row['Input Fields'] ?? null;
                $text = mb_substr(OrderTransformer::pyStr($inputFields), (int) config('ordersuite.indiv_prefix_length'));
                if ($text === 'nan' || OrderTransformer::pyStrip($text) === '') {
                    $missingIndivText[] = $line;
                }
                if ($inputFields !== null && ! str_contains((string) $inputFields, self::INDIV_PREFIX)) {
                    $unexpectedPrefix[] = $line;
                }
            }

            $pv = OrderTransformer::pyStr($row['Product Variation'] ?? null);
            $parts = explode('|', $pv, 5);
            $klasse = array_key_exists(2, $parts) ? trim(str_replace('Klasse:', '', $parts[2])) : '';
            if ($klasse === '') {
                $emptyClass[] = $line;
            }
        }

        if ($unknownSizes !== []) {
            $warnings[] = [
                'type' => 'unknown_size',
                'message' => 'Unbekannte Größen (nicht XS–XXXL): Diese Stücke fehlen in den Übersichts-Sheets (inkl. Grand Totals) und sortieren in der Bestellliste ans Ende — Legacy-Verhalten.',
                'rows' => $unknownSizes,
            ];
        }
        if ($missingIndivText !== []) {
            $warnings[] = [
                'type' => 'missing_indiv_text',
                'message' => 'Individualisierung „Ja" ohne verwertbaren Text: erscheint im internen Report in der Prüfspalte als TRUE.',
                'rows' => $missingIndivText,
            ];
        }
        if ($unexpectedPrefix !== []) {
            $warnings[] = [
                'type' => 'unexpected_prefix',
                'message' => 'Der Individualisierungstext hat nicht das erwartete WooCommerce-Präfix — der 50-Zeichen-Schnitt könnte Text verstümmeln.',
                'rows' => $unexpectedPrefix,
            ];
        }
        if ($emptyClass !== []) {
            $warnings[] = [
                'type' => 'empty_class',
                'message' => 'Keine Klasse in „Product Variation" gefunden (3. Segment). Diese Stücke sortieren in der Bestellliste ans Ende.',
                'rows' => $emptyClass,
            ];
        }
        if ($unknownIndiv !== []) {
            $warnings[] = [
                'type' => 'unknown_indiv',
                'message' => 'Wert in „Individualisierung" ist weder „Ja" noch „Nein" — wird wie „Nein" behandelt.',
                'rows' => $unknownIndiv,
            ];
        }
        if ($invalidAnzahl !== []) {
            $errors[] = 'Spalte „'.$anzahlKey.'" enthält fehlende oder ungültige Mengen (Zeilen '.implode(', ', array_slice($invalidAnzahl, 0, 20)).'). Bitte Export korrigieren.';
        }

        // Hinweis auf Positionen ohne Produktname (würden im Pivot fehlen)
        $noName = [];
        foreach ($rows as $i => $row) {
            if (($row[$nameKey] ?? null) === null) {
                $noName[] = $i + 2;
            }
        }
        if ($noName !== []) {
            $warnings[] = [
                'type' => 'missing_product',
                'message' => 'Positionen ohne Produktname: fehlen in den Übersichts-Sheets.',
                'rows' => $noName,
            ];
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
