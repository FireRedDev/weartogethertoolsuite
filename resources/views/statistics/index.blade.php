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

        @if ($error)
            <div class="alert error" style="margin-top:1rem;">
                ✖ {{ $error }}
                @if ($technical)
                    <details class="warnrows" open>
                        <summary>Technische Details (zum Kopieren, für Support)</summary>
                        <textarea readonly rows="3" style="font-family:ui-monospace,monospace;font-size:0.8rem;margin-top:0.4rem;" onclick="this.select()">{{ $technical }}</textarea>
                    </details>
                @endif
            </div>
        @endif

        {{-- Eine Filterzeile für die ganze Seite: alle Diagramme zeigen denselben Ausschnitt. --}}
        <form method="get" action="{{ route('statistics.index') }}" style="margin-top:1rem;">
            <div class="filters">
                <div>
                    <label for="schuljahr">Schuljahr
                        <x-info label="Wann beginnt und endet das Schuljahr?">
                            Ein Schuljahr läuft hier vom
                            {{ config('statistics.school_year.start_day') }}.{{ config('statistics.school_year.start_month') }}.
                            bis zum Tag davor im Folgejahr. Die <strong>Sommerferien zählen ans ablaufende
                            Schuljahr</strong> — Nachzügler- und Ferienbestellungen gehören zu dem Bestellfenster, das
                            im Juni endete, nicht zum neuen Jahr. Verglichen wird immer mit dem unmittelbaren Vorjahr.
                        </x-info>
                    </label>
                    <select id="schuljahr" name="schuljahr">
                        @foreach ($years as $year)
                            <option value="{{ $year->key() }}" @selected($year->key() === $filters->year->key())>
                                {{ $year->label() }}{{ $year->isCurrent() ? ' (laufend)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="lieferart">Lieferart</label>
                    <select id="lieferart" name="lieferart">
                        @foreach (App\Services\Statistics\StatisticsFilters::DELIVERY_TYPES as $key => $label)
                            <option value="{{ $key }}" @selected($key === $filters->deliveryType)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="schule">Schule</label>
                    <select id="schule" name="schule">
                        <option value="">Alle Schulen</option>
                        @foreach ($schools as $school)
                            <option value="{{ $school->id }}" @selected($school->id === $filters->schoolId)>{{ $school->school_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="vorlauf">Vorlauf (Tage)
                        <x-info label="Warum ein Puffer um das Bestellfenster?">
                            Für „Ø Umsatz je Bestellfenster" wird jede Bestellung dem Fenster ihrer Schule zugeordnet.
                            Der Zeitraum wird dabei <strong>absichtlich breiter genommen als im Antrag
                            eingestellt</strong>: Nach Ablauf wird ein Fenster oft noch um eine Woche verlängert
                            (automatische Nachfrist), und Nachzügler bestellen auch danach noch. Da nie mehrere
                            Bestellfenster derselben Schule direkt aneinander liegen, kann der Puffer keine fremden
                            Bestellungen einsammeln. Standard: {{ config('statistics.window_padding.before') }} Tage
                            vorher, {{ config('statistics.window_padding.after') }} Tage nachher.
                        </x-info>
                    </label>
                    <input type="number" id="vorlauf" name="vorlauf" min="0" max="{{ config('statistics.window_padding.max') }}" value="{{ $filters->paddingBefore }}">
                </div>

                <div>
                    <label for="nachlauf">Nachlauf (Tage)</label>
                    <input type="number" id="nachlauf" name="nachlauf" min="0" max="{{ config('statistics.window_padding.max') }}" value="{{ $filters->paddingAfter }}">
                </div>

                <div>
                    <label for="ziel">Zielumsatz (€)
                        <x-info label="Wofür der Zielumsatz?">
                            Die Zielmarke im Verlaufsdiagramm und die Restrechnung darunter. Leer gelassen gilt
                            automatisch der <strong>Gesamtumsatz des Vorjahres</strong> als Ziel.
                        </x-info>
                    </label>
                    <input type="number" id="ziel" name="ziel" min="0" step="100" placeholder="{{ $forecast['previousTotal'] ?? 0 }}" value="{{ $filters->target }}">
                </div>
            </div>

            <details class="explain" style="margin-top:0.9rem;">
                <summary>Bestellstatus, die mitzählen ({{ count($filters->statuses) }} ausgewählt)</summary>
                <div class="explain-body">
                    <p>Standard sind die Status, mit denen auch die Auftragsdokumente arbeiten. Stornierte und
                        rückerstattete Bestellungen sind bewusst nicht dabei.</p>
                    <div style="display:flex;flex-wrap:wrap;gap:0.4rem 1.2rem;">
                        @foreach (config('ordersuite.woocommerce.statuses') as $key => $label)
                            <label style="font-weight:400;display:flex;gap:0.35rem;align-items:center;margin:0;">
                                <input type="checkbox" name="status[]" value="{{ $key }}" @checked(in_array($key, $filters->statuses, true))
                                       style="width:auto;margin:0;">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </details>

            <div style="margin-top:0.9rem;">
                <button class="btn" type="submit">Auswerten</button>
                @if ($filters->isFiltered())
                    <a class="btn secondary" href="{{ route('statistics.index', ['schuljahr' => $filters->year->key()]) }}" style="margin-left:0.5rem;">Filter zurücksetzen</a>
                @endif
            </div>
        </form>
    </div>

    @if (! $error)
        @php
            $revenueDelta = $delta($current['revenue'], $previous['revenue']);
            $ytdDelta = $delta($current['revenue'], $previousAtSamePoint);
            $orderDelta = $delta($current['avgPerOrder'], $previous['avgPerOrder']);
            $collectiveDelta = $delta($current['collective']['avg'], $previous['collective']['avg']);
            $ondemandDelta = $delta($current['ondemand']['avg'], $previous['ondemand']['avg']);
            $quantityDelta = $delta((float) $current['quantity'], (float) $previous['quantity']);

            // Sammelbestellfenster und On-Demand-Shops in einer Tabelle.
            $windowRows = collect($current['collective']['list'])->map(fn ($r) => $r + ['type' => 'Sammelbestellfenster'])
                ->concat(collect($current['ondemand']['list'])->map(fn ($r) => $r + ['type' => 'On-Demand']))
                ->sortByDesc('revenue')->values();
        @endphp

        <div class="card">
            <h2>Schuljahr {{ $current['label'] }}
                <span class="hint">Vergleich: {{ $previous['label'] }}</span>
                @if ($current['unassigned'] > 0)
                    <x-info label="Was heißt „ohne Schulzuordnung“?">
                        {{ $euro($current['unassigned']) }} des Umsatzes stammen aus Produkten, die zu keiner
                        Schul-Kategorie gehören (allgemeine Shop-Artikel oder Schulen, deren Antrag in der Toolsuite
                        fehlt). Sie zählen in den Gesamtumsatz, aber in keine Fenster-Auswertung. Sobald du nach
                        Lieferart oder Schule filterst, fallen sie heraus.
                    </x-info>
                @endif
            </h2>

            <div class="kpis">
                <div class="kpi">
                    <div class="label">Umsatz {{ $current['label'] }}</div>
                    <div class="value hero">{{ $euro($current['revenue']) }}</div>
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
                            Alle Bestellungen, die im (gepufferten) Zeitraum eines Sammelbestellfensters liegen,
                            geteilt durch die Anzahl der Fenster, die in diesem Schuljahr geendet haben.
                            Aktuell: {{ $current['collective']['count'] }}
                            {{ $current['collective']['count'] === 1 ? 'Fenster' : 'Fenster' }},
                            zusammen {{ $euro($current['collective']['revenue']) }}.
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
                            zusammen {{ $euro($current['ondemand']['revenue']) }}.
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
                            <span class="hint">(Vorjahr)</span>
                        @endif
                    </div>
                    <div class="value">{{ $euro($forecast['target']) }}</div>
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
                    Nach Produktart über alle Schulen hinweg: Der Schulname und Druckzusätze fallen aus dem
                    Produktnamen, „BG Korneuburg Schulhoodie" und „HAK Wien STICK-Schulhoodie" landen also beide
                    unter „Schulhoodie". Sortiert nach <strong>Stückzahl</strong>; der Umsatz steht in der Tabelle.
                    On-Demand-Produkte heißen bei Printify teils anders und erscheinen dann als eigener Eintrag.
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
            <h2>Bestellfenster im Detail
                <x-info label="Welcher Zeitraum gilt je Schule?">
                    Der angezeigte Zeitraum enthält bereits den Puffer aus der Filterzeile
                    ({{ $filters->paddingBefore }} Tage vorher, {{ $filters->paddingAfter }} Tage nachher). Er ist
                    deshalb länger als das im Antrag eingestellte Bestellfenster.
                </x-info>
            </h2>

            <div class="tablewrap">
                <table class="data">
                    <thead><tr><th>Schule</th><th>Art</th><th>Gewerteter Zeitraum</th><th>Umsatz</th></tr></thead>
                    <tbody>
                        @forelse ($windowRows as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['type'] }}</td>
                                <td>{{ $row['from'] }} – {{ $row['to'] }}</td>
                                <td>{{ $euro($row['revenue']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4">Im Schuljahr {{ $current['label'] }} endete kein Bestellfenster und es war kein On-Demand-Shop aktiv.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
