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

    {{-- Schullogo & Druck --}}
    {{-- Die Upload-Formulare müssen eigenständig sein (HTML erlaubt keine
         verschachtelten Formulare); Druck-Häkchen und Platzierung gehören
         dagegen zum Konfigurator und werden über form="configurator-form"
         mitgespeichert. --}}
    <div class="card">
        <h2>Schullogo &amp; Druck</h2>
        <p class="lead">Das Logo ist im Formular kein Pflichtfeld — hier lässt es sich nachträglich hochladen und austauschen.
            Der Upload der Kund:innen gilt automatisch für beide Drucke; pro Druck kann aber eine eigene Datei hinterlegt werden.
            Druck-Häkchen, Position und Größe werden mit <strong>Speichern</strong> im Konfigurator übernommen.</p>

        {{-- Marker: ohne ihn wäre „Häkchen weg" nicht von „Feld nicht mitgeschickt" zu unterscheiden --}}
        <input type="hidden" form="configurator-form" name="print_slots_submitted" value="1">

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1rem;">
            @foreach (\App\Models\SchoolOnboarding::PRINT_SLOTS as $slot => $slotLabel)
                @php($logoUrl = $onboarding->logoUrl($slot))
                @php($previewUrl = $onboarding->hasUploadedLogo($slot) ? route('schools.logo.show', [$onboarding, $slot]) : $logoUrl)
                <div style="border:1px solid var(--line);border-radius:10px;padding:0.9rem;">
                    <label style="font-weight:600;display:flex;gap:0.5rem;align-items:center;">
                        <input type="checkbox" form="configurator-form" name="print_{{ $slot }}" value="1" {{ $onboarding->prints($slot) ? 'checked' : '' }}>
                        {{ $slotLabel }}
                    </label>

                    <div style="display:flex;gap:0.75rem;align-items:flex-start;margin-top:0.6rem;">
                        <div style="width:96px;height:96px;flex:none;border:1px solid var(--line);border-radius:8px;background:#f7f8fa url('data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'16\' height=\'16\'><rect width=\'8\' height=\'8\' fill=\'%23eceff3\'/><rect x=\'8\' y=\'8\' width=\'8\' height=\'8\' fill=\'%23eceff3\'/></svg>');display:flex;align-items:center;justify-content:center;overflow:hidden;">
                            @if ($previewUrl)
                                <img src="{{ $previewUrl }}" alt="Logo {{ $slotLabel }}" style="max-width:100%;max-height:100%;object-fit:contain;">
                            @else
                                <span class="hint" style="text-align:center;font-size:0.7rem;">kein Logo</span>
                            @endif
                        </div>
                        <div style="min-width:0;">
                            @if ($previewUrl)
                                <p class="hint" style="margin:0;word-break:break-all;">
                                    {{ $onboarding->hasUploadedLogo($slot) ? 'Im Tool hochgeladen' : 'Aus dem Formular übernommen' }}
                                </p>
                                <a class="btn secondary" style="padding:0.25rem 0.6rem;font-size:0.8rem;margin-top:0.35rem;"
                                   href="{{ $onboarding->hasUploadedLogo($slot) ? route('schools.logo.show', [$onboarding, $slot, 'download' => 1]) : $logoUrl }}"
                                   target="_blank" rel="noopener" download>Herunterladen</a>
                                @if ($onboarding->hasUploadedLogo($slot))
                                    <form method="post" action="{{ route('schools.logo.reset', [$onboarding, $slot]) }}" style="display:inline;"
                                          onsubmit="return confirm('Hochgeladenes Logo entfernen? Danach gilt wieder die Datei aus dem Formular.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn secondary" type="submit" style="padding:0.25rem 0.6rem;font-size:0.8rem;color:var(--error);">Entfernen</button>
                                    </form>
                                @endif
                            @else
                                <p class="hint" style="margin:0;">Für diesen Druck ist noch keine Datei hinterlegt.</p>
                            @endif
                        </div>
                    </div>

                    <form method="post" action="{{ route('schools.logo.upload', [$onboarding, $slot]) }}" enctype="multipart/form-data" style="margin-top:0.6rem;">
                        @csrf
                        <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp" required style="font-size:0.82rem;">
                        <button class="btn secondary" type="submit" style="padding:0.3rem 0.7rem;font-size:0.82rem;">
                            {{ $onboarding->hasUploadedLogo($slot) ? 'Austauschen' : 'Hochladen' }}
                        </button>
                    </form>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-top:0.7rem;">
                        <div>
                            <label for="logo_{{ $slot }}_position">Position</label>
                            <select form="configurator-form" id="logo_{{ $slot }}_position" name="logo_{{ $slot }}_position"
                                    style="width:100%;padding:0.5rem;border:1px solid var(--line);border-radius:8px;font:inherit;background:#fff;">
                                @foreach (config('schoolshop.logo_positions') as $positionKey => $position)
                                    <option value="{{ $positionKey }}" {{ $onboarding->logoPositionKey($slot) === $positionKey ? 'selected' : '' }}>{{ $position['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="logo_{{ $slot }}_size">Größe</label>
                            <select form="configurator-form" id="logo_{{ $slot }}_size" name="logo_{{ $slot }}_size"
                                    style="width:100%;padding:0.5rem;border:1px solid var(--line);border-radius:8px;font:inherit;background:#fff;">
                                @foreach (config('schoolshop.logo_sizes') as $sizeKey => $size)
                                    <option value="{{ $sizeKey }}" {{ $onboarding->logoSizeKey($slot) === $sizeKey ? 'selected' : '' }}>{{ $size['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="hint" style="margin-top:0.75rem;">Erlaubt sind PNG, JPG und WebP bis 5 MB (kein SVG — Printify und die Mockup-Erzeugung brauchen ein Pixelformat).
            Hochgeladene Logos werden zusätzlich in die WordPress-Mediathek gelegt, weil Printify und Dynamic Mockups die Datei selbst herunterladen müssen.</p>
    </div>

    {{-- Konfigurator --}}
    <div class="card">
        <h2>Konfigurator</h2>
        <form method="post" action="{{ route('schools.update', $onboarding) }}" id="configurator-form">
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
            @php($hasNonEuProvider = collect($printifyEconomics ?? [])->contains(fn ($i) => $i['country'] !== null && ! $i['is_eu']))
            <h2 style="margin-top:1rem;">Produkte & Preise</h2>
            @if ($isOndemand)
                <p class="hint">On-Demand: Blueprint-ID und Print-Provider-ID pro Produkt eintragen — mit dem 🔍-Button direkt hier im Konfigurator suchen (siehe auch Spaltenkopf-Hinweis ⓘ).
                    Die Spalten Einkauf, Versand und Marge kommen live aus dem Printify-Katalog (24 h gecacht) und beziehen sich auf genau die Varianten, die auch angelegt werden.
                    Der Verkaufspreis wird beim Anlegen automatisch gegen Produktionskosten + Versand + {{ (int) round(config('schoolshop.printify.min_margin') * 100) }}% Marge geprüft.
                    Angelegt werden nur Varianten in den oben gewählten Farben und Größen — sonst greift das Printify-Limit von {{ config('schoolshop.printify.max_variants') }} Varianten pro Produkt,
                    und die Vorschaubilder zeigen Farben, die die Schule gar nicht bestellt.</p>
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
                                <th>Region</th>
                                <th title="Einkaufspreis bei Printify (Produktionskosten je Stück, ohne Versand). Bei mehreren Größen/Farben die Spanne — für die Margenprüfung zählt der höchste Wert.">Einkauf (€) ⓘ</th>
                                <th title="Versandkosten des ersten Artikels nach Österreich, laut Versandprofil des Print-Providers.">Versand (€) ⓘ</th>
                                <th title="Marge = (Verkaufspreis − Einkauf − Versand) ÷ (Einkauf + Versand). Rot, wenn sie unter der Mindestmarge liegt — dann verweigert die Shop-Anlage das Produkt.">Marge ⓘ</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($onboarding->products ?? [] as $product)
                            @if (! empty($product['unmapped']))
                                <tr><td colspan="{{ $isOndemand ? 12 : 6 }}"><span class="alert warn" style="display:block;">⚠ {{ $product['label'] }} — bitte manuell im Shop anlegen.</span></td></tr>
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
                                @php($info = $printifyEconomics[$product['key']] ?? null)
                                    <td style="white-space:nowrap;">
                                        @if ($info === null)
                                            <span class="hint">—</span>
                                        @else
                                            <span title="Print-Provider: {{ $info['provider_title'] }}">
                                                {{ $info['country'] ? ($info['is_eu'] ? '🇪🇺 '.$info['country'] : '🌍 '.$info['country']) : '?' }}
                                            </span>
                                            @if ($info['country'] !== null && ! $info['is_eu'])
                                                <br><span class="hint" style="color:var(--error);">außerhalb EU</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td style="white-space:nowrap;">
                                        @if ($info === null || $info['cost_max_eur'] === null)
                                            <span class="hint">—</span>
                                        @else
                                            <span title="Gilt für die {{ $info['variant_selected'] ?: $info['variant_total'] }} tatsächlich angelegten Varianten (von {{ $info['variant_total'] }} im Katalog).">
                                                @if ($info['cost_min_eur'] !== null && $info['cost_min_eur'] < $info['cost_max_eur'])
                                                    {{ number_format($info['cost_min_eur'], 2, ',', '') }}–{{ number_format($info['cost_max_eur'], 2, ',', '') }}
                                                @else
                                                    {{ number_format($info['cost_max_eur'], 2, ',', '') }}
                                                @endif
                                            </span>
                                            @if ($info['missing_colors'] !== [])
                                                <br><span class="hint" style="color:var(--error);" title="Verfügbar bei diesem Provider: {{ implode(', ', $info['available_colors']) ?: '—' }}">Farbe fehlt: {{ implode(', ', $info['missing_colors']) }}</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td style="white-space:nowrap;">
                                        @if ($info === null || $info['shipping_eur'] === null)
                                            <span class="hint">—</span>
                                        @else
                                            @php($shipTo = $info['shipping_is_row']
                                                ? 'alle übrigen Länder (Sammelprofil „Rest der Welt")'
                                                : (count($info['shipping_countries']) > 12
                                                    ? implode(', ', array_slice($info['shipping_countries'], 0, 12)).' … ('.count($info['shipping_countries']).' Länder)'
                                                    : implode(', ', $info['shipping_countries'])))
                                            <span title="Versand von {{ $info['country'] ?? 'unbekannt' }} nach {{ $shipTo ?: 'unbekannt' }}. Angegeben ist der erste Artikel einer Sendung.">
                                                {{ number_format($info['shipping_eur'], 2, ',', '') }}
                                            </span>
                                            @if ($info['shipping_is_fallback'])
                                                <br><span class="hint" style="color:var(--error);">kein Profil für AT — Ersatzwert</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td style="white-space:nowrap;">
                                        @if ($info === null || $info['margin_pct'] === null)
                                            <span class="hint">—</span>
                                        @else
                                            <strong style="color:{{ $info['margin_ok'] ? 'var(--ok, #16803c)' : 'var(--error)' }};">{{ number_format($info['margin_pct'], 1, ',', '') }} %</strong>
                                            @unless ($info['margin_ok'])
                                                <br><span class="hint" style="color:var(--error);">min. {{ number_format($info['min_price_eur'], 2, ',', '') }} €</span>
                                            @endunless
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn secondary" style="margin-top:0.6rem;" onclick="addProductRow()">+ Produkt hinzufügen</button>

            <h2 style="margin-top:1.25rem;">Produktfotos (Mockups) <span class="hint">optional</span></h2>
            <label style="font-weight:400;display:flex;gap:0.5rem;align-items:flex-start;">
                <input type="checkbox" name="mockups_enabled" value="1" style="margin-top:0.25rem;" {{ old('mockups_enabled', $onboarding->mockups_enabled) ? 'checked' : '' }}>
                <span>Beim Anlegen automatisch Produktfotos erzeugen und als Produktbild + Galerie setzen —
                    1–2 Model-Fotos (bevorzugt eine Frau und ein Mann, wechselnd je Schule) plus Detailansichten in den
                    gewählten Farben, jeweils mit dem Schullogo an der gewählten Position.</span>
            </label>
            <p class="hint">Logo-Position und -Größe kommen aus dem <strong>Frontprint</strong> (Bereich „Schullogo &amp; Druck" oben).
                Gilt für Sammelbestellfenster-Produkte (On-Demand: Printify erzeugt eigene Produktbilder).
                Vorlagen je Produkt werden einmalig in <code>config/schoolshop.php</code> (<code>mockups.templates</code>) hinterlegt —
                nachschlagen mit <code>php artisan mockups:check</code>. Produkte ohne Vorlagen werden übersprungen.</p>

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
                <td class="hint" colspan="4">Kosten/Marge nach dem Speichern sichtbar</td>
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

    {{-- Präsentationsblatt --}}
    @php($sheetSlots = ['back' => ['Mockup Rückenansicht', 'oben rechts — Person von hinten mit Backprint'], 'front' => ['Mockup Vorderansicht', 'unten links — Person mit Frontprint'], 'detail' => ['Detailaufnahme (optional)', 'für den Kreis; ohne Upload wird die Vorderansicht herangezoomt']])
    <div class="card" id="praesentationsblatt">
        <h2>Präsentationsblatt <span class="hint">A4, wie die bisherige InDesign-Vorlage</span></h2>
        <p class="lead">Schulname, Produkte, Farben, Bestellzeitraum, QR-Code und Adresse kommen automatisch aus diesem
            Antrag — hochzuladen sind nur die beiden Mockups.</p>

        @if ($sheetMissing !== [])
            <div class="alert warn">⚠ Noch nicht erzeugbar. Es fehlt: {{ implode(', ', $sheetMissing) }}.</div>
        @endif

        {{-- Upload-Formulare müssen eigenständig sein; die Einstellfelder hängen
             per form="sheet-form" am Speichern-Formular weiter unten. --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;">
            @foreach ($sheetSlots as $slot => [$label, $note])
                <div style="border:1px solid var(--line);border-radius:10px;padding:0.9rem;">
                    <strong>{{ $label }}</strong>
                    <p class="hint" style="margin:0.15rem 0 0.5rem;">{{ $note }}</p>

                    <div style="display:flex;gap:0.75rem;align-items:flex-start;">
                        <div style="width:110px;height:110px;flex:none;border:1px solid var(--line);border-radius:8px;background:#f7f8fa;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                            @if ($onboarding->{"sheet_{$slot}_path"})
                                <img src="{{ route('sheet.image', [$onboarding, $slot]) }}" alt="{{ $label }}" style="max-width:100%;max-height:100%;object-fit:contain;">
                            @else
                                <span class="hint" style="font-size:0.7rem;">noch kein Bild</span>
                            @endif
                        </div>
                        <div style="min-width:0;flex:1;">
                            <form method="post" action="{{ route('sheet.upload', [$onboarding, $slot]) }}" enctype="multipart/form-data">
                                @csrf
                                <input type="file" name="mockup" accept=".png,.jpg,.jpeg,.webp" required style="font-size:0.8rem;max-width:100%;">
                                <button class="btn secondary" type="submit" style="padding:0.3rem 0.7rem;font-size:0.8rem;margin-top:0.3rem;">
                                    {{ $onboarding->{"sheet_{$slot}_path"} ? 'Austauschen' : 'Hochladen' }}
                                </button>
                            </form>
                            @if ($onboarding->{"sheet_{$slot}_path"})
                                <form method="post" action="{{ route('sheet.delete', [$onboarding, $slot]) }}" onsubmit="return confirm('Bild entfernen?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn secondary" type="submit" style="padding:0.25rem 0.6rem;font-size:0.78rem;color:var(--error);">Entfernen</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    @php($sourceSlot = $slot === 'detail' && ! $onboarding->sheet_detail_path ? 'front' : $slot)
                    @if ($onboarding->{"sheet_{$sourceSlot}_path"})
                        <p class="hint" style="margin:0.7rem 0 0.3rem;">
                            Bildausschnitt — <strong>ins linke Bild klicken</strong>, um den Mittelpunkt zu setzen.
                            @if ($slot === 'detail' && ! $onboarding->sheet_detail_path)
                                Quelle ist die Vorderansicht; ziel auf den Brustdruck.
                            @endif
                        </p>
                        <div class="cropper" data-slot="{{ $slot }}"
                             data-src="{{ route('sheet.image', [$onboarding, $sourceSlot]) }}"
                             data-aspect="{{ $sheetWindows[$slot]['width'] / $sheetWindows[$slot]['height'] }}"
                             data-round="{{ $slot === 'detail' ? '1' : '0' }}"
                             style="display:flex;gap:0.6rem;align-items:flex-start;">
                            <div class="crop-pick" style="position:relative;flex:1;min-width:0;cursor:crosshair;border:1px solid var(--line);border-radius:6px;overflow:hidden;">
                                <img alt="Quellbild" style="display:block;width:100%;">
                                <span class="crop-marker" style="position:absolute;width:14px;height:14px;margin:-7px 0 0 -7px;border:2px solid #fff;border-radius:50%;box-shadow:0 0 0 1.5px var(--error);pointer-events:none;"></span>
                            </div>
                            <div style="flex:none;">
                                <div class="crop-preview" style="width:104px;background-repeat:no-repeat;border:1px solid var(--line);"></div>
                                <p class="hint" style="margin:0.2rem 0 0;text-align:center;font-size:0.7rem;">so wird's gedruckt</p>
                            </div>
                        </div>
                        <label for="sheet_{{ $slot }}_zoom" style="font-size:0.78rem;margin-top:0.4rem;">Zoom</label>
                        <input class="crop-zoom" form="sheet-form" type="range" step="0.05"
                               min="1" max="{{ $slot === 'detail' ? 12 : 4 }}"
                               id="sheet_{{ $slot }}_zoom" name="sheet_{{ $slot }}_zoom"
                               value="{{ $onboarding->{"sheet_{$slot}_zoom"} }}" style="width:100%;margin:0;">
                    @endif

                    {{-- Die tatsächlich gespeicherten Werte; werden vom Wähler oben gesetzt --}}
                    @foreach (['focus_x', 'focus_y'] as $field)
                        <input class="crop-{{ $field }}" form="sheet-form" type="hidden"
                               name="sheet_{{ $slot }}_{{ $field }}" value="{{ $onboarding->{"sheet_{$slot}_{$field}"} }}">
                    @endforeach
                </div>
            @endforeach
        </div>

        <script>
            // Ausschnitt-Wähler: bildet exakt nach, was SheetImages::coverCrop()
            // serverseitig rechnet — Klickpunkt = Mittelpunkt des Ausschnitts,
            // Zoom vergrößert ihn. Die Vorschau rechts zeigt das Ergebnis.
            document.querySelectorAll('.cropper').forEach(function (root) {
                const img = root.querySelector('.crop-pick img');
                const marker = root.querySelector('.crop-marker');
                const preview = root.querySelector('.crop-preview');
                const zoom = root.parentElement.querySelector('.crop-zoom');
                const fx = root.parentElement.querySelector('.crop-focus_x');
                const fy = root.parentElement.querySelector('.crop-focus_y');
                const aspect = parseFloat(root.dataset.aspect);
                const round = root.dataset.round === '1';

                preview.style.height = Math.round(104 / aspect) + 'px';
                preview.style.borderRadius = round ? '50%' : '4px';
                img.src = root.dataset.src;

                function draw() {
                    const x = parseFloat(fx.value), y = parseFloat(fy.value), z = parseFloat(zoom.value);
                    marker.style.left = (x * 100) + '%';
                    marker.style.top = (y * 100) + '%';

                    const sw = img.naturalWidth, sh = img.naturalHeight;
                    if (! sw) return;
                    const pw = preview.clientWidth, ph = preview.clientHeight;
                    const scale = Math.max(pw / sw, ph / sh) * z;
                    const w = sw * scale, h = sh * scale;
                    // gleiche Begrenzung wie serverseitig: der Ausschnitt bleibt im Bild
                    const left = Math.min(0, Math.max(pw - w, pw / 2 - x * w));
                    const top = Math.min(0, Math.max(ph - h, ph / 2 - y * h));
                    preview.style.backgroundImage = 'url("' + img.src + '")';
                    preview.style.backgroundSize = w + 'px ' + h + 'px';
                    preview.style.backgroundPosition = left + 'px ' + top + 'px';
                }

                root.querySelector('.crop-pick').addEventListener('click', function (event) {
                    const box = this.getBoundingClientRect();
                    fx.value = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width)).toFixed(3);
                    fy.value = Math.min(1, Math.max(0, (event.clientY - box.top) / box.height)).toFixed(3);
                    draw();
                });
                zoom.addEventListener('input', draw);
                img.complete ? draw() : img.addEventListener('load', draw);
            });
        </script>

        <form method="post" action="{{ route('sheet.update', $onboarding) }}" id="sheet-form" style="margin-top:1rem;">
            @csrf
            @method('PUT')

            <h3 style="font-size:1rem;margin-bottom:0.4rem;">Produktzeilen</h3>
            <p class="hint" style="margin-top:0;">Vorbelegt aus dem Konfigurator (maximal {{ config('presentation_sheet.products.max_products') }} Zeilen — mehr passt neben dem Foto nicht).
                Die Zeile „1 Produkt = 1 Baum" kommt automatisch dazu.</p>
            <div class="tablewrap">
                <table class="data">
                    <thead><tr><th>Bezeichnung</th><th>Untertitel</th><th>Icon</th></tr></thead>
                    <tbody>
                        @foreach ($sheetRows as $i => $row)
                            <tr>
                                <td><input form="sheet-form" type="text" name="rows[{{ $i }}][name]" value="{{ $row['name'] }}" style="margin:0;width:220px;"></td>
                                <td><input form="sheet-form" type="text" name="rows[{{ $i }}][sub]" value="{{ $row['sub'] }}" style="margin:0;width:240px;"></td>
                                <td>
                                    <select form="sheet-form" name="rows[{{ $i }}][icon]" style="margin:0;padding:0.4rem;border:1px solid var(--line);border-radius:8px;font:inherit;background:#fff;">
                                        <option value="">— kein Icon —</option>
                                        @foreach ($sheetIcons as $iconName)
                                            <option value="{{ $iconName }}" {{ $row['iconName'] === $iconName ? 'selected' : '' }}>{{ $iconName }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin-top:1rem;">
                <div>
                    <label for="sheet_first_name">Vorname im Kreis <span class="hint">(„Print your name!")</span></label>
                    <input type="text" id="sheet_first_name" name="sheet_first_name" value="{{ $onboarding->sheet_first_name }}" placeholder="z. B. Julian">
                </div>
                <div>
                    <label for="sheet_shop_url">Adresse der Bestellseite <span class="hint">(leer = automatisch)</span></label>
                    <input type="url" id="sheet_shop_url" name="sheet_shop_url" value="{{ $onboarding->sheet_shop_url }}" placeholder="{{ $sheetShopUrl }}">
                </div>
            </div>

            <button class="btn" type="submit">Speichern</button>
        </form>

        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.75rem;align-items:center;">
            <form method="post" action="{{ route('sheet.reset-rows', $onboarding) }}">
                @csrf
                <button class="btn secondary" type="submit">Produktzeilen aus dem Konfigurator übernehmen</button>
            </form>
            @if ($sheetMissing === [])
                <a class="btn secondary" href="{{ route('sheet.preview', $onboarding) }}" target="_blank" rel="noopener">Vorschau öffnen</a>
                <a class="btn" href="{{ route('sheet.pdf', $onboarding) }}">PDF herunterladen</a>
            @endif
        </div>
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
