{{--
    Kumulierter Verlauf mit Hochrechnung und Zielmarke. Geometrie aus
    App\Services\Statistics\Charts\LineChart.
--}}
@props(['chart', 'title', 'axisTitle' => 'Umsatz in €'])

<figure class="chart">
    <figcaption>{{ $title }} {{ $slot }}</figcaption>

    @if ($chart['empty'])
        <p class="hint">Für diesen Zeitraum sind keine Umsätze erfasst.</p>
    @else
        <div class="chart-legend">
            @foreach ($chart['legend'] as $entry)
                <span><i class="{{ ($entry['dashed'] ?? false) ? 'dashed' : '' }}" style="background:{{ $entry['color'] }}"></i>{{ $entry['label'] }}</span>
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

            @if ($chart['target'])
                <line class="chart-target" x1="{{ $chart['plotLeft'] }}" y1="{{ $chart['target']['y'] }}"
                      x2="{{ $chart['plotRight'] }}" y2="{{ $chart['target']['y'] }}"></line>
                <text class="chart-targetlabel" x="{{ $chart['plotLeft'] + 4 }}" y="{{ $chart['target']['y'] - 5 }}">{{ $chart['target']['label'] }}</text>
            @endif

            @foreach ($chart['series'] as $series)
                <path d="{{ $series['path'] }}" fill="none" stroke="{{ $series['color'] }}" stroke-width="2"
                      stroke-linejoin="round" stroke-linecap="round"
                      @if ($series['dashed']) stroke-dasharray="6 5" @endif></path>
            @endforeach

            @foreach ($chart['markers'] as $marker)
                <circle cx="{{ $marker['x'] }}" cy="{{ $marker['y'] }}" r="4.5" fill="{{ $marker['color'] }}"
                        stroke="#ffffff" stroke-width="2"><title>{{ $marker['title'] }}</title></circle>
                <text class="chart-value" x="{{ $marker['x'] + $marker['dx'] }}" y="{{ $marker['y'] - 8 }}"
                      text-anchor="{{ $marker['anchor'] }}">{{ $marker['text'] }}</text>
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
