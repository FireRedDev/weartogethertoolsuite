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
                    <label for="lieferart">Lieferart
                        <x-info label="Woher kennt die Auswertung die Lieferart?">
                            Aus dem Onboarding-Antrag der Schule. Schulen, die im Shop bestehen, aber keinen Antrag in
                            der Toolsuite haben (händisch angelegt oder aus der Zeit davor), haben keine hinterlegte
                            Lieferart — sie zählen bei „Alle" mit, fallen aber heraus, sobald hier
                            Sammelbestellfenster oder On-Demand gewählt wird.
                        </x-info>
                    </label>
                    <select id="lieferart" name="lieferart">
                        @foreach (App\Services\Statistics\StatisticsFilters::DELIVERY_TYPES as $key => $label)
                            <option value="{{ $key }}" @selected($key === $filters->deliveryType)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="schule">Schule
                        <x-info label="Welche Schulen stehen zur Auswahl?">
                            Alle Produktkategorien unterhalb von
                            „{{ config('schoolshop.parent_category_name') }}" im Shop — unabhängig davon, ob es dazu
                            einen Antrag in der Toolsuite gibt.
                        </x-info>
                    </label>
                    <select id="schule" name="schule">
                        <option value="">Alle Schulen</option>
                        @foreach ($schools ?? [] as $categoryId => $school)
                            <option value="{{ $categoryId }}" @selected($categoryId === $filters->schoolId)>{{ $school['name'] }}</option>
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

            <x-explain title="Was die Filter beeinflussen">
                <p>Die Filterzeile gilt für die <strong>ganze Seite</strong> — jede Kennzahl, jedes Diagramm und
                    jede Rangliste zeigt denselben Ausschnitt. Zwei Filter wirken jedoch nur an einer Stelle:</p>
                <ul>
                    <li><strong>Schuljahr, Lieferart, Schule, Bestellstatus</strong> — wirken auf alles: Kennzahlen,
                        Monatsverlauf, Prognose und alle drei Ranglisten.</li>
                    <li><strong>Vorlauf/Nachlauf</strong> — wirken <em>ausschließlich</em> auf „Ø je
                        Sammelbestellfenster" und „Ø je On-Demand-Shop". Gesamtumsatz, Monatsverlauf und die
                        Ranglisten ändern sich dadurch nicht, weil dort das Bestelldatum zählt und nicht das Fenster.</li>
                    <li><strong>Das Saisonziel ist kein Filter</strong> — es steht als eigene Karte über der
                        Auswertung, wird gespeichert und gilt für alle im Team, bis es jemand ändert.</li>
                </ul>
            </x-explain>

            <div style="margin-top:0.9rem;">
                <button class="btn" type="submit">Auswerten</button>
                @if ($filters->isFiltered())
                    <a class="btn secondary" href="{{ route('statistics.index', ['schuljahr' => $filters->year->key()]) }}" style="margin-left:0.5rem;">Filter zurücksetzen</a>
                @endif
            </div>
        </form>
