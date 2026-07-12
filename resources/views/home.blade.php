@extends('layouts.app')

@section('title', 'Wear Together Order Suite')

@section('content')
    <div class="card">
        <h1>Willkommen bei der Wear Together Order Suite</h1>
        <p class="lead">Drei Werkzeuge, ein Login — bitte auswählen, womit du starten möchtest.</p>
    </div>

    <div class="downloads" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">
        <a href="{{ route('tool.index') }}" class="card home-link-card">
            <h2>📄 Auftragsdokumente</h2>
            <p class="lead">Aus einem Bestell-Export (direkt aus dem Shop oder als Datei-Upload) automatisch die fertigen
                Auftragsunterlagen erzeugen: Lieferanten-Report, interner Report mit Prüfspalte, Kunden-Report mit
                Provision und ein Verteil-PDF. Inklusive Prüfbericht für unbekannte Größen oder fehlende
                Individualisierungstexte.</p>
            <span class="btn" style="margin-top:0.5rem;">Zu den Auftragsdokumenten</span>
        </a>

        <a href="{{ route('schools.index') }}" class="card home-link-card">
            <h2>🏫 Schul-Onboarding</h2>
            <p class="lead">Neue Schulen/Organisationen automatisiert im Shop einrichten — vom
                Webshop-Startfragebogen bis zur fertigen Produktkategorie mit Varianten. Unterstützt sowohl das
                klassische Sammelbestellfenster (mit Bestellemail an die Druckerei) als auch On-Demand-Produkte über
                Printify, inklusive Margen-Prüfung und Blueprint/Provider-Suche.</p>
            <span class="btn" style="margin-top:0.5rem;">Zum Schul-Onboarding</span>
        </a>

        <a href="{{ route('close-window.index') }}" class="card home-link-card">
            <h2>🔒 Bestellfenster schließen</h2>
            <p class="lead">Wenn die Bestellfrist einer Schule abgelaufen ist: Mit einem Klick alle Produkte dieser
                Schule im Shop auf privat setzen (nicht mehr sichtbar/bestellbar) und im Schule-Eintrag
                „Bestellfenster offen" auf NEIN stellen. Typischerweise direkt nachdem die Auftragsdokumente
                exportiert wurden.</p>
            <span class="btn" style="margin-top:0.5rem;">Zum Bestellfenster-Schließen</span>
        </a>
    </div>

    <style>
        .home-link-card { display: block; text-decoration: none; color: inherit; transition: border-color 0.15s, transform 0.1s; }
        .home-link-card:hover { border-color: var(--accent); transform: translateY(-2px); }
    </style>
@endsection
