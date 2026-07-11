@extends('layouts.app')

@section('title', 'Aus dem Shop laden — Wear Together Order Suite')

@section('content')
    <div class="steps">
        <span class="step active">1 · Bestellungen laden</span>
        <span class="step">2 · Auftrag & Prüfung</span>
        <span class="step">3 · Dokumente</span>
    </div>

    <div class="card">
        <h1>Direkt aus dem Shop laden</h1>
        <p class="lead">Lädt die Bestellungen über die Shop-Schnittstelle — mit denselben Einstellungen wie der bisherige Plugin-Export (Status „In Bearbeitung", „In Wartestellung", „Abgeschlossen"; eine Zeile pro Bestellposition; neueste Bestellungen zuerst).</p>

        @if ($apiError !== null)
            <div class="alert error">
                ✖ <strong>{{ $apiError->userMessage() }}</strong>
                @if ($apiError->hint())
                    <div style="margin-top:0.4rem;">{{ $apiError->hint() }}</div>
                @endif
                <details class="warnrows">
                    <summary>Technische Details (für Support)</summary>
                    <code style="word-break:break-all;">{{ $apiError->getMessage() }}</code>
                </details>
            </div>
            <a class="btn secondary" href="{{ route('shop.form') }}">Erneut versuchen</a>
            <a class="btn secondary" href="{{ route('tool.index') }}" style="margin-left:0.5rem;">Zurück (Weg 2: Datei hochladen)</a>
        @else
            @if (session('apiFetchError'))
                <div class="alert error">
                    ✖ <strong>{{ session('apiFetchError')['user'] }}</strong>
                    @if (session('apiFetchError')['hint'])
                        <div style="margin-top:0.4rem;">{{ session('apiFetchError')['hint'] }}</div>
                    @endif
                    <details class="warnrows">
                        <summary>Technische Details (für Support)</summary>
                        <code style="word-break:break-all;">{{ session('apiFetchError')['technical'] }}</code>
                    </details>
                </div>
            @endif

            @if ($errors->any())
                @foreach ($errors->all() as $error)
                    <div class="alert error">{{ $error }}</div>
                @endforeach
            @endif

            <form method="post" action="{{ route('shop.fetch') }}" id="shopform">
                @csrf
                <label for="category">Schule/Organisation <span class="hint">(Produktkategorie im Shop)</span></label>
                <select id="category" name="category" required style="width:100%;padding:0.6rem 0.75rem;border:1px solid var(--line);border-radius:8px;font:inherit;margin-bottom:1rem;background:#fff;">
                    <option value="" disabled {{ old('category') ? '' : 'selected' }}>Bitte auswählen …</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category['id'] }}" {{ (string) old('category') === (string) $category['id'] ? 'selected' : '' }}>
                            {{ $category['name'] }} ({{ $category['count'] }} Produkte)
                        </option>
                    @endforeach
                </select>
                <input type="hidden" name="category_name" id="category_name" value="{{ old('category_name') }}">

                <label>Bestellstatus</label>
                <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
                    @foreach ($statuses as $key => $label)
                        <label style="font-weight:400;display:flex;align-items:center;gap:0.35rem;">
                            <input type="checkbox" name="statuses[]" value="{{ $key }}"
                                {{ in_array($key, old('statuses', $defaultStatuses), true) ? 'checked' : '' }}>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>

                <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                    <div>
                        <label for="date_from">Bestellungen von <span class="hint">(optional)</span></label>
                        <input type="date" id="date_from" name="date_from" value="{{ old('date_from') }}" style="width:auto;">
                    </div>
                    <div>
                        <label for="date_to">bis <span class="hint">(optional)</span></label>
                        <input type="date" id="date_to" name="date_to" value="{{ old('date_to') }}" style="width:auto;">
                    </div>
                </div>

                <button class="btn" type="submit" id="fetchbtn">Bestellungen laden</button>
                <a class="btn secondary" href="{{ route('tool.index') }}" style="margin-left:0.5rem;">Zurück</a>
                <span class="hint" id="loadinghint" style="display:none;margin-left:0.75rem;">Lade Bestellungen aus dem Shop — das kann bei vielen Bestellungen eine Minute dauern …</span>
            </form>
        @endif
    </div>

    <script>
        const form = document.getElementById('shopform');
        if (form) {
            const select = document.getElementById('category');
            select.addEventListener('change', () => {
                document.getElementById('category_name').value =
                    select.options[select.selectedIndex].text.replace(/\s*\(\d+ Produkte\)$/, '');
            });
            form.addEventListener('submit', () => {
                document.getElementById('fetchbtn').disabled = true;
                document.getElementById('loadinghint').style.display = 'inline';
            });
        }
    </script>
@endsection
