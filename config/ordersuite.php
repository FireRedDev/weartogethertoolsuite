<?php

/*
 * Fachliche Defaults der Order Suite.
 * Die Werte entsprechen EXAKT dem Legacy-Skript wear_together_toolsuite.py @ cff1227
 * (siehe AGENTIC_INTENT_SPEC.md Kapitel 4). Änderungen hier ändern den Standard-Output!
 */
return [

    // Zugangsschutz: leer = kein Login (nur für lokales Testen empfohlen)
    'password' => env('TOOL_PASSWORD', ''),

    // Aufbewahrung von Uploads/Reports in Stunden
    'retention_hours' => (int) env('ORDER_RETENTION_HOURS', 24),

    // Geordnete Größenliste (Legacy: pd.CategoricalDtype, ordered=True)
    'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],

    // Stück pro Karton
    'karton_size' => 20,

    // Anzahl Zeichen des WooCommerce-Präfixes im Individualisierungstext (str[50:])
    'indiv_prefix_length' => 50,

    // Teilstring-Ersetzungen Produktname -> Lieferanten-Artikelnummer (Reihenfolge relevant)
    'supplier_map' => [
        'Schulpullover' => 'JH001',
        'Schulshirt' => 'B&C #E150',
        'Schulzoodie' => 'JH050',
        'Schuljacke' => 'JH043',
        'Schulsweater' => 'JH030',
        'Schulpolo' => 'B&C ID.001',
        'Sportshirt' => 'JC001',
        'Match-Polo' => 'JC021',
    ],

    // Provisionsstaffel: 0-basierter Stück-Index => Betrag pro Stück
    'commission' => [
        'tiers' => [
            ['from' => 50, 'to' => 99, 'amount' => 0.5],
            ['from' => 100, 'to' => 199, 'amount' => 1.0],
            ['from' => 200, 'to' => 299, 'amount' => 1.25],
            ['from' => 300, 'to' => 499, 'amount' => 1.5],
            ['from' => 500, 'to' => null, 'amount' => 2.0],
        ],
        'minimum' => 20.0,
        'minimum_from_pieces' => 50,
    ],

    // Spalten, die im PDF nie erscheinen (falls vorhanden)
    'pdf_drop_columns' => [
        '⚠ Fehlender Individualisierungstext',
        'Order Total Amount without Tax',
        'Order Total Fee',
        'Order Line (w/o tax)',
        'Order Line Subtotal',
        'paypal fee',
        'Stripe fee',
    ],

    // PDF-Paginierung: max. Basiszeilen pro Seite (Legacy: ceil(n/40))
    'pdf_rows_basis' => 40,

    /*
     * Weg 1: Direkter Bestell-Import über die WooCommerce REST API.
     * Repliziert den Export des Plugins "Advanced Order Export For WooCommerce"
     * mit den produktiv genutzten Einstellungen (Statusfilter, Kategorie je
     * Schule, Sortierung Order-ID absteigend, eine Zeile pro Produktposition).
     */
    'woocommerce' => [
        // z. B. https://www.wear-together.at — ohne /wp-json
        'store_url' => env('WC_STORE_URL', ''),
        // Read-only API-Schlüssel (WooCommerce → Einstellungen → Erweitert → REST-API)
        'consumer_key' => env('WC_CONSUMER_KEY', ''),
        'consumer_secret' => env('WC_CONSUMER_SECRET', ''),
        'timeout_seconds' => 30,
        'per_page' => 100,

        // Bestellstatus wie im Plugin vorausgewählt
        'default_statuses' => ['processing', 'on-hold', 'completed'],
        'statuses' => [
            'processing' => 'In Bearbeitung',
            'on-hold' => 'In Wartestellung',
            'completed' => 'Abgeschlossen',
            'pending' => 'Zahlung ausstehend',
            'cancelled' => 'Storniert',
            'refunded' => 'Rückerstattet',
            'failed' => 'Fehlgeschlagen',
        ],

        // Zuordnung Export-Spalte -> mögliche Meta-Keys der Bestellposition
        // (wie die [P]-Felder pa_size/pa_color/klasse/pa_individualisierung im Plugin).
        // Es wird der erste Treffer verwendet; Attribut-Labels (display_key)
        // werden als Fallback ebenfalls verglichen.
        'attribute_meta_keys' => [
            'Größe' => ['pa_size', 'Größe'],
            'Farbe' => ['pa_color', 'Farbe'],
            'Klasse' => ['klasse', 'pa_klasse', 'Klasse'],
            'Individualisierung' => ['pa_individualisierung', 'Individualisierung'],
        ],

        // Positions-Metas, deren Label diesen Text enthält, bilden die Spalte
        // "Input Fields" (Format wie das Plugin: "\n{Label}: {Wert}") und werden
        // aus "Product Variation" ausgeschlossen.
        'input_fields_label_contains' => 'Individualisierungstext',

        // Bestell-Meta für die Spalte "Individualisierungstext(zählt nur wenn
        // Individualisierung Ja)" (Checkout-Feld _additional_wooccm4).
        'order_meta_indiv_text' => '_additional_wooccm4',
    ],
];
