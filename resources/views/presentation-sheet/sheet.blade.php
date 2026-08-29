{{--
    Präsentationsblatt A4 — reine Ausgabe, alle Maße und Umbrüche rechnet
    PresentationSheetRenderer aus. Die Elemente sind bereits in Zeichen-
    reihenfolge sortiert: erst die Fotos, dann der Hintergrund (ein PNG mit
    transparenten Fenstern, das die Fotos in der schrägen Rahmenform freistellt),
    zuletzt Texte, Produkt-Icons und QR-Code.
--}}
<style>
    @font-face { font-family: 'SourceSans'; font-weight: 400; font-style: normal; src: url("{{ $fontDir }}/SourceSans3-Regular.ttf") format('truetype'); }
    @font-face { font-family: 'SourceSans'; font-weight: 400; font-style: italic;  src: url("{{ $fontDir }}/SourceSans3-It.ttf") format('truetype'); }
    @font-face { font-family: 'SourceSans'; font-weight: 600; font-style: normal; src: url("{{ $fontDir }}/SourceSans3-Semibold.ttf") format('truetype'); }
    @font-face { font-family: 'SourceSans'; font-weight: 700; font-style: normal; src: url("{{ $fontDir }}/SourceSans3-Bold.ttf") format('truetype'); }

    @page { margin: 0; }
    html, body { margin: 0; padding: 0; }
    body { width: {{ $page['width'] }}pt; height: {{ $page['height'] }}pt; font-family: 'SourceSans', sans-serif; }
    .e { position: absolute; line-height: 1; margin: 0; padding: 0; }
</style>

@foreach ($elements as $e)
    @if ($e['type'] === 'image')
        <img class="e" src="{{ $e['src'] }}" style="left:{{ $e['left'] }}pt; top:{{ $e['top'] }}pt; width:{{ $e['width'] }}pt; height:{{ $e['height'] }}pt;">
    @else
        <div class="e" style="left:{{ $e['left'] }}pt; top:{{ $e['top'] }}pt; width:{{ $e['width'] }}pt; text-align:{{ $e['align'] }}; font-size:{{ $e['size'] }}pt; font-weight:{{ $e['weight'] }}; font-style:{{ $e['italic'] ? 'italic' : 'normal' }}; color:{{ $e['color'] }};">{{ $e['text'] }}</div>
    @endif
@endforeach
