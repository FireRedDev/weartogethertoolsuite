@extends('layouts.app')

@section('title', $onboarding->school_name.' — Schul-Onboarding')

@section('content')
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
            <div>
                <h1>{{ $onboarding->school_name }}</h1>
                <p class="lead">
                    #{{ $onboarding->id }} · {{ $onboarding->statusLabel() }} · {{ $onboarding->deliveryTypeLabel() }}
                    · Quelle: {{ $onboarding->source === 'webhook' ? 'Formular' : 'manuell' }}
                    @if ($onboarding->created_at) · Eingang {{ $onboarding->created_at->format('d.m.Y H:i') }} @endif
                </p>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <a class="btn secondary" href="{{ route('schools.index') }}">Zur Übersicht</a>
                <form method="post" action="{{ route('schools.destroy', $onboarding) }}"
                      onsubmit="return confirm('Diesen Antrag wirklich löschen? Bereits im Shop Angelegtes bleibt bestehen und müsste dort separat entfernt werden.');">
                    @csrf
                    @method('DELETE')
                    <button class="btn secondary" type="submit" style="color:var(--error);">Antrag löschen</button>
                </form>
            </div>
        </div>

        @if (session('saved'))
            <div class="alert ok">✓ Gespeichert.</div>
        @endif
        @if ($errors->any())
            @foreach ($errors->all() as $error)
                <div class="alert error">✖ {{ $error }}</div>
            @endforeach
        @endif

        <div class="stats">
            <div class="stat"><div class="value">{{ $onboarding->expected_orders ?? '—' }}</div><div class="label">erwartete Bestellungen</div></div>
            <div class="stat"><div class="value">{{ $onboarding->student_count ?? '—' }}</div><div class="label">Schüler:innen</div></div>
            <div class="stat"><div class="value">{{ count($onboarding->enabledProducts()) }}</div><div class="label">aktive Produkte</div></div>
            <div class="stat"><div class="value">{{ $onboarding->woo_category_id ? '✓' : '—' }}</div><div class="label">Shop angelegt</div></div>
        </div>
    </div>

    {{-- Formulardaten (Webhook) --}}
    <div class="card">
        <h2>Anfrage-Daten</h2>
        @if (str_starts_with($onboarding->school_name, '⚠') || ($onboarding->source === 'webhook' && $onboarding->notes && str_contains($onboarding->notes, 'Zuordnung fehlgeschlagen')))
            <div class="alert warn">⚠ Die automatische Zuordnung dieser Formular-Einsendung ist fehlgeschlagen — die Rohdaten sind unten einsehbar. Bitte Felder im Konfigurator manuell setzen. Details: {{ $onboarding->notes }}</div>
        @endif
        <div class="tablewrap">
            <table class="data">
                <tbody>
                    <tr><th style="width:220px;">Kontakt</th><td>{{ $onboarding->contact_name }} ({{ $onboarding->contact_role }}) · {{ $onboarding->contact_email }} · {{ $onboarding->contact_phone }} · bevorzugt: {{ $onboarding->contact_preference ?? '—' }}</td></tr>
                    <tr><th>Adresse</th><td>{{ implode(', ', array_filter($onboarding->address ?? [])) ?: '—' }}</td></tr>
                    <tr><th>Druckflächen</th><td>{{ implode(', ', $onboarding->print_areas ?? []) ?: '—' }}</td></tr>
                    <tr><th>Logo-Dateien</th>
                        <td>
                            @forelse ($onboarding->logo_files ?? [] as $file)
                                <a href="{{ $file }}" target="_blank" rel="noopener">{{ basename(parse_url($file, PHP_URL_PATH) ?? $file) }}</a><br>
                            @empty — @endforelse
                        </td>
                    </tr>
                    <tr><th>Logo-Positionierung</th><td>{{ $onboarding->logo_notes ?: '—' }}</td></tr>
                    @if ($onboarding->design_notes)<tr><th>Design-Wunsch</th><td>{{ $onboarding->design_notes }}</td></tr>@endif
                </tbody>
            </table>
        </div>

        @if ($onboarding->source === 'webhook' && $onboarding->raw_entry)
            <details class="warnrows" style="margin-top:0.75rem;">
                <summary>Rohdaten der Formular-Einsendung (Webhook-Payload)</summary>
                <textarea readonly rows="12" style="font-family:ui-monospace,monospace;font-size:0.8rem;margin-top:0.4rem;" onclick="this.select()">{{ json_encode($onboarding->raw_entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</textarea>
            </details>
        @endif
    </div>

    {{-- Konfigurator --}}
    <div class="card">
        <h2>Konfigurator</h2>
        <form method="post" action="{{ route('schools.update', $onboarding) }}">
            @csrf
            @method('PUT')

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                <div>
                    <label for="school_name">Schule/Organisation</label>
                    <input type="text" id="school_name" name="school_name" required value="{{ old('school_name', $onboarding->school_name) }}">
                </div>
                <div>
                    <label for="delivery_type">Lieferart</label>
                    <select id="delivery_type" name="delivery_type" style="width:100%;padding:0.6rem 0.75rem;border:1px solid var(--line);border-radius:8px;font:inherit;background:#fff;">
                        @foreach (\App\Models\SchoolOnboarding::DELIVERY_TYPES as $key => $label)
                            <option value="{{ $key }}" {{ old('delivery_type', $onboarding->delivery_type) === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="status">Status</label>
                    <select id="status" name="status" style="width:100%;padding:0.6rem 0.75rem;border:1px solid var(--line);border-radius:8px;font:inherit;background:#fff;">
                        @foreach (\App\Models\SchoolOnboarding::STATUSES as $key => $label)
                            <option value="{{ $key }}" {{ old('status', $onboarding->status) === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @php($isOndemandInitial = old('delivery_type', $onboarding->delivery_type) === 'ondemand')
                <div id="window_start_field" style="{{ $isOndemandInitial ? 'display:none;' : '' }}">
                    <label for="window_start">Bestellfenster von</label>
                    <input type="date" id="window_start" name="window_start" value="{{ old('window_start', $onboarding->window_start?->format('Y-m-d')) }}">
                </div>
                <div id="window_end_field" style="{{ $isOndemandInitial ? 'display:none;' : '' }}">
                    <label for="window_end">Bestellfenster bis</label>
                    <input type="date" id="window_end" name="window_end" value="{{ old('window_end', $onboarding->window_end?->format('Y-m-d')) }}">
                </div>
            </div>

            <div id="class_list_field" style="{{ $isOndemandInitial ? 'display:none;' : '' }}">
                <label for="class_list" style="margin-top:1rem;">Klassenliste <span class="hint">(kommagetrennt — wird zum Attribut „Klasse")</span></label>
                <textarea id="class_list" name="class_list" rows="2">{{ old('class_list', $onboarding->class_list) }}</textarea>
            </div>
            <p class="hint" id="ondemand_window_hint" style="{{ $isOndemandInitial ? '' : 'display:none;' }}">On-Demand: Bestellfenster und Klassenliste entfallen — Produkte werden laufend einzeln an die Privatadresse der Kund:innen verschickt.</p>

            @php($isOndemand = $onboarding->delivery_type === 'ondemand')
            @php($hasNonEuProvider = collect($printifyShippingInfo ?? [])->contains(fn ($i) => $i['country'] !== null && ! $i['is_eu']))
            <h2 style="margin-top:1rem;">Produkte & Preise</h2>
            @if ($isOndemand)
                <p class="hint">On-Demand: Blueprint-ID und Print-Provider-ID pro Produkt eintragen — mit dem 🔍-Button direkt hier im Konfigurator suchen (siehe auch Spaltenkopf-Hinweis ⓘ). Der Verkaufspreis wird beim Anlegen automatisch gegen Produktionskosten + Versand + {{ (int) round(config('schoolshop.printify.min_margin') * 100) }}% Marge geprüft.</p>
                @if ($hasNonEuProvider)
                    <div class="alert warn">⚠ Mindestens ein Produkt hat aktuell keinen EU-Provider hinterlegt — außerhalb der EU sind Versandkosten und Lieferzeit nach Österreich in der Regel höher (siehe Region/Versand-Spalte unten). Die Marge wird trotzdem korrekt gegen die tatsächlichen Versandkosten geprüft.</div>
                @endif
            @endif
            <div class="tablewrap">
                <table class="data" id="products-table">
                    <thead>
                        <tr>
                            <th></th><th>Produkt</th><th>Preis (€)</th><th>Aufpreis Indiv. (€)</th><th>Größen</th><th>Farben</th>
                            @if ($isOndemand)
                                <th title="IDs herausfinden: (1) 🔍-Button in dieser Zeile — sucht direkt im Printify-Katalog. (2) Per SSH am Server: php artisan printify:check --blueprints=SUCHBEGRIFF. (3) Direkt auf printify.com im Produktkatalog nachsehen.">Printify Blueprint-ID ⓘ</th>
                                <th title="IDs herausfinden: (1) 🔍-Button in dieser Zeile (braucht eine bereits eingetragene Blueprint-ID). (2) Per SSH am Server: php artisan printify:check --providers=BLUEPRINT_ID. (3) Direkt auf printify.com beim Produkt nachsehen.">Provider-ID ⓘ</th>
                                <th>Region / Versand</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($onboarding->products ?? [] as $product)
                            @if (! empty($product['unmapped']))
                                <tr><td colspan="{{ $isOndemand ? 9 : 6 }}"><span class="alert warn" style="display:block;">⚠ {{ $product['label'] }} — bitte manuell im Shop anlegen.</span></td></tr>
                                @continue
                            @endif
                            <tr>
                                <td><input type="checkbox" name="products[{{ $product['key'] }}][enabled]" value="1" {{ ! empty($product['enabled']) ? 'checked' : '' }}></td>
                                <td>{{ $product['label'] }}</td>
                                <td><input type="text" name="products[{{ $product['key'] }}][base_price]" value="{{ number_format($product['base_price'], 2, ',', '') }}" style="width:90px;margin:0;"></td>
                                <td><input type="text" name="products[{{ $product['key'] }}][indiv_surcharge]" value="{{ number_format($product['indiv_surcharge'], 2, ',', '') }}" style="width:90px;margin:0;"></td>
                                <td><input type="text" name="products[{{ $product['key'] }}][sizes]" value="{{ implode(', ', $product['sizes']) }}" style="width:200px;margin:0;"></td>
                                <td><input type="text" name="products[{{ $product['key'] }}][colors]" value="{{ implode(', ', $product['colors']) }}" style="width:220px;margin:0;"></td>
                                @if ($isOndemand)
                                    <td style="white-space:nowrap;">
                                        <input type="text" id="bp-{{ $product['key'] }}" name="products[{{ $product['key'] }}][printify_blueprint_id]" value="{{ $product['printify_blueprint_id'] ?? '' }}" style="width:80px;margin:0;display:inline-block;vertical-align:middle;" placeholder="z. B. 6">
                                        <button type="button" class="btn secondary" style="padding:0.2rem 0.45rem;font-size:0.75rem;margin-left:0.2rem;vertical-align:middle;" onclick="openPrintifySearch('blueprint', 'bp-{{ $product['key'] }}')" title="Blueprint suchen">🔍</button>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        <input type="text" id="pv-{{ $product['key'] }}" name="products[{{ $product['key'] }}][printify_provider_id]" value="{{ $product['printify_provider_id'] ?? '' }}" style="width:70px;margin:0;display:inline-block;vertical-align:middle;" placeholder="z. B. 27">
                                        <button type="button" class="btn secondary" style="padding:0.2rem 0.45rem;font-size:0.75rem;margin-left:0.2rem;vertical-align:middle;" onclick="openPrintifySearch('provider', 'pv-{{ $product['key'] }}', 'bp-{{ $product['key'] }}')" title="Provider suchen (braucht Blueprint-ID)">🔍</button>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        @php($info = $printifyShippingInfo[$product['key']] ?? null)
                                        @if ($info === null)
                                            <span class="hint">—</span>
                                        @else
                                            <span title="{{ $info['provider_title'] }}">
                                                {{ $info['country'] ? ($info['is_eu'] ? '🇪🇺 '.$info['country'] : '🌍 '.$info['country']) : '?' }}
                                            </span>
                                            @if ($info['shipping_eur'] !== null)
                                                · {{ number_format($info['shipping_eur'], 2, ',', '') }} €
                                            @endif
                                            @if ($info['country'] !== null && ! $info['is_eu'])
                                                <br><span class="hint" style="color:var(--error);">außerhalb EU</span>
                                            @endif
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn secondary" style="margin-top:0.6rem;" onclick="addProductRow()">+ Produkt hinzufügen</button>

            <label for="notes" style="margin-top:1rem;">Interne Notizen</label>
            <textarea id="notes" name="notes" rows="2">{{ old('notes', $onboarding->notes) }}</textarea>

            <button class="btn" type="submit">Speichern</button>
        </form>
    </div>

    {{-- Vorlage für "+ Produkt hinzufügen" (wird per JS geklont, __KEY__ durch einen eindeutigen Schlüssel ersetzt) --}}
    <template id="new-product-row-template">
        <tr>
            <td><input type="checkbox" name="products[__KEY__][enabled]" value="1" checked></td>
            <td><input type="text" name="products[__KEY__][label]" placeholder="Produktname" style="width:160px;margin:0;"></td>
            <td><input type="text" name="products[__KEY__][base_price]" placeholder="0,00" style="width:90px;margin:0;"></td>
            <td><input type="text" name="products[__KEY__][indiv_surcharge]" value="{{ number_format(config('schoolshop.indiv_surcharge'), 2, ',', '') }}" style="width:90px;margin:0;"></td>
            <td><input type="text" name="products[__KEY__][sizes]" placeholder="z. B. S, M, L, XL" style="width:200px;margin:0;"></td>
            <td><input type="text" name="products[__KEY__][colors]" placeholder="z. B. schwarz, weiß" style="width:220px;margin:0;"></td>
            @if ($isOndemand)
                <td style="white-space:nowrap;">
                    <input type="text" id="bp-__KEY__" name="products[__KEY__][printify_blueprint_id]" style="width:80px;margin:0;display:inline-block;vertical-align:middle;" placeholder="z. B. 6">
                    <button type="button" class="btn secondary" style="padding:0.2rem 0.45rem;font-size:0.75rem;margin-left:0.2rem;vertical-align:middle;" onclick="openPrintifySearch('blueprint', 'bp-__KEY__')" title="Blueprint suchen">🔍</button>
                </td>
                <td style="white-space:nowrap;">
                    <input type="text" id="pv-__KEY__" name="products[__KEY__][printify_provider_id]" style="width:70px;margin:0;display:inline-block;vertical-align:middle;" placeholder="z. B. 27">
                    <button type="button" class="btn secondary" style="padding:0.2rem 0.45rem;font-size:0.75rem;margin-left:0.2rem;vertical-align:middle;" onclick="openPrintifySearch('provider', 'pv-__KEY__', 'bp-__KEY__')" title="Provider suchen (braucht Blueprint-ID)">🔍</button>
                </td>
                <td class="hint">nach dem Speichern sichtbar</td>
            @endif
            <td><input type="hidden" name="products[__KEY__][new]" value="1"><button type="button" class="btn secondary" style="color:var(--error);padding:0.2rem 0.5rem;" onclick="this.closest('tr').remove()">✕ entfernen</button></td>
        </tr>
    </template>

    {{-- Printify-Suche (Modal) --}}
    <div id="printify-search-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:100;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:1.25rem;max-width:520px;width:92%;max-height:80vh;overflow:auto;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:0.5rem;">
                <h2 id="printify-search-title" style="margin:0;font-size:1.05rem;"></h2>
                <button type="button" class="btn secondary" style="padding:0.25rem 0.65rem;" onclick="closePrintifySearch()">✕</button>
            </div>
            <p class="hint" id="printify-search-hint" style="margin-top:0;"></p>
            <input type="text" id="printify-search-input" placeholder="Suchbegriff eingeben …">
            <div id="printify-search-results"></div>
        </div>
    </div>

    {{-- Shop-Anlage --}}
    <div class="card">
        <h2>Shop-Anlage</h2>
        <p class="lead">Legt Produktkategorie, Produkte mit Variationen (Individualisierung Ja/Nein), Individualisierungs-Eingabefeld und den Schule-Eintrag (CPT) an. {{ $onboarding->delivery_type === 'ondemand' ? 'On-Demand: Versandklasse „'.config('schoolshop.shipping_class_ondemand').'" wird gesetzt; Printify-Anlage siehe README (Beta).' : 'Sammelbestellfenster: kostenloser Versand.' }}</p>

        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <form method="post" action="{{ route('schools.preview', $onboarding) }}">
                @csrf
                <button class="btn secondary" type="submit">Vorschau (ohne Änderungen)</button>
            </form>
            <form method="post" action="{{ route('schools.provision', $onboarding) }}" onsubmit="return confirm('Jetzt wirklich im Shop anlegen?');">
                @csrf
                <button class="btn" type="submit">Im Shop anlegen</button>
            </form>
            @if ($onboarding->delivery_type === 'ondemand' && $onboarding->printify_product_ids)
                <form method="post" action="{{ route('schools.ondemand-sync', $onboarding) }}">
                    @csrf
                    <button class="btn secondary" type="submit">On-Demand-Nachbearbeitung (Versandklasse + Kategorie)</button>
                </form>
            @endif
        </div>

        @if (session('plan'))
            <div class="alert warn" style="margin-top:1rem;">
                <strong>Vorschau — diese Schritte würden ausgeführt:</strong>
                <ol style="margin:0.5rem 0 0 1.2rem;">
                    @foreach (session('plan') as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            </div>
        @endif

        @if (session('provisionError'))
            @php($provisionError = session('provisionError'))
            <div class="alert error" style="margin-top:1rem;">
                ✖ <strong>{{ $provisionError['user'] }}</strong>
                @if ($provisionError['hint'])
                    <div style="margin-top:0.4rem;">{{ $provisionError['hint'] }}</div>
                @endif
                <details class="warnrows" open>
                    <summary>Technische Details (zum Kopieren, für Support)</summary>
                    <textarea readonly rows="3" style="font-family:ui-monospace,monospace;font-size:0.8rem;margin-top:0.4rem;" onclick="this.select()">{{ $provisionError['technical'] }}</textarea>
                </details>
            </div>
        @endif

        @if (session('provisionLog'))
            <div class="alert {{ collect(session('provisionLog'))->every(fn ($l) => $l['ok']) ? 'ok' : 'error' }}" style="margin-top:1rem;">
                <strong>Protokoll:</strong>
                <ol style="margin:0.5rem 0 0 1.2rem;">
                    @foreach (session('provisionLog') as $entry)
                        <li>{{ $entry['ok'] ? '✓' : '✖' }} {{ $entry['step'] }}{{ $entry['detail'] ? ' — '.$entry['detail'] : '' }}</li>
                    @endforeach
                </ol>
            </div>
        @endif

        @if ($onboarding->woo_category_id || $onboarding->pods_post_id)
            <p class="hint" style="margin-top:0.75rem;">
                Angelegt: Kategorie-ID {{ $onboarding->woo_category_id ?? '—' }} ·
                Produkte: {{ implode(', ', array_map(fn ($k, $v) => "$k #$v", array_keys($onboarding->woo_product_ids ?? []), $onboarding->woo_product_ids ?? [])) ?: '—' }} ·
                CPT-ID {{ $onboarding->pods_post_id ?? '—' }}
            </p>
        @endif
    </div>

    {{-- Bestellemail (nur Sammelbestellfenster) --}}
    @if ($emailBody !== null)
        <div class="card">
            <h2>Bestellemail an die Druckerei <span class="hint">(Vorlage zum Kopieren)</span></h2>
            <p class="lead">Betreff: <strong>{{ $emailSubject }}</strong></p>
            <textarea id="emailbody" rows="18" readonly style="font-family:ui-monospace,monospace;font-size:0.85rem;">{{ $emailBody }}</textarea>
            <button class="btn secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('emailbody').value).then(() => this.textContent = '✓ Kopiert')">In Zwischenablage kopieren</button>
            <a class="btn secondary" style="margin-left:0.5rem;" href="mailto:?subject={{ rawurlencode($emailSubject) }}&body={{ rawurlencode($emailBody) }}">Im Mailprogramm öffnen</a>
        </div>
    @endif

    <script>
        // Bestellfenster/Klassenliste ausblenden, sobald On-Demand gewählt ist
        // (die Felder werden serverseitig ohnehin ignoriert/überschrieben).
        (function () {
            const select = document.getElementById('delivery_type');
            const windowStart = document.getElementById('window_start_field');
            const windowEnd = document.getElementById('window_end_field');
            const classList = document.getElementById('class_list_field');
            const hint = document.getElementById('ondemand_window_hint');
            if (! select) return;

            function sync() {
                const isOndemand = select.value === 'ondemand';
                [windowStart, windowEnd, classList].forEach(el => { if (el) el.style.display = isOndemand ? 'none' : ''; });
                if (hint) hint.style.display = isOndemand ? '' : 'none';
            }
            select.addEventListener('change', sync);
            sync();
        })();

        // "+ Produkt hinzufügen": Vorlagenzeile klonen und mit eindeutigem Schlüssel in die Tabelle einfügen.
        function addProductRow() {
            const template = document.getElementById('new-product-row-template');
            const tbody = document.querySelector('#products-table tbody');
            if (! template || ! tbody) return;

            const key = 'custom_' + Date.now();
            const fragment = template.content.cloneNode(true);
            fragment.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace(/__KEY__/g, key); });
            fragment.querySelectorAll('[id]').forEach(el => { el.id = el.id.replace(/__KEY__/g, key); });
            fragment.querySelectorAll('[onclick]').forEach(el => { el.setAttribute('onclick', el.getAttribute('onclick').replace(/__KEY__/g, key)); });
            tbody.appendChild(fragment);
        }

        // Printify-Blueprint-/Provider-Suche direkt im Konfigurator (Alternative zu SSH/Terminal).
        let printifySearchState = null;
        let printifySearchTimer = null;

        function openPrintifySearch(type, targetInputId, blueprintInputId) {
            printifySearchState = { type: type, targetInputId: targetInputId, blueprintInputId: blueprintInputId };
            const modal = document.getElementById('printify-search-modal');
            const title = document.getElementById('printify-search-title');
            const hint = document.getElementById('printify-search-hint');
            const input = document.getElementById('printify-search-input');
            document.getElementById('printify-search-results').innerHTML = '';
            input.value = '';
            modal.style.display = 'flex';

            if (type === 'blueprint') {
                title.textContent = 'Printify-Blueprint suchen';
                hint.textContent = 'Suchbegriff eingeben (z. B. Modellname oder Marke, mind. 2 Zeichen) — durchsucht den Printify-Produktkatalog live.';
                input.focus();
            } else {
                const blueprintInput = document.getElementById(blueprintInputId);
                const blueprintId = blueprintInput ? blueprintInput.value.trim() : '';
                if (! blueprintId) {
                    modal.style.display = 'none';
                    alert('Bitte zuerst eine Blueprint-ID eintragen (oder über die Blueprint-Suche wählen).');
                    return;
                }
                title.textContent = 'Print-Provider zu Blueprint ' + blueprintId;
                hint.textContent = 'Alle verfügbaren Print-Provider für diese Blueprint-ID — optional per Suchbegriff filtern.';
                input.focus();
                fetchPrintifyProviders(blueprintId, '');
            }
        }

        function closePrintifySearch() {
            document.getElementById('printify-search-modal').style.display = 'none';
            printifySearchState = null;
        }

        document.getElementById('printify-search-modal').addEventListener('click', function (event) {
            if (event.target === this) closePrintifySearch();
        });

        document.getElementById('printify-search-input').addEventListener('input', function () {
            clearTimeout(printifySearchTimer);
            const query = this.value.trim();
            printifySearchTimer = setTimeout(() => {
                if (! printifySearchState) return;
                if (printifySearchState.type === 'blueprint') {
                    fetchPrintifyBlueprints(query);
                } else {
                    const blueprintInput = document.getElementById(printifySearchState.blueprintInputId);
                    fetchPrintifyProviders(blueprintInput.value.trim(), query);
                }
            }, 350);
        });

        function renderPrintifyResults(items, emptyText) {
            const results = document.getElementById('printify-search-results');
            results.innerHTML = '';
            if (! items || items.length === 0) {
                results.innerHTML = '<p class="hint">' + emptyText + '</p>';
                return;
            }
            items.forEach(item => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn secondary';
                btn.style.cssText = 'display:block;width:100%;text-align:left;margin-top:0.4rem;white-space:normal;';
                btn.textContent = item.id + ' — ' + item.title;
                btn.addEventListener('click', () => {
                    document.getElementById(printifySearchState.targetInputId).value = item.id;
                    closePrintifySearch();
                });
                results.appendChild(btn);
            });
        }

        function fetchPrintifyBlueprints(query) {
            if (query.length < 2) {
                renderPrintifyResults([], 'Mindestens 2 Zeichen eingeben.');
                return;
            }
            fetch('{{ route("schools.printify.blueprints") }}?q=' + encodeURIComponent(query))
                .then(r => r.json())
                .then(data => data.error ? renderPrintifyResults([], data.error) : renderPrintifyResults(data.results, 'Keine Treffer.'))
                .catch(() => renderPrintifyResults([], 'Suche fehlgeschlagen — Verbindung zu Printify prüfen.'));
        }

        function fetchPrintifyProviders(blueprintId, query) {
            fetch('{{ route("schools.printify.providers") }}?blueprint_id=' + encodeURIComponent(blueprintId) + '&q=' + encodeURIComponent(query))
                .then(r => r.json())
                .then(data => data.error ? renderPrintifyResults([], data.error) : renderPrintifyResults(data.results, 'Keine Provider gefunden.'))
                .catch(() => renderPrintifyResults([], 'Suche fehlgeschlagen — Verbindung zu Printify prüfen.'));
        }
    </script>
@endsection
