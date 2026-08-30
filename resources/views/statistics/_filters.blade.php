{{--
    Filterzeile des Statistik-Moduls. Wird von der Auswertung UND von der
    Ladeseite eingebunden, damit sich die Einstellung auch während des Aufbaus
    ändern lässt.

    Erwartet: $filters, $years, $forecast (optional)
--}}
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
                        @foreach ($schools ?? [] as $school)
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
                    <input type="number" id="ziel" name="ziel" min="0" step="100" placeholder="{{ $forecast['previousTotal'] ?? '' }}" value="{{ $filters->target }}">
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
