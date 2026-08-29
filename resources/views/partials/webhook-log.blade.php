{{--
    Webhook-Diagnose: zeigt JEDEN Treffer auf den Webhook-Endpunkt, noch vor
    Secret- und Zuordnungslogik. Wird sowohl unter Admin-Informationen als auch
    im Schul-Onboarding eingebunden — dort sucht man danach, wenn ein Antrag
    nicht angekommen ist.

    Erwartet: $webhookLogs
--}}
<h2>Webhook-Diagnose <span class="hint">FluentForms-Eingang</span></h2>
<p class="lead">Hier erscheint <strong>jeder</strong> Aufruf der Webhook-URL (auch Browser-Tests und Aufrufe mit
    falschem Secret) — noch bevor irgendetwas geprüft wird. Damit lässt sich zweifelsfrei sehen, ob FluentForms
    die App wirklich erreicht.</p>
<p class="hint">Webhook-URL: <code>{{ url('/webhooks/fluentforms/'.(config('schoolshop.webhook_secret') ? '<SECRET>' : '')) }}</code>
    — dieselbe Adresse im Browser öffnen ist ein gültiger Test und muss hier auftauchen.</p>

@if ($webhookLogs->isEmpty())
    <div class="alert warn">
        Noch <strong>kein einziger</strong> Aufruf registriert. Wenn nach einer Formular-Einsendung hier nichts
        erscheint (und auch der Browser-Test der URL nichts einträgt), erreicht die Anfrage die App gar nicht —
        dann liegt es an SSL/Netzwerk zwischen dem WordPress-Server und dieser Domain, an einer vorgelagerten
        Basic-Auth (RunCloud) oder daran, dass der FluentForms-Webhook nicht wirklich auslöst.
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
