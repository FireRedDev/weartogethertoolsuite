@extends('layouts.app')

@section('title', 'Statistiken — Wear Together Order Suite')

@section('content')
    @php
        $euro = fn ($v) => number_format((float) $v, 2, ',', '.').' €';
    @endphp

    <div class="card">
        <h1>Statistiken</h1>

        <div class="alert error">
            ✖ {{ $error }}
            @if ($technical)
                <details class="warnrows" open>
                    <summary>Technische Details (zum Kopieren, für Support)</summary>
                    <textarea readonly rows="3" style="font-family:ui-monospace,monospace;font-size:0.8rem;margin-top:0.4rem;" onclick="this.select()">{{ $technical }}</textarea>
                </details>
            @endif
        </div>

        {{--
            Kein Endpunkt, sondern eine Abzweigung: Gewinn, Marge, Schuljahres-
            bilanz und Stückzahlen stehen vollständig in der Auftragsbilanz und
            brauchen keine Schnittstelle. Ohne diesen Kasten war der Weg dorthin
            nur über „?shop=0" in der Adresszeile zu finden.
        --}}
        @if (($balanceOrders ?? 0) > 0)
            <div class="explain" style="background:#f8fafc;">
                <div class="explain-body" style="color:var(--ink);">
                    <p style="margin-top:0;font-weight:600;font-size:1rem;">Auswerten geht trotzdem</p>
                    <p>
                        In der <a href="{{ route('balance.index') }}">Auftragsbilanz</a> stehen
                        <strong>{{ number_format($balanceOrders, 0, ',', '.') }} Aufträge</strong> aus
                        {{ $balanceYears }} {{ $balanceYears === 1 ? 'Schuljahr' : 'Schuljahren' }} über zusammen
                        <strong>{{ $euro($balanceRevenue) }}</strong> — mit Einnahmen, Ausgaben, Provision und
                        Umsatzsteuer. Daraus lassen sich Gewinn, Marge, Schuljahresbilanz, Schulen und verkaufte
                        Teile vollständig rechnen; dafür braucht es den Webshop nicht.
                    </p>
                    <p>
                        <strong>Ohne Shop-Zahlen fehlen:</strong> die Ranglisten der meistverkauften Produkte und
                        Farben, die Umsätze je Bestellfenster und der Monatsverlauf des laufenden Schuljahres.
                    </p>
                    <p style="margin-bottom:0;">
                        <a class="btn" href="{{ route('statistics.index', $filters->query(['shop' => '0'])) }}">Ohne Shop-Zahlen auswerten</a>
                        <a class="btn secondary" href="{{ route('balance.index') }}" style="margin-left:0.5rem;">Zur Auftragsbilanz</a>
                    </p>
                </div>
            </div>
        @endif

        <p class="hint">
            Die Zugangsdaten stehen in der <code>.env</code>; nach einer Änderung
            <code>php artisan config:cache</code> ausführen oder neu deployen. Ob die Verbindung steht, zeigen die
            <a href="{{ route('admin.status') }}">Admin-Informationen</a>.
        </p>
    </div>
@endsection
