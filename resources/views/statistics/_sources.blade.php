{{--
    Die beiden Quellenschalter — der zentrale Schalter über der ganzen
    Auswertung. Sie wirken auf alles darunter: Kennzahlen, Monatsverlauf,
    Prognose, Bedarfsrechnung und die Schulrangliste.

    Umgesetzt als Links, nicht als Formular: So bleibt jeder Zustand eine
    eigene Adresse und damit als Lesezeichen speicher- und teilbar — genau wie
    die übrigen Filter. Der jeweils letzte eingeschaltete Schalter lässt sich
    nicht ausschalten; eine Auswertung ohne Quelle wäre eine leere Seite.
--}}
@php
    $sources = [
        'shop' => [
            'on' => $filters->sourceShop,
            'title' => 'Shop-Umsätze',
            'note' => 'Bestellungen aus dem Webshop',
            'icon' => '🛒',
        ],
        'sonstige' => [
            'on' => $filters->sourceOther,
            'title' => 'Sonstige Umsätze',
            'note' => 'Bargeld, Direktverkäufe, händisch erfasste Aufträge',
            'icon' => '✎',
        ],
    ];
    $onCount = ($filters->sourceShop ? 1 : 0) + ($filters->sourceOther ? 1 : 0);
@endphp

<div class="sources">
    <span class="sources-label">Quellen
        <x-info label="Was bewirken die Schalter?">
            <strong>Shop-Umsätze</strong> sind die Bestellungen aus dem Webshop — daraus kommen auch
            Produkt- und Farbranglisten und die Umsätze je Bestellfenster.<br>
            <strong>Sonstige Umsätze</strong> kommen aus der
            <a href="{{ route('balance.index') }}">Auftragsbilanz</a>: Bargeld, Direktverkäufe und
            die Online-Einnahmen der Jahre vor dem eigenen Shop.<br>
            Doppelt gezählt wird nichts: Ein Auftrag, dessen Online-Einnahmen aus dem Webshop
            stammen, steuert hier nur seinen Bargeldanteil bei.
        </x-info>
    </span>

    @foreach ($sources as $key => $source)
        @php
            $isLastOn = $source['on'] && $onCount === 1;
            $target = route('statistics.index', $filters->query([$key => $source['on'] ? '0' : null]));
        @endphp
        @if ($isLastOn)
            <span class="toggle on locked" aria-disabled="true"
                  title="Mindestens eine Quelle muss eingeschaltet bleiben">
                <span class="toggle-track" aria-hidden="true"><span class="toggle-knob"></span></span>
                <span class="toggle-text"><strong>{{ $source['icon'] }} {{ $source['title'] }}</strong><small>{{ $source['note'] }}</small></span>
            </span>
        @else
            <a class="toggle {{ $source['on'] ? 'on' : 'off' }}" href="{{ $target }}"
               role="switch" aria-checked="{{ $source['on'] ? 'true' : 'false' }}">
                <span class="toggle-track" aria-hidden="true"><span class="toggle-knob"></span></span>
                <span class="toggle-text"><strong>{{ $source['icon'] }} {{ $source['title'] }}</strong><small>{{ $source['note'] }}</small></span>
            </a>
        @endif
    @endforeach
</div>
