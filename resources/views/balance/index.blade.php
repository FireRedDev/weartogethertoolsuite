@extends('layouts.app')

@section('title', 'Auftragsbilanz')

@section('content')
    @php
        $euro = fn ($v) => number_format((float) $v, 2, ',', '.').' €';
        $euro0 = fn ($v) => number_format((float) $v, 0, ',', '.').' €';
        $pct = fn ($v) => $v === null ? '–' : number_format($v * 100, 0, ',', '.').' %';
    @endphp

    <h1 style="margin-bottom:0.25rem;">Auftragsbilanz</h1>
    <p class="lead" style="margin-bottom:0.5rem;">
        Jeder Auftrag eine Zeile — wie bisher in der Excel, nur an einem Ort und mit dem Webshop verbunden.
    </p>
    {{-- Die zwei Module gehören zusammen; das war in der Navigation nicht zu sehen. --}}
    <p class="hint" style="margin-bottom:1.25rem;">
        <strong style="color:var(--ink);">Hier wird eingetragen.</strong> Ausgewertet wird nebenan:
        <a href="{{ route('statistics.index') }}">Statistiken</a> zeigt Gewinn, Ranglisten, Prognose und den
        Vergleich mit dem Webshop.
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

        /*
         * Veränderung zum selben Schuljahr davor. Bewusst nur bei den Kacheln
         * und nicht in der Tabelle: Das Modul ist Eingabe und Anzeige,
         * ausgewertet wird im Statistikmodul.
         */
        $delta = function (float $now, float $before) {
            if ($before <= 0.0) {
                return null;
            }
            $change = ($now - $before) / $before;

            return [
                'text' => ($change >= 0 ? '+' : '−').number_format(abs($change) * 100, 1, ',', '.').' %',
                'tone' => abs($change) < 0.005 ? 'flat' : ($change > 0 ? 'up' : 'down'),
            ];
        };
        $vs = ' gegenüber '.$year->previous()->label();

        // Was noch nachzutragen ist. Die 12 Aufträge ohne Ausgaben sind der
        // Grund für die 83-%-Margen in der Statistik — sie sind nicht
        // besonders gut, nur unfertig.
        $openExpenses = collect($s['list'])->filter(
            fn ($o) => $o->revenueTotal() > 0 && (float) $o->expenses <= 0.0
        );
    @endphp

    <div class="kpis">
        <div class="kpi">
            <div class="label">Einnahmen gesamt (brutto)</div>
            <div class="value hero">{{ $euro($s['revenue']) }}</div>
            <div class="delta flat">{{ $s['orders'] }} Aufträge · {{ $euro0($s['avgRevenue'] ?? 0) }} im Schnitt</div>
            @php $d = $delta((float) $s['revenue'], (float) $previous['revenue']); @endphp
            @if ($d)
                <div class="delta {{ $d['tone'] }}">{{ $d['text'] }}{{ $vs }}</div>
            @endif
        </div>
        <div class="kpi">
            <div class="label">
                davon Online
                <x-info label="Was zählt als Online?">
                    Alles, was über einen Webshop bezahlt wurde. Ob die Statistik diesen Betrag verwendet
                    oder ihn selbst aus dem Shop holt, entscheidet <strong>je Auftrag</strong> die Einstellung
                    „Online-Einnahmen kommen": Steht sie auf <strong>Aus dem Webshop</strong>, bleibt diese
                    Spalte in der Statistik beiseite, damit kein Umsatz doppelt zählt.<br><br>
                    Bei der Übernahme der Altdaten wurde alles ab dem Schuljahr
                    {{ config('auftragsbilanz.shop_online_from_year') }}/{{ substr((string) (config('auftragsbilanz.shop_online_from_year') + 1), -2) }}
                    auf „Aus dem Webshop" gestellt — das war die erste Saison, die vollständig über den
                    eigenen Shop lief. Bei einzelnen Aufträgen lässt sich das ändern.
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
        {{--
            Der Gewinn steht am Ende der Geldkette, nicht mittendrin: Er ergibt
            sich aus allem, was links von ihm steht.
        --}}
        <div class="kpi">
            <div class="label">Gewinn</div>
            <div class="value hero">{{ $euro($s['profit']) }}</div>
            <div class="delta {{ ($s['margin'] ?? 0) >= 0.25 ? 'up' : (($s['margin'] ?? 0) < 0.1 ? 'warn' : 'flat') }}">
                {{ $pct($s['margin']) }} vom Bruttoumsatz
            </div>
            @php $d = $delta((float) $s['profit'], (float) $previous['profit']); @endphp
            @if ($d)
                <div class="delta {{ $d['tone'] }}">{{ $d['text'] }}{{ $vs }}</div>
            @endif
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
            @php $d = $delta((float) $s['products'], (float) $previous['products']); @endphp
            @if ($d)
                <div class="delta {{ $d['tone'] }}">{{ $d['text'] }}{{ $vs }}</div>
            @endif
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
            <p class="hint" style="margin:0 0 0.75rem;">
                Jede Zeile lässt sich direkt hier <strong style="color:var(--ink);">bearbeiten</strong> oder
                <strong style="color:var(--ink);">löschen</strong> — die beiden Verweise stehen unter dem
                Auftragsnamen.
            </p>

            <div class="searchbox">
                <label for="ordersearch" class="hint">Auftrag oder Schule suchen</label>
                <input type="search" id="ordersearch" placeholder="z. B. Dachsberg" autocomplete="off">
            </div>

            {{--
                Der Arbeitsvorrat: Aufträge, bei denen erkennbar noch etwas
                fehlt. Kein Alarm, sondern eine Liste zum Abarbeiten — genau
                diese Zeilen erzeugen sonst in der Statistik Margen von 83 %.
            --}}
            @if ($openExpenses->isNotEmpty())
                <p class="hint" style="margin:0 0 0.6rem;">
                    Zu prüfen
                    <x-info label="Warum ist das wichtig?">
                        Ohne Ausgaben ist der Gewinn dieser Aufträge rechnerisch der ganze Nettoumsatz — sie
                        stehen dadurch in jeder Rangliste ganz oben, obwohl sie nur unfertig sind. Die Marge
                        bleibt deshalb leer, bis die Produktionskosten eingetragen sind.
                    </x-info><span>:</span>
                    <button type="button" class="linkish" data-filter="ohne-ausgaben">{{ $openExpenses->count() }} {{ $openExpenses->count() === 1 ? 'Auftrag' : 'Aufträge' }} ohne eingetragene Ausgaben ({{ $euro($openExpenses->sum(fn ($o) => $o->revenueTotal())) }} Umsatz)</button>
                </p>
            @endif

            <div class="tablewrap cards">
                <table class="data cards" id="ordertable">
                    <thead>
                        <tr>
                            <th class="stickycol sortable" data-sort="text">Auftrag</th>
                            <th class="sortable" data-sort="num">Datum</th>
                            <th class="sortable" data-sort="num" style="text-align:right;">Einnahmen ges.</th>
                            <th style="text-align:right;">Online</th>
                            <th style="text-align:right;">Bar</th>
                            <th style="text-align:right;">Provision</th>
                            <th class="sortable" data-sort="num" style="text-align:right;">Ausgaben</th>
                            <th style="text-align:right;">USt.</th>
                            <th class="sortable" data-sort="num" style="text-align:right;">Gewinn</th>
                            <th class="sortable" data-sort="num" style="text-align:right;">Marge</th>
                            <th class="sortable" data-sort="num" style="text-align:right;">Teile</th>
                            <th style="text-align:right;">Indiv.</th>
                            <th>Verknüpfung</th>
                            <th>Anmerkung</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($s['list'] as $order)
                            @php
                                $total = $order->revenueTotal();
                                $hasExpenses = (float) $order->expenses > 0.0;
                                // Ohne Ausgaben ist die Marge rechnerisch richtig und
                                // inhaltlich irreführend — dann lieber ein Strich.
                                $margin = $total > 0 && ! $hasExpenses ? null : $order->marginShare();
                                $needsExpenses = $total > 0 && ! $hasExpenses;
                                // Ein Auftrag ganz ohne Beträge ist meist ein Musterpaket
                                // oder eine Gutscheineinlösung; die Anmerkung sagt das.
                                $blank = $total <= 0.0 && $order->productCount() === 0;
                                // Nullzellen kosten auf der Telefonkarte nur Platz.
                                $z = fn ($v) => (float) $v == 0.0 ? ' blank' : '';
                            @endphp
                            <tr @class(['muted' => $blank]) data-ohne-ausgaben="{{ $needsExpenses ? '1' : '0' }}">
                                {{--
                                    Bearbeiten und Löschen stehen in der fixierten ersten
                                    Spalte, weil nur sie beim Scrollen stehen bleibt — hinten
                                    in Spalte 15 war „Bearbeiten" am Telefon unerreichbar und
                                    am Desktop außerhalb des Sichtfelds. „Löschen" gab es
                                    überhaupt nur unten auf der Bearbeiten-Seite.
                                --}}
                                <td class="stickycol">
                                    <a href="{{ route('balance.edit', $order) }}">{{ $order->label() }}</a>
                                    @if ($blank && $order->note)
                                        <span class="hint"> · {{ $order->note }}</span>
                                    @endif
                                    <span class="rowactions">
                                        <a href="{{ route('balance.edit', $order) }}">✎ Bearbeiten</a>
                                        <form method="post" action="{{ route('balance.destroy', $order) }}"
                                              data-confirm="{{ $order->label() }}">
                                            @csrf
                                            @method('delete')
                                            <button type="submit" class="linkish danger">🗑 Löschen</button>
                                        </form>
                                    </span>
                                </td>
                                <td data-label="Datum" data-value="{{ $order->ordered_on?->format('Ymd') }}">
                                    @if ($order->date_is_estimate)
                                        <span class="hint">Schuljahresende (geschätzt)</span>
                                    @else
                                        {{ $order->ordered_on?->format('d.m.Y') }}
                                    @endif
                                </td>
                                <td data-label="Einnahmen ges." data-value="{{ $total }}" style="text-align:right;">{{ $euro($total) }}</td>
                                <td data-label="Online" class="{{ trim($z($order->revenue_online)) }}" style="text-align:right;">{{ $euro($order->revenue_online) }}</td>
                                <td data-label="Bar" class="{{ trim($z($order->revenue_cash)) }}" style="text-align:right;">{{ $euro($order->revenue_cash) }}</td>
                                <td data-label="Provision" class="{{ trim($z($order->commission)) }}" style="text-align:right;">{{ $euro($order->commission) }}</td>
                                <td data-label="Ausgaben" data-value="{{ $order->expenses }}" class="{{ trim($z($order->expenses)) }}" style="text-align:right;">{{ $euro($order->expenses) }}</td>
                                <td data-label="USt." class="{{ trim($z($order->vat)) }}" style="text-align:right;">{{ $euro($order->vat) }}</td>
                                <td data-label="Gewinn" data-value="{{ $order->profit() }}" style="text-align:right;color:{{ $order->profit() < 0 ? 'var(--error)' : 'inherit' }};">{{ $euro($order->profit()) }}</td>
                                <td data-label="Marge" data-value="{{ $margin ?? -1 }}" style="text-align:right;">
                                    @if ($needsExpenses)
                                        <span class="hint">–</span>
                                    @else
                                        {{ $pct($margin) }}
                                    @endif
                                </td>
                                <td data-label="Teile" data-value="{{ $order->productCount() }}" class="{{ $order->productCount() === 0 ? 'blank' : '' }}" style="text-align:right;">{{ $order->productCount() }}</td>
                                <td data-label="Indiv." class="{{ (int) $order->individual === 0 ? 'blank' : '' }}" style="text-align:right;">{{ $order->individual }}</td>
                                <td data-label="Verknüpfung" class="{{ $order->school_onboarding_id === null ? 'blank' : '' }}">
                                    @if ($order->school_onboarding_id !== null)
                                        <a href="{{ route('schools.show', $order->school_onboarding_id) }}">Bestellfenster</a>
                                    @else
                                        <span class="hint">–</span>
                                    @endif
                                </td>
                                <td data-label="Anmerkung" class="{{ $order->note && ! $blank ? '' : 'blank' }}" style="white-space:normal;max-width:22rem;">{{ $order->note }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:700;">
                            <td class="stickycol">Summe {{ $year->label() }}</td>
                            <td data-label="Aufträge">{{ $s['orders'] }} Aufträge</td>
                            <td data-label="Einnahmen ges." style="text-align:right;">{{ $euro($s['revenue']) }}</td>
                            <td data-label="Online" style="text-align:right;">{{ $euro($s['revenueOnline']) }}</td>
                            <td data-label="Bar" style="text-align:right;">{{ $euro($s['revenueCash']) }}</td>
                            <td data-label="Provision" class="blank" style="text-align:right;">{{ $euro($s['commission']) }}</td>
                            <td data-label="Ausgaben" style="text-align:right;">{{ $euro($s['expenses']) }}</td>
                            <td data-label="USt." class="blank" style="text-align:right;">{{ $euro($s['vat']) }}</td>
                            <td data-label="Gewinn" style="text-align:right;">{{ $euro($s['profit']) }}</td>
                            <td data-label="Marge" style="text-align:right;">{{ $pct($s['margin']) }}</td>
                            <td data-label="Teile" style="text-align:right;">{{ $s['products'] }}</td>
                            <td data-label="Indiv." class="blank" style="text-align:right;">{{ $s['individual'] }}</td>
                            <td colspan="2" class="blank"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

    <script>
        /*
         * Reine Anzeigehilfen — nichts davon verändert Daten oder lädt nach:
         * Suche, Schnellfilter „ohne Ausgaben" und Sortieren nach Spalte.
         * Ohne JavaScript bleibt die Tabelle vollständig und in Auftragsfolge.
         */
        (function () {
            const table = document.getElementById('ordertable');
            if (! table) return;

            /*
             * Löschen fragt nach — mit dem Namen des Auftrags, damit in einer
             * Liste von 35 Zeilen erkennbar ist, welche gerade erwischt wurde.
             * Der Name steht in data-confirm und nicht in onsubmit: Ein
             * Apostroph im Schulnamen würde das Attribut sonst zerreißen.
             */
            table.addEventListener('submit', function (event) {
                const form = event.target.closest('form[data-confirm]');
                if (! form) return;
                const text = '„' + form.dataset.confirm + '" wirklich löschen?'
                    + ' Das lässt sich nicht rückgängig machen.';
                if (! window.confirm(text)) {
                    event.preventDefault();
                }
            });
            const box = document.getElementById('ordersearch');
            const body = table.tBodies[0];
            let needle = '';
            let onlyOpen = false;

            function apply() {
                Array.prototype.forEach.call(body.rows, function (row) {
                    const bySearch = needle === '' || row.textContent.toLowerCase().includes(needle);
                    const byFilter = ! onlyOpen || row.dataset.ohneAusgaben === '1';
                    row.hidden = ! (bySearch && byFilter);
                });
            }

            if (box) {
                box.addEventListener('input', function () {
                    needle = box.value.trim().toLowerCase();
                    apply();
                });
            }

            const filterButton = document.querySelector('[data-filter="ohne-ausgaben"]');
            if (filterButton) {
                filterButton.setAttribute('aria-pressed', 'false');
                filterButton.addEventListener('click', function () {
                    onlyOpen = ! onlyOpen;
                    filterButton.setAttribute('aria-pressed', onlyOpen ? 'true' : 'false');
                    apply();
                });
            }

            // Sortieren: Zahlen aus data-value, Text aus dem Zellinhalt.
            // Ein zweiter Klick dreht die Richtung um.
            const headers = table.querySelectorAll('th.sortable');
            Array.prototype.forEach.call(headers, function (th, position) {
                const index = Array.prototype.indexOf.call(th.parentNode.cells, th);
                th.setAttribute('tabindex', '0');
                th.setAttribute('role', 'button');

                function sort() {
                    const numeric = th.dataset.sort === 'num';
                    const descending = th.getAttribute('aria-sort') !== 'descending';
                    Array.prototype.forEach.call(headers, function (other) {
                        other.removeAttribute('aria-sort');
                    });
                    th.setAttribute('aria-sort', descending ? 'descending' : 'ascending');

                    const rows = Array.prototype.slice.call(body.rows);
                    rows.sort(function (a, b) {
                        const x = a.cells[index], y = b.cells[index];
                        if (numeric) {
                            const nx = parseFloat(x.dataset.value || '0') || 0;
                            const ny = parseFloat(y.dataset.value || '0') || 0;
                            return descending ? ny - nx : nx - ny;
                        }
                        const tx = x.textContent.trim(), ty = y.textContent.trim();
                        return descending ? ty.localeCompare(tx, 'de') : tx.localeCompare(ty, 'de');
                    });
                    rows.forEach(function (row) { body.appendChild(row); });
                }

                th.addEventListener('click', sort);
                th.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        sort();
                    }
                });
            });
        })();
    </script>
@endsection
