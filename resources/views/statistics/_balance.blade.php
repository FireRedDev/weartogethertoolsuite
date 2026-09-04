{{--
    Die Auswertungen, die bisher in den Pivot-Blättern der Excel standen.

    Sie beruhen auf der Auftragsbilanz, nicht auf dem Shop: Ausgaben, Provision
    und damit jeder Gewinn sind dort eingetragen und im Webshop nirgends zu
    finden. Deshalb hängen sie auch nicht an den Quellenschaltern oben.
--}}
@php
    $b = $balance;
    $pct = fn ($v) => $v === null ? '–' : number_format($v * 100, 0, ',', '.').' %';
    // Vorjahreswerte für die Veränderungspfeile
    $prevRow = null;
    foreach ($balanceYears as $row) {
        if ($row['year']->startYear === $filters->year->startYear - 1) {
            $prevRow = $row;
        }
    }
@endphp

@if ($b['orders'] === 0 && $balanceYears === [])
    <div class="card">
        <h2 style="margin-top:0;">Auftragsbilanz</h2>
        <p class="lead" style="margin-bottom:0;">
            Es ist noch kein Auftrag erfasst. Sobald in der
            <a href="{{ route('balance.index') }}">Auftragsbilanz</a> Aufträge stehen, erscheinen hier
            Gewinn, Marge, Ausgaben und die Ranglisten je Auftrag.
        </p>
    </div>
