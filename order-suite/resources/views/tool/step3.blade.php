@extends('layouts.app')

@section('title', 'Dokumente — Wear Together Order Suite')

@section('content')
    <div class="steps">
        <span class="step done">1 · Hochladen ✓</span>
        <span class="step done">2 · Auftrag & Prüfung ✓</span>
        <span class="step active">3 · Dokumente</span>
    </div>

    @php($stats = $meta['stats'])
    @php($files = $meta['files'])

    <div class="card">
        <h1>{{ $meta['ordername'] }} — Dokumente fertig</h1>

        <div class="stats">
            <div class="stat"><div class="value">{{ $stats['pieces'] }}</div><div class="label">Stück</div></div>
            <div class="stat"><div class="value">{{ $stats['kartons'] }}</div><div class="label">Kartons</div></div>
            <div class="stat"><div class="value">{{ $stats['personalized'] }}</div><div class="label">Personalisierungen</div></div>
            <div class="stat"><div class="value">{{ number_format((float) $stats['commission'], 2, ',', '.') }} €</div><div class="label">Provision</div></div>
        </div>

        <div class="downloads">
            <div class="dl">
                <div class="name">📦 Lieferanten-Report</div>
                <div class="desc">Produktionsgrundlage für den Textil-Lieferanten (Excel).</div>
                <a class="btn" href="{{ route('job.download', [$jobId, $files['supplier']]) }}">Herunterladen</a>
            </div>
            <div class="dl">
                <div class="name">🔍 Interner Report</div>
                <div class="desc">Arbeits- und Prüfdokument inkl. Prüfspalte „⚠ Fehlender Individualisierungstext" (Excel).</div>
                <a class="btn" href="{{ route('job.download', [$jobId, $files['internal']]) }}">Herunterladen</a>
            </div>
            <div class="dl">
                <div class="name">🏫 Kunden-Report</div>
                <div class="desc">Übersicht + Provisionsinformation für die Schule/Organisation (Excel).</div>
                <a class="btn" href="{{ route('job.download', [$jobId, $files['customer']]) }}">Herunterladen</a>
            </div>
            <div class="dl">
                <div class="name">🖨️ Verteil-PDF</div>
                <div class="desc">Druckbare Stückliste zum Abhaken bei der Ausgabe.</div>
                <a class="btn" href="{{ route('job.download', [$jobId, $files['pdf']]) }}">Herunterladen</a>
            </div>
        </div>

        <div style="margin-top:1rem;">
            <a class="btn secondary" href="{{ route('job.zip', $jobId) }}">⬇ Alle 4 Dokumente als ZIP</a>
            <a class="btn secondary" href="{{ route('tool.index') }}" style="margin-left:0.5rem;">Neuen Auftrag starten</a>
        </div>
        <p class="hint" style="margin-top:0.75rem;">Die Dateien werden nach {{ config('ordersuite.retention_hours') }} Stunden automatisch gelöscht.</p>
    </div>

    <div class="card">
        <h2>Vorschau</h2>
        <div class="tabs">
            <button class="tab active" data-target="preview-orders" type="button">Bestellliste ({{ $stats['pieces'] }})</button>
            <button class="tab" data-target="preview-pivot" type="button">Übersicht</button>
        </div>
        <div class="searchbox">
            <input type="text" id="tablesearch" placeholder="Suchen (Name, Klasse, Produkt …)" aria-label="In Tabelle suchen">
        </div>

        <div class="tablewrap" id="preview-orders">
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($preview['orders_columns'] as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($preview['orders_rows'] as $row)
                        <tr>
                            @foreach ($preview['orders_columns'] as $column)
                                <td>{{ $row[$column] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="tablewrap" id="preview-pivot" style="display:none;">
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($preview['pivot_columns'] as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($preview['pivot_rows'] as $row)
                        <tr>
                            @foreach ($preview['pivot_columns'] as $column)
                                <td>{{ $row[$column] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <script>
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('preview-orders').style.display = tab.dataset.target === 'preview-orders' ? '' : 'none';
                document.getElementById('preview-pivot').style.display = tab.dataset.target === 'preview-pivot' ? '' : 'none';
            });
        });
        document.getElementById('tablesearch').addEventListener('input', function () {
            const term = this.value.toLowerCase();
            document.querySelectorAll('.tablewrap tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    </script>
@endsection
