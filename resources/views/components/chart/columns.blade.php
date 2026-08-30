{{--
    Gruppierte Säulen. Die gesamte Geometrie kommt aus
    App\Services\Statistics\Charts\ColumnChart — hier wird nur gezeichnet.
    Farben tragen ausschließlich die Datenflächen; Beschriftungen bleiben in
    den Textfarben der App (Klassen .chart-tick/.chart-value im Layout).
--}}
@props(['chart', 'title', 'axisTitle' => 'Umsatz in €'])

<figure class="chart">
    <figcaption>{{ $title }} {{ $slot }}</figcaption>

    @if ($chart['empty'])
        <p class="hint">Für diesen Zeitraum sind keine Umsätze erfasst.</p>
    @else
        <div class="chart-legend">
            @foreach ($chart['legend'] as $entry)
                <span><i style="background:{{ $entry['color'] }}"></i>{{ $entry['label'] }}</span>
            @endforeach
        </div>

        <div class="chart-scroll">
        <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" role="img"
             aria-label="{{ $title }} — alle Werte stehen in der Tabelle unter dem Diagramm">
            @foreach ($chart['gridlines'] as $line)
                <line class="chart-grid" x1="{{ $chart['plotLeft'] }}" y1="{{ $line['y'] }}"
                      x2="{{ $chart['plotRight'] }}" y2="{{ $line['y'] }}"></line>
                <text class="chart-tick" x="{{ $chart['plotLeft'] - 8 }}" y="{{ $line['y'] + 4 }}" text-anchor="end">{{ $line['label'] }}</text>
            @endforeach

            @foreach ($chart['columns'] as $column)
                <path d="{{ $column['path'] }}" fill="{{ $column['color'] }}"><title>{{ $column['title'] }}</title></path>
            @endforeach

            @foreach ($chart['labels'] as $label)
                <text class="chart-value" x="{{ $label['x'] }}" y="{{ $label['y'] }}" text-anchor="middle">{{ $label['text'] }}</text>
            @endforeach

            @foreach ($chart['ticks'] as $tick)
                <text class="chart-tick" x="{{ $tick['x'] }}" y="{{ $chart['baseline'] + 17 }}" text-anchor="middle">{{ $tick['label'] }}</text>
            @endforeach

            <text class="chart-tick" x="0" y="10">{{ $axisTitle }}</text>
        </svg>
        </div>
    @endif

    @isset($table)
        <details class="explain chart-table">
            <summary>Als Tabelle</summary>
            <div class="explain-body">{{ $table }}</div>
        </details>
    @endisset
</figure>
