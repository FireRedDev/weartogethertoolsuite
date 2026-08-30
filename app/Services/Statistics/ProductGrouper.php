<?php

namespace App\Services\Statistics;

/**
 * Fasst Bestellpositionen zu **Produktarten** zusammen.
 *
 * Die Frage der Rangliste lautet „wurde mehr Schulshirt oder mehr
 * Schulpullover verkauft?" — nicht „welches Shop-Produkt hatte den Namen X".
 * Im Shop heißt jedes Produkt anders, weil der Schulname im Namen steckt
 * („BG Korneuburg Schulhoodie", „HAK Wien STICK-Hoodie + Backprint"). Nach
 * Produktnamen zu gruppieren ergibt deshalb eine Liste, in der jede Schule
 * eigene Zeilen hat — nutzlos für den Vergleich.
 *
 * Stattdessen wird der Positionsname gegen eine Liste von Suchbegriffen
 * geprüft: der erste Treffer bestimmt die Produktart. Die Begriffe kommen aus
 * dem Produktkatalog (`schoolshop.catalog` → `name_suffix`), ergänzt um
 * historische Schreibweisen (`statistics.product_group_aliases`).
 *
 * Die Reihenfolge ist entscheidend: „Schulpullover Kids" muss vor
 * „Schulpullover" geprüft werden, sonst landet die Kinderversion beim
 * Erwachsenenprodukt. Deshalb wird nach Länge absteigend sortiert.
 */
class ProductGrouper
{
    /** @var list<array{label: string, needle: string}>|null */
    private ?array $needles = null;

    /**
     * Produktart einer Position. Passt nichts, bleibt der bereinigte
     * Produktname stehen (ohne Schulnamen), damit nichts verschwindet.
     */
    public function group(string $productName, ?string $schoolName = null): string
    {
        $haystack = mb_strtolower($productName);

        foreach ($this->needles() as $entry) {
            if (str_contains($haystack, $entry['needle'])) {
                return $entry['label'];
            }
        }

        return $this->fallbackLabel($productName, $schoolName);
    }

    /**
     * Suchbegriffe, längste zuerst.
     *
     * @return list<array{label: string, needle: string}>
     */
    private function needles(): array
    {
        if ($this->needles !== null) {
            return $this->needles;
        }

        $entries = [];

        // 1) Der Produktkatalog der Toolsuite — wächst automatisch mit, wenn
        //    dort ein Produkt ergänzt wird.
        foreach (config('schoolshop.catalog', []) as $product) {
            $suffix = trim((string) ($product['name_suffix'] ?? ''));
            if ($suffix !== '') {
                $entries[] = ['label' => $suffix, 'needle' => mb_strtolower($suffix)];
            }
        }

        // 2) Historische und abweichende Schreibweisen aus dem Shop.
        foreach (config('statistics.product_group_aliases', []) as $label => $aliases) {
            foreach ((array) $aliases as $alias) {
                $alias = trim((string) $alias);
                if ($alias !== '') {
                    $entries[] = ['label' => (string) $label, 'needle' => mb_strtolower($alias)];
                }
            }
        }

        // Längster Suchbegriff gewinnt: „Schulpullover Kids" vor „Schulpullover".
        usort($entries, static fn ($a, $b) => mb_strlen($b['needle']) <=> mb_strlen($a['needle']));

        // Doppelte Begriffe entfernen (erster Eintrag gewinnt)
        $seen = [];
        $unique = [];
        foreach ($entries as $entry) {
            if (isset($seen[$entry['needle']])) {
                continue;
            }
            $seen[$entry['needle']] = true;
            $unique[] = $entry;
        }

        return $this->needles = $unique;
    }

    /** Unbekanntes Produkt: Schulname und Variantenzusatz abschneiden. */
    private function fallbackLabel(string $productName, ?string $schoolName): string
    {
        $name = $productName;
        if ($schoolName !== null && $schoolName !== '') {
            $name = str_ireplace($schoolName, '', $name);
        }
        // Variantenzusatz der API („… - Blau, M")
        $name = preg_replace('/\s+-\s+[^-]*$/u', '', $name) ?? $name;
        $name = trim(preg_replace('/\s{2,}/u', ' ', $name) ?? $name);

        return $name !== '' ? $name : $productName;
    }
}
