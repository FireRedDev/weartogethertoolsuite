@extends('layouts.app')

@section('title', 'Statistiken — Wear Together Order Suite')

@section('content')
    @php
        use App\Services\Statistics\Charts\Palette;

        $euro = fn ($value) => Palette::euro($value === null ? null : (float) $value);
        $stk = fn ($value) => number_format((float) $value, 0, ',', '.');

        // Veränderung gegenüber dem Vorjahr als Prozentwert + Richtung.
        $delta = function (?float $now, ?float $before) {
            if ($now === null || $before === null || $before <= 0.0) {
                return null;
            }
            $change = ($now - $before) / $before;

            return [
                'text' => ($change >= 0 ? '+' : '−').number_format(abs($change) * 100, 1, ',', '.').' %',
                'tone' => abs($change) < 0.005 ? 'flat' : ($change > 0 ? 'up' : 'down'),
            ];
        };
    @endphp

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
            <h1 style="margin:0;">Statistiken
                <x-info label="Was wird hier ausgewertet?">
                    Die tatsächlichen Bestellungen aus dem WooCommerce-Shop, zusammengefasst nach
                    <strong>österreichischem Schuljahr</strong> und verglichen mit dem Vorjahr. Umsatz ist immer die
                    Summe der Bestellpositionen{{ config('statistics.revenue_includes_tax') ? ' inklusive USt.' : ' ohne USt.' }} —
                    Versandkosten und Gebühren zählen nicht mit, weil sie keinem Produkt und keiner Schule zuzuordnen
                    sind.
                </x-info>
            </h1>
            <a class="btn secondary" href="{{ route('statistics.index', $filters->query(['neu' => 1])) }}">↻ Daten neu laden</a>
        </div>

        @include('statistics._filters')
    </div>

    @php
        $revenueDelta = $delta($current['revenue'], $previous['revenue']);
        $ytdDelta = $delta($current['revenue'], $previousAtSamePoint);
        $orderDelta = $delta($current['avgPerOrder'], $previous['avgPerOrder']);
        $collectiveDelta = $delta($current['collective']['avg'], $previous['collective']['avg']);
        $ondemandDelta = $delta($current['ondemand']['avg'], $previous['ondemand']['avg']);
        $quantityDelta = $delta((float) $current['quantity'], (float) $previous['quantity']);
    @endphp

        <div class="card">
            <h2>Schuljahr {{ $current['label'] }}
                <span class="hint">Vergleich: {{ $previous['label'] }}</span>
                @if ($current['unassigned'] > 0)
                    <x-info label="Was heißt „ohne Schulzuordnung“?">
                        {{ $euro($current['unassigned']) }} des Umsatzes stammen aus Produkten, die in keiner
                        Schul-Kategorie unterhalb von „{{ config('schoolshop.parent_category_name') }}" liegen —
                        typischerweise allgemeine Shop-Artikel. Sie zählen in den Gesamtumsatz, erscheinen aber in
                        keiner Rangliste. Sobald du nach Lieferart oder Schule filterst, fallen sie heraus.
                    </x-info>
                @endif
            </h2>

            <div class="kpis">
                <div class="kpi">
                    <div class="label">Umsatz {{ $current['label'] }}
                        <x-info label="Was genau ist hier Umsatz?">
                            Die Summe der Bestellpositionen
                            {{ config('statistics.revenue_includes_tax') ? 'inklusive Umsatzsteuer' : 'ohne Umsatzsteuer' }},
                            ohne Versandkosten und Gebühren — die lassen sich keiner Schule und keinem Produkt
                            zuordnen. <strong>Erstattungen sind nicht abgezogen:</strong> Eine ganz stornierte
                            Bestellung fällt über den Bestellstatus heraus, eine teilweise erstattete zählt aber
                            in voller Höhe. Wie viel das ausmacht, steht unter der Zahl — abgezogen wird es nicht,
                            weil eine Erstattung oft nur den Versand oder eine einzelne Position betrifft und sich
                            keiner Produktart zuordnen lässt.
                        </x-info>
                    </div>
                    <div class="value hero">{{ $euro($current['revenue']) }}</div>
                    @if (($current['refundedOrders'] ?? 0) > 0)
                        <div class="delta warn">
                            darin {{ $current['refundedOrders'] }}
                            {{ $current['refundedOrders'] === 1 ? 'Bestellung' : 'Bestellungen' }}
                            mit Erstattung über zusammen {{ $euro($current['refundedTotal']) }} — nicht abgezogen
                        </div>
                    @endif
                    @if ($revenueDelta)
                        <div class="delta {{ $revenueDelta['tone'] }}">{{ $revenueDelta['text'] }} gegenüber {{ $previous['label'] }} (ganzes Jahr)</div>
                    @endif
                </div>

                @if ($filters->year->isCurrent())
                    <div class="kpi">
                        <div class="label">Vorjahr zum selben Zeitpunkt
                            <x-info label="Warum dieser Vergleich?">
                                Ein halbes Schuljahr gegen ein volles zu stellen wäre irreführend. Dieser Wert ist der
                                Umsatz des Vorjahres bis zum <strong>gleichen Tag im Schuljahr</strong> — nur so lässt
                                sich sagen, ob es gerade besser oder schlechter läuft.
                            </x-info>
                        </div>
                        <div class="value">{{ $euro($previousAtSamePoint) }}</div>
                        @if ($ytdDelta)
                            <div class="delta {{ $ytdDelta['tone'] }}">{{ $ytdDelta['text'] }} im Vergleich</div>
                        @endif
                    </div>
                @endif

                <div class="kpi">
                    <div class="label">Ø Umsatz je Bestellung</div>
                    <div class="value">{{ $euro($current['avgPerOrder']) }}</div>
                    <div class="delta {{ $orderDelta ? $orderDelta['tone'] : 'flat' }}">
                        {{ $stk($current['orders']) }} Bestellungen{{ $orderDelta ? ' · '.$orderDelta['text'] : '' }}
                    </div>
                </div>

                <div class="kpi">
                    <div class="label">Ø je Sammelbestellfenster
                        <x-info label="Wie wird das gerechnet?">
                            Alle Bestellungen im (gepufferten) Zeitraum eines Sammelbestellfensters, geteilt durch die
                            Anzahl der Fenster, die in diesem Schuljahr geendet haben. Aktuell
                            {{ $current['collective']['count'] }} Fenster, zusammen
                            {{ $euro($current['collective']['revenue']) }}.<br><br>
                            Gezählt werden nur Schulen mit <strong>Bestellfenster-Daten im Antrag</strong> — nur dort
                            ist bekannt, wann das Fenster lief.
                            @if ($current['schoolsWithoutWindow'] > 0)
                                {{ $current['schoolsWithoutWindow'] }} Schul-Kategorien im Shop haben keinen Antrag in
                                der Toolsuite; ihr Umsatz steht in der Rangliste ganz unten, fließt aber in diesen
                                Durchschnitt nicht ein.
                            @endif
                            <br><br>Nur diese Kachel reagiert auf Vorlauf/Nachlauf.
                        </x-info>
                    </div>
                    <div class="value">{{ $euro($current['collective']['avg']) }}</div>
                    <div class="delta {{ $collectiveDelta ? $collectiveDelta['tone'] : 'flat' }}">
                        {{ $current['collective']['count'] }} Fenster{{ $collectiveDelta ? ' · '.$collectiveDelta['text'] : '' }}
                    </div>
                </div>

                <div class="kpi">
                    <div class="label">Ø je On-Demand-Shop
                        <x-info label="Warum „Shop“ und nicht „Fenster“?">
                            On-Demand-Produkte haben kein Bestellfenster — sie sind dauerhaft bestellbar und werden
                            einzeln verschickt. Gewertet wird deshalb je <strong>On-Demand-Schule und Schuljahr</strong>:
                            der gesamte Umsatz dieser Schule im Schuljahr, geteilt durch die Anzahl der Schulen, die im
                            Schuljahr schon angelegt waren. Aktuell: {{ $current['ondemand']['count'] }},
                            zusammen {{ $euro($current['ondemand']['revenue']) }}. Auch hier zählen nur Schulen mit
                            Antrag in der Toolsuite — nur dort ist die Lieferart hinterlegt.
                        </x-info>
                    </div>
                    <div class="value">{{ $euro($current['ondemand']['avg']) }}</div>
                    <div class="delta {{ $ondemandDelta ? $ondemandDelta['tone'] : 'flat' }}">
                        {{ $current['ondemand']['count'] }} Shops{{ $ondemandDelta ? ' · '.$ondemandDelta['text'] : '' }}
                    </div>
                </div>

                <div class="kpi">
                    <div class="label">Verkaufte Teile</div>
                    <div class="value">{{ $stk($current['quantity']) }}</div>
                    <div class="delta {{ $quantityDelta ? $quantityDelta['tone'] : 'flat' }}">
                        Vorjahr {{ $stk($previous['quantity']) }}{{ $quantityDelta ? ' · '.$quantityDelta['text'] : '' }}
                    </div>
                </div>
            </div>

            <x-chart.columns :chart="$monthChart" title="Umsatz je Monat">
                <x-info label="Warum September zuerst?">
                    Das Diagramm folgt dem Schuljahr, nicht dem Kalenderjahr — Monat 1 ist September. Die zweite
                    Säule je Monat ist derselbe Monat im Vorjahr.
                </x-info>

                <x-slot:table>
                    <div class="tablewrap">
                        <table class="data">
                            <thead><tr><th>Monat</th><th>{{ $current['label'] }}</th><th>{{ $previous['label'] }}</th></tr></thead>
                            <tbody>
                                @foreach (array_values($current['months']) as $index => $month)
                                    <tr>
                                        <td>{{ $month['label'] }}</td>
                                        <td>{{ $euro($month['revenue']) }}</td>
                                        <td>{{ $euro(array_values($previous['months'])[$index]['revenue'] ?? 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-slot:table>
            </x-chart.columns>
        </div>

        <div class="card">
            <h2>Prognose bis Schuljahresende
                <x-info label="Wie wird hochgerechnet?">
                    Nicht linear — ein Schuljahr verläuft stark ungleichmäßig (die meisten Bestellfenster liegen im
                    Herbst und im Frühjahr). Stattdessen wird aus den abgeschlossenen Vorjahren gemittelt, welcher
                    Anteil des Jahresumsatzes in welchen Monat fiel. Der Umsatz bis heute wird durch den nach diesem
                    Muster erwarteten Anteil geteilt.
                    @if ($forecast['basis'])
                        Grundlage: {{ implode(', ', $forecast['basis']) }}.
                    @endif
                </x-info>
            </h2>

            @if ($forecast['reason'])
                <div class="alert info">{{ $forecast['reason'] }}</div>
            @endif

            <div class="kpis">
                <div class="kpi">
                    <div class="label">Hochgerechneter Jahresumsatz</div>
                    <div class="value hero">{{ $euro($forecast['projection']) }}</div>
                    @if ($forecast['possible'])
                        <div class="delta flat">davon noch offen: {{ $euro($forecast['remaining']) }}</div>
                    @endif
                </div>
                <div class="kpi">
                    <div class="label">Zielumsatz
                        @if ($forecast['targetIsDefault'])
                            <x-info label="Woher kommt dieses Ziel?">
                                Es wurde kein eigenes Ziel eingetragen, deshalb gilt der <strong>tatsächlich
                                erreichte Umsatz von {{ $previous['label'] }}</strong>
                                ({{ $euro($forecast['previousTotal']) }}) als Ziel — also: mindestens so gut werden
                                wie im Vorjahr. Ein eigenes Ziel lässt sich oben in der Filterzeile eintragen.
                            </x-info>
                        @endif
                    </div>
                    <div class="value">{{ $euro($forecast['target']) }}</div>
                    @if ($forecast['targetIsDefault'])
                        <div class="delta flat">= Umsatz {{ $previous['label'] }} (kein eigenes Ziel eingetragen)</div>
                    @endif
                    @if ($forecast['gapToTarget'] !== null)
                        <div class="delta {{ $forecast['gapToTarget'] >= 0 ? 'up' : 'down' }}">
                            Hochrechnung {{ $forecast['gapToTarget'] >= 0 ? 'über' : 'unter' }} Ziel:
                            {{ $euro(abs($forecast['gapToTarget'])) }}
                        </div>
                    @endif
                </div>
                <div class="kpi">
                    <div class="label">Zielerreichung bisher</div>
                    <div class="value">{{ $forecast['targetShare'] === null ? '—' : number_format($forecast['targetShare'] * 100, 1, ',', '.').' %' }}</div>
                    <div class="delta {{ $forecast['openToTarget'] > 0 ? 'flat' : 'up' }}">
                        {{ $forecast['openToTarget'] > 0 ? 'noch '.$euro($forecast['openToTarget']).' bis zum Ziel' : 'Ziel bereits erreicht' }}
                    </div>
                </div>
                @if ($forecast['openToTarget'] > 0 && $forecast['neededPerMonth'] !== null && $forecast['monthsLeft'] > 0)
                    <div class="kpi">
                        <div class="label">Nötig je Restmonat</div>
                        <div class="value">{{ $euro($forecast['neededPerMonth']) }}</div>
                        <div class="delta flat">{{ $forecast['monthsLeft'] }} Monate bis Schuljahresende</div>
                    </div>
                @endif
            </div>

            <x-chart.lines :chart="$curveChart" title="Kumulierter Umsatz im Schuljahresverlauf">
                <x-info label="Was zeigt die strichlierte Linie?">
                    Die Fortschreibung nach dem Saisonmuster der Vorjahre. Sie setzt beim heutigen Stand an — nicht
                    beim Jahresanfang — und läuft auf den hochgerechneten Jahresumsatz zu. Die waagrechte Linie ist
                    der Zielumsatz.
                </x-info>

                <x-slot:table>
                    <div class="tablewrap">
                        <table class="data">
                            <thead><tr><th>Monat</th><th>{{ $current['label'] }} kumuliert</th><th>{{ $previous['label'] }} kumuliert</th><th>Hochrechnung</th></tr></thead>
                            <tbody>
                                @foreach ($forecast['curve'] as $point)
                                    <tr>
                                        <td>{{ $point['label'] }}</td>
                                        <td>{{ $point['current'] === null ? '—' : $euro($point['current']) }}</td>
                                        <td>{{ $point['previous'] === null ? '—' : $euro($point['previous']) }}</td>
                                        <td>{{ $point['forecast'] === null ? '—' : $euro($point['forecast']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-slot:table>
            </x-chart.lines>
        </div>

        <div class="card">
            <x-chart.bars :chart="$productChart" title="Meistverkaufte Produkte">
                <x-info label="Wie werden Produkte zusammengefasst?">
                    Nach <strong>Produktart</strong> über alle Schulen hinweg — die Frage ist ja, ob mehr Shirts oder
                    mehr Pullover verkauft wurden. Im Shop heißt jedes Produkt anders (der Schulname steckt im Namen),
                    deshalb wird der Produktname nach Stichwörtern durchsucht: alles mit „Schulshirt" oder „Shirt"
                    zählt als Schulshirt, alles mit „Hoodie" als Schulhoodie und so weiter. Sortiert nach
                    <strong>Stückzahl</strong>; der Umsatz steht in der Tabelle.<br><br>
                    Taucht ein Produkt falsch oder doppelt auf, fehlt die Schreibweise in
                    <code>statistics.product_group_aliases</code>.
                </x-info>

                <x-slot:table>
                    <div class="tablewrap">
                        <table class="data">
                            <thead><tr><th>Produkt</th><th>Stück {{ $current['label'] }}</th><th>Umsatz {{ $current['label'] }}</th><th>Stück {{ $previous['label'] }}</th><th>Umsatz {{ $previous['label'] }}</th></tr></thead>
                            <tbody>
                                @forelse ($productRanking as $row)
                                    <tr>
                                        <td>{{ $row['name'] }}</td>
                                        <td>{{ $stk($row['quantity']) }}</td>
                                        <td>{{ $euro($row['revenue']) }}</td>
                                        <td>{{ $stk($row['previousQuantity']) }}</td>
                                        <td>{{ $euro($row['previousRevenue']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5">Keine Verkäufe im gewählten Zeitraum.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-slot:table>
            </x-chart.bars>
        </div>

        <div class="card">
            <x-chart.bars :chart="$colorChart" title="Beliebteste Produktfarben"
                          emptyText="Für diesen Zeitraum sind keine Farben erfasst.">
                <x-info label="Woher kommt die Farbe?">
                    Aus dem Farbattribut der Bestellposition. Sammelbestellfenster-Produkte legt die Toolsuite selbst
                    an und tragen „Farbe"; On-Demand-Produkte kommen von Printify und heißen dort teils englisch —
                    beides wird erkannt. Positionen ohne Farbattribut stehen unter „ohne Farbangabe". Das kleine
                    Quadrat neben dem Namen ist nur ein Wiedererkennungszeichen; die Balken behalten die Serienfarbe,
                    damit die Skala lesbar bleibt.
                </x-info>

                <x-slot:table>
                    <div class="tablewrap">
                        <table class="data">
                            <thead><tr><th>Farbe</th><th>Stück {{ $current['label'] }}</th><th>Umsatz {{ $current['label'] }}</th><th>Stück {{ $previous['label'] }}</th><th>Umsatz {{ $previous['label'] }}</th></tr></thead>
                            <tbody>
                                @forelse ($colorRanking as $row)
                                    <tr>
                                        <td>{{ $row['name'] }}</td>
                                        <td>{{ $stk($row['quantity']) }}</td>
                                        <td>{{ $euro($row['revenue']) }}</td>
                                        <td>{{ $stk($row['previousQuantity']) }}</td>
                                        <td>{{ $euro($row['previousRevenue']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5">Keine Verkäufe im gewählten Zeitraum.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-slot:table>
            </x-chart.bars>
        </div>

        <div class="card">
            <x-chart.bars :chart="$schoolChart" title="Umsatzstärkste Schulen"
                          emptyText="Für diesen Zeitraum ist keiner Schule Umsatz zugeordnet.">
                <x-info label="Wie wird der Umsatz einer Schule ermittelt?">
                    Über die <strong>Produktkategorie der Schule im Shop</strong>: alles, was im Schuljahr aus dieser
                    Kategorie bestellt wurde. Das gilt unabhängig davon, ob es zur Schule einen Antrag in der
                    Toolsuite gibt — auch von Hand angelegte Schulen erscheinen hier. Gereiht wird nach Umsatz;
                    Stückzahlen stehen in der Tabelle.
                </x-info>

                <x-slot:table>
                    <div class="tablewrap">
                        <table class="data">
                            <thead><tr><th>Schule</th><th>Umsatz {{ $current['label'] }}</th><th>Teile {{ $current['label'] }}</th><th>Umsatz {{ $previous['label'] }}</th><th>Teile {{ $previous['label'] }}</th></tr></thead>
                            <tbody>
                                @forelse ($schoolRanking as $row)
                                    <tr>
                                        <td>{{ $row['name'] }}</td>
                                        <td>{{ $euro($row['revenue']) }}</td>
                                        <td>{{ $stk($row['quantity']) }}</td>
                                        <td>{{ $euro($row['previousRevenue']) }}</td>
                                        <td>{{ $stk($row['previousQuantity']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5">Keine Umsätze im gewählten Zeitraum.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-slot:table>
            </x-chart.bars>
        </div>
    </div>
@endsection
