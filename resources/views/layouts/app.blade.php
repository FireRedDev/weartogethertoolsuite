<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Wear Together Order Suite')</title>
    <style>
        :root {
            --accent: #ffbb00;
            --accent-dark: #e0a500;
            --ink: #1d2733;
            --muted: #64748b;
            --bg: #f6f7f9;
            --card: #ffffff;
            --line: #e2e8f0;
            --ok: #16803c;
            --warn: #b45309;
            --error: #b91c1c;
        }
        * { box-sizing: border-box; }
        /*
         * Muss sein: Ein inline gesetztes display (z. B. display:flex) schlägt
         * sonst die eingebaute Regel [hidden]{display:none} des Browsers, und
         * ein per JavaScript verstecktes Element bleibt sichtbar.
         */
        [hidden] { display: none !important; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--ink);
            line-height: 1.5;
        }
        header.site {
            background: var(--ink);
            color: #fff;
            padding: 0.9rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        header.site .brand { font-weight: 700; font-size: 1.05rem; letter-spacing: 0.01em; }
        header.site .brand .dot { color: var(--accent); }
        main { max-width: 1080px; margin: 2rem auto 4rem; padding: 0 1.25rem; }
        .steps { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .step {
            padding: 0.35rem 0.9rem;
            border-radius: 999px;
            background: var(--card);
            border: 1px solid var(--line);
            color: var(--muted);
            font-size: 0.85rem;
        }
        .step.active { background: var(--accent); border-color: var(--accent); color: var(--ink); font-weight: 600; }
        .step.done { color: var(--ok); border-color: var(--ok); }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
        }
        h1 { font-size: 1.35rem; margin: 0 0 0.5rem; }
        h2 { font-size: 1.05rem; margin: 0 0 0.75rem; }
        p.lead { color: var(--muted); margin-top: 0; }
        label { display: block; font-weight: 600; margin-bottom: 0.3rem; }
        .hint { font-weight: 400; color: var(--muted); font-size: 0.85rem; }
        input[type=text], input[type=password], textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--line);
            border-radius: 8px;
            font: inherit;
            margin-bottom: 1rem;
            background: #fff;
        }
        input:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
        .btn {
            display: inline-block;
            background: var(--accent);
            color: var(--ink);
            font-weight: 700;
            border: none;
            border-radius: 8px;
            padding: 0.65rem 1.4rem;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover { background: var(--accent-dark); }
        .btn.secondary { background: var(--card); border: 1px solid var(--line); font-weight: 600; }
        .btn.secondary:hover { background: var(--bg); }
        .dropzone {
            border: 2px dashed var(--line);
            border-radius: 12px;
            padding: 3rem 1.5rem;
            text-align: center;
            color: var(--muted);
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }
        .dropzone.dragover { border-color: var(--accent); background: #fffaeb; }
        .dropzone strong { color: var(--ink); }
        .dropzone .filename { color: var(--ok); font-weight: 600; margin-top: 0.5rem; }
        .alert { border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.95rem; }
        .alert.error { background: #fef2f2; border: 1px solid #fecaca; color: var(--error); }
        .alert.warn { background: #fffbeb; border: 1px solid #fde68a; color: var(--warn); }
        .alert.ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: var(--ok); }
        /* Neutral: weder Problem noch Erfolgsmeldung — z. B. offene Punkte ohne Dringlichkeit */
        .alert.info { background: #f8fafc; border: 1px solid var(--line); color: var(--ink); }

        /*
         * Info-Symbol mit antippbarer Erklärung. Ersetzt title="…"-Tooltips,
         * die auf Telefonen nicht erscheinen (kein Mouseover).
         */
        .info { position: relative; display: inline-block; vertical-align: baseline; }
        .info-toggle {
            width: 1.15rem; height: 1.15rem; padding: 0; border-radius: 50%;
            border: 1px solid var(--line); background: #eef2f7; color: var(--muted);
            font: 700 0.72rem/1 ui-serif, Georgia, serif; cursor: pointer;
            vertical-align: middle; flex: none;
        }
        .info-toggle:hover, .info-toggle[aria-expanded="true"] { background: var(--ink); border-color: var(--ink); color: #fff; }
        .info-box {
            position: absolute; z-index: 40; left: 0; top: calc(100% + 0.35rem);
            width: max-content; max-width: min(22rem, 78vw);
            background: var(--ink); color: #f1f5f9;
            font-size: 0.82rem; font-weight: 400; font-style: normal; line-height: 1.45;
            text-align: left; white-space: normal;
            padding: 0.55rem 0.7rem; border-radius: 8px; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.28);
        }
        .info-box code { background: rgba(255,255,255,0.14); color: #fff; }
        .info-box a { color: var(--accent); }

        /* Ausklappbarer Erklärblock für längere Texte */
        .explain { margin: 0 0 0.85rem; border: 1px solid var(--line); border-radius: 8px; background: #f8fafc; }
        .explain > summary {
            cursor: pointer; padding: 0.5rem 0.75rem; font-size: 0.85rem; font-weight: 600;
            color: var(--muted); list-style: none;
        }
        .explain > summary::-webkit-details-marker { display: none; }
        .explain > summary::before { content: "▸ "; display: inline-block; width: 1em; }
        .explain[open] > summary { color: var(--ink); border-bottom: 1px solid var(--line); }
        .explain[open] > summary::before { content: "▾ "; }
        .explain-body { padding: 0.6rem 0.75rem; font-size: 0.88rem; color: var(--muted); line-height: 1.55; }
        .explain-body > :first-child { margin-top: 0; }
        .explain-body > :last-child { margin-bottom: 0; }
        .explain-body ul, .explain-body ol { margin: 0.3rem 0 0.5rem; padding-left: 1.1rem; }
        /*
         * Diagramme (Inline-SVG, kein Node-Build). Beschriftungen tragen immer
         * Textfarben — Farbe gehört den Datenflächen, sonst wird ein heller
         * Serienton als Text unlesbar.
         */
        .chart { margin: 0 0 1.25rem; }
        .chart figcaption { font-weight: 600; font-size: 0.95rem; margin-bottom: 0.35rem; }
        /*
         * Auf schmalen Schirmen wird das Diagramm nicht mitgeschrumpft (die
         * Beschriftung wäre bei 390 px nur noch 6 px groß), sondern scrollt
         * waagrecht im eigenen Kasten. Die Seite selbst scrollt nie waagrecht.
         */
        .chart-scroll { overflow-x: auto; }
        .chart svg { display: block; width: 100%; min-width: 620px; height: auto; }
        .chart-grid { stroke: var(--line); stroke-width: 1; }
        .chart-target { stroke: var(--ink); stroke-width: 1.5; }
        .chart-targetlabel { fill: var(--ink); font-size: 11px; font-weight: 600; font-family: inherit; }
        .chart-tick { fill: var(--muted); font-size: 11px; font-family: inherit; font-variant-numeric: tabular-nums; }
        .chart-rowlabel { fill: var(--ink); font-size: 12px; font-family: inherit; }
        .chart-value { fill: var(--ink); font-size: 11px; font-weight: 600; font-family: inherit; }
        .chart-swatch { stroke: var(--line); stroke-width: 1; }
        .chart-legend { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; font-size: 0.82rem; color: var(--muted); margin-bottom: 0.4rem; }
        .chart-legend span { display: inline-flex; align-items: center; gap: 0.35rem; }
        .chart-legend i { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
        .chart-legend i.dashed { height: 3px; border-radius: 0; background-image: linear-gradient(90deg, currentColor 0 3px, transparent 3px 6px); }
        details.explain.chart-table { margin-top: 0.5rem; }
        details.explain.chart-table table.data { font-size: 0.8rem; }

        /* Ladeanzeige der Statistik (Fortschrittsbalken + Spinner) */
        .loading-block { border: 1px solid var(--line); border-radius: 10px; padding: 1.1rem 1.25rem; background: #f8fafc; }
        .loading-head { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 0.8rem; }
        .loading-title { font-weight: 600; }
        .spinner {
            width: 22px; height: 22px; flex: none; border-radius: 50%;
            border: 3px solid var(--line); border-top-color: var(--accent);
            animation: spin 0.9s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        /* Wer „weniger Bewegung" eingestellt hat, bekommt einen ruhigen Punkt */
        @media (prefers-reduced-motion: reduce) {
            .spinner { animation: none; border-top-color: var(--accent); }
            .progress-fill { transition: none; }
        }
        .progress { height: 10px; border-radius: 999px; background: #e2e8f0; overflow: hidden; }
        .progress-fill { height: 100%; background: var(--accent); border-radius: 999px; transition: width 0.4s ease; }

        /* Kennzahl-Kacheln der Statistik */
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(215px, 1fr)); gap: 0.85rem; margin: 0.25rem 0 1.25rem; }
        .kpi { border: 1px solid var(--line); border-radius: 10px; padding: 0.85rem 1rem; background: var(--card); }
        .kpi .label { color: var(--muted); font-size: 0.8rem; display: flex; align-items: center; gap: 0.25rem; }
        .kpi .value { font-size: 1.45rem; font-weight: 700; line-height: 1.2; margin-top: 0.15rem; }
        .kpi .value.hero { font-size: 1.85rem; }
        .kpi .delta { font-size: 0.8rem; margin-top: 0.15rem; }
        .kpi .delta.up { color: var(--ok); }
        .kpi .delta.down { color: var(--error); }
        .kpi .delta.flat { color: var(--muted); }
        /* Einschränkender Hinweis zur Kennzahl, z. B. nicht abgezogene Erstattungen */
        .kpi .delta.warn { color: var(--warn); }
        /* Bedarfsrechnung der Saisonplanung — abgesetzt, aber keine eigene Karte */
        .need-block {
            margin-top: 1.25rem;
            padding-top: 1.1rem;
            border-top: 1px solid var(--line);
        }
        .need-block h3 { margin: 0 0 0.8rem; font-size: 1rem; }

        /*
         * Quellenschalter über der Auswertung. Bewusst Links und keine
         * Kästchen: Jeder Zustand ist eine eigene Adresse und damit als
         * Lesezeichen speicherbar. Der Zustand hängt NIE nur an der Farbe —
         * der Knopf steht sichtbar links oder rechts, und die Schrift
         * wechselt zwischen kräftig und blass.
         */
        .sources { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; margin-bottom: 1rem; }
        .sources-label { font-size: 0.82rem; color: var(--muted); font-weight: 600; }
        .toggle {
            display: inline-flex; align-items: center; gap: 0.55rem;
            padding: 0.4rem 0.75rem 0.4rem 0.55rem;
            border: 1px solid var(--line); border-radius: 999px;
            background: var(--card); text-decoration: none; color: var(--ink);
        }
        .toggle:hover { border-color: var(--ink); }
        .toggle.locked { cursor: default; }
        .toggle.locked:hover { border-color: var(--line); }
        .toggle-track {
            width: 34px; height: 20px; border-radius: 999px; flex: none;
            background: #cbd5e1; position: relative; transition: background 0.15s ease;
        }
        .toggle-knob {
            position: absolute; top: 2px; left: 2px; width: 16px; height: 16px;
            border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.25);
            transition: transform 0.15s ease;
        }
        .toggle.on .toggle-track { background: var(--ok); }
        .toggle.on .toggle-knob { transform: translateX(14px); }
        .toggle-text { display: flex; flex-direction: column; line-height: 1.15; }
        .toggle-text strong { font-size: 0.88rem; }
        .toggle-text small { font-size: 0.74rem; color: var(--muted); }
        .toggle.off { background: var(--bg); }
        .toggle.off .toggle-text strong { color: var(--muted); font-weight: 500; text-decoration: line-through; }
        @media (prefers-reduced-motion: reduce) {
            .toggle-track, .toggle-knob { transition: none; }
        }

        /* Filterzeile über allen Diagrammen — gilt für die ganze Seite */
        .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem 1rem; align-items: end; }
        .filters label { font-size: 0.82rem; margin-bottom: 0.2rem; }
        .filters select, .filters input { width: 100%; padding: 0.45rem 0.6rem; border: 1px solid var(--line); border-radius: 8px; font: inherit; background: #fff; margin: 0; }

        details.warnrows { margin-top: 0.4rem; font-size: 0.85rem; }
        details.warnrows summary { cursor: pointer; }
        .stats { display: flex; gap: 1rem; flex-wrap: wrap; margin: 0.5rem 0 1rem; }
        .stat {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 0.75rem 1.25rem;
            min-width: 130px;
        }
        .stat .value { font-size: 1.4rem; font-weight: 700; }
        .stat .label { color: var(--muted); font-size: 0.8rem; }
        .downloads { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 0.9rem; }
        .dl {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 1rem;
            background: var(--card);
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .dl .name { font-weight: 600; font-size: 0.95rem; }
        .dl .desc { color: var(--muted); font-size: 0.82rem; flex: 1; }
        .tablewrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 10px; }
        table.data { border-collapse: collapse; width: 100%; font-size: 0.82rem; }
        table.data th, table.data td { border-bottom: 1px solid var(--line); padding: 0.4rem 0.6rem; text-align: left; white-space: nowrap; }
        table.data th { background: #eef2f7; position: sticky; top: 0; }
        table.data tr:nth-child(even) td { background: #fafbfc; }
        /* Fixierte erste Spalte: bleibt beim horizontalen Scrollen sichtbar (z. B. „Öffnen"-Button) */
        table.data th.stickycol, table.data td.stickycol { position: sticky; left: 0; z-index: 2; background: var(--card); box-shadow: 1px 0 0 var(--line); }
        table.data th.stickycol { z-index: 3; background: #eef2f7; }
        table.data tr:nth-child(even) td.stickycol { background: #fafbfc; }
        /*
         * Breite Tabellen am Telefon: Jede Zeile wird zu einer Karte.
         * Ohne das blieb bei 390 px nur die fixierte erste Spalte stehen — eine
         * Liste von Namen ohne eine einzige Zahl. Die Beschriftung kommt aus
         * data-label am <td>, damit es nur EINE Auszeichnung im Blade gibt.
         */
        /* Ein Knopf, der wie ein Link aussieht — für reine Anzeigeschalter. */
        .linkish {
            border: 0; background: none; padding: 0; font: inherit; color: var(--warn);
            text-decoration: underline; cursor: pointer;
        }
        .linkish[aria-pressed="true"] { font-weight: 700; }
        table.data tr.muted td { color: var(--muted); }
        table.data th.sortable { cursor: pointer; user-select: none; }
        table.data th.sortable::after { content: " ⇅"; color: var(--muted); font-weight: 400; }
        table.data th.sortable[aria-sort="ascending"]::after { content: " ↑"; color: var(--ink); }
        table.data th.sortable[aria-sort="descending"]::after { content: " ↓"; color: var(--ink); }
        @media (max-width: 720px) {
            .tablewrap.cards { overflow-x: visible; border: 0; border-radius: 0; }
            table.data.cards, table.data.cards tbody, table.data.cards tfoot,
            table.data.cards tr, table.data.cards td { display: block; }
            table.data.cards thead { display: none; }
            table.data.cards tr {
                background: var(--card); border: 1px solid var(--line); border-radius: 10px;
                margin-bottom: 0.6rem; padding: 0.55rem 0.75rem;
            }
            table.data.cards tr:nth-child(even) td { background: transparent; }
            table.data.cards td {
                border: 0; padding: 0.12rem 0; white-space: normal; text-align: left !important;
                display: flex; justify-content: space-between; gap: 1rem; font-size: 0.88rem;
            }
            table.data.cards td::before { content: attr(data-label); color: var(--muted); font-size: 0.78rem; }
            table.data.cards td.stickycol {
                position: static; box-shadow: none; display: block;
                font-weight: 700; font-size: 0.95rem; padding-bottom: 0.3rem;
            }
            table.data.cards td.stickycol::before { content: none; }
            /* Nullwerte und leere Zellen kosten auf der Karte nur Platz. */
            table.data.cards td.blank { display: none; }
            table.data.cards tfoot tr { background: var(--ink); border-color: var(--ink); }
            table.data.cards tfoot td, table.data.cards tfoot td.stickycol { background: transparent; color: #fff; }
            table.data.cards tfoot td::before { color: #94a3b8; }
        }
        .tabs { display: flex; gap: 0.5rem; margin-bottom: 0.75rem; }
        .tab {
            border: 1px solid var(--line);
            background: var(--card);
            border-radius: 8px;
            padding: 0.4rem 1rem;
            cursor: pointer;
            font: inherit;
            font-size: 0.9rem;
        }
        .tab.active { background: var(--ink); color: #fff; border-color: var(--ink); }
        .searchbox { margin-bottom: 0.75rem; }
        .searchbox input { margin-bottom: 0; max-width: 320px; }
        footer.site { text-align: center; color: var(--muted); font-size: 0.8rem; padding: 2rem 1rem 3rem; }
        footer.site nav { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; margin-bottom: 0.5rem; }
        footer.site a { color: var(--muted); }
    </style>
</head>
<body>
<header class="site">
    <div style="display:flex;align-items:baseline;gap:0.5rem;">
        <a href="{{ route('home') }}" class="brand" style="color:#fff;text-decoration:none;">Wear Together <span class="dot">●</span> Order Suite</a>
        <span title="Versionsnummer — zeigt, ob der letzte Push bereits deployt wurde" style="color:#64748b;font-size:0.75rem;font-weight:600;">v{{ trim(@file_get_contents(base_path('VERSION')) ?: '?') }}</span>
    </div>
    <nav style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
        @php($isTool = request()->routeIs('tool.*', 'shop.*', 'job.*'))
        @php($isSchools = request()->routeIs('schools.*'))
        @php($isClose = request()->routeIs('close-window.*'))
        @php($isBalance = request()->routeIs('balance.*'))
        @php($isStats = request()->routeIs('statistics.*'))
        @php($isAdmin = request()->routeIs('admin.*'))
        <a href="{{ route('home') }}" style="color:{{ request()->routeIs('home') ? '#ffbb00' : '#cbd5e1' }};text-decoration:none;font-weight:600;font-size:0.9rem;">Startseite</a>
        <span style="color:#475569;">|</span>
        <a href="{{ route('tool.index') }}" style="color:{{ $isTool ? '#ffbb00' : '#cbd5e1' }};text-decoration:none;font-weight:600;font-size:0.9rem;">Auftragsdokumente</a>
        <span style="color:#475569;">|</span>
        <a href="{{ route('schools.index') }}" style="color:{{ $isSchools ? '#ffbb00' : '#cbd5e1' }};text-decoration:none;font-weight:600;font-size:0.9rem;">Schul-Onboarding</a>
        <span style="color:#475569;">|</span>
        <a href="{{ route('close-window.index') }}" style="color:{{ $isClose ? '#ffbb00' : '#cbd5e1' }};text-decoration:none;font-weight:600;font-size:0.9rem;">Bestellfenster schließen</a>
        <span style="color:#475569;">|</span>
        <a href="{{ route('balance.index') }}" style="color:{{ $isBalance ? '#ffbb00' : '#cbd5e1' }};text-decoration:none;font-weight:600;font-size:0.9rem;">Auftragsbilanz</a>
        <span style="color:#475569;">|</span>
        <a href="{{ route('statistics.index') }}" style="color:{{ $isStats ? '#ffbb00' : '#cbd5e1' }};text-decoration:none;font-weight:600;font-size:0.9rem;">Statistiken</a>
        {{-- Deutlich abgesetzt: das ist der Prüfstand, nicht ein weiteres Modul --}}
        <a href="{{ route('admin.status') }}" title="Schnittstellen und Webhook prüfen"
           style="margin-left:0.5rem;padding:0.25rem 0.7rem;border:1px solid {{ $isAdmin ? '#ffbb00' : '#475569' }};border-radius:999px;color:{{ $isAdmin ? '#ffbb00' : '#cbd5e1' }};text-decoration:none;font-weight:600;font-size:0.9rem;">🛠 Admin-Informationen</a>
        @if (config('ordersuite.password') !== '' && session('tool_authenticated'))
            <form method="post" action="{{ route('logout') }}" style="margin-left:0.75rem;">
                @csrf
                <button class="btn secondary" type="submit" style="padding:0.35rem 0.9rem;font-size:0.85rem;">Abmelden</button>
            </form>
        @endif
    </nav>
</header>
<main>
    @yield('content')
</main>
<footer class="site">
    <nav>
        <a href="{{ route('home') }}">Startseite</a><span>·</span>
        <a href="{{ route('tool.index') }}">Auftragsdokumente</a><span>·</span>
        <a href="{{ route('schools.index') }}">Schul-Onboarding</a><span>·</span>
        <a href="{{ route('close-window.index') }}">Bestellfenster schließen</a><span>·</span>
        <a href="{{ route('balance.index') }}">Auftragsbilanz</a><span>·</span>
        <a href="{{ route('statistics.index') }}">Statistiken</a><span>·</span>
        <a href="{{ route('admin.status') }}"><strong>Admin-Informationen</strong> (Schnittstellen &amp; Webhook prüfen)</a>
    </nav>
    Wear Together Order Suite — Nachfolger der Wear Together Toolsuite
</footer>

<script>
    // Info-Symbole: Tippen/Klicken öffnet die Erklärung, ein zweites schließt
    // sie. Bewusst kein Mouseover — auf dem Telefon gibt es keines.
    (function () {
        function closeAll(except) {
            document.querySelectorAll('.info-toggle[aria-expanded="true"]').forEach(function (btn) {
                if (btn === except) return;
                btn.setAttribute('aria-expanded', 'false');
                const box = btn.nextElementSibling;
                if (box) box.hidden = true;
            });
        }

        document.addEventListener('click', function (event) {
            const toggle = event.target.closest('.info-toggle');
            if (! toggle) { closeAll(null); return; }

            event.preventDefault();
            const box = toggle.nextElementSibling;
            const open = toggle.getAttribute('aria-expanded') === 'true';
            closeAll(toggle);
            toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
            if (! box) return;
            box.hidden = open;
            if (open) return;

            // Waagrecht ins Bild schieben. Auf schmalen Schirmen ragt der Kasten
            // sonst links oder rechts hinaus — je nachdem, wo das Symbol steht.
            box.style.left = '0px';
            const margin = 8;
            const width = document.documentElement.clientWidth;
            const rect = box.getBoundingClientRect();
            let shift = 0;
            if (rect.right > width - margin) shift = width - margin - rect.right;
            if (rect.left + shift < margin) shift = margin - rect.left;
            box.style.left = shift + 'px';
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeAll(null);
        });
    })();
</script>
</body>
</html>
