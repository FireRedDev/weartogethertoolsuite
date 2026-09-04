<?php

namespace App\Services\Balance;

use App\Models\BalanceOrder;
use App\Models\SchoolOnboarding;
use App\Services\Statistics\SchoolYear;

/**
 * Übernimmt die Altdaten aus `Auftragsbilanz_gesamt.xlsx` in die Datenbank.
 *
 * Die Datei `database/data/auftragsbilanz.json` ist der eingefrorene Stand der
 * Excel vom 08.07.2026 — bewusst als Datei im Repo und nicht als Migration
 * voller INSERTs: Die Daten sind Inhalt, keine Struktur, und der Vorgang soll
 * wiederholbar sein, ohne die Datenbank zurückzusetzen.
 *
 * Aufgerufen wird er von der Migration (damit beim Deploy nichts von Hand
 * nachzuholen ist) und vom Befehl `auftragsbilanz:import`.
 *
 * Wiederholtes Ausführen legt nichts doppelt an: Ein Auftrag ist durch Nummer,
 * Schulname und Schuljahr bestimmt. Bereits vorhandene Zeilen werden per
 * Voreinstellung NICHT überschrieben — sonst würde eine zweite Migration von
 * Hand nachgetragene Beträge wieder auf den Excel-Stand zurücksetzen.
 */
class AuftragsbilanzImporter
{
    /**
     * @param  bool  $overwrite  Bestehende Zeilen auf den Stand der Datei zurücksetzen
     * @return array{created: int, skipped: int, updated: int, linked: int}
     */
    public function import(?string $path = null, bool $overwrite = false, bool $dryRun = false): array
    {
        $path = $path ?? database_path('data/auftragsbilanz.json');
        $rows = json_decode((string) file_get_contents($path), true);

        if (! is_array($rows) || $rows === []) {
            throw new \RuntimeException("Die Importdatei enthält keine Aufträge: {$path}");
        }

        $shopFrom = (int) config('auftragsbilanz.shop_online_from_year');
        $result = ['created' => 0, 'skipped' => 0, 'updated' => 0, 'linked' => 0];

        foreach ($rows as $row) {
            $year = new SchoolYear((int) $row['school_year']);

            $order = BalanceOrder::query()->firstOrNew([
                'number' => (string) $row['number'],
                'school_name' => (string) $row['school_name'],
                'school_year' => (int) $row['school_year'],
            ]);

            if ($order->exists && ! $overwrite) {
                $result['skipped']++;

                continue;
            }

            $onboarding = $this->matchOnboarding((string) $row['school_name'], $year);
            if ($onboarding !== null) {
                $result['linked']++;
            }

            $order->exists ? $result['updated']++ : $result['created']++;

            if ($dryRun) {
                continue;
            }

            $order->fill([
                // Ohne eigenes Datum in der Excel: das Schuljahresende. Als
                // Schätzung gekennzeichnet, damit niemand die Monatsverteilung
                // der Altjahre für eine echte Beobachtung hält.
                'ordered_on' => $year->end()->startOfDay(),
                'date_is_estimate' => true,
                'delivery_type' => $onboarding?->delivery_type === 'ondemand' ? 'ondemand' : null,
                'school_onboarding_id' => $onboarding?->id,
                'woo_category_id' => $onboarding?->woo_category_id,
                // Ab dem Jahr, in dem der eigene Webshop lief, gilt die
                // Shop-Zahl — sonst stünde derselbe Umsatz zweimal da.
                'online_source' => (int) $row['school_year'] >= $shopFrom ? 'shop' : 'manual',
                'revenue_online' => (float) $row['revenue_online'],
                'revenue_online_excel' => (float) $row['revenue_online'],
                'revenue_cash' => (float) $row['revenue_cash'],
                'commission' => (float) $row['commission'],
                'expenses' => (float) $row['expenses'],
                'vat' => (float) $row['vat'],
                'products' => $row['products'],
                'individual' => (int) $row['individual'],
                'note' => $row['note'],
                'source' => 'excel',
            ])->save();
        }

        return $result;
    }

    /**
     * Der passende Onboarding-Antrag zu einem Altauftrag — nur bei einem
     * eindeutigen Treffer.
     *
     * Absichtlich streng: Name exakt gleich UND dasselbe Schuljahr. Eine
     * Teilstringsuche würde „HAK Wien" an „HAK Wien 13" hängen, und ohne den
     * Jahresvergleich landete ein Auftrag von 2019 am Bestellfenster von 2025.
     */
    private function matchOnboarding(string $schoolName, SchoolYear $year): ?SchoolOnboarding
    {
        $matches = SchoolOnboarding::query()
            ->whereRaw('LOWER(school_name) = ?', [mb_strtolower(trim($schoolName))])
            ->get()
            ->filter(fn (SchoolOnboarding $o) => $o->window_end !== null && $year->contains($o->window_end));

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
