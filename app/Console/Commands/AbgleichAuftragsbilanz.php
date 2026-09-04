<?php

namespace App\Console\Commands;

use App\Models\BalanceOrder;
use App\Services\Balance\BalanceReport;
use App\Services\Balance\ShopComparison;
use App\Services\Statistics\RevenueReport;
use App\Services\Statistics\SchoolYear;
use App\Services\Statistics\StatisticsFilters;
use Illuminate\Console\Command;

/**
 * Stellt die eingetragenen Online-Einnahmen dem gegenüber, was der Webshop
 * tatsächlich meldet — je Schuljahr, je Schule und je verknüpftem Auftrag.
 *
 * Gedacht als einmalige Kontrolle nach der Übernahme der Altdaten: Wo weichen
 * die Zahlen der Excel von der Wirklichkeit im Shop ab, und wo liegt die
 * Trennlinie `auftragsbilanz.shop_online_from_year` richtig?
 *
 * **Nur auf der Konsole.** Anders als jede Seite darf dieser Befehl fehlende
 * Monate nachholen — er läuft dadurch mehrere Minuten und fragt den Shop
 * hunderte Male, mit der eingebauten Pause zwischen den Anfragen. Auf einer
 * Seite wäre das genau die Bauart, die die Anwendung schon zweimal lahmgelegt
 * hat.
 */
class AbgleichAuftragsbilanz extends Command
{
    protected $signature = 'auftragsbilanz:abgleich
        {--jahr=* : Nur diese Schuljahre (Startjahr, z. B. 2024)}
        {--schulen : Zusätzlich je Schule aufschlüsseln}
        {--cache-only : Nichts nachladen, nur bereits geladene Monate verwenden}';

    protected $description = 'Eingetragene Online-Einnahmen gegen die Zahlen des Webshops halten';

    public function handle(BalanceReport $report, ShopComparison $comparison, RevenueReport $revenue): int
    {
        $years = $this->option('jahr') !== []
            ? array_values(array_filter(array_map(
                static fn ($value) => SchoolYear::parse((string) $value),
                (array) $this->option('jahr'),
            )))
            : $report->years();

        if ($years === []) {
            $this->info('Keine Aufträge vorhanden — nichts abzugleichen.');

            return self::SUCCESS;
        }

        $allowFetching = ! $this->option('cache-only');
        if ($allowFetching) {
            $this->warn('Dieser Abgleich holt fehlende Monate aus dem Shop nach und kann einige Minuten dauern.');
        }

        $grenze = (int) config('auftragsbilanz.shop_online_from_year');
        $zeilen = [];
        $offen = [];

        foreach ($years as $year) {
            $result = $comparison->forYear($year, $allowFetching);
            $jahr = $report->forYear($year);
            $gilt = $year->startYear >= $grenze;

            if (! $result['available']) {
                $offen[] = $year->label();
                $zeilen[] = [
                    $year->label(), $gilt ? 'Shop' : 'Eintrag', $jahr['orders'],
                    $this->euro($result['entered']), '— nicht geladen —', '', '',
                ];

                continue;
            }

            $zeilen[] = [
                $year->label(),
                $gilt ? 'Shop' : 'Eintrag',
                $jahr['orders'],
                $this->euro($result['entered']),
                $this->euro($result['shop']),
                ($result['difference'] > 0 ? '+' : '').$this->euro($result['difference']),
                $this->quote($result['share'], $result['mismatch']),
            ];
        }

        $this->newLine();
        $this->line('<options=bold>Online-Einnahmen: Eintrag gegen Webshop</>');
        $this->table(
            ['Schuljahr', 'Zählt aus', 'Aufträge', 'Eingetragen', 'Shop meldet', 'Unterschied', 'Abweichung'],
            $zeilen,
        );
        $this->line('„Zählt aus" sagt, welche Zahl die Statistik verwendet — die Trennlinie steht in '
            ."config('auftragsbilanz.shop_online_from_year') und liegt derzeit bei ".$grenze.'/'
            .substr((string) ($grenze + 1), -2).'.');

        if ($offen !== []) {
            $this->newLine();
            $this->warn('Für diese Schuljahre fehlen Shop-Daten: '.implode(', ', $offen)
                .'. Mit „php artisan statistics:warm --runs=20" aufbauen oder ohne --cache-only laufen lassen.');
        }

        if ($this->option('schulen')) {
            $this->bySchool($years, $report, $revenue, $allowFetching);
        }

        $this->unlinked();

        return self::SUCCESS;
    }

