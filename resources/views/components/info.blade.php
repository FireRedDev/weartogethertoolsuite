{{--
    Antippbares Info-Symbol mit Erklärung.

    Ersetzt die früheren title="…"-Tooltips: die zeigt ein Telefon nicht an,
    weil es kein Mouseover gibt. Hier öffnet ein Tipp bzw. Klick die Erklärung,
    ein zweiter schließt sie wieder (Steuerung in layouts/app.blade.php).

    Verwendung:  <x-info>Erklärung …</x-info>
                 <x-info label="Was ist die Blueprint-ID?">…</x-info>
--}}
@props(['label' => 'Erklärung anzeigen'])

<span class="info">
    <button type="button" class="info-toggle" aria-expanded="false" aria-label="{{ $label }}">i</button>
    <span class="info-box" role="note" hidden>{{ $slot }}</span>
</span>
