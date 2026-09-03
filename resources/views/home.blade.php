@extends('layouts.app')

@section('title', 'Wear Together Order Suite')

@section('content')
    <div class="card">
        <h1>Wear Together Order Suite</h1>
        <p class="lead">Der Arbeitsplatz für Schul-Bestellfenster: vom eingegangenen Formular über den
            Webshop der Schule bis zu den fertigen Auftragsdokumenten für die Druckerei.
            Unten steht, was gerade zu tun ist — darunter, was die einzelnen Bereiche können.</p>
    </div>

    {{-- Was ist zu tun --}}
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:1rem;flex-wrap:wrap;">
            <h2 style="margin:0;">Was ist zu tun?</h2>
            <span class="hint">{{ $openCount }} offene{{ $openCount === 1 ? 'r Punkt' : ' Punkte' }}</span>
        </div>

        @if ($extended !== [])
            <div class="alert ok" style="margin-top:0.75rem;">
                <strong>Automatisch verlängert:</strong>
                <ul style="margin:0.4rem 0 0 1.1rem;">
                    @foreach ($extended as $entry)
                        <li>{{ $entry['ok'] ? '✓' : '✖' }} {{ $entry['detail'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($groups === [])
            <div class="alert ok" style="margin-top:0.75rem;">✓ Nichts offen — kein Bestellfenster läuft ab, keine unbearbeiteten Anträge.</div>
        @else
            @foreach ($groups as $group)
                <div class="alert {{ $group['tone'] }}" style="margin-top:0.75rem;">
                    <strong>{{ $group['title'] }} ({{ count($group['items']) }})</strong>
                    <div class="hint" style="margin:0.15rem 0 0.4rem;">{{ $group['explanation'] }}</div>
                    <ul style="margin:0 0 0 1.1rem;padding:0;">
                        @foreach ($group['items'] as $item)
                            <li style="margin-bottom:0.15rem;">
                                <a href="{{ route('schools.show', $item['onboarding']) }}"><strong>{{ $item['onboarding']->school_name }}</strong></a>
                                <span class="hint">— {{ $item['note'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        @endif
    </div>

    {{-- Der Ablauf, damit klar ist, wo man gerade steht --}}
    <div class="card">
        <h2>Der Ablauf einer Schule</h2>
        <ol style="margin:0.5rem 0 0 1.2rem;">
            <li><strong>Antrag geht ein</strong> — das ausgefüllte FluentForms-Formular landet automatisch im Schul-Onboarding (Status <em>Neu</em>).</li>
            <li><strong>Konfigurator befüllen</strong> — Produkte, Preise, Größen, Farben, Klassenliste, Bestellfenster, Logo (Status <em>In Bearbeitung</em>).</li>
            <li><strong>Im Shop anlegen</strong> — Kategorie, Produkte mit Variationen und der Schule-Eintrag entstehen per Klick (Status <em>Im Shop angelegt</em>).</li>
            <li><strong>Präsentationsblatt erzeugen</strong> — A4-Aushang mit QR-Code zur Bestellseite; zwei Mockups hochladen, den Rest füllt das Tool.</li>
            <li><strong>Bestellfenster läuft</strong> — Nachzügler bekommen auf Wunsch automatisch eine Nachfrist.</li>
            <li><strong>Bestellfenster schließen</strong> — Produkte auf privat, Schule-Eintrag zu (Status <em>Abgeschlossen</em>).</li>
            <li><strong>Auftragsdokumente erzeugen</strong> — die drei Excel-Reports und das Verteil-PDF für die Druckerei.</li>
        </ol>
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

        <a href="{{ route('balance.index') }}" class="card home-link-card">
            <h2>📒 Auftragsbilanz</h2>
            <p class="lead">Die gepflegte Auftragsliste — Nachfolgerin der Excel „Auftragsbilanz_gesamt".
                Jeder Auftrag eine Zeile mit Einnahmen (online und bar), Provision, Ausgaben, Umsatzsteuer und
                Stückzahlen je Produktart; Gewinn und Marge rechnet die Software. Hängt ein Auftrag an einem
                Bestellfenster, holt sie sich die Online-Einnahmen selbst aus dem Webshop und meldet, wenn der
                eingetragene Wert davon abweicht. Hier wird nur eingetragen und angesehen — ausgewertet wird in
                den Statistiken.</p>
            <span class="btn" style="margin-top:0.5rem;">Zur Auftragsbilanz</span>
        </a>

        <a href="{{ route('statistics.index') }}" class="card home-link-card">
            <h2>📈 Statistiken</h2>
            <p class="lead">Umsatzauswertung nach Schuljahr (1. August bis 31. Juli, Sommerferien zählen ans
                ablaufende Jahr), immer im Vergleich zum Vorjahr: Gesamtumsatz und Monatsverlauf,
                Ø Umsatz je Bestellung, je Sammelbestellfenster und je On-Demand-Shop, dazu die Ranglisten der
                meistverkauften Produkte und beliebtesten Farben. Eine Hochrechnung aus dem Saisonverlauf der
                Vorjahre zeigt, ob das Saisonziel erreicht wird, und rechnet aus, wie viele Bestellfenster dafür
                noch fehlen. Zwei Schalter oben bestimmen, welche Umsätze zählen: die aus dem Webshop, die aus
                der Auftragsbilanz (Bargeld, Direktverkäufe) oder beide. Gewinn, Marge und Ausgaben kommen aus
                der Auftragsbilanz.</p>
            <span class="btn" style="margin-top:0.5rem;">Zu den Statistiken</span>
        </a>

        <a href="{{ route('admin.status') }}" class="card home-link-card">
            <h2>🛠 Admin-Informationen</h2>
            <p class="lead">Prüfstand für alle Schnittstellen: WooCommerce, WordPress, Printify, Dynamic Mockups und der
                FluentForms-Webhook werden bei jedem Aufruf live getestet. Dazu das vollständige Webhook-Protokoll —
                dort steht jeder Aufruf der Webhook-URL, auch abgelehnte. Erste Anlaufstelle, wenn eine
                Formular-Einsendung nicht ankommt oder die Shop-Anlage scheitert.</p>
            <span class="btn" style="margin-top:0.5rem;">Zu den Admin-Informationen</span>
        </a>
    </div>

    <style>
        .home-link-card { display: block; text-decoration: none; color: inherit; transition: border-color 0.15s, transform 0.1s; }
        .home-link-card:hover { border-color: var(--accent); transform: translateY(-2px); }
    </style>
@endsection
