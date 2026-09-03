<?php

namespace App\Console\Commands;

use App\Models\BalanceOrder;
use App\Models\SchoolOnboarding;
use App\Services\Statistics\SchoolYear;
use Illuminate\Console\Command;

/**
 * Übernimmt die Altdaten aus `Auftragsbilanz_gesamt.xlsx` in die Datenbank.
 *
 * Die Datei `database/data/auftragsbilanz.json` ist der eingefrorene Stand der
 * Excel vom 08.07.2026 — bewusst als Datei im Repo und nicht als Migration:
 * Die Daten sind Inhalt, keine Struktur, und der Import soll wiederholbar sein,
 * ohne die Datenbank zurückzusetzen.
 *
 * Wiederholtes Ausführen legt nichts doppelt an: Ein Auftrag ist durch Nummer,
 * Schulname und Schuljahr bestimmt. Von Hand geänderte Beträge werden dabei
 * überschrieben — deshalb fragt der Befehl nach, wenn schon Aufträge da sind.
 */
class ImportAuftragsbilanz extends Command
{
    protected $signature = 'auftragsbilanz:import
        {--file= : Abweichende Importdatei}
        {--force : Ohne Rückfrage überschreiben}
        {--dry-run : Nur anzeigen, was passieren würde}';

    protected $description = 'Die Aufträge aus der bisherigen Excel-Auftragsbilanz übernehmen';

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/auftragsbilanz.json');

        if (! is_file($path)) {
            $this->error("Importdatei nicht gefunden: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows) || $rows === []) {
            $this->error('Die Importdatei enthält keine Aufträge.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $existing = BalanceOrder::query()->count();

        if ($existing > 0 && ! $dryRun && ! $this->option('force')
            && ! $this->confirm("Es sind bereits {$existing} Aufträge gespeichert. Übereinstimmende Zeilen werden überschrieben. Fortfahren?")) {
            $this->info('Abgebrochen.');

            return self::SUCCESS;
        }

        $shopFrom = (int) config('auftragsbilanz.shop_online_from_year');
        $created = 0;
        $updated = 0;
        $linked = 0;

        foreach ($rows as $row) {
            $year = new SchoolYear((int) $row['school_year']);
            $onboarding = $this->matchOnboarding((string) $row['school_name'], $year);

            $attributes = [
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
            ];

            if ($onboarding !== null) {
                $linked++;
            }

            if ($dryRun) {
                $created++;

                continue;
            }

            $order = BalanceOrder::query()->firstOrNew([
                'number' => (string) $row['number'],
                'school_name' => (string) $row['school_name'],
                'school_year' => (int) $row['school_year'],
            ]);

            $order->exists ? $updated++ : $created++;
            $order->fill($attributes)->save();
        }

        if ($dryRun) {
            $this->info("Probelauf: {$created} Aufträge würden übernommen, {$linked} davon mit einem Antrag verknüpft.");

            return self::SUCCESS;
        }

        $this->info("{$created} Aufträge angelegt, {$updated} aktualisiert, {$linked} mit einem Antrag verknüpft.");

        return self::SUCCESS;
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
            ->filter(fn (SchoolOnboarding $o) => $o->window_end !== null
                && $year->contains($o->window_end));

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
