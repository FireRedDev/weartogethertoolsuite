<?php

namespace App\Services\Statistics;

use App\Exceptions\WooCommerceApiException;
use Illuminate\Support\Facades\Cache;

/**
 * Baut den Datenbestand der Statistik im Hintergrund auf.
 *
 * Warum überhaupt im Hintergrund: Ein Schuljahr eines gut gefüllten Shops sind
 * tausende Bestellungen; abgerufen wird deshalb monatsweise (siehe
 * OrderRepository). Beim ersten Aufruf sind das je nach Filter über zwanzig
 * Monate — das dauert länger, als ein Seitenaufruf dauern darf.
 *
 * Deshalb:
 *  - Die Seite selbst ruft **nie** den Shop auf. Sie zeigt entweder die
 *    fertige Auswertung (alles im Zwischenspeicher) oder eine Ladeseite mit
 *    Fortschrittsbalken.
 *  - Der eigentliche Aufbau läuft, **nachdem** die Antwort beim Browser ist
 *    (`app()->terminating()`), zusätzlich `ignore_user_abort` — er läuft also
 *    weiter, wenn jemand die Seite verlässt oder den Tab schließt.
 *  - **Immer nur ein Durchgang gleichzeitig** (Sperre) und eine Pause zwischen
 *    den Monaten. Der Webshop läuft auf demselben Server; er darf durch die
 *    Auswertung nicht langsam werden.
 *  - Jeder fertige Monat bleibt gespeichert. Ein abgebrochener Durchgang
 *    verliert nichts, der nächste macht dort weiter.
 */
class StatisticsWarmer
{
    private const LOCK = 'statistics.warm.lock';

    private const RUNNING = 'statistics.warm.running';

    private const ERROR = 'statistics.warm.error';

    public function __construct(private readonly OrderRepository $repository) {}

    /**
     * Wie weit ist der Aufbau? Reine Zwischenspeicher-Abfrage, ohne jeden
     * Shop-Aufruf — diese Methode muss immer sofort antworten.
     *
     * @return array{loaded: int, total: int, percent: int, done: bool, running: bool, error: ?array{message: string, technical: string}}
     */
    public function progress(StatisticsFilters $filters): array
    {
        $steps = $this->steps($filters);
        $loaded = ($this->repository->hasProducts() ? 1 : 0) + ($this->repository->hasCategories() ? 1 : 0);
        foreach ($steps as $month) {
            if ($this->repository->isMonthCached($month['key'], $filters->statuses)) {
                $loaded++;
            }
        }

        $total = count($steps) + 2; // + Produktkatalog + Kategorien (= Schulen)

        return [
            'loaded' => $loaded,
            'total' => $total,
            'percent' => $total > 0 ? (int) floor($loaded / $total * 100) : 100,
            'done' => $loaded >= $total,
            'running' => (bool) Cache::get(self::RUNNING, false),
            'error' => Cache::get(self::ERROR),
        ];
    }

    /**
     * Einen Durchgang laufen lassen: fehlende Monate der Reihe nach holen, mit
     * Pause dazwischen, bis alles da ist oder das Zeitbudget aufgebraucht ist.
     *
     * Läuft ein Durchgang bereits, kehrt die Methode sofort zurück — sonst
     * würden mehrere Aufrufe den Shop gleichzeitig belasten.
     *
     * @return array{ran: bool, fetched: int}
     */
    public function warm(StatisticsFilters $filters, ?float $budgetSeconds = null): array
    {
        // Sperr-Laufzeit knapp über dem Budget: stirbt ein Durchgang
        // unsanft, blockiert er den nächsten nicht minutenlang.
        $lock = Cache::lock(self::LOCK, (int) ($budgetSeconds ?? config('statistics.warm_budget_seconds')) + 60);
        if (! $lock->get()) {
            return ['ran' => false, 'fetched' => 0];
        }

        // Der Aufbau soll auch dann fertig werden, wenn der Browser weg ist.
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit((int) ($budgetSeconds ?? config('statistics.warm_budget_seconds')) + 60);
        }

        $budget = (float) ($budgetSeconds ?? config('statistics.warm_budget_seconds'));
        $deadline = microtime(true) + $budget;
        $pause = max(0, (int) config('statistics.pause_ms')) * 1000;
        $fetched = 0;

