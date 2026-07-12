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
                <div>
                    <label for="window_start">Bestellfenster von</label>
                    <input type="date" id="window_start" name="window_start" value="{{ old('window_start', $onboarding->window_start?->format('Y-m-d')) }}">
                </div>
                <div>
                    <label for="window_end">Bestellfenster bis</label>
                    <input type="date" id="window_end" name="window_end" value="{{ old('window_end', $onboarding->window_end?->format('Y-m-d')) }}">
                </div>
            </div>

            <label for="class_list" style="margin-top:1rem;">Klassenliste <span class="hint">(kommagetrennt — wird zum Attribut „Klasse")</span></label>
            <textarea id="class_list" name="class_list" rows="2">{{ old('class_list', $onboarding->class_list) }}</textarea>

            <h2 style="margin-top:1rem;">Produkte & Preise</h2>
            <div class="tablewrap">
                <table class="data">
                    <thead>
                        <tr><th></th><th>Produkt</th><th>Preis (€)</th><th>Aufpreis Indiv. (€)</th><th>Größen</th><th>Farben</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($onboarding->products ?? [] as $product)
                            @if (! empty($product['unmapped']))
                                <tr><td colspan="6"><span class="alert warn" style="display:block;">⚠ {{ $product['label'] }} — bitte manuell im Shop anlegen.</span></td></tr>
                                @continue
                            @endif
                            <tr>
                                <td><input type="checkbox" name="products[{{ $product['key'] }}][enabled]" value="1" {{ ! empty($product['enabled']) ? 'checked' : '' }}></td>
                                <td>{{ $product['label'] }}</td>
                                <td><input type="text" name="products[{{ $product['key'] }}][base_price]" value="{{ number_format($product['base_price'], 2, ',', '') }}" style="width:90px;margin:0;"></td>
                                <td><input type="text" name="products[{{ $product['key'] }}][indiv_surcharge]" value="{{ number_format($product['indiv_surcharge'], 2, ',', '') }}" style="width:90px;margin:0;"></td>
                                <td><input type="text" name="products[{{ $product['key'] }}][sizes]" value="{{ implode(', ', $product['sizes']) }}" style="width:200px;margin:0;"></td>
                                <td><input type="text" name="products[{{ $product['key'] }}][colors]" value="{{ implode(', ', $product['colors']) }}" style="width:220px;margin:0;"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <label for="notes" style="margin-top:1rem;">Interne Notizen</label>
            <textarea id="notes" name="notes" rows="2">{{ old('notes', $onboarding->notes) }}</textarea>

            <button class="btn" type="submit">Speichern</button>
        </form>
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
@endsection
