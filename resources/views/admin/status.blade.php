@extends('layouts.app')

@section('title', 'Admin-Informationen — Wear Together Order Suite')

@section('content')
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div>
                <h1>Admin-Informationen</h1>
                <p class="lead">Live-Status aller API-Anbindungen, Webhooks und Schnittstellen — bei jedem Aufruf dieser
                    Seite neu geprüft.</p>
            </div>
            <a class="btn secondary" href="{{ route('admin.status') }}">↻ Erneut prüfen</a>
        </div>

        <div class="alert ok" style="margin-top:0.5rem;">
            Wechselt eine konfigurierte Schnittstelle von OK auf fehlgeschlagen, schickt die Toolsuite
            <strong>einmalig</strong> eine Benachrichtigung — aber ausschließlich über die WordPress-REST-API
            (<code>wp_mail()</code> auf der WordPress-Seite). Die Toolsuite selbst hat keinen Mailer und verschickt nie
            direkt E-Mails. Das erfordert ein kleines mu-Plugin auf der WordPress-Seite —
            siehe <code>wordpress-mu-plugin/weartogether-notify.php</code> im Repository. Ist es nicht installiert,
            funktioniert alles andere trotzdem; die E-Mail fällt dann einfach aus (unten je Zeile ersichtlich).
        </div>

        <div class="tablewrap" style="margin-top:1rem;">
            <table class="data">
                <thead>
                    <tr><th>Schnittstelle</th><th>Status</th><th>Details</th><th>Benachrichtigung</th></tr>
                </thead>
                <tbody>
                    @foreach ($results as $result)
                        <tr>
                            <td>{{ $result['label'] }}</td>
                            <td>
                                @if (! $result['configured'])
                                    <span class="hint">— nicht eingerichtet</span>
                                @elseif ($result['ok'])
                                    <span style="color:var(--ok);font-weight:600;">✓ OK</span>
                                @else
                                    <span style="color:var(--error);font-weight:600;">✖ Fehler</span>
                                @endif
                            </td>
                            <td style="white-space:normal;max-width:420px;">{{ $result['message'] }}</td>
                            <td style="white-space:normal;max-width:280px;">
                                @if ($result['notify'] === null)
                                    <span class="hint">—</span>
                                @elseif ($result['notify']['ok'])
                                    <span style="color:var(--ok);">✓ E-Mail über WordPress ausgelöst</span>
                                @else
                                    <span style="color:var(--warn);">⚠ nicht zugestellt: {{ $result['notify']['detail'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="hint" style="margin-top:0.75rem;">
            Der FluentForms-Webhook empfängt nur (kein aktiver Verbindungstest möglich) — jeder Aufruf steht unten
            in der Webhook-Diagnose. Er löst nie automatisch eine Benachrichtigung aus.
        </p>
    </div>

    <div class="card">
        @include('partials.webhook-log')
    </div>

    <div class="card">
        <h2>Version &amp; Umgebung</h2>
        <div class="tablewrap">
            <table class="data">
                <tbody>
                    <tr><th style="width:240px;">Version</th><td>v{{ trim(@file_get_contents(base_path('VERSION')) ?: '?') }} <span class="hint">— steht auch in der Navigationsleiste; stimmt sie nicht mit dem letzten Push überein, wurde noch nicht deployt</span></td></tr>
                    <tr><th>Shop-Adresse</th><td><code>{{ config('ordersuite.woocommerce.store_url') ?: '— nicht gesetzt' }}</code></td></tr>
                    <tr><th>Webhook-Secret</th><td>{{ config('schoolshop.webhook_secret') ? '✓ gesetzt' : '✖ fehlt' }}</td></tr>
                    <tr><th>Zugangsschutz (TOOL_PASSWORD)</th><td>{{ config('ordersuite.password') !== '' ? '✓ aktiv' : '— kein Login nötig' }}</td></tr>
                    <tr><th>PHP</th><td>{{ PHP_VERSION }}</td></tr>
                    <tr><th>Konfigurations-Cache</th><td>{{ file_exists(base_path('bootstrap/cache/config.php')) ? '✓ aktiv — nach .env-Änderungen php artisan config:cache ausführen' : '— nicht aktiv' }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection
