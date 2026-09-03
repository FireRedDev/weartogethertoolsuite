@extends('layouts.app')

@section('title', 'Auftragsbilanz')

@section('content')
    @php
        $euro = fn ($v) => number_format((float) $v, 2, ',', '.').' €';
        $euro0 = fn ($v) => number_format((float) $v, 0, ',', '.').' €';
        $pct = fn ($v) => $v === null ? '–' : number_format($v * 100, 0, ',', '.').' %';
    @endphp

    <h1 style="margin-bottom:0.25rem;">Auftragsbilanz</h1>
    <p class="lead">
        Jeder Auftrag eine Zeile — wie bisher in der Excel, nur an einem Ort und mit dem Webshop verbunden.
        Ausgewertet wird im <a href="{{ route('statistics.index') }}">Statistikmodul</a>.
    </p>

    @if (session('balanceSaved'))
        <div class="alert ok">„{{ session('balanceSaved') }}" gespeichert.</div>
    @endif
    @if (session('balanceDeleted'))
        <div class="alert info">„{{ session('balanceDeleted') }}" gelöscht.</div>
    @endif

    <div class="card">
        <form method="get" action="{{ route('balance.index') }}" class="filters" style="align-items:end;">
            <div>
                <label for="schuljahr">Schuljahr
                    <x-info label="Wann beginnt das Schuljahr?">
                        Das Geschäftsjahr des Hauses läuft vom <strong>1. August bis 31. Juli</strong>.
                        Ein Auftrag zählt in das Jahr, in dem sein Auftragsdatum liegt — nicht in das,
                        in dem das Bestellfenster geöffnet wurde.
                    </x-info>
                </label>
                <select id="schuljahr" name="schuljahr" onchange="this.form.submit()">
                    @foreach ($years as $option)
                        <option value="{{ $option->key() }}" @selected($option->key() === $year->key())>{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <noscript><button class="btn secondary" type="submit">Anzeigen</button></noscript>
            </div>
            <div style="text-align:right;">
                <a class="btn" href="{{ route('balance.create') }}">+ Neuer Auftrag</a>
            </div>
        </form>
    </div>

    @php
        $s = $summary;
        // Blade erkennt @if nicht, wenn es direkt hinter einem Wortzeichen
        // steht — solche Texte deshalb hier bauen und unten nur ausgeben.
        $estimateNote = $s['estimatedDates'] > 0
            ? $s['estimatedDates'].' von '.$s['orders'].' Aufträgen tragen noch das geschätzte Datum des Schuljahresendes.'
            : null;
    @endphp

    <div class="kpis">
        <div class="kpi">
            <div class="label">Einnahmen gesamt (brutto)</div>
            <div class="value hero">{{ $euro($s['revenue']) }}</div>
            <div class="delta flat">{{ $s['orders'] }} Aufträge · {{ $euro0($s['avgRevenue'] ?? 0) }} im Schnitt</div>
        </div>
        <div class="kpi">
            <div class="label">
                davon Online
                <x-info label="Was zählt als Online?">
                    Alles, was über einen Webshop bezahlt wurde. Für Schuljahre ab
                    {{ config('auftragsbilanz.shop_online_from_year') }}/{{ substr((string) (config('auftragsbilanz.shop_online_from_year') + 1), -2) }}
                    ist das der eigene Shop — dort holt sich die Statistik die Zahlen selbst und
                    lässt diese Spalte beiseite, damit kein Umsatz doppelt zählt.
                </x-info>
            </div>
            <div class="value">{{ $euro($s['revenueOnline']) }}</div>
        </div>
        <div class="kpi">
            <div class="label">
                davon Bar und direkt
                <x-info label="Was zählt hier hinein?">
                    Barzahlungen bei der Ausgabe, Sammelüberweisungen der Schule, Rechnungen an
                    Vereine — alles, was nicht über den Shop lief. Diese Beträge kennt der Shop
                    nicht; sie kommen ausschließlich von hier.
                </x-info>
            </div>
            <div class="value">{{ $euro($s['revenueCash']) }}</div>
        </div>
        <div class="kpi">
            <div class="label">Gewinn</div>
            <div class="value">{{ $euro($s['profit']) }}</div>
            <div class="delta {{ ($s['margin'] ?? 0) >= 0.25 ? 'up' : (($s['margin'] ?? 0) < 0.1 ? 'warn' : 'flat') }}">
                {{ $pct($s['margin']) }} vom Bruttoumsatz
            </div>
        </div>
        <div class="kpi">
            <div class="label">
                Ausgaben und Provision
                <x-info label="Was steckt darin?">
                    <strong>Ausgaben</strong> sind die Produktionskosten des Auftrags (Textil, Druck,
                    Versand). <strong>Provision</strong> ist der Anteil, der an die Schule oder
                    Schülervertretung zurückgeht.
                </x-info>
            </div>
            <div class="value">{{ $euro($s['expenses'] + $s['commission']) }}</div>
            <div class="delta flat">{{ $euro0($s['expenses']) }} Ausgaben · {{ $euro0($s['commission']) }} Provision</div>
        </div>
        <div class="kpi">
            <div class="label">
                Umsatzsteuer
                <x-info label="Wie wird gerechnet?">
                    Die Einnahmen werden brutto eingetragen; die Umsatzsteuer wird daraus
                    herausgerechnet (brutto × 20/120) und beim Gewinn abgezogen — sie läuft nur
                    durch. Vor der GmbH-Gründung fiel keine an; dort steht 0,00 €.
                </x-info>
            </div>
            <div class="value">{{ $euro($s['vat']) }}</div>
            <div class="delta flat">{{ $euro0($s['revenueNet']) }} netto</div>
        </div>
        <div class="kpi">
            <div class="label">
                Verkaufte Teile
                <x-info label="Was wird gezählt?">
                    Nur Kleidungsstücke. Individualisierungen (Namen, Nummern) sind kein eigenes
                    Teil, sondern ein Zusatz darauf — sie stehen deshalb daneben.
                </x-info>
            </div>
            <div class="value">{{ number_format($s['products'], 0, ',', '.') }}</div>
            <div class="delta flat">{{ number_format($s['individual'], 0, ',', '.') }} Individualisierungen</div>
        </div>
    </div>

    {{-- Abgleich mit dem Webshop --}}
    @include('balance._comparison', ['comparison' => $comparison, 'year' => $year, 'euro' => $euro])

    @if ($estimateNote)
        <div class="alert info">
            <strong>Datum geschätzt:</strong> {{ $estimateNote }}
            Sie stammen aus der Excel, die kein Auftragsdatum kannte. Für Jahres- und Schulsummen
            macht das keinen Unterschied — im Monatsverlauf sitzen sie alle am Jahresende.
            Wer ein Datum kennt, kann es beim Auftrag eintragen.
        </div>
    @endif

    <div class="card">
        <h2 style="margin-top:0;font-size:1.05rem;">Aufträge {{ $year->label() }}</h2>

        @if ($s['orders'] === 0)
            <p class="lead" style="margin-bottom:0;">
                Für dieses Schuljahr ist noch kein Auftrag erfasst.
                <a href="{{ route('balance.create') }}">Jetzt den ersten anlegen.</a>
            </p>
        @else
            <div class="searchbox">
                <label for="ordersearch" class="hint">Auftrag oder Schule suchen</label>
                <input type="search" id="ordersearch" placeholder="z. B. Dachsberg" autocomplete="off">
            </div>
            <div class="tablewrap">
                <table class="data" id="ordertable">
                    <thead>
                        <tr>
                            <th class="stickycol">Auftrag</th>
                            <th>Datum</th>
                            <th style="text-align:right;">Einnahmen ges.</th>
                            <th style="text-align:right;">Online</th>
                            <th style="text-align:right;">Bar</th>
                            <th style="text-align:right;">Provision</th>
                            <th style="text-align:right;">Ausgaben</th>
                            <th style="text-align:right;">USt.</th>
                            <th style="text-align:right;">Gewinn</th>
                            <th style="text-align:right;">%</th>
                            <th style="text-align:right;">Teile</th>
                            <th style="text-align:right;">Indiv.</th>
                            <th>Verknüpfung</th>
                            <th>Anmerkung</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($s['list'] as $order)
                            @php
                                $margin = $order->marginShare();
                                $dateNote = $order->date_is_estimate ? ' (geschätzt)' : '';
                            @endphp
                            <tr>
                                <td class="stickycol"><a href="{{ route('balance.edit', $order) }}">{{ $order->label() }}</a></td>
                                <td>{{ $order->ordered_on?->format('d.m.Y') }}{{ $dateNote }}</td>
                                <td style="text-align:right;">{{ $euro($order->revenueTotal()) }}</td>
                                <td style="text-align:right;">{{ $euro($order->revenue_online) }}</td>
                                <td style="text-align:right;">{{ $euro($order->revenue_cash) }}</td>
                                <td style="text-align:right;">{{ $euro($order->commission) }}</td>
                                <td style="text-align:right;">{{ $euro($order->expenses) }}</td>
                                <td style="text-align:right;">{{ $euro($order->vat) }}</td>
                                <td style="text-align:right;color:{{ $order->profit() < 0 ? 'var(--error)' : 'inherit' }};">{{ $euro($order->profit()) }}</td>
                                <td style="text-align:right;">{{ $pct($margin) }}</td>
                                <td style="text-align:right;">{{ $order->productCount() }}</td>
                                <td style="text-align:right;">{{ $order->individual }}</td>
                                <td>
                                    @if ($order->school_onboarding_id !== null)
                                        <a href="{{ route('schools.show', $order->school_onboarding_id) }}">Bestellfenster</a>
                                    @else
                                        <span class="hint">–</span>
                                    @endif
                                </td>
                                <td style="white-space:normal;max-width:22rem;">{{ $order->note }}</td>
                                <td><a href="{{ route('balance.edit', $order) }}">Bearbeiten</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:700;">
                            <td class="stickycol">Summe {{ $year->label() }}</td>
                            <td>{{ $s['orders'] }} Aufträge</td>
                            <td style="text-align:right;">{{ $euro($s['revenue']) }}</td>
                            <td style="text-align:right;">{{ $euro($s['revenueOnline']) }}</td>
                            <td style="text-align:right;">{{ $euro($s['revenueCash']) }}</td>
                            <td style="text-align:right;">{{ $euro($s['commission']) }}</td>
                            <td style="text-align:right;">{{ $euro($s['expenses']) }}</td>
                            <td style="text-align:right;">{{ $euro($s['vat']) }}</td>
                            <td style="text-align:right;">{{ $euro($s['profit']) }}</td>
                            <td style="text-align:right;">{{ $pct($s['margin']) }}</td>
                            <td style="text-align:right;">{{ $s['products'] }}</td>
                            <td style="text-align:right;">{{ $s['individual'] }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

    <script>
        // Reine Anzeigehilfe: Zeilen ausblenden, die nicht zur Suche passen.
        (function () {
            const box = document.getElementById('ordersearch');
            const table = document.getElementById('ordertable');
            if (! box || ! table) return;
            box.addEventListener('input', function () {
                const needle = box.value.trim().toLowerCase();
                table.querySelectorAll('tbody tr').forEach(function (row) {
                    row.hidden = needle !== '' && ! row.textContent.toLowerCase().includes(needle);
                });
            });
        })();
    </script>
@endsection