        // Laufzeit-Marke genauso lang halten wie die Sperre. Wäre sie länger,
        // meldete die Ladeseite nach einem harten Abbruch (Deploy, Speichernot)
        // noch minutenlang „läuft", ohne dass jemand lädt — und stieße
        // deshalb auch keinen neuen Durchgang an.
        Cache::put(self::RUNNING, true, now()->addSeconds((int) $budget + 60));

        try {
            if (! $this->repository->hasCategories()) {
                $this->repository->categories();
                $fetched++;
                $this->pause($pause);
            }
            if (! $this->repository->hasProducts()) {
                $this->repository->products();
                $fetched++;
                $this->pause($pause);
            }

            foreach ($this->steps($filters) as $month) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                if ($this->repository->isMonthCached($month['key'], $filters->statuses)) {
                    continue;
                }
                $this->repository->loadMonth($month, $filters->statuses);
                $fetched++;
                $this->pause($pause);
            }

            Cache::forget(self::ERROR);
        } catch (WooCommerceApiException $e) {
            // Der Fehler gehört auf die Ladeseite, nicht ins Nichts.
            Cache::put(self::ERROR, [
                'message' => $e->userMessage().($e->hint() ? ' '.$e->hint() : ''),
                'technical' => $e->getMessage(),
            ], now()->addSeconds($this->errorTtl()));
        } catch (\Throwable $e) {
            report($e);
            Cache::put(self::ERROR, [
                'message' => 'Beim Aufbau der Auswertung ist ein unerwarteter Fehler aufgetreten. Der Aufbau versucht es gleich noch einmal.',
                'technical' => get_class($e).': '.$e->getMessage(),
            ], now()->addSeconds($this->errorTtl()));
        } finally {
            Cache::forget(self::RUNNING);
            $lock->release();
        }

        return ['ran' => true, 'fetched' => $fetched];
    }

    /** „Daten neu laden": alles verwerfen, damit der Aufbau frisch beginnt. */
    public function reset(StatisticsFilters $filters): void
    {
        $this->repository->forgetProducts();
        $this->repository->forgetCategories();
        foreach ($this->years($filters) as $year) {
            $this->repository->forget($year, $filters->statuses, $filters->fetchPadding());
        }
        Cache::forget(self::ERROR);
    }

    /**
     * Welche Schuljahre die Auswertung braucht: das gewählte, das Vorjahr für
     * den Vergleich, dazu die abgeschlossenen Jahre für den Saisonverlauf der
     * Prognose.
     *
     * @return list<SchoolYear>
     */
    public function years(StatisticsFilters $filters): array
    {
        $years = [$filters->year, $filters->year->previous()];

        $extra = (int) config('statistics.forecast.history_years') - 1;
        for ($i = 1; $i <= max(0, $extra); $i++) {
            $older = new SchoolYear($filters->year->startYear - 1 - $i);
            if (! $older->isComplete()) {
                break;
            }
            $years[] = $older;
        }

        return $years;
    }

    /**
     * Alle benötigten Monate, jeder genau einmal — die Zeiträume der
     * Schuljahre überlappen sich am Rand.
     *
     * @return list<array{key: string, after: string, before: string}>
     */
    private function steps(StatisticsFilters $filters): array
    {
        $months = [];
        foreach ($this->years($filters) as $year) {
            foreach ($this->repository->monthPlan($year, $filters->fetchPadding()) as $month) {
                $months[$month['key']] = $month;
            }
        }
        ksort($months);

        return array_values($months);
    }

    /**
     * Wie lange ein Fehler den Aufbau anhält. Solange er gespeichert ist, wird
     * kein neuer Durchgang angestoßen — bei 15 Minuten stünde die Auswertung
     * nach einem einmaligen 500er unnötig lange still. Kurz genug, dass sich
     * ein vorübergehendes Zucken des Shops von selbst erledigt, lang genug,
     * dass ein dauerhaft kaputter Shop nicht im Sekundentakt angefragt wird.
     */
    private function errorTtl(): int
    {
        return max(30, (int) config('statistics.error_retry_seconds', 120));
    }

    /** Pause zwischen zwei Shop-Anfragen — der Webshop teilt sich den Server. */
    private function pause(int $microseconds): void
    {
        if ($microseconds > 0 && ! app()->runningUnitTests()) {
            usleep($microseconds);
        }
    }
}
