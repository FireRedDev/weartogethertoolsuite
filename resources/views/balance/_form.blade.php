{{--
    Eingabemaske für einen Auftrag. Die Reihenfolge folgt der Excel, damit sich
    beim Abtippen nichts sucht: erst wer und wann, dann das Geld, dann die Stücke.

    Was hier NICHT steht: Einnahmen gesamt, netto, Gewinn und Prozent. Das waren
    in der Excel Formeln — hier werden sie ebenso gerechnet und in der Liste
    angezeigt. Wären sie eingebbar, könnte eine Zeile entstehen, deren Summe
    nicht zu ihren Teilen passt.
--}}
@php
    $value = fn (string $field, $fallback = null) => old($field, $fallback);
@endphp

@if ($errors->any())
    <div class="alert error">
        <strong>Bitte noch einmal ansehen:</strong>
        <ul style="margin:0.4rem 0 0;padding-left:1.1rem;">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card">
    <h2 style="margin-top:0;font-size:1.05rem;">Auftrag</h2>
    <div class="filters">
        <div>
            <label for="number">Auftragsnummer <span class="hint">(fortlaufend)</span></label>
            <input type="text" id="number" name="number" maxlength="20" value="{{ $value('number', $order->number) }}">
        </div>
        <div style="grid-column:span 2;">
            <label for="school_name">Schule oder Kunde *</label>
            <input type="text" id="school_name" name="school_name" required maxlength="200"
                   value="{{ $value('school_name', $order->school_name) }}">
        </div>
        <div>
            <label for="ordered_on">Auftragsdatum *
                <x-info label="Welches Datum gehört hierher?">
                    Der Tag, an dem der Auftrag zustande kam — üblicherweise das <strong>Ende des
                    Bestellfensters</strong>, weil dann bestellt wird. Er entscheidet über Schuljahr
                    und Monat in der Auswertung.
                </x-info>
            </label>
            <input type="date" id="ordered_on" name="ordered_on" required
                   value="{{ $value('ordered_on', $order->ordered_on?->format('Y-m-d')) }}">
        </div>
        <div>
            <label for="delivery_type">Art</label>
            <select id="delivery_type" name="delivery_type">
                <option value="">unbekannt</option>
                @foreach (\App\Models\BalanceOrder::DELIVERY_TYPES as $key => $label)
                    <option value="{{ $key }}" @selected($value('delivery_type', $order->delivery_type) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="filters" style="margin-top:0.9rem;">
        <div style="grid-column:span 2;">
            <label for="school_onboarding_id">Verknüpftes Bestellfenster
                <x-info label="Wofür ist die Verknüpfung?">
                    Ist ein Bestellfenster hinterlegt, weiß die Software, welche Shop-Kategorie zu
                    diesem Auftrag gehört — und kann die Online-Einnahmen selbst füllen.
                    Ohne Verknüpfung bleibt der Auftrag eine reine Handeintragung; das ist für
                    Barverkäufe, Vereine und alte Aufträge völlig in Ordnung.
                </x-info>
            </label>
            <select id="school_onboarding_id" name="school_onboarding_id">
                <option value="">— keines —</option>
                @foreach ($onboardings as $onboarding)
                    <option value="{{ $onboarding->id }}" @selected((int) $value('school_onboarding_id', $order->school_onboarding_id) === $onboarding->id)>
                        {{ $onboarding->school_name }}
                        ({{ $onboarding->deliveryTypeLabel() }}{{ $onboarding->window_end ? ', bis '.$onboarding->window_end->format('d.m.Y') : '' }})
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="online_source">Online-Einnahmen kommen
                <x-info label="Was bewirkt die Einstellung?">
                    <strong>Aus dem Webshop:</strong> Die Statistik zählt diesen Umsatz aus den
                    Shop-Bestellungen und lässt das Feld unten beiseite — sonst stünde derselbe
                    Betrag zweimal in der Jahressumme.<br>
                    <strong>Händisch:</strong> Der eingetragene Betrag zählt. So gehören alle
                    Aufträge vor dem eigenen Webshop und alles, was über einen fremden Shop lief.
                </x-info>
            </label>
            <select id="online_source" name="online_source">
                @foreach (\App\Models\BalanceOrder::ONLINE_SOURCES as $key => $label)
                    <option value="{{ $key }}" @selected($value('online_source', $order->online_source) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;font-size:1.05rem;">Geld</h2>
    <p class="lead" style="margin-bottom:0.8rem;">Alle Beträge brutto, in Euro — so wie sie im Shop und auf der Rechnung stehen.</p>
    <div class="filters">
        <div>
            <label for="revenue_online">Einnahmen Online</label>
            <input type="number" step="0.01" min="0" id="revenue_online" name="revenue_online"
                   value="{{ $value('revenue_online', $order->revenue_online ?: '') }}">
            {{--
                Steht die Quelle auf „Webshop", trägt der nächtliche Abgleich
                den Betrag selbst nach — was hier von Hand steht, ist dann weg.
                Das Feld bleibt trotzdem bedienbar: vor dem ersten Nachtrag
                muss sich etwas eintragen lassen.
            --}}
            <p class="hint" id="online_auto" hidden style="margin:-0.7rem 0 1rem;">
                ↻ wird aus dem verknüpften Bestellfenster nachgetragen
            </p>
            <p class="hint" id="online_unlinked" hidden style="margin:-0.7rem 0 1rem;color:var(--warn);">
                ⚠ „Aus dem Webshop“ ohne verknüpftes Bestellfenster — dann kann nichts nachgetragen werden.
            </p>
        </div>
        <div>
            <label for="revenue_cash">Einnahmen Bar und direkt</label>
            <input type="number" step="0.01" min="0" id="revenue_cash" name="revenue_cash"
                   value="{{ $value('revenue_cash', $order->revenue_cash ?: '') }}">
        </div>
        <div>
            <label for="commission">Provision an die Schule</label>
            <input type="number" step="0.01" min="0" id="commission" name="commission"
                   value="{{ $value('commission', $order->commission ?: '') }}">
        </div>
        <div>
            <label for="expenses">Ausgaben <span class="hint">(Produktion, Druck, Versand)</span></label>
            <input type="number" step="0.01" min="0" id="expenses" name="expenses"
                   value="{{ $value('expenses', $order->expenses ?: '') }}">
        </div>
        <div>
            <label for="vat">Umsatzsteuer
                <x-info label="Leer lassen?">
                    Leer gelassen wird sie aus den Einnahmen herausgerechnet (brutto × 20/120).
                    Ausdrücklich <strong>0</strong> eintragen nur, wenn wirklich keine anfällt —
                    das betrifft die Aufträge vor der GmbH-Gründung.
                </x-info>
            </label>
            <input type="number" step="0.01" min="0" id="vat" name="vat"
                   placeholder="wird berechnet" value="{{ $value('vat', $order->exists ? $order->vat : null) }}">
        </div>
    </div>

    {{--
        Die Ergebniszeile der Excel: Gesamt, Umsatzsteuer, Gewinn und Marge,
        beim Tippen mitgerechnet. Reine Anzeige — gespeichert wird nichts
        davon, gerechnet wird weiterhin im Model (siehe Kopf dieser Datei).
    --}}
    <div id="money-result" hidden
         style="margin-top:1rem;background:var(--ink);border-radius:10px;padding:0.85rem 1.1rem;display:flex;flex-wrap:wrap;gap:0.4rem 1.75rem;align-items:center;">
        <span class="hint" style="color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;font-size:0.72rem;font-weight:700;">Ergibt</span>
        <span style="color:#fff;">Einnahmen gesamt <strong data-result="total">–</strong></span>
        <span style="color:#fff;">− Umsatzsteuer <strong data-result="vat">–</strong></span>
        <span style="color:#fff;">− Ausgaben und Provision <strong data-result="costs">–</strong></span>
        <span style="margin-left:auto;color:#ffbb00;font-weight:700;">Gewinn <span data-result="profit">–</span></span>
        <span style="color:#ffbb00;font-weight:700;">Marge <span data-result="margin">–</span></span>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;font-size:1.05rem;">Stückzahlen</h2>
    <p class="lead" style="margin-bottom:0.8rem;">
        Wie viele Teile je Art. Leere Felder zählen als 0.
    </p>
    <div class="filters">
        @foreach ($productTypes as $type => $label)
            <div>
                <label for="p_{{ $type }}">{{ $label }}</label>
                <input type="number" min="0" step="1" id="p_{{ $type }}" name="products[{{ $type }}]"
                       value="{{ $value('products.'.$type, $order->productQuantity($type) ?: '') }}">
            </div>
        @endforeach
        <div>
            <label for="individual">Individualisierungen
                <x-info label="Was zählt hier?">
                    Namen, Nummern, Klassenbezeichnungen — alles, was zusätzlich auf ein Teil
                    gedruckt wird. Kein eigenes Kleidungsstück, zählt deshalb nicht in die
                    verkauften Teile hinein.
                </x-info>
            </label>
            <input type="number" min="0" step="1" id="individual" name="individual"
                   value="{{ $value('individual', $order->individual ?: '') }}">
        </div>
    </div>
</div>

<div class="card">
    <label for="note">Anmerkung</label>
    <input type="text" id="note" name="note" maxlength="500"
           placeholder="z. B. „Stick statt Druck, daher höhere Kosten“"
           value="{{ $value('note', $order->note) }}">
</div>

<script>
    /*
     * Reine Anzeigehilfen im Formular. Nichts davon wird gespeichert:
     *  - die Ergebniszeile rechnet mit, wie es die Formelspalten der Excel taten
     *  - der Hinweis am Feld „Einnahmen Online" sagt, wann die Software den
     *    Wert selbst nachträgt (Quelle „Webshop" + verknüpftes Bestellfenster)
     * Ohne JavaScript fehlt beides und das Formular funktioniert unverändert.
     */
    (function () {
        const vatRate = {{ (float) config('auftragsbilanz.vat_rate') }};
        const box = document.getElementById('money-result');
        const fields = ['revenue_online', 'revenue_cash', 'commission', 'expenses', 'vat']
            .map((id) => document.getElementById(id));
        const source = document.getElementById('online_source');
        const link = document.getElementById('school_onboarding_id');
        const autoNote = document.getElementById('online_auto');
        const unlinkedNote = document.getElementById('online_unlinked');
        if (fields.some((field) => field === null)) return;

        // Schreibweise wie überall sonst im Werkzeug: 1.234,56 €
        // Bewusst „de-DE": de-AT trennt Tausender mit einem schmalen
        // Leerzeichen, der Rest der Anwendung mit einem Punkt.
        const euro = (value) => value.toLocaleString('de-DE', {
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        }) + ' €';
        const num = (field) => {
            const value = parseFloat(String(field.value).replace(',', '.'));
            return isNaN(value) ? 0 : value;
        };
        const put = (name, text) => {
            const cell = box.querySelector('[data-result="' + name + '"]');
            if (cell) cell.textContent = text;
        };

        function recalculate() {
            const [online, cash, commission, expenses, vatField] = fields;
            const total = num(online) + num(cash);
            // Leeres USt.-Feld heißt „herausrechnen", eine ausdrückliche 0 bleibt 0.
            const vat = vatField.value === ''
                ? Math.round(total * vatRate / (1 + vatRate) * 100) / 100
                : num(vatField);
            const costs = num(commission) + num(expenses);
            const profit = Math.round((total - vat - costs) * 100) / 100;

            box.hidden = total <= 0 && costs <= 0;
            put('total', euro(total));
            put('vat', euro(vat));
            put('costs', euro(costs));
            put('profit', euro(profit));
            put('margin', total > 0 ? Math.round(profit / total * 100) + ' %' : '–');
        }

        function updateOnlineHint() {
            if (! source || ! autoNote || ! unlinkedNote) return;
            const fromShop = source.value === 'shop';
            const linked = link && link.value !== '';
            autoNote.hidden = ! (fromShop && linked);
            unlinkedNote.hidden = ! (fromShop && ! linked);
        }

        fields.forEach((field) => field.addEventListener('input', recalculate));
        if (source) source.addEventListener('change', updateOnlineHint);
        if (link) link.addEventListener('change', updateOnlineHint);
        recalculate();
        updateOnlineHint();
    })();
</script>
