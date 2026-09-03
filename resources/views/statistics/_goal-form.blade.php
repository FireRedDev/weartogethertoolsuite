{{--
    Saisonziel und Umsätze außerhalb des Webshops.

    Bewusst ein eigenes Formular mit eigener Aktion (POST) statt eines Filters:
    Die Werte werden gespeichert und gelten für alle im Team.
--}}
<details class="explain" @if (! $goal->isSet()) open @endif>
    <summary>{{ $goal->isSet() ? 'Ziel und Umsätze außerhalb des Shops ändern' : 'Ziel und Umsätze außerhalb des Shops eintragen' }}</summary>
    <div class="explain-body">
        <form method="post" action="{{ route('statistics.goal') }}">
            @csrf
            <input type="hidden" name="schuljahr" value="{{ $filters->year->key() }}">
            @foreach ($filters->query() as $key => $value)
                @if ($key !== 'schuljahr')
                    @if (is_array($value))
                        @foreach ($value as $entry)
                            <input type="hidden" name="{{ $key }}[]" value="{{ $entry }}">
                        @endforeach
                    @else
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endif
            @endforeach

            <div class="filters">
                <div>
                    <label for="target_revenue">Zielumsatz {{ $current['label'] }} (€)
                        <x-info label="Was passiert ohne Eintrag?">
                            Dann gilt der <strong>tatsächlich erreichte Umsatz des Vorjahres</strong>
                            ({{ $euro($forecast['previousTotal']) }}) als Ziel — also: mindestens so gut werden wie
                            letztes Jahr. Das Ziel setzt die Zielmarke im Verlaufsdiagramm und ist die Grundlage
                            der Bedarfsrechnung („wie viele Bestellfenster fehlen noch?").
                        </x-info>
                    </label>
                    <input type="number" id="target_revenue" name="target_revenue" min="0" step="100"
                           placeholder="{{ $forecast['previousTotal'] ?: '' }}"
                           value="{{ old('target_revenue', $goal->target_revenue) }}">
                </div>

                <div>
                    <label for="manual_revenue">Bereits erzielt außerhalb des Shops (€)
                        <x-info label="Wofür ist das?">
                            Umsätze, die nicht über den Webshop laufen — Listenbestellungen, Direktverkäufe,
                            Rechnungen an Vereine. Sie zählen zum <strong>Ist</strong> und damit zur
                            Zielerreichung, tauchen aber in keiner Rangliste auf (dort gibt es keine
                            Bestellpositionen dazu).
                        </x-info>
                    </label>
                    <input type="number" id="manual_revenue" name="manual_revenue" min="0" step="10"
                           value="{{ old('manual_revenue', $goal->manualRevenue() ?: '') }}">
                </div>

                <div>
                    <label for="manual_forecast">Zusätzlich erwartet außerhalb des Shops (€)
                        <x-info label="Wofür ist das?">
                            Was bis Schuljahresende außerhalb des Shops noch dazukommen soll — bereits zugesagte
                            Listenbestellungen zum Beispiel. Zählt <strong>nur in die Hochrechnung</strong>, nicht
                            ins Ist.
                        </x-info>
                    </label>
                    <input type="number" id="manual_forecast" name="manual_forecast" min="0" step="10"
                           value="{{ old('manual_forecast', $goal->manualForecast() ?: '') }}">
                </div>

                <div>
                    <label for="manual_note">Notiz zu den Umsätzen außerhalb des Shops</label>
                    <input type="text" id="manual_note" name="manual_note" maxlength="200"
                           placeholder="z. B. „2 Listenbestellungen, Rechnung Musikverein“"
                           value="{{ old('manual_note', $goal->manual_note) }}">
                </div>
            </div>

            <div style="margin-top:0.9rem;">
                <button class="btn" type="submit">Saisonziel speichern</button>
                <span class="hint" style="margin-left:0.6rem;">
                    Gilt für alle im Team und bleibt stehen, bis es jemand ändert.
                    @if ($goal->exists && $goal->updated_at)
                        Zuletzt geändert {{ $goal->updated_at->format('d.m.Y, H:i') }} Uhr.
                    @endif
                </span>
            </div>
        </form>
    </div>
</details>
