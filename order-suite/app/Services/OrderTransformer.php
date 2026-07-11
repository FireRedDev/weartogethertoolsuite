<?php

namespace App\Services;

/**
 * Exakte Nachbildung der Transformations-Pipeline aus
 * wear_together_toolsuite.py @ cff1227 (AGENTIC_INTENT_SPEC.md Kapitel 4.3).
 *
 * Die Schritt-Nummern in den Kommentaren verweisen auf die Spezifikation.
 */
class OrderTransformer
{
    public const INDIV_TEXT_COLUMN = 'Individualisierungstext(zählt nur wenn Individualisierung Ja)';

    public const WARNING_COLUMN = '⚠ Fehlender Individualisierungstext';

    /** @var list<string> */
    private array $columns = [];

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /**
     * @param  array{columns: list<string>, rows: list<array<string, mixed>>}  $table
     */
    public function transform(array $table): TransformResult
    {
        $this->columns = $table['columns'];
        $this->rows = $table['rows'];
        $sizes = config('ordersuite.sizes');
        $sizeIndex = array_flip($sizes);

        // Schritt 1: Klasse aus "Product Variation" (3. Pipe-Segment, "Klasse:" entfernt, KEIN Trim)
        foreach ($this->rows as &$row) {
            $pv = self::pyStr($row['Product Variation'] ?? null);
            $parts = explode('|', $pv, 5);
            $row['Klasse'] = array_key_exists(2, $parts)
                ? str_replace('Klasse:', '', $parts[2])
                : null;
        }
        unset($row);
        $this->ensureColumn('Klasse');

        // Schritt 2: Header normalisieren
        $this->renameColumn('Item Name(löschen)', 'Produktname');
        $this->renameColumn('Anzahl ', 'Anzahl');

        // Schritt 3: Mengen-Expansion (1 Zeile pro Stück)
        $expanded = [];
        foreach ($this->rows as $row) {
            $count = (int) ($row['Anzahl'] ?? 0);
            for ($i = 0; $i < $count; $i++) {
                $expanded[] = $row;
            }
        }
        $this->rows = $expanded;

        // Schritt 4: Spalten verwerfen
        foreach (['Anzahl', 'Product Variation', 'Bestellnotiz', 'Bestellung Gesamtsumme(löschen)'] as $drop) {
            $this->dropColumn($drop);
        }

        // Schritt 5: Größen-Ordnung (unbekannte Größen => null, wie NaN)
        foreach ($this->rows as &$row) {
            $g = $row['Größe'] ?? null;
            $row['Größe'] = ($g !== null && isset($sizeIndex[(string) $g])) ? (string) $g : null;
        }
        unset($row);

        // Schritt 6: Sortierung 1 (stabil): Klasse, Produktname, Farbe, Größe
        $this->stableSort(fn (array $a, array $b): int => $this->compareBy($a, $b, ['Klasse', 'Produktname', 'Farbe'], $sizeIndex));

        // Schritt 7: Individualisierungstext extrahieren (50-Zeichen-Schnitt, "nan" => "", Trim)
        $prefixLength = (int) config('ordersuite.indiv_prefix_length');
        foreach ($this->rows as &$row) {
            $raw = ($row['Individualisierung'] ?? null) === 'Ja' ? ($row['Input Fields'] ?? null) : '';
            $text = mb_substr(self::pyStr($raw), $prefixLength);
            if ($text === 'nan') {
                $text = '';
            }
            $row[self::INDIV_TEXT_COLUMN] = self::pyStrip($text);
        }
        unset($row);
        $this->ensureColumn(self::INDIV_TEXT_COLUMN);

        // Schritt 8: Prüfspalte
        foreach ($this->rows as &$row) {
            $row[self::WARNING_COLUMN] =
                ($row['Individualisierung'] ?? null) === 'Ja' && $row[self::INDIV_TEXT_COLUMN] === ''
                    ? 'TRUE' : '';
        }
        unset($row);
        $this->ensureColumn(self::WARNING_COLUMN);

        // Schritt 9: Kartonzuteilung auf Basis von Sortierung 1
        $kartonSize = (int) config('ordersuite.karton_size');
        foreach ($this->rows as $i => &$row) {
            $row['Karton'] = intdiv($i, $kartonSize) + 1;
        }
        unset($row);
        $this->ensureColumn('Karton');

        // Schritt 10: Input Fields verwerfen
        $this->dropColumn('Input Fields');

        // Schritt 11: Sortierung 2 (stabil): Karton, Klasse, Produktname, Farbe, Größe
        $this->stableSort(function (array $a, array $b) use ($sizeIndex): int {
            $cmp = $a['Karton'] <=> $b['Karton'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return $this->compareBy($a, $b, ['Klasse', 'Produktname', 'Farbe'], $sizeIndex);
        });

        // Schritt 12: Konstantspalten
        foreach ($this->rows as &$row) {
            $row['Checkbox'] = '☐';
            $row['Anzahl'] = 1;
        }
        unset($row);
        $this->ensureColumn('Checkbox');
        $this->ensureColumn('Anzahl');

        // Schritt 13: Pivot "Übersicht_Tabelle" (Zeilen mit null-Schlüssel fallen heraus,
        // auch aus den Grand Totals — pandas dropna-Verhalten, verifiziert)
        [$pivotRows, $grandTotals] = $this->buildPivot($sizeIndex);

        // Schritt 14: "Übersicht_Liste" mit Lieferanten-Artikelmapping
        $pivotList = $this->buildPivotList($pivotRows, $grandTotals);

        // Schritt 15: ID-Spalte vorn einfügen
        foreach ($this->rows as $i => &$row) {
            $row['ID'] = $i + 1;
        }
        unset($row);
        array_unshift($this->columns, 'ID');

        return new TransformResult(
            ordersColumns: $this->columns,
            ordersRows: $this->rows,
            pivotRows: $pivotRows,
            grandTotals: $grandTotals,
            pivotListColumns: ['Produktname', 'Farbe', 'Größe', 'Anzahl gesamt', 'Davon Personalisierungen', 'Kartonnummer', 'Ausschuss', 'Anmerkungen', 'Produktname-Lieferant'],
            pivotListRows: $pivotList,
        );
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array{count: int, personalized: int}}
     */
    private function buildPivot(array $sizeIndex): array
    {
        $groups = [];
        $totalCount = 0;
        $totalJa = 0;
        foreach ($this->rows as $row) {
            $prod = $row['Produktname'] ?? null;
            $farbe = $row['Farbe'] ?? null;
            $groesse = $row['Größe'] ?? null;
            if ($prod === null || $farbe === null || $groesse === null) {
                continue; // pandas: NaN-Gruppenschlüssel werden verworfen (inkl. Margins)
            }
            $key = $prod."\x1f".$farbe."\x1f".$groesse;
            if (! isset($groups[$key])) {
                $groups[$key] = ['Produktname' => (string) $prod, 'Farbe' => (string) $farbe, 'Größe' => (string) $groesse, 'Anzahl gesamt' => 0, 'Davon Personalisierungen' => 0];
            }
            $groups[$key]['Anzahl gesamt']++;
            $totalCount++;
            if (($row['Individualisierung'] ?? null) === 'Ja') {
                $groups[$key]['Davon Personalisierungen']++;
                $totalJa++;
            }
        }

        $pivotRows = array_values($groups);
        usort($pivotRows, function (array $a, array $b) use ($sizeIndex): int {
            $cmp = strcmp($a['Produktname'], $b['Produktname']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['Farbe'], $b['Farbe']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $sizeIndex[$a['Größe']] <=> $sizeIndex[$b['Größe']];
        });

        return [$pivotRows, ['count' => $totalCount, 'personalized' => $totalJa]];
    }

    /**
     * @param  list<array<string, mixed>>  $pivotRows
     * @param  array{count: int, personalized: int}  $grandTotals
     * @return list<array<string, mixed>>
     */
    private function buildPivotList(array $pivotRows, array $grandTotals): array
    {
        $map = config('ordersuite.supplier_map');
        $list = [];
        foreach ($pivotRows as $row) {
            $row['Kartonnummer'] = '';
            $row['Ausschuss'] = '';
            $row['Anmerkungen'] = '';
            $row['Produktname-Lieferant'] = str_replace(array_keys($map), array_values($map), $row['Produktname']);
            $list[] = $row;
        }
        $list[] = [
            'Produktname' => 'Grand Totals', 'Farbe' => '', 'Größe' => '',
            'Anzahl gesamt' => $grandTotals['count'], 'Davon Personalisierungen' => $grandTotals['personalized'],
            'Kartonnummer' => '', 'Ausschuss' => '', 'Anmerkungen' => '',
            'Produktname-Lieferant' => str_replace(array_keys($map), array_values($map), 'Grand Totals'),
        ];

        return $list;
    }

    /**
     * Vergleich wie pandas sort_values: Strings nach Codepoint, null/NaN ans Ende,
     * Größe nach kategorialer Ordnung.
     *
     * @param  list<string>  $stringKeys
     */
    private function compareBy(array $a, array $b, array $stringKeys, array $sizeIndex): int
    {
        foreach ($stringKeys as $key) {
            $cmp = self::compareNullable($a[$key] ?? null, $b[$key] ?? null);
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        $ga = $a['Größe'] ?? null;
        $gb = $b['Größe'] ?? null;

        return self::compareNullable(
            $ga === null ? null : $sizeIndex[$ga],
            $gb === null ? null : $sizeIndex[$gb],
        );
    }

    private static function compareNullable(mixed $a, mixed $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1; // NaN sortiert ans Ende
        }
        if ($b === null) {
            return -1;
        }
        if (is_int($a) && is_int($b)) {
            return $a <=> $b;
        }

        return strcmp((string) $a, (string) $b);
    }

    private function stableSort(callable $comparator): void
    {
        usort($this->rows, $comparator); // usort ist ab PHP 8.0 stabil
        $this->rows = array_values($this->rows);
    }

    private function ensureColumn(string $name): void
    {
        if (! in_array($name, $this->columns, true)) {
            $this->columns[] = $name;
        }
    }

    private function renameColumn(string $from, string $to): void
    {
        $pos = array_search($from, $this->columns, true);
        if ($pos === false) {
            return;
        }
        $this->columns[$pos] = $to;
        foreach ($this->rows as &$row) {
            $row[$to] = $row[$from];
            unset($row[$from]);
        }
        unset($row);
    }

    private function dropColumn(string $name): void
    {
        $pos = array_search($name, $this->columns, true);
        if ($pos === false) {
            throw new \RuntimeException("Pflichtspalte '{$name}' fehlt im Shop-Export.");
        }
        array_splice($this->columns, $pos, 1);
        foreach ($this->rows as &$row) {
            unset($row[$name]);
        }
        unset($row);
    }

    /** Python str()-Äquivalent für Zellwerte (NaN => "nan"). */
    public static function pyStr(mixed $value): string
    {
        if ($value === null) {
            return 'nan';
        }
        if (is_float($value) && floor($value) === $value) {
            return number_format($value, 1, '.', '');
        }

        return (string) $value;
    }

    /** Python str.strip(): Whitespace inkl. \n \t \r an beiden Enden. */
    public static function pyStrip(string $value): string
    {
        return trim($value, " \t\n\r\0\x0B\x0C");
    }
}
