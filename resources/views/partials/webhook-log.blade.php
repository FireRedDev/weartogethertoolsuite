{{--
    Webhook-Diagnose: zeigt JEDEN Treffer auf den Webhook-Endpunkt, noch vor
    Secret- und Zuordnungslogik. Wird sowohl unter Admin-Informationen als auch
    im Schul-Onboarding eingebunden — dort sucht man danach, wenn ein Antrag
    nicht angekommen ist.

    Erwartet: $webhookLogs
--}}
<h2>Webhook-Diagnose <span class="hint">FluentForms-Eingang</span>
    <x-info label="Wozu die Webhook-Diagnose?">
        Hier erscheint <strong>jeder</strong> Aufruf der Webhook-URL — auch Browser-Tests und Aufrufe mit falschem
        Secret, noch bevor irgendetwas geprüft wird. Damit lässt sich zweifelsfrei sehen, ob FluentForms die App
        überhaupt erreicht.<br><br>
        Webhook-URL: <code>{{ url('/webhooks/fluentforms/'.(config('schoolshop.webhook_secret') ? '<SECRET>' : '')) }}</code><br>
        Dieselbe Adresse im Browser zu öffnen ist ein gültiger Test und muss hier auftauchen.
    </x-info>
</h2>

@if ($webhookLogs->isEmpty())
    <div class="alert warn">
        Noch <strong>kein einziger</strong> Aufruf registriert.
        <x-info label="Woran kann das liegen?">
            Erscheint nach einer Formular-Einsendung hier nichts — und trägt auch der Browser-Test der URL nichts
            ein —, erreicht die Anfrage die App gar nicht. Mögliche Ursachen: SSL oder Netzwerk zwischen dem
            WordPress-Server und dieser Domain, eine vorgelagerte Basic-Auth (RunCloud), oder der
            FluentForms-Webhook löst gar nicht aus.
        </x-info>
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
