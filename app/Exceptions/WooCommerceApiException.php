<?php

namespace App\Exceptions;

/**
 * Fehler bei der Verbindung zur WooCommerce REST API.
 *
 * userMessage() liefert eine für nicht-technische Kolleg:innen verständliche
 * deutsche Erklärung; getMessage() enthält die technischen Details.
 */
class WooCommerceApiException extends \RuntimeException
{
    public function __construct(
        private readonly string $userMessage,
        string $technicalDetails,
        private readonly ?string $hint = null,
    ) {
        parent::__construct($technicalDetails);
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }

    public function hint(): ?string
    {
        return $this->hint;
    }

    public static function notConfigured(): self
    {
        return new self(
            'Die Shop-Verbindung ist noch nicht eingerichtet.',
            'WC_STORE_URL / WC_CONSUMER_KEY / WC_CONSUMER_SECRET fehlen in der .env-Datei.',
            'Ein:e Administrator:in muss in der .env-Datei der App die Shop-Adresse und einen Read-only-API-Schlüssel hinterlegen (Anleitung im README unter „Shop-Verbindung einrichten").',
        );
    }

    public static function unreachable(string $details): self
    {
        return new self(
            'Der Shop ist gerade nicht erreichbar.',
            $details,
            'Bitte prüfen, ob der Shop im Browser lädt. Wenn ja: ein paar Minuten warten und erneut versuchen — eventuell blockiert eine Firewall oder Wartung die Verbindung.',
        );
    }

    public static function timeout(string $details): self
    {
        return new self(
            'Der Shop hat zu lange nicht geantwortet.',
            $details,
            'Meist ist der Shop nur kurz überlastet — bitte in ein bis zwei Minuten erneut versuchen.',
        );
    }

    public static function unauthorized(string $details): self
    {
        $hint = str_contains($details, 'woocommerce_rest_cannot_view')
            ? 'Der Schlüssel kommt zwar an, aber der Shop erlaubt damit keinen Lesezugriff. Häufigste Ursache: Der API-Schlüssel ist an ein Benutzerkonto ohne Administrator-/Shop-Manager-Rolle gebunden. Ein:e Administrator:in sollte in WooCommerce → Einstellungen → Erweitert → REST-API einen neuen Read-only-Schlüssel erstellen und dabei als Benutzer ein Administrator-Konto auswählen.'
            : 'Der hinterlegte Schlüssel ist falsch, wurde gelöscht oder ist abgelaufen. Ein:e Administrator:in muss in WooCommerce → Einstellungen → Erweitert → REST-API einen neuen Read-only-Schlüssel erstellen und in der .env-Datei eintragen (danach: php artisan config:cache).';

        return new self(
            'Der Shop hat den API-Schlüssel abgelehnt (Anmeldung fehlgeschlagen).',
            $details,
            $hint,
        );
    }

    public static function forbidden(string $details): self
    {
        return new self(
            'Der Shop hat den Zugriff verweigert.',
            $details,
            'Entweder hat der API-Schlüssel keine Leseberechtigung, oder ein Sicherheits-Plugin/eine Firewall des Shops blockiert die Anfrage.',
        );
    }

    public static function apiNotFound(string $details): self
    {
        return new self(
            'Die Shop-Schnittstelle wurde unter dieser Adresse nicht gefunden.',
            $details,
            'Bitte prüfen, ob die Shop-Adresse in der .env-Datei stimmt (ohne /wp-json am Ende) und ob im WordPress die Permalinks nicht auf „Einfach" stehen.',
        );
    }

    public static function serverError(int $status, string $details): self
    {
        return new self(
            "Im Shop ist ein Fehler aufgetreten (Fehlercode {$status}).",
            $details,
            'Das ist ein Problem auf der Shop-Seite, nicht in diesem Tool. Bitte später erneut versuchen; wenn es bleibt, die Shop-Administration informieren.',
        );
    }

    public static function unexpectedResponse(string $details): self
    {
        return new self(
            'Der Shop hat eine unerwartete Antwort geliefert.',
            $details,
            'Möglicherweise zeigt der Shop gerade eine Wartungsseite oder ein Plugin stört die Schnittstelle. Bitte prüfen, ob der Shop im Browser normal lädt.',
        );
    }
}
