{{--
    Abgleich: Was der Webshop meldet gegen das, was hier eingetragen ist.

    Der Kasten fragt den Shop NIE selbst — er zeigt nur, was das Statistikmodul
    ohnehin schon geladen hat. Fehlt etwas, sagt er das, statt die Seite
    aufzuhalten.
--}}
@php
    $diff = $comparison['difference'];
    $level = ! $comparison['available'] ? 'info' : ($comparison['mismatch'] ? 'warn' : 'ok');
@endphp

<div class="alert {{ $level }}">
    @if (! $comparison['available'])
        <strong>Abgleich mit dem Webshop steht noch aus.</strong>
        Für {{ $year->label() }} sind noch nicht alle Monate aus dem Shop geladen.
        Sobald die <a href="{{ route('statistics.index', ['schuljahr' => $year->key()]) }}">Statistik</a>
        für dieses Jahr fertig aufgebaut ist, steht hier der Vergleich.
        Eingetragen sind derzeit {{ $euro($comparison['entered']) }} an Online-Einnahmen.
    @elseif ($comparison['mismatch'])
        <strong>Der Webshop meldet etwas anderes.</strong>
        Shop: {{ $euro($comparison['shop']) }} · eingetragen: {{ $euro($comparison['entered']) }} ·
        Unterschied: {{ $diff > 0 ? '+' : '' }}{{ $euro($diff) }}.
        <x-info label="Woran liegt das üblicherweise?">
            Die häufigsten Gründe: In der Excel wurden Erstattungen abgezogen, ein Auftrag lief
            über einen fremden Shop, oder eine Bestellung fehlt hier noch. Die Altwerte aus der
            Excel bleiben unverändert stehen — sie sind der Stand, mit dem bisher gerechnet wurde.
            Für die Statistik gilt bei verknüpften Aufträgen die Shop-Zahl.
        </x-info>
    @else
        <strong>Deckt sich mit dem Webshop.</strong>
        Shop: {{ $euro($comparison['shop']) }} · eingetragen: {{ $euro($comparison['entered']) }}.
    @endif
</div>
