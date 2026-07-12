@extends('layouts.app')

@section('title', 'Schul-Onboarding — Wear Together Order Suite')

@section('content')
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div>
                <h1>Schul-Onboarding</h1>
                <p class="lead">Alle Onboarding-Anfragen (auch noch nicht angelegte) — von der Formular-Einsendung bis zur fertigen Shop-Anlage. Ob eine Schule im Shop angelegt ist, zeigt die Spalte „Status".</p>
            </div>
            <a class="btn" href="{{ route('schools.create') }}">+ Schule manuell anlegen</a>
        </div>

        @if (session('deleted'))
            <div class="alert ok" style="margin-top:1rem;">✓ Antrag „{{ session('deleted') }}" gelöscht.</div>
        @endif

        @if ($onboardings->isEmpty())
            <div class="alert ok" style="margin-top:1rem;">
                Noch keine Anfragen. Sobald der FluentForms-Webhook eingerichtet ist
                (URL: <code>{{ url('/webhooks/fluentforms/&lt;SECRET&gt;') }}</code>),
                erscheinen neue Formular-Einsendungen automatisch hier.
            </div>
        @else
            <div class="tablewrap" style="margin-top:1rem;">
                <table class="data">
                    <thead>
                        <tr>
                            <th>#</th><th>Schule/Organisation</th><th>Status</th><th>Lieferart</th>
                            <th>Bestellfenster</th><th>Kontakt</th><th>Eingang</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($onboardings as $onboarding)
                            <tr>
                                <td>{{ $onboarding->id }}</td>
                                <td><strong>{{ $onboarding->school_name }}</strong></td>
                                <td>{{ $onboarding->statusLabel() }}</td>
                                <td>{{ $onboarding->deliveryTypeLabel() }}</td>
                                <td>
                                    @if ($onboarding->window_start)
                                        {{ $onboarding->window_start->format('d.m.Y') }} – {{ $onboarding->window_end?->format('d.m.Y') ?? '?' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $onboarding->contact_name }} <span class="hint">{{ $onboarding->contact_email }}</span></td>
                                <td>{{ $onboarding->created_at->format('d.m.Y H:i') }}</td>
                                <td><a class="btn secondary" style="padding:0.3rem 0.8rem;font-size:0.85rem;" href="{{ route('schools.show', $onboarding) }}">Öffnen</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Webhook-Diagnose: zeigt JEDEN Treffer auf den Webhook-Endpunkt, noch vor Secret-/Zuordnungslogik --}}
    <div class="card">
        <h2>Webhook-Diagnose</h2>
        <p class="lead">Hier erscheint <strong>jeder</strong> Aufruf der Webhook-URL (auch Browser-Tests und Aufrufe mit
            falschem Secret) — noch bevor irgendetwas geprüft wird. Damit lässt sich zweifelsfrei sehen, ob FluentForms
            die App wirklich erreicht.</p>
        @if ($webhookLogs->isEmpty())
            <div class="alert warn">
                Noch <strong>kein einziger</strong> Aufruf registriert. Wenn nach einer Formular-Einsendung hier nichts
                erscheint (und auch der Browser-Test der URL nichts einträgt), erreicht die Anfrage die App gar nicht —
                dann liegt es an SSL/Netzwerk zwischen dem WordPress-Server und dieser Domain oder daran, dass der
                FluentForms-Webhook nicht wirklich auslöst.
            </div>
        @else
            <div class="tablewrap">
                <table class="data">
                    <thead>
                        <tr><th>Zeit</th><th>Methode</th><th>Secret</th><th>IP</th><th>Content-Type</th><th>Ergebnis</th><th>Rohdaten</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($webhookLogs as $log)
                            <tr>
                                <td>{{ $log->created_at->format('d.m.Y H:i:s') }}</td>
                                <td>{{ $log->method }}</td>
                                <td>{{ $log->secret_ok ? '✓' : '✖' }}</td>
                                <td>{{ $log->ip }}</td>
                                <td>{{ $log->content_type ?: '—' }}</td>
                                <td>{{ $log->outcome }}</td>
                                <td>
                                    @if ($log->body_snippet)
                                        <details><summary>zeigen</summary><textarea readonly rows="6" style="min-width:320px;font-family:ui-monospace,monospace;font-size:0.75rem;" onclick="this.select()">{{ $log->body_snippet }}</textarea></details>
                                    @else — @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
