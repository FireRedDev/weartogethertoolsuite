@extends('layouts.app')

@section('title', 'Bestellfenster schließen')

@section('content')
    <div class="card">
        <h1>Bestellfenster schließen</h1>
        <p class="lead">Schule auswählen — alle zugehörigen Shop-Produkte werden auf <strong>privat</strong> gesetzt
            (für Kund:innen nicht mehr sichtbar oder bestellbar) und im Schule-Eintrag wird
            <strong>„Bestellfenster offen" auf NEIN</strong> gestellt.</p>

        @if (session('closedSchool'))
            <div class="alert ok">✓ Bestellfenster für <strong>{{ session('closedSchool') }}</strong> geschlossen.</div>
        @endif
        @if ($errors->any())
            @foreach ($errors->all() as $error)
                <div class="alert error">✖ {{ $error }}</div>
            @endforeach
        @endif

        @if (session('closeError'))
            @php($closeError = session('closeError'))
            <div class="alert error">
                ✖ <strong>{{ $closeError['user'] }}</strong>
                @if ($closeError['hint'])<div style="margin-top:0.4rem;">{{ $closeError['hint'] }}</div>@endif
                <details class="warnrows" open>
                    <summary>Technische Details (zum Kopieren, für Support)</summary>
                    <textarea readonly rows="3" style="font-family:ui-monospace,monospace;font-size:0.8rem;margin-top:0.4rem;" onclick="this.select()">{{ $closeError['technical'] }}</textarea>
                </details>
            </div>
        @endif

        @if (session('closeLog'))
            <div class="alert {{ collect(session('closeLog'))->every(fn ($l) => $l['ok']) ? 'ok' : 'error' }}">
                <strong>Protokoll:</strong>
                <ol style="margin:0.5rem 0 0 1.2rem;">
                    @foreach (session('closeLog') as $entry)
                        <li>{{ $entry['ok'] ? '✓' : '✖' }} {{ $entry['step'] }}{{ $entry['detail'] ? ' — '.$entry['detail'] : '' }}</li>
                    @endforeach
                </ol>
            </div>
        @endif

        @if ($schools->isEmpty())
            <div class="alert warn">Es gibt noch keine angelegte Schule, deren Bestellfenster geschlossen werden könnte.
                Zuerst im <a href="{{ route('schools.index') }}">Schul-Onboarding</a> eine Schule im Shop anlegen.</div>
        @else
            <form method="post" id="close-form" onsubmit="return confirmClose();">
                @csrf
                <label for="school_select">Schule</label>
                <select id="school_select" name="school_select" required
                        style="width:100%;max-width:480px;padding:0.6rem 0.75rem;border:1px solid var(--line);border-radius:8px;font:inherit;background:#fff;margin-bottom:1rem;">
                    <option value="" disabled selected>— bitte auswählen —</option>
                    @foreach ($schools as $school)
                        <option value="{{ route('close-window.close', $school) }}"
                                data-name="{{ $school->school_name }}"
                                data-status="{{ $school->statusLabel() }}">
                            {{ $school->school_name }} ({{ $school->deliveryTypeLabel() }}, Status: {{ $school->statusLabel() }})
                        </option>
                    @endforeach
                </select>
                <div>
                    <button class="btn" type="submit" style="background:var(--error);color:#fff;">Bestellfenster schließen</button>
                    <a class="btn secondary" href="{{ route('schools.index') }}" style="margin-left:0.5rem;">Zum Schul-Onboarding</a>
                </div>
            </form>
        @endif
    </div>

    <script>
        function confirmClose() {
            const select = document.getElementById('school_select');
            const option = select.options[select.selectedIndex];
            if (! select.value) return false;
            const ok = confirm('Bestellfenster für „' + option.dataset.name + '" wirklich schließen?\n\n'
                + 'Alle Produkte dieser Schule werden auf privat gesetzt und „Bestellfenster offen" auf NEIN gestellt.');
            if (ok) {
                document.getElementById('close-form').action = select.value;
            }
            return ok;
        }
    </script>
@endsection
