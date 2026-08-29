<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Verlängert abgelaufene Sammelbestellfenster automatisch.
 *
 * Die Verlängerung greift bewusst erst, wenn das Fenster tatsächlich abgelaufen
 * ist — nicht vorher. Nachzügler bekommen so eine Nachfrist, ohne dass das
 * ursprüngliche Enddatum gegenüber der Schule von Anfang an unglaubwürdig wäre.
 *
 * Sie erfolgt **einmal je Fenster** (`auto_extended_at`). Alles andere wäre ein
 * Fenster, das sich nie schließt — geschlossen wird weiterhin bewusst über
 * Modul 3. Setzt jemand danach von Hand ein neues Enddatum, ist die
 * Verlängerung wieder frei (siehe `resetFor()`).
 *
 * Ausgeführt wird sie über `php artisan windows:extend` (Cron, einmal täglich)
 * und zusätzlich gedrosselt beim Aufruf der Startseite — damit sie auch ohne
 * eingerichteten Cron greift.
 */
class OrderWindowExtender
{
    private const THROTTLE_KEY = 'order_windows.last_auto_extend';

    public function __construct(private readonly WordPressClient $wordpress) {}

    /**
     * Anträge, deren Fenster jetzt zu verlängern ist.
     *
     * @return \Illuminate\Support\Collection<int, SchoolOnboarding>
     */
    public function due(): \Illuminate\Support\Collection
    {
        return SchoolOnboarding::query()
            ->where('delivery_type', 'collective')
            ->where('status', OnboardingStatus::ANGELEGT)
            ->where('auto_extend', true)
            ->whereNull('auto_extended_at')
            ->whereNotNull('window_end')
            ->whereDate('window_end', '<', Carbon::today())
            ->orderBy('window_end')
            ->get();
    }

    /**
     * Verlängert ein einzelnes Fenster und schreibt das neue Ende auch in den
     * Schule-Eintrag (CPT). Schlägt WordPress fehl, bleibt die Verlängerung im
     * Tool trotzdem bestehen — sie wird dann beim nächsten Speichern
     * mitgeschickt; die Meldung landet im Protokoll.
     *
     * @return array{step: string, ok: bool, detail: string}
     */
    public function extend(SchoolOnboarding $onboarding): array
    {
        $days = max(1, (int) $onboarding->auto_extend_days);
        $previousEnd = $onboarding->window_end;

        // Von heute aus rechnen, falls die Verlängerung verspätet läuft (kein
        // Cron, Tool länger nicht geöffnet) — sonst läge das neue Ende wieder
        // in der Vergangenheit und die Nachfrist wäre wirkungslos.
        $base = $previousEnd->isPast() ? Carbon::today() : $previousEnd;
        $newEnd = $base->copy()->addDays($days);

        $onboarding->forceFill([
            'window_end' => $newEnd,
            'auto_extended_at' => now(),
            'auto_extend_from' => $previousEnd,
        ])->save();

        $detail = sprintf(
            'Bestellfenster von %s auf %s verlängert (+%d Tage)',
            $previousEnd->format('d.m.Y'),
            $newEnd->format('d.m.Y'),
            $days,
        );

        if (! $onboarding->pods_post_id) {
            return ['step' => "Automatische Verlängerung {$onboarding->school_name}", 'ok' => true,
                'detail' => $detail.' — kein Schule-Eintrag hinterlegt, im Shop nicht nachgezogen.'];
        }

        try {
            $this->wordpress->updateSchule((int) $onboarding->pods_post_id, [
                'bestellfensterende' => $newEnd->format('Y-m-d 23:59:59'),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return ['step' => "Automatische Verlängerung {$onboarding->school_name}", 'ok' => false,
                'detail' => $detail.' — konnte im Schule-Eintrag NICHT gesetzt werden: '.$e->getMessage()];
        }

        return ['step' => "Automatische Verlängerung {$onboarding->school_name}", 'ok' => true, 'detail' => $detail];
    }

    /**
     * Alle fälligen Fenster verlängern.
     *
     * @return list<array{step: string, ok: bool, detail: string}>
     */
    public function runDue(): array
    {
        $log = [];
        foreach ($this->due() as $onboarding) {
            $entry = $this->extend($onboarding);
            $onboarding->provision_log = array_merge($onboarding->provision_log ?? [], [$entry]);
            $onboarding->save();
            $log[] = $entry;
        }
        Cache::put(self::THROTTLE_KEY, now()->toIso8601String(), now()->addDay());

        return $log;
    }

    /**
     * Beiläufiger Lauf beim Seitenaufruf: höchstens stündlich, nur wenn
     * überhaupt etwas fällig ist, und niemals mit einem Fehler nach außen —
     * eine kaputte WordPress-Verbindung darf die Startseite nicht blockieren.
     *
     * @return list<array{step: string, ok: bool, detail: string}>
     */
    public function runDueOpportunistically(): array
    {
        $last = Cache::get(self::THROTTLE_KEY);
        if ($last !== null && Carbon::parse($last)->diffInMinutes(now()) < 60) {
            return [];
        }

        try {
            return $this->runDue();
        } catch (\Throwable $e) {
            Log::warning('Automatische Fensterverlängerung fehlgeschlagen: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Gibt die Verlängerung wieder frei — aufzurufen, wenn jemand das Enddatum
     * von Hand ändert oder das Fenster neu geöffnet wird.
     */
    public static function resetFor(SchoolOnboarding $onboarding): void
    {
        $onboarding->auto_extended_at = null;
        $onboarding->auto_extend_from = null;
    }
}
