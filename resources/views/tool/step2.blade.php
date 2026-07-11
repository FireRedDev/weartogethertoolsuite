@extends('layouts.app')

@section('title', 'Auftrag & Prüfung — Wear Together Order Suite')

@section('content')
    <div class="steps">
        <span class="step done">1 · Hochladen ✓</span>
        <span class="step active">2 · Auftrag & Prüfung</span>
        <span class="step">3 · Dokumente</span>
    </div>

    @php($validation = $meta['validation'])
    @php($blocked = $validation['errors'] !== [])

    <div class="card">
        <h1>Prüfbericht</h1>
        <p class="lead">
            {{ $meta['original_filename'] ?? 'Export' }} —
            <strong>{{ $meta['positions'] }}</strong> Positionen,
            <strong>{{ $meta['pieces'] }}</strong> Stück gesamt
        </p>

        @foreach ($validation['errors'] as $error)
            <div class="alert error">✖ {{ $error }}</div>
        @endforeach

        @foreach ($validation['warnings'] as $warning)
            <div class="alert warn">
                ⚠ {{ $warning['message'] }}
                <details class="warnrows">
                    <summary>{{ count($warning['rows']) }} betroffene Zeile(n) im Export anzeigen</summary>
                    Zeilen: {{ implode(', ', $warning['rows']) }}
                </details>
            </div>
        @endforeach

        @if (! $blocked && $validation['warnings'] === [])
            <div class="alert ok">✓ Keine Auffälligkeiten — der Export sieht gut aus.</div>
        @elseif (! $blocked)
            <div class="alert ok">✓ Keine blockierenden Fehler — die Dokumente können erstellt werden. Warnungen ändern den Output nicht (Legacy-Verhalten), bitte nur prüfen.</div>
        @endif
    </div>

    @unless ($blocked)
        <div class="card">
            <h1>Auftragsdaten</h1>

            @if ($errors->any())
                @foreach ($errors->all() as $error)
                    <div class="alert error">{{ $error }}</div>
                @endforeach
            @endif

            <form method="post" action="{{ route('job.generate', $jobId) }}">
                @csrf
                <label for="ordername">Name der Schule/Organisation <span class="hint">(wird Teil der Dateinamen, z. B. AHS_Korneuburg)</span></label>
                <input type="text" id="ordername" name="ordername" required maxlength="120" value="{{ old('ordername') }}" placeholder="z. B. AHS Korneuburg">

                <label for="orderinformation">Informationen für den Lieferanten <span class="hint">(optional — landet im Sheet „Auftragsinformationen")</span></label>
                <textarea id="orderinformation" name="orderinformation" rows="3" maxlength="2000" placeholder="z. B. Liefertermin, Ansprechpartner …">{{ old('orderinformation') }}</textarea>

                <button class="btn" type="submit">Dokumente erstellen</button>
                <a class="btn secondary" href="{{ route('tool.index') }}" style="margin-left:0.5rem;">Anderen Export hochladen</a>
            </form>
        </div>
    @else
        <a class="btn" href="{{ route('tool.index') }}">Zurück zum Upload</a>
    @endunless
@endsection
