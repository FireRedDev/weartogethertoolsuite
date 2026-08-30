{{--
    Waagrechte Balken für die Ranglisten. Geometrie aus
    App\Services\Statistics\Charts\BarChart.
--}}
@props(['chart', 'title', 'emptyText' => 'Für diesen Zeitraum sind keine Verkäufe erfasst.'])

<figure class="chart">
    <figcaption>{{ $title }} {{ $slot }}</figcaption>

    @if ($chart['empty'])
        <p class="hint">{{ $emptyText }}</p>
    @else
        <div class="chart-legend">
            @foreach ($chart['legend'] as $entry)
                <span><i style="background:{{ $entry['color'] }}"></i>{{ $entry['label'] }}</span>
            @endforeach
        </div>

        <div class="chart-scroll">
        <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" role="img"
             aria-label="{{ $title }} — alle Werte stehen in der Tabelle unter dem Diagramm">
            @foreach ($chart['axis'] as $row)
                @if ($row['swatch'])
                    <rect class="chart-swatch" x="{{ $chart['plotLeft'] - 158 }}" y="{{ $row['y'] - 6 }}" width="11" height="11" rx="3"
                          fill="{{ $row['swatch'] }}"></rect>
                @endif
                <text class="chart-rowlabel" x="{{ $chart['plotLeft'] - ($row['swatch'] ? 142 : 158) }}" y="{{ $row['y'] + 4 }}">{{ $row['name'] }}</text>
                @if ($row['note'])
                    <text class="chart-tick" x="{{ $chart['plotLeft'] - 10 }}" y="{{ $row['y'] + 4 }}" text-anchor="end">{{ $row['note'] }}</text>
                @endif
            @endforeach

            @foreach ($chart['bars'] as $bar)
                <path d="{{ $bar['path'] }}" fill="{{ $bar['color'] }}"><title>{{ $bar['title'] }}</title></path>
            @endforeach

            @foreach ($chart['labels'] as $label)
                <text class="chart-value" x="{{ $label['x'] }}" y="{{ $label['y'] }}">{{ $label['text'] }}</text>
            @endforeach
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
