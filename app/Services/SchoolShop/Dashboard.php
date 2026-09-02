<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;
use App\Services\PresentationSheet\PresentationSheetRenderer;
use Illuminate\Support\Carbon;

/**
 * „Was ist zu tun?" — stellt aus den Anträgen die Punkte zusammen, die gerade
 * Aufmerksamkeit brauchen. Ausschließlich aus der eigenen Datenbank, ohne
 * API-Aufrufe: die Startseite muss auch dann sofort laden, wenn WooCommerce
 * oder WordPress gerade klemmen.
 */
class Dashboard
{
    /** Ab wann gilt ein Bestellfenster als „läuft demnächst ab"? */
    private const SOON_DAYS = 7;

    public function __construct(private readonly PresentationSheetRenderer $sheet) {}

    /**
     * Aufgabengruppen, dringendste zuerst. Leere Gruppen fallen weg.
     *
     * @return list<array{key: string, title: string, tone: string, explanation: string, items: list<array{onboarding: SchoolOnboarding, note: string}>}>
     */
    public function groups(): array
    {
        $all = SchoolOnboarding::orderBy('window_end')->orderBy('school_name')->get();
        $today = Carbon::today();

        $groups = [
            [
                'key' => 'expired_open',
                'title' => 'Bestellfenster abgelaufen, im Shop aber noch offen',
                'tone' => 'error',
                'explanation' => 'Hier wird weiter bestellt, obwohl die Frist vorbei ist. Entweder schließen oder das Fenster bewusst verlängern.',
                'items' => $this->map(
                    $all->filter(fn ($o) => $o->windowExpiredButOpen()),
                    fn ($o) => 'Ende war '.$o->window_end->format('d.m.Y')
                        .($o->auto_extend && ! $o->auto_extended_at ? ' — wird automatisch um '.$o->auto_extend_days.' Tage verlängert' : ''),
                ),
            ],
            [
                'key' => 'closing_soon',
                'title' => 'Bestellfenster läuft in den nächsten '.self::SOON_DAYS.' Tagen ab',
                'tone' => 'warn',
                'explanation' => 'Rechtzeitig daran denken: nach dem Ende Fenster schließen und die Auftragsdokumente erzeugen.',
                'items' => $this->map(
                    $all->filter(fn ($o) => $o->windowIsRunning()
                        && $o->window_end->lte($today->copy()->addDays(self::SOON_DAYS))),
                    fn ($o) => 'endet '.$o->window_end->format('d.m.Y').' ('.max(0, (int) $today->diffInDays($o->window_end, false)).' Tage)',
                ),
            ],
            [
                'key' => 'closed_no_documents',
                'title' => 'Geschlossen, Auftragsdokumente fehlen noch',
                'tone' => 'warn',
                'explanation' => 'Das Bestellfenster ist zu — jetzt die Reports und das Verteil-PDF erzeugen und an die Druckerei geben.',
                'items' => $this->map(
                    $all->filter(fn ($o) => $o->status === OnboardingStatus::ABGESCHLOSSEN
                        && $o->documents_exported_at === null
                        && $o->woo_category_id !== null),
                    fn ($o) => 'geschlossen, Bestellzeitraum bis '.($o->window_end?->format('d.m.Y') ?? '—'),
                ),
            ],
            [
                'key' => 'no_window',
                'title' => 'Bestellzeitraum fehlt',
                'tone' => 'warn',
                'explanation' => 'Im Formular stand kein auswertbares Datum. Ohne Bestellzeitraum gibt es kein '
                    .'Präsentationsblatt, keine automatische Nachfrist und keine Zuordnung in der Statistik — '
                    .'bitte im Konfigurator nachtragen.',
                'items' => $this->map(
                    $all->filter(fn ($o) => $o->delivery_type === 'collective'
                        && $o->status !== OnboardingStatus::ABGESCHLOSSEN
                        && ($o->window_start === null || $o->window_end === null)),
                    fn ($o) => 'eingegangen '.$o->created_at->format('d.m.Y').', Zeitraum offen',
                ),
            ],
            [
                'key' => 'new',
                'title' => 'Neue Anträge',
                'tone' => 'warn',
                'explanation' => 'Aus dem Formular eingegangen und noch nicht angesehen.',
                'items' => $this->map(
                    $all->filter(fn ($o) => $o->status === OnboardingStatus::NEU),
                    fn ($o) => 'eingegangen '.$o->created_at->format('d.m.Y'),
                ),
            ],
            [
                'key' => 'in_progress',
                'title' => 'In Bearbeitung, noch nicht im Shop angelegt',
                'tone' => 'info',
                'explanation' => 'Konfigurator ist offen — solange nichts angelegt ist, kann die Schule nicht bestellen.',
                'items' => $this->map(
                    $all->filter(fn ($o) => $o->status === OnboardingStatus::IN_BEARBEITUNG),
                    fn ($o) => count($o->enabledProducts()).' Produkt(e) aktiviert',
                ),
            ],
            [
                'key' => 'sheet_missing',
                'title' => 'Angelegt, Präsentationsblatt fehlt',
                'tone' => 'info',
                'explanation' => 'Der Shop steht, aber die Schule hat noch nichts zum Aushängen.',
                'items' => $this->map(
                    $all->filter(fn ($o) => $o->status === OnboardingStatus::ANGELEGT
                        && $this->sheet->missingRequirements($o) !== []),
                    fn ($o) => 'es fehlt: '.implode(', ', $this->sheet->missingRequirements($o)),
                ),
            ],
        ];

        return array_values(array_filter($groups, fn ($g) => $g['items'] !== []));
    }

    /** Gesamtzahl offener Punkte — für die Überschrift. */
    public function openCount(array $groups): int
    {
        return array_sum(array_map(fn ($g) => count($g['items']), $groups));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SchoolOnboarding>  $items
     * @return list<array{onboarding: SchoolOnboarding, note: string}>
     */
    private function map($items, callable $note): array
    {
        return $items->map(fn ($o) => ['onboarding' => $o, 'note' => $note($o)])->values()->all();
    }
}
