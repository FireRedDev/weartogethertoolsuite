@extends('layouts.app')

@section('title', 'Schul-Onboarding — Wear Together Order Suite')

@section('content')
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div>
                <h1>Schul-Onboarding</h1>
                <p class="lead">Anfragen aus dem Webshopstartfragebogen — von der Formular-Einsendung bis zur fertigen Shop-Anlage.</p>
            </div>
            <a class="btn" href="{{ route('schools.create') }}">+ Schule manuell anlegen</a>
        </div>

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
@endsection
