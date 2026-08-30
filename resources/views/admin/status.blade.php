@extends('layouts.app')

@section('title', 'Admin-Informationen — Wear Together Order Suite')

@section('content')
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div>
                <h1>Admin-Informationen</h1>
                <p class="lead">Bei jedem Aufruf dieser Seite neu geprüft.</p>
            </div>
            <a class="btn secondary" href="{{ route('admin.status') }}">↻ Erneut prüfen</a>
        </div>

        <x-explain title="Was passiert, wenn eine Schnittstelle ausfällt?">
            <p>Wechselt eine konfigurierte Schnittstelle von OK auf fehlgeschlagen, schickt die Toolsuite
                <strong>einmalig</strong> eine Benachrichtigung — aber ausschließlich über die WordPress-REST-API
                (<code>wp_mail()</code> auf der WordPress-Seite). Die Toolsuite selbst hat keinen Mailer und
                verschickt nie direkt E-Mails.</p>
            <p>Dafür braucht es ein kleines mu-Plugin auf der WordPress-Seite, siehe
                <code>wordpress-mu-plugin/weartogether-notify.php</code> im Repository. Fehlt es, funktioniert alles
                andere trotzdem — nur die E-Mail fällt aus (unten je Zeile ersichtlich).</p>
        </x-explain>

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
            Der FluentForms-Webhook lässt sich nicht aktiv testen
            <x-info label="Warum nicht?">
                Er empfängt nur — die Toolsuite kann ihn nicht von sich aus auslösen. Statt eines Verbindungstests
                zeigt die Webhook-Diagnose unten jeden eingegangenen Aufruf. Eine Benachrichtigung löst er nie aus,
                sonst käme bei jedem Aufruf mit falschem Secret eine E-Mail.
            </x-info>
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
                    <tr><th style="width:240px;">Version</th><td>v{{ trim(@file_get_contents(base_path('VERSION')) ?: '?') }}
                        <x-info label="Wozu die Versionsnummer?">
                            Steht auch in der Navigationsleiste. Stimmt sie nicht mit dem letzten Push überein,
                            wurde noch nicht deployt.
                        </x-info>
                    </td></tr>
                    <tr><th>Shop-Adresse</th><td><code>{{ config('ordersuite.woocommerce.store_url') ?: '— nicht gesetzt' }}</code></td></tr>
                    <tr><th>Webhook-Secret</th><td>{{ config('schoolshop.webhook_secret') ? '✓ gesetzt' : '✖ fehlt' }}</td></tr>
                    <tr><th>Zugangsschutz (TOOL_PASSWORD)</th><td>{{ config('ordersuite.password') !== '' ? '✓ aktiv' : '— kein Login nötig' }}</td></tr>
                    <tr><th>PHP</th><td>{{ PHP_VERSION }}</td></tr>
                    <tr><th>Konfigurations-Cache</th><td>{{ file_exists(base_path('bootstrap/cache/config.php')) ? '✓ aktiv — nach .env-Änderungen php artisan config:cache ausführen' : '— nicht aktiv' }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Datensicherung
            <x-info label="Was enthält die Sicherung?">
                Die Datenbank (alle Anträge samt Konfiguration und Protokollen) und die hochgeladenen Dateien
                (Schullogos, Mockups) als ZIP. Die Zugangsdaten aus der <code>.env</code> sind bewusst
                <strong>nicht</strong> enthalten.
            </x-info>
        </h2>
        <form method="post" action="{{ route('admin.backup') }}">
            @csrf
            <button class="btn" type="submit">Sicherung herunterladen</button>
        </form>
        <x-explain title="Automatisch sichern (Cron)">
            <p><code>30 3 * * * cd {{ base_path() }} && php artisan backup:create</code></p>
            <p>Die letzten fünf Sicherungen bleiben unter <code>storage/app/backups</code> liegen, ältere werden
                automatisch entfernt.</p>
        </x-explain>
    </div>
@endsection
