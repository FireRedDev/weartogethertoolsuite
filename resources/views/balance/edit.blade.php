@extends('layouts.app')

@section('title', 'Auftrag bearbeiten')

@section('content')
    @php
        $euro = fn ($v) => number_format((float) $v, 2, ',', '.').' €';
    @endphp

    <h1 style="margin-bottom:0.25rem;">{{ $order->label() }}</h1>
    <p class="lead">
        <a href="{{ route('balance.index', ['schuljahr' => $order->school_year]) }}">← zurück zur Auftragsbilanz</a>
        · Schuljahr {{ $order->schoolYear()->label() }}
    </p>

    <div class="kpis">
        <div class="kpi">
            <div class="label">Einnahmen gesamt</div>
            <div class="value">{{ $euro($order->revenueTotal()) }}</div>
            <div class="delta flat">{{ $euro($order->revenueNet()) }} netto</div>
        </div>
        <div class="kpi">
            <div class="label">Gewinn</div>
            <div class="value">{{ $euro($order->profit()) }}</div>
            <div class="delta flat">
                {{ $order->marginShare() === null ? '–' : number_format($order->marginShare() * 100, 0, ',', '.').' %' }} vom Bruttoumsatz
            </div>
        </div>
        <div class="kpi">
            <div class="label">Verkaufte Teile</div>
            <div class="value">{{ $order->productCount() }}</div>
            <div class="delta flat">{{ $order->individual }} Individualisierungen</div>
        </div>
    </div>

    @if ($order->source === 'excel' && $order->revenue_online_excel !== null)
        <div class="alert info">
            <strong>Aus der bisherigen Excel übernommen.</strong>
            Dort standen {{ $euro($order->revenue_online_excel) }} an Online-Einnahmen.
            Dieser Wert bleibt als Vergleich stehen, auch wenn der Betrag oben geändert wird.
        </div>
    @endif

    <form method="post" action="{{ route('balance.update', $order) }}">
        @csrf
        @method('put')
        @include('balance._form', ['order' => $order, 'productTypes' => $productTypes, 'onboardings' => $onboardings])

        <div style="display:flex;gap:0.6rem;align-items:center;">
            <button class="btn" type="submit">Änderungen speichern</button>
            <a class="btn secondary" href="{{ route('balance.index', ['schuljahr' => $order->school_year]) }}">Abbrechen</a>
        </div>
    </form>

    <form method="post" action="{{ route('balance.destroy', $order) }}" style="margin-top:1.5rem;"
          onsubmit="return confirm('Diesen Auftrag wirklich löschen? Das lässt sich nicht rückgängig machen.');">
        @csrf
        @method('delete')
        <button class="btn secondary" type="submit" style="color:var(--error);">Auftrag löschen</button>
    </form>
@endsection