    /**
     * Je Schule: der Shop-Umsatz der Kategorie gegen die eingetragenen
     * Online-Einnahmen derselben Schule. Zugeordnet wird über den Namen —
     * genau hier fallen Schreibvarianten auf.
     *
     * @param  list<SchoolYear>  $years
     */
    private function bySchool(array $years, BalanceReport $report, RevenueReport $revenue, bool $allowFetching): void
    {
        foreach ($years as $year) {
            $data = $revenue->build($this->filtersFor($year), allowFetching: $allowFetching);
            if (! $data['complete']) {
                continue;
            }

            $shop = [];
            foreach ($data['current']['schools'] as $row) {
                $shop[$this->normalize($row['name'])] = ['name' => $row['name'], 'revenue' => (float) $row['revenue']];
            }

            $zeilen = [];
            foreach ($report->bySchool($year) as $row) {
                $key = $this->normalize($row['name']);
                $eingetragen = round((float) BalanceOrder::query()->ofYear($year)
                    ->where('school_name', $row['name'])->sum('revenue_online'), 2);
                $shopWert = $shop[$key]['revenue'] ?? null;
                unset($shop[$key]);

                if ($shopWert === null && $eingetragen <= 0.0) {
                    continue;
                }
                $unterschied = $shopWert === null ? null : round($shopWert - $eingetragen, 2);
                if ($unterschied !== null && abs($unterschied) < (float) config('auftragsbilanz.mismatch.amount')) {
                    continue;
                }

                $zeilen[] = [
                    $row['name'], $row['orders'], $this->euro($eingetragen),
                    $shopWert === null ? '— keine Kategorie —' : $this->euro($shopWert),
                    $unterschied === null ? '' : ($unterschied > 0 ? '+' : '').$this->euro($unterschied),
                ];
            }

            // Kategorien, zu denen es gar keinen Auftrag gibt — dort fehlt
            // ein Eintrag in der Auftragsbilanz.
            foreach ($shop as $rest) {
                if ($rest['revenue'] < (float) config('auftragsbilanz.mismatch.amount')) {
                    continue;
                }
                $zeilen[] = [$rest['name'], 0, $this->euro(0), $this->euro($rest['revenue']), '+'.$this->euro($rest['revenue'])];
            }

            if ($zeilen === []) {
                continue;
            }

            usort($zeilen, static fn ($a, $b) => abs((float) str_replace(['.', ',', ' €', '+'], ['', '.', '', ''], (string) $b[4]))
                <=> abs((float) str_replace(['.', ',', ' €', '+'], ['', '.', '', ''], (string) $a[4])));

            $this->newLine();
            $this->line("<options=bold>{$year->label()} — je Schule (nur Abweichungen)</>");
            $this->table(['Schule', 'Aufträge', 'Eingetragen', 'Shop meldet', 'Unterschied'], array_slice($zeilen, 0, 25));
        }
    }

    /**
     * Aufträge, deren Online-Einnahmen laut Einstellung aus dem Shop kommen
     * sollen, die aber an keinem Bestellfenster hängen. Für sie kann die
     * Software nichts nachtragen — sie bleiben auf dem Excel-Stand stehen.
     */
    private function unlinked(): void
    {
        $offen = BalanceOrder::query()
            ->where('online_source', 'shop')
            ->whereNull('school_onboarding_id')
            ->where('revenue_online', '>', 0)
            ->get();

        if ($offen->isEmpty()) {
            return;
        }

        $summe = round($offen->sum('revenue_online'), 2);
        $this->newLine();
        $this->warn("{$offen->count()} Aufträge über zusammen {$this->euro($summe)} stehen auf „aus dem Webshop\", "
            .'hängen aber an keinem Bestellfenster — für sie kann nichts nachgetragen werden. '
            .'Sie behalten den Wert aus der Excel.');

        $this->table(
            ['Auftrag', 'Schuljahr', 'Eingetragen'],
            $offen->sortByDesc('revenue_online')->take(15)
                ->map(fn (BalanceOrder $o) => [$o->label(), $o->schoolYear()->label(), $this->euro($o->revenue_online)])
                ->values()->all(),
        );
    }

    private function filtersFor(SchoolYear $year): StatisticsFilters
    {
        return new StatisticsFilters(
            year: $year,
            deliveryType: 'all',
            schoolId: null,
            paddingBefore: (int) config('statistics.window_padding.before'),
            paddingAfter: (int) config('statistics.window_padding.after'),
            statuses: (array) config('ordersuite.woocommerce.default_statuses'),
            fresh: false,
        );
    }

    private function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9äöüß]/iu', '', mb_strtolower(trim($name))) ?? '';
    }

    private function euro(?float $value): string
    {
        return $value === null ? '–' : number_format($value, 2, ',', '.').' €';
    }

    private function quote(?float $share, bool $mismatch): string
    {
        if ($share === null) {
            return '';
        }
        $text = number_format($share * 100, 1, ',', '.').' %';

        return $mismatch ? "<fg=yellow>{$text}</>" : $text;
    }
}
