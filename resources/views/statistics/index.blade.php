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
                    Zwei Quellen: die tatsächlichen Bestellungen aus dem WooCommerce-Shop und die
                    <a href="{{ route('balance.index') }}">Auftragsbilanz</a> mit allem, was am Shop vorbeilief
                    (Bargeld, Direktverkäufe, die Jahre vor dem eigenen Shop). Zusammengefasst nach
                    <strong>Schuljahr — 1. August bis 31. Juli</strong> — und verglichen mit dem Vorjahr.
                    Umsatz ist immer der Bruttobetrag{{ config('statistics.revenue_includes_tax') ? ' inklusive USt.' : ' ohne USt.' }};
                    Versandkosten und Gebühren zählen nicht mit, weil sie keinem Produkt und keiner Schule zuzuordnen
                    sind.
                </x-info>
            </h1>
            <a class="btn secondary" href="{{ route('statistics.index', $filters->query(['neu' => 1])) }}">↻ Daten neu laden</a>
        </div>

        {{-- Die zwei Module gehören zusammen; das war in der Navigation nicht zu sehen. --}}
        <p class="hint" style="margin:0.35rem 0 0;">
            <strong style="color:var(--ink);">Hier wird ausgewertet.</strong> Eingetragen wird nebenan:
            <a href="{{ route('balance.index') }}">Auftragsbilanz</a> — dort stehen Einnahmen, Ausgaben und
            Provision je Auftrag.
        </p>

        @php
            // Drei ehrliche Zustände statt eines Platzhalters mit angehängtem
            // „Uhr" — ohne geladene Monate ergab das den Satz „Datenstand:
            // unbekannt Uhr".
            $dataState = $fetchedAt
                ? $fetchedAt->format('d.m.Y, H:i').' Uhr'
                : (! $filters->sourceShop
                    ? 'Auftragsbilanz, laufend gepflegt'
                    : 'noch nichts aus dem Shop geladen');
        @endphp
        <p class="hint" style="margin:0.35rem 0 0;">
            Datenstand: <strong>{{ $dataState }}</strong>
            <x-info label="Was heißt Datenstand?">
                So alt ist der älteste Baustein dieser Auswertung — alles Angezeigte ist also mindestens so aktuell.
                Abgerufen wird monatsweise: Abgeschlossene Monate ändern sich nicht mehr und werden 24 Stunden
                gehalten, der laufende Monat höchstens 30 Minuten. Wer die Zahlen sofort frisch braucht, klickt
                „↻ Daten neu laden".
            </x-info>
        </p>

        @include('statistics._sources')
        @include('statistics._filters')
    </div>

    @if (session('goalSaved'))
        <div class="alert ok">Saisonziel gespeichert. Es gilt jetzt für alle im Team, bis es jemand ändert.</div>
    @endif

    <div class="card" id="saisonziel">
        <h2 style="margin-top:0;">Saisonziel {{ $current['label'] }}
            <x-info label="Warum steht das hier und nicht bei den Filtern?">
                Ein Ziel ist keine Ansicht, sondern eine Vereinbarung. Es wird gespeichert, gilt für alle im Team
                und bleibt stehen, bis es jemand ändert — anders als die Filter, die nur den eigenen Blick auf die
                Daten verändern.
            </x-info>
        </h2>

        <div class="kpis" style="margin-bottom:1rem;">
            <div class="kpi">
                <div class="label">Ziel</div>
                <div class="value hero">{{ $forecast['targetKnown'] ? $euro($forecast['target']) : '—' }}</div>
                @if (! $forecast['targetKnown'])
                    <div class="delta warn">
                        Kein Ziel eingetragen, und der Vorjahresumsatz taugt gerade nicht als Vorgabe: Eine
                        Umsatzquelle ist abgeschaltet, damit wäre er nur ein Ausschnitt.
                    </div>
                @elseif ($forecast['targetIsDefault'])
                    <div class="delta flat">kein eigenes Ziel eingetragen — es gilt der Vorjahresumsatz</div>
                @endif
            </div>
            <div class="kpi">
                <div class="label">Bereits erreicht
                    {{--
                        Die Aufteilung muss die tatsächlichen Quellen nennen. „X aus dem
                        Webshop" war falsch: In `ytd` steckt auch, was die Auftragsbilanz
                        beisteuert — und mit abgeschalteter Shop-Quelle ausschließlich das.
                    --}}
                    @if ($forecast['manualRevenue'] > 0 || ($current['manualRevenue'] ?? 0) > 0)
                        <x-info label="Woraus setzt sich das zusammen?">
                            @if ($filters->sourceShop)
                                {{ $euro($current['shopRevenue'] ?? $forecast['ytd']) }} aus dem Webshop.
                            @endif
                            @if (($current['manualRevenue'] ?? 0) > 0)
                                {{ $euro($current['manualRevenue']) }} aus der
                                <a href="{{ route('balance.index') }}">Auftragsbilanz</a> (Bargeld und Aufträge
                                außerhalb des Shops).
                            @endif
                            @if ($forecast['manualRevenue'] > 0)
                                {{ $euro($forecast['manualRevenue']) }} beim Saisonziel von Hand
                                eingetragen{{ $goal->manual_note ? ' ('.$goal->manual_note.')' : '' }}.
                            @endif
                        </x-info>
                    @endif
                </div>
                <div class="value">{{ $euro($forecast['achieved']) }}</div>
                <div class="delta {{ $forecast['openToTarget'] > 0 ? 'flat' : 'up' }}">
                    {{ $forecast['targetShare'] === null ? '—' : number_format($forecast['targetShare'] * 100, 1, ',', '.').' % vom Ziel' }}
                </div>
            </div>
            <div class="kpi">
                <div class="label">Noch offen</div>
                <div class="value {{ $plan['reached'] || ! $plan['targetKnown'] ? '' : 'hero' }}">
                    @if (! $plan['targetKnown'])
                        —
                    @else
                        {{ $plan['reached'] ? 'Ziel erreicht' : $euro($plan['open']) }}
                    @endif
                </div>
                @if ($plan['targetKnown'] && ! $plan['reached'] && $plan['expectedRest'] !== null)
                    <div class="delta {{ ($plan['gapAfterForecast'] ?? 0) > 0 ? 'down' : 'up' }}">
                        Hochrechnung deckt davon {{ $euro($plan['expectedRest']) }}{{ ($plan['gapAfterForecast'] ?? 0) > 0 ? ', es fehlen '.$euro($plan['gapAfterForecast']) : '' }}
                    </div>
                @endif
            </div>
        </div>

        @include('statistics._goal-form')
    </div>

    @php
        $revenueDelta = $delta($current['revenue'], $previous['revenue']);
        $ytdDelta = $delta($current['revenue'], $previousAtSamePoint);
        $orderDelta = $delta($current['avgPerOrder'], $previous['avgPerOrder']);
        $collectiveDelta = $delta($current['collective']['avg'], $previous['collective']['avg']);
        $ondemandDelta = $delta($current['ondemand']['avg'], $previous['ondemand']['avg']);
        $quantityDelta = $delta((float) $current['quantity'], (float) $previous['quantity']);

        // Zählzeile der Fenster-Kacheln. Bewusst hier und nicht in der Ansicht:
        // Ein @if direkt hinter einem Wort erkennt Blade nicht als Direktive,
        // es landete wörtlich auf der Seite.
        $windowCount = function (array $box, string $noun) {
            $text = $box['done'].' von '.$box['count'].' '.$noun;
            if ($box['running'] > 0) {
                $text .= ', '.$box['running'].' laufend';
            }

            return $text;
        };

        /*
         * Die aus der Excel übernommenen Aufträge tragen alle das geschätzte
         * Datum des Schuljahresendes. Im Monatsverlauf sitzen sie deshalb als
         * ein einziger Balken im Juli — ohne diesen Hinweis liest man daraus
         * eine Saisonspitze, die es nie gab. Die Jahressumme stimmt trotzdem.
         */
        $estimateNotice = null;
        if ($filters->sourceOther) {
            $estimates = [];
            foreach ([[$current['label'], $balance], [$previous['label'], $balancePrevious]] as [$yearLabel, $row]) {
                if (($row['estimatedDates'] ?? 0) > 0) {
                    $estimates[] = $row['estimatedDates'].' von '.$row['orders'].' Aufträgen in '.$yearLabel;
                }
            }
            if ($estimates !== []) {
                $estimateNotice = 'Ohne echtes Auftragsdatum aus der Excel übernommen: '.implode(', ', $estimates)
                    .'. Sie sitzen alle am 31. Juli — der Monatsverlauf dieser Jahre ist deshalb nicht'
                    .' aussagekräftig, die Jahressumme schon.';
            }
        }

        // Ein Schuljahr, das gerade erst begonnen hat, ist nicht leer, weil
        // etwas fehlt — es ist leer, weil noch nichts passiert ist. Das muss
        // dort stehen, wo sonst „keine Verkäufe erfasst" steht.
        $freshSeason = $filters->year->isCurrent() && (float) $current['revenue'] <= 0.0;
        $shopOff = ! $filters->sourceShop;

        /*
         * Eine leere Rangliste hat drei mögliche Gründe, und nur einer davon
         * heißt „es wurde nichts verkauft". Produkte, Farben und Schulen kommen
         * ausschließlich aus den Bestellpositionen des Shops — ist die Quelle
         * abgeschaltet, sind sie zwangsläufig leer und dürfen das nicht den
         * Daten anlasten.
         */
        $rankingEmpty = static function (string $fallback) use ($shopOff, $freshSeason) {
            if ($shopOff) {
                return 'Diese Rangliste kommt aus den Bestellungen des Webshops. Die Shop-Quelle ist gerade '
                    .'ausgeschaltet — mit dem Schalter „Shop-Umsätze“ ganz oben kommt sie zurück.';
            }
            if ($freshSeason) {
                return 'Das Schuljahr hat am 1. August begonnen — hier stehen Zahlen, sobald die erste Bestellung '
                    .'eingegangen ist.';
            }

            return $fallback;
        };
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

            {{--
                Ein frisch begonnenes Schuljahr ist nicht kaputt, es ist jung.
                Ohne diesen Satz stehen darunter nur Nullen und Striche, und der
                erste Eindruck der Seite ist „hier fehlen die Daten".
            --}}
            @if ($freshSeason)
                <div class="alert info">
                    <strong>Die Saison {{ $current['label'] }} hat gerade erst begonnen</strong> (Schuljahr:
                    1. August bis 31. Juli). Umsatz, Ranglisten und Prognose füllen sich, sobald die ersten
                    Bestellungen eingehen — bis dahin stehen hier Nullen, und das ist richtig so.
                    <a href="{{ route('statistics.index', $filters->query(['schuljahr' => $filters->year->previous()->key()])) }}">
                        {{ $previous['label'] }} ansehen</a>, dort sind die Zahlen vollständig.
                </div>
            @endif

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
                    @if ($filters->sourceShop && $filters->sourceOther && ($current['manualRevenue'] ?? 0) > 0)
                        <div class="delta flat">
                            {{ $euro($current['shopRevenue']) }} aus dem Shop ·
                            {{ $euro($current['manualRevenue']) }} sonstige
                            ({{ $current['manualOrders'] }} {{ $current['manualOrders'] === 1 ? 'Auftrag' : 'Aufträge' }})
                        </div>
                    @elseif (! $filters->sourceShop)
                        <div class="delta warn">Nur sonstige Umsätze — die Shop-Quelle ist ausgeschaltet.</div>
                    @elseif (! $filters->sourceOther)
                        <div class="delta warn">Nur Shop-Umsätze — Bargeld und händische Aufträge sind ausgeschaltet.</div>
                    @endif
                    @if (($current['refundedOrders'] ?? 0) > 0)
                        <div class="delta warn">
                            darin {{ $current['refundedOrders'] }}
                            {{ $current['refundedOrders'] === 1 ? 'Bestellung' : 'Bestellungen' }}
                            mit Erstattung über zusammen {{ $euro($current['refundedTotal']) }} — nicht abgezogen
                        </div>
                    @endif
                    {{--
                        Im laufenden Schuljahr gehört hierher der Vergleich zum SELBEN ZEITPUNKT.
                        Das ganze Vorjahr danebenzustellen ergibt in den ersten Wochen jedes Jahr
                        „−100 %" — eine Zahl, die nur beschreibt, dass das Jahr jung ist.
                    --}}
                    @if ($filters->year->isCurrent() && $ytdDelta)
                        <div class="delta {{ $ytdDelta['tone'] }}">{{ $ytdDelta['text'] }} gegenüber {{ $previous['label'] }} zum selben Zeitpunkt</div>
                    @elseif (! $filters->year->isCurrent() && $revenueDelta)
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
                    <div class="label">Ø Umsatz je Bestellung
                        <x-info label="Welche Bestellungen sind gemeint?">
                            Einzelbestellungen im Webshop — was eine Schülerin oder ein Elternteil in einem
                            Einkauf ausgibt. Die Auftragsbilanz kennt keine Einzelbestellungen, sondern ganze
                            Aufträge; sie bleibt bei dieser Kennzahl deshalb außen vor, auch wenn die sonstigen
                            Umsätze eingeschaltet sind.
                        </x-info>
                    </div>
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
                            <br><br>Nur diese Kachel reagiert auf den Puffer in der Filterzeile.
                        </x-info>
                    </div>
                    <div class="value">{{ $euro($current['collective']['avg']) }}</div>
                    <div class="delta {{ $collectiveDelta ? $collectiveDelta['tone'] : 'flat' }}">
                        {{ $windowCount($current['collective'], 'Fenstern gelaufen') }}{{ $collectiveDelta ? ' · '.$collectiveDelta['text'] : '' }}
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
                        {{ $windowCount($current['ondemand'], 'Shops abgeschlossen') }}{{ $ondemandDelta ? ' · '.$ondemandDelta['text'] : '' }}
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
                <x-info label="Warum August zuerst?">
                    Das Diagramm folgt dem Schuljahr, nicht dem Kalenderjahr — Monat 1 ist August. Die zweite
                    Säule je Monat ist derselbe Monat im Vorjahr.
                </x-info>

                @if ($estimateNotice)
                    <p class="hint" style="margin:0.4rem 0 0;">{{ $estimateNotice }}</p>
                @endif

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
                    <div class="label">Hochgerechneter Jahresumsatz
                        @if ($forecast['manualRevenue'] > 0 || $forecast['manualForecast'] > 0)
                            <x-info label="Woraus setzt sich das zusammen?">
                                {{-- Hochgerechnet wird der Umsatz der eingeschalteten Quellen, nicht nur der des Shops. --}}
                                {{ $euro($forecast['projection']) }} aus dem bisherigen Verlauf hochgerechnet.
                                @if ($forecast['manualRevenue'] > 0)
                                    Dazu {{ $euro($forecast['manualRevenue']) }} bereits außerhalb des Shops erzielt.
                                @endif
                                @if ($forecast['manualForecast'] > 0)
                                    Dazu {{ $euro($forecast['manualForecast']) }} außerhalb des Shops erwartet.
                                @endif
                            </x-info>
                        @endif
                    </div>
                    <div class="value hero">{{ $euro($forecast['projectionTotal'] ?? $forecast['projection']) }}</div>
                    @if ($forecast['possible'])
                        <div class="delta flat">davon noch offen: {{ $euro($forecast['remaining']) }}</div>
                    @endif
                </div>
                <div class="kpi">
                    <div class="label">Zielumsatz
                        {{--
                            Nur wenn das Ziel überhaupt bekannt ist: Bei abgeschalteter Quelle wäre
                            „der tatsächlich erreichte Umsatz des Vorjahres" nur ein Ausschnitt, und
                            genau diese falsche Zahl stand vorher in der Kachel.
                        --}}
                        @if ($forecast['targetKnown'] && $forecast['targetIsDefault'])
                            <x-info label="Woher kommt dieses Ziel?">
                                Es wurde kein eigenes Ziel eingetragen, deshalb gilt der <strong>tatsächlich
                                erreichte Umsatz von {{ $previous['label'] }}</strong>
                                ({{ $euro($forecast['previousTotal']) }}) als Ziel — also: mindestens so gut werden
                                wie im Vorjahr. Ein eigenes Ziel lässt sich oben unter „Saisonziel" eintragen —
                                es wird gespeichert und gilt für alle im Team.
                            </x-info>
                        @endif
                    </div>
                    <div class="value">{{ $forecast['targetKnown'] ? $euro($forecast['target']) : '—' }}</div>
                    @if (! $forecast['targetKnown'])
                        <div class="delta warn">kein Ziel, solange eine Umsatzquelle abgeschaltet ist</div>
                    @elseif ($forecast['targetIsDefault'])
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
                    @if ($forecast['targetKnown'])
                        <div class="delta {{ $forecast['openToTarget'] > 0 ? 'flat' : 'up' }}">
                            {{ $forecast['openToTarget'] > 0 ? 'noch '.$euro($forecast['openToTarget']).' bis zum Ziel' : 'Ziel bereits erreicht' }}
                        </div>
                    @endif
                </div>
                @if ($forecast['openToTarget'] > 0 && $forecast['neededPerMonth'] !== null && $forecast['monthsLeft'] > 0)
                    <div class="kpi">
                        <div class="label">Nötig je Restmonat</div>
                        <div class="value">{{ $euro($forecast['neededPerMonth']) }}</div>
                        <div class="delta flat">{{ $forecast['monthsLeft'] }} Monate bis Schuljahresende</div>
                    </div>
                @endif
            </div>

            {{--
                Ohne bekanntes Ziel gibt es nichts zu planen. Sonst stünde hier
                „Noch offen bis zum Ziel (0,00 €)" und darunter für jede Fensterart
                ein Strich mit der falschen Begründung, es fehle ein abgeschlossenes
                Fenster.
            --}}
            @if ($plan['targetKnown'] && ! $plan['reached'])
                <div class="need-block">
                    <h3>Wie viele Bestellfenster fehlen noch?
                        <x-info label="Wie wird das gerechnet?">
                            Noch offen bis zum Ziel ({{ $euro($plan['open']) }}) geteilt durch den durchschnittlichen
                            Umsatz eines Bestellfensters. Der Durchschnitt kommt aus den <strong>abgeschlossenen</strong>
                            Fenstern dieser Saison und des Vorjahres zusammen — laufende Fenster zählen nicht mit, sie
                            hätten naturgemäß weniger Umsatz und würden die Rechnung zu pessimistisch machen.
                            Sammelbestellfenster und On-Demand-Shops bringen unterschiedlich viel, deshalb steht hier
                            je Art eine eigene Zahl. Es sind <strong>Alternativen</strong>: entweder so viele der
                            einen Art, oder so viele der anderen — in der Praxis wird es eine Mischung.
                        </x-info>
                    </h3>

                    @if (! $plan['hasBasis'])
                        <div class="alert info">Für die Rechnung fehlt die Grundlage: In dieser Saison und im Vorjahr
                            ist noch kein Bestellfenster abgeschlossen, aus dem sich ein Durchschnitt bilden ließe.</div>
                    @else
                        <div class="kpis">
                            @foreach ($plan['types'] as $type)
                                <div class="kpi">
                                    <div class="label">Nötig: {{ $type['label'] }}</div>
                                    @if ($type['needed'] === null)
                                        <div class="value">—</div>
                                        <div class="delta flat">noch kein abgeschlossenes Fenster dieser Art</div>
                                    @else
                                        <div class="value hero">{{ $type['needed'] }}×</div>
                                        <div class="delta flat">
                                            à Ø {{ $euro($type['avg']) }} · Grundlage {{ $type['avgBasis'] }}
                                            abgeschlossene{{ $type['avgFromPrevious'] > 0 ? ' (inkl. '.$type['avgFromPrevious'].' aus dem Vorjahr)' : '' }}
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($plan['expectedRest'] !== null)
                            <p class="hint" style="margin-top:0.6rem;">
                                Die Hochrechnung erwartet bis Schuljahresende ohnehin noch
                                <strong>{{ $euro($plan['expectedRest']) }}</strong>.
                                @if (($plan['gapAfterForecast'] ?? 0) > 0)
                                    Darüber hinaus fehlen <strong>{{ $euro($plan['gapAfterForecast']) }}</strong> —
                                    das ist der Teil, der ohne zusätzliche Schulen nicht zusammenkommt.
                                @else
                                    Damit wäre das Ziel rechnerisch erreicht, wenn die Saison so weiterläuft wie in
                                    den Vorjahren.
                                @endif
                            </p>
                        @endif
                    @endif
                </div>
            @endif

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
            <x-chart.bars :chart="$productChart" title="Meistverkaufte Produkte"
                          :emptyText="$rankingEmpty('Für diesen Zeitraum sind keine Verkäufe erfasst.')">
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
                          :emptyText="$rankingEmpty('Für diesen Zeitraum sind keine Farben erfasst.')">
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
                          :emptyText="$rankingEmpty('Für diesen Zeitraum ist keiner Schule Umsatz zugeordnet.')">
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

    {{-- Die Auswertungen aus der bisherigen Excel — Gewinn, Marge, Ausgaben --}}
    @include('statistics._balance')
@endsection
