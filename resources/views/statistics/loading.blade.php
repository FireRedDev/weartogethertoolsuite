@extends('layouts.app')

@section('title', 'Statistiken werden aufgebaut — Wear Together Order Suite')

@section('content')
    {{--
        Solange nicht alle Monate geladen sind, wird bewusst KEINE Zahl gezeigt —
        eine halbe Auswertung wäre schlimmer als keine. Der Aufbau läuft im
        Hintergrund weiter, auch wenn diese Seite geschlossen wird.
    --}}
    <div class="card">
        <h1 style="margin:0 0 0.35rem;">Statistiken
            <x-info label="Warum dauert das?">
                Ausgewertet werden die echten Bestellungen aus dem Shop. Beim ersten Aufruf holt die Toolsuite dafür
                {{ $progress['total'] }} Datenpakete (den Produktkatalog und je einen Monat) — bewusst langsam und
                immer nur eines nach dem anderen, damit der Webshop auf demselben Server nicht ausgebremst wird.
                Danach ist alles gespeichert und die Auswertung erscheint sofort.
            </x-info>
        </h1>

        <div class="loading-block">
            <div class="loading-head">
                <span class="spinner" aria-hidden="true"></span>
                <div>
                    <div class="loading-title" id="loading-title">Die Auswertung wird aufgebaut …</div>
                    <div class="hint" id="loading-sub">
                        {{ $progress['loaded'] }} von {{ $progress['total'] }} Datenpaketen geladen
                    </div>
                </div>
            </div>

            <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                 aria-valuenow="{{ $progress['percent'] }}" aria-labelledby="loading-title">
                <div class="progress-fill" id="progress-fill" style="width:{{ max(2, $progress['percent']) }}%"></div>
            </div>
            <div class="hint" id="progress-percent" style="margin-top:0.35rem;">{{ $progress['percent'] }} %</div>

            <p class="hint" style="margin:0.85rem 0 0;">
                Diese Seite aktualisiert sich von selbst und zeigt die Auswertung, sobald alles da ist.
                <strong>Du kannst sie auch schließen</strong> — der Aufbau läuft im Hintergrund weiter.
            </p>
        </div>

        <div class="alert error" id="loading-error" @if (! $progress['error']) hidden @endif style="margin-top:1rem;">
            ✖ <span id="loading-error-message">{{ $progress['error']['message'] ?? '' }}</span>
            <details class="warnrows" open>
                <summary>Technische Details (zum Kopieren, für Support)</summary>
                <textarea id="loading-error-technical" readonly rows="3"
                          style="font-family:ui-monospace,monospace;font-size:0.8rem;margin-top:0.4rem;"
                          onclick="this.select()">{{ $progress['error']['technical'] ?? '' }}</textarea>
            </details>
            <div style="margin-top:0.5rem;">
                <a class="btn" href="{{ route('statistics.index', $filters->query()) }}">Erneut versuchen</a>
            </div>
        </div>

        @include('statistics._filters')
    </div>

    <script>
        // Fortschritt abfragen, Balken nachziehen, bei „fertig" die Auswertung
        // laden. Jede Abfrage stößt serverseitig den nächsten Ladeschritt an —
        // der läuft aber unabhängig von dieser Seite weiter.
        (function () {
            const url = @json(route('statistics.progress', $filters->query()));
            const every = @json((int) config('statistics.poll_seconds') * 1000);
            const fill = document.getElementById('progress-fill');
            const percent = document.getElementById('progress-percent');
            const sub = document.getElementById('loading-sub');
            const title = document.getElementById('loading-title');
            const errorBox = document.getElementById('loading-error');
            let misses = 0;

            async function tick() {
                try {
                    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    if (! response.ok) throw new Error('HTTP ' + response.status);
                    const data = await response.json();
                    misses = 0;

                    fill.style.width = Math.max(2, data.percent) + '%';
                    percent.textContent = data.percent + ' %';
                    sub.textContent = data.loaded + ' von ' + data.total + ' Datenpaketen geladen';

                    if (data.error) {
                        document.getElementById('loading-error-message').textContent = data.error.message;
                        document.getElementById('loading-error-technical').value = data.error.technical;
                        errorBox.hidden = false;
                        title.textContent = 'Der Aufbau wurde unterbrochen';
                        return; // nicht weiter abfragen
                    }

                    if (data.done) {
                        title.textContent = 'Fertig — Auswertung wird geöffnet …';
                        window.location.reload();
                        return;
                    }
                } catch (e) {
                    // Kurze Aussetzer (Neustart, Netz) nicht sofort als Fehler
                    // zeigen — erst nach mehreren Versuchen aufgeben.
                    misses++;
                    if (misses >= 10) {
                        title.textContent = 'Keine Verbindung zum Server';
                        return;
                    }
                }
                setTimeout(tick, every);
            }

            setTimeout(tick, every);
        })();
    </script>
@endsection
