<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;

/**
 * Bedeutung der Antrags-Status und welche Wechsel erlaubt sind.
 *
 * Der Status beschreibt, was im Shop tatsächlich passiert ist — er ist kein
 * frei setzbares Etikett. Zwei Werte bedeuten eine ausgeführte Handlung und
 * sind deshalb nur über die jeweilige Aktion erreichbar, nicht über das
 * Auswahlfeld:
 *
 *   „Im Shop angelegt"  ← Shop-Anlage ausführen
 *   „Abgeschlossen"     ← Bestellfenster schließen
 *
 * Sonst stünde im Tool „angelegt", ohne dass es im Shop etwas gäbe.
 */
final class OnboardingStatus
{
    public const NEU = 'neu';

    public const IN_BEARBEITUNG = 'in_bearbeitung';

    public const ANGELEGT = 'angelegt';

    public const ABGESCHLOSSEN = 'abgeschlossen';

    /**
     * Alle Status mit Kurzbezeichnung und Erklärung.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function all(): array
    {
        return [
            self::NEU => [
                'label' => 'Neu',
                'description' => 'Der Antrag ist eingegangen (Formular oder manuell), aber noch niemand hat ihn bearbeitet. Nichts im Shop angelegt.',
            ],
            self::IN_BEARBEITUNG => [
                'label' => 'In Bearbeitung',
                'description' => 'Der Konfigurator wird befüllt: Produkte, Preise, Farben, Bestellfenster, Logo. Im Shop existiert noch nichts.',
            ],
            self::ANGELEGT => [
                'label' => 'Im Shop angelegt',
                'description' => 'Kategorie, Produkte und der Schule-Eintrag sind im Shop vorhanden — die Schule kann bestellen. Wird automatisch gesetzt, wenn die Shop-Anlage durchläuft.',
            ],
            self::ABGESCHLOSSEN => [
                'label' => 'Abgeschlossen',
                'description' => 'Das Bestellfenster ist geschlossen, die Produkte stehen im Shop auf privat. Wird automatisch gesetzt, wenn „Bestellfenster schließen" durchläuft.',
            ],
        ];
    }

    public static function label(string $status): string
    {
        return self::all()[$status]['label'] ?? $status;
    }

    public static function description(string $status): string
    {
        return self::all()[$status]['description'] ?? '';
    }

    /**
     * Status, die sich von hier aus im Auswahlfeld setzen lassen — der aktuelle
     * immer eingeschlossen, damit ein Speichern ohne Statuswechsel möglich ist.
     *
     * @return array<string, string> Status => Beschriftung im Auswahlfeld
     */
    public static function manualOptions(SchoolOnboarding $onboarding): array
    {
        $current = (string) $onboarding->status;
        $options = [$current => self::label($current)];

        switch ($current) {
            case self::NEU:
                $options[self::IN_BEARBEITUNG] = 'In Bearbeitung nehmen';
                break;

            case self::IN_BEARBEITUNG:
                // Ohne Shop-Anlage lässt sich ein Antrag abhaken (Absage, Dublette).
                // Sobald etwas im Shop steht, muss es über das Schließen laufen.
                if (! $onboarding->isProvisioned()) {
                    $options[self::ABGESCHLOSSEN] = 'Ohne Shop-Anlage abschließen (Absage/Dublette)';
                }
                break;

            case self::ANGELEGT:
                $options[self::IN_BEARBEITUNG] = 'Zurück in Bearbeitung (Shop-Inhalte bleiben bestehen)';
                break;

            case self::ABGESCHLOSSEN:
                // Zurück geht es nur über „Bestellfenster wieder öffnen" —
                // sonst stünde der Antrag offen, während der Shop zu ist.
                break;
        }

        return $options;
    }

    /**
     * Status, die von hier aus nur eine Aktion herbeiführt — mit dem Hinweis,
     * welche. Wird im Antrag unter dem Auswahlfeld angezeigt.
     *
     * @return array<string, array{label: string, hint: string, route: ?string}>
     */
    public static function actionOnly(SchoolOnboarding $onboarding): array
    {
        $current = (string) $onboarding->status;
        $actions = [];

        if (in_array($current, [self::NEU, self::IN_BEARBEITUNG], true)) {
            $actions[self::ANGELEGT] = [
                'label' => self::label(self::ANGELEGT),
                'hint' => 'wird gesetzt, sobald „Im Shop anlegen" durchgelaufen ist',
                'route' => null,
            ];
        }

        if ($current === self::ANGELEGT) {
            $actions[self::ABGESCHLOSSEN] = [
                'label' => self::label(self::ABGESCHLOSSEN),
                'hint' => 'über „Bestellfenster schließen"',
                'route' => 'close-window.index',
            ];
        }

        if ($current === self::ABGESCHLOSSEN) {
            $actions[self::ANGELEGT] = [
                'label' => self::label(self::ANGELEGT),
                'hint' => 'über „Bestellfenster wieder öffnen"',
                'route' => 'close-window.index',
            ];
        }

        return $actions;
    }

    /** Darf dieser Antrag gerade in den Zielstatus wechseln (manuell)? */
    public static function canSwitchTo(SchoolOnboarding $onboarding, string $target): bool
    {
        return array_key_exists($target, self::manualOptions($onboarding));
    }
}
