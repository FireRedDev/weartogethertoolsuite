{{--
    Ausklappbarer Erklärblock für längere Texte, die man einmal liest und
    danach nicht mehr braucht. Bewusst als <details> — funktioniert auf dem
    Telefon ohne JavaScript und ist von Haus aus bedienbar.

    Verwendung:  <x-explain>Langer Erklärtext …</x-explain>
                 <x-explain title="Wie funktioniert das?" open>…</x-explain>
--}}
@props(['title' => 'Wie das funktioniert', 'open' => false])

<details class="explain" {{ $open ? 'open' : '' }}>
    <summary>{{ $title }}</summary>
    <div class="explain-body">{{ $slot }}</div>
</details>