@else
    {{--
        Sichtbare Trennlinie: Ab hier hängt nichts mehr an den Quellenschaltern.
        Vorher sahen diese Karten aus wie der Rest der Seite und schienen die
        Schalter zu ignorieren — der Grund stand nur im Info-Symbol.
    --}}
    <p class="hint" style="margin:1.5rem 0 0.5rem;border-top:1px solid var(--line);padding-top:1rem;">
        <strong style="color:var(--ink);">Ab hier: aus der Auftragsbilanz</strong> — Ausgaben, Provision und damit
        jeder Gewinn stehen nur dort. Diese Karten zeigen deshalb immer alle erfassten Aufträge und sind von den
        Quellenschaltern oben unberührt.
    </p>

    <div class="card">
        <h2 style="margin-top:0;">Wirtschaftlichkeit {{ $current['label'] }}
            <x-info label="Woher kommen diese Zahlen?">
                Aus der <a href="{{ route('balance.index') }}">Auftragsbilanz</a>. Was ein Auftrag gekostet
                hat, weiß der Webshop nicht — Produktionskosten und Provision werden dort eingetragen.
                Diese Kennzahlen zeigen deshalb immer alle erfassten Aufträge, unabhängig von den
                Quellenschaltern oben.
            </x-info>
        </h2>

        @if ($b['orders'] === 0)
            <p class="lead" style="margin-bottom:0;">
                Für {{ $current['label'] }} ist noch kein Auftrag erfasst.
                <a href="{{ route('balance.index', ['schuljahr' => $filters->year->key()]) }}">Jetzt eintragen.</a>
            </p>
        @else
            <div class="kpis">
                <div class="kpi">
                    <div class="label">Gewinn</div>
                    <div class="value hero">{{ $euro($b['profit']) }}</div>
                    @php($profitDelta = $delta($b['profit'], $prevRow['profit'] ?? null))
                    @if ($profitDelta)
                        <div class="delta {{ $profitDelta['tone'] }}">{{ $profitDelta['text'] }} gegenüber {{ $previous['label'] }}</div>
                    @endif
                </div>
                <div class="kpi">
                    <div class="label">Marge
                        <x-info label="Wie wird gerechnet?">
                            Gewinn geteilt durch den Bruttoumsatz. Der Gewinn ist der Bruttoumsatz abzüglich
                            Umsatzsteuer, Provision und Ausgaben.
                        </x-info>
                    </div>
                    <div class="value">{{ $pct($b['margin']) }}</div>
                    <div class="delta flat">{{ $euro($b['revenue']) }} Umsatz, {{ $b['orders'] }} Aufträge</div>
                </div>
                <div class="kpi">
                    <div class="label">Ausgaben</div>
                    <div class="value">{{ $euro($b['expenses']) }}</div>
                    <div class="delta flat">{{ $euro($b['commission']) }} Provision an die Schulen</div>
                </div>
                <div class="kpi">
                    <div class="label">Ø Gewinn je Auftrag</div>
                    <div class="value">{{ $euro($b['avgProfit']) }}</div>
                    <div class="delta flat">{{ $euro($b['avgRevenue']) }} Umsatz je Auftrag</div>
                </div>
                <div class="kpi">
                    <div class="label">Verkaufte Teile</div>
                    <div class="value">{{ $stk($b['products']) }}</div>
                    <div class="delta flat">{{ $stk($b['individual']) }} Individualisierungen</div>
                </div>
            </div>
        @endif
    </div>

    @if ($balanceOrders !== [])
        <div class="card">
            <h2 style="margin-top:0;">Größte Aufträge {{ $current['label'] }}</h2>
            <div class="tablewrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Auftrag</th>
                            <th style="text-align:right;">Umsatz</th>
                            <th style="text-align:right;">Gewinn</th>
                            <th style="text-align:right;">Marge</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($balanceOrders as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td style="text-align:right;">{{ $euro($row['revenue']) }}</td>
                                <td style="text-align:right;">{{ $euro($row['profit']) }}</td>
                                <td style="text-align:right;">{{ $pct($row['margin']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($balanceSchools !== [])
        <div class="card">
            <h2 style="margin-top:0;">Schulen {{ $current['label'] }}
                <x-info label="Unterschied zur Umsatzrangliste oben?">
                    Oben stehen die Shop-Kategorien mit ihrem Shop-Umsatz. Hier stehen die Schulen aus der
                    Auftragsbilanz — mit Bargeld, Ausgaben und Gewinn und deshalb auch mit Aufträgen, die
                    nie über den Shop liefen.
                </x-info>
            </h2>
            <div class="tablewrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Schule</th>
                            <th style="text-align:right;">Aufträge</th>
                            <th style="text-align:right;">Umsatz</th>
                            <th style="text-align:right;">Gewinn</th>
                            <th style="text-align:right;">Teile</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($balanceSchools as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td style="text-align:right;">{{ $row['orders'] }}</td>
                                <td style="text-align:right;">{{ $euro($row['revenue']) }}</td>
                                <td style="text-align:right;">{{ $euro($row['profit']) }}</td>
                                <td style="text-align:right;">{{ $stk($row['products']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if (count($balanceYears) > 1)
        <div class="card">
            <h2 style="margin-top:0;">Schuljahresbilanz
                <x-info label="Was steht in dieser Tabelle?">
                    Jedes Schuljahr in einer Zeile — die Übersicht, die bisher das Blatt „Schuljahresbilanz"
                    der Excel war. Ein Schuljahr läuft vom 1. August bis 31. Juli.
                </x-info>
            </h2>
            {{--
                Zwei Umsatzbegriffe stehen auf dieser Seite untereinander und
                dürfen auseinandergehen. Ohne diesen Satz wirkt das wie ein
                Rechenfehler.
            --}}
            <p class="hint" style="margin:-0.3rem 0 0.8rem;">
                „Einnahmen ges." ist das, was hier <strong>eingetragen</strong> ist. Der „Umsatz {{ $current['label'] }}"
                weiter oben ist das, was der <strong>Webshop meldet</strong>, plus alles, was am Shop vorbeilief.
                Beide dürfen abweichen — die Spalte „Shop meldet" rechts ist genau dieser Vergleich.
            </p>
            <div class="tablewrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th class="stickycol">Schuljahr</th>
                            <th style="text-align:right;">Aufträge</th>
                            <th style="text-align:right;">Einnahmen ges.</th>
                            <th style="text-align:right;">Online</th>
                            <th style="text-align:right;">Bar</th>
                            <th style="text-align:right;">Provision</th>
                            <th style="text-align:right;">Ausgaben</th>
                            <th style="text-align:right;">USt.</th>
                            <th style="text-align:right;">Gewinn</th>
                            <th style="text-align:right;">Marge</th>
                            <th style="text-align:right;">Ø Umsatz/Auftrag</th>
                            <th style="text-align:right;">Teile</th>
                            <th style="text-align:right;">Shop meldet
                                <x-info label="Warum steht hier manchmal nichts?">
                                    Der Vergleich nutzt nur die Monate, die schon aus dem Shop geladen sind —
                                    diese Tabelle fragt den Shop nie selbst. Für ältere Schuljahre steht deshalb
                                    ein Strich, bis sie einmal aufgerufen wurden. Den vollständigen Abgleich
                                    liefert <code>php artisan auftragsbilanz:abgleich</code> auf dem Server.
                                </x-info>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($balanceYears as $row)
                            @php($vergleich = $balanceComparison[$row['year']->key()] ?? null)
                            <tr @class(['current' => $row['year']->startYear === $filters->year->startYear])
                                style="{{ $row['year']->startYear === $filters->year->startYear ? 'font-weight:600;background:#fffaeb;' : '' }}">
                                <td class="stickycol">{{ $row['label'] }}</td>
                                <td style="text-align:right;">{{ $row['orders'] }}</td>
                                <td style="text-align:right;">{{ $euro($row['revenue']) }}</td>
                                <td style="text-align:right;">{{ $euro($row['revenueOnline']) }}</td>
                                <td style="text-align:right;">{{ $euro($row['revenueCash']) }}</td>
                                <td style="text-align:right;">{{ $euro($row['commission']) }}</td>
                                <td style="text-align:right;">{{ $euro($row['expenses']) }}</td>
                                <td style="text-align:right;">{{ $euro($row['vat']) }}</td>
                                <td style="text-align:right;">{{ $euro($row['profit']) }}</td>
                                <td style="text-align:right;">{{ $pct($row['margin']) }}</td>
                                <td style="text-align:right;">{{ $euro($row['avgRevenue']) }}</td>
                                <td style="text-align:right;">{{ $stk($row['products']) }}</td>
                                <td style="text-align:right;">
                                    @if ($vergleich === null || ! $vergleich['available'])
                                        <span class="hint">noch nicht geladen</span>
                                    @else
                                        {{ $euro($vergleich['shop']) }}
                                        <br>
                                        <span class="hint" style="{{ $vergleich['mismatch'] ? 'color:var(--warn);font-weight:600;' : '' }}">
                                            {{ $vergleich['difference'] > 0 ? '+' : '' }}{{ $euro($vergleich['difference']) }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Verkaufte Teile je Schuljahr
                <x-info label="Wozu die Ø-Zeile?">
                    Die untere Zahl in jeder Zelle ist der Schnitt je Auftrag. Daran lässt sich ablesen, ob ein
                    Auftrag im Lauf der Jahre größer oder kleiner geworden ist — bei steigender Auftragszahl
                    sagt die reine Stückzahl das nicht.
                </x-info>
            </h2>
            <div class="tablewrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th class="stickycol">Schuljahr</th>
                            <th style="text-align:right;">Gesamt</th>
                            @foreach ($balanceProducts['types'] as $label)
                                <th style="text-align:right;">{{ $label }}</th>
                            @endforeach
                            <th style="text-align:right;">Indiv.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($balanceProducts['years'] as $row)
                            @php($avg = fn ($n) => $row['orders'] > 0 ? number_format($n / $row['orders'], 1, ',', '.') : '–')
                            <tr>
                                <td class="stickycol">{{ $row['label'] }}<br><span class="hint">Ø je Auftrag</span></td>
                                <td style="text-align:right;">{{ $stk($row['total']) }}<br><span class="hint">{{ $avg($row['total']) }}</span></td>
                                @foreach ($balanceProducts['types'] as $type => $label)
                                    <td style="text-align:right;">
                                        {{ $stk($row['quantities'][$type] ?? 0) }}<br>
                                        <span class="hint">{{ $avg($row['quantities'][$type] ?? 0) }}</span>
                                    </td>
                                @endforeach
                                <td style="text-align:right;">{{ $stk($row['individual']) }}<br><span class="hint">{{ $avg($row['individual']) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endif
