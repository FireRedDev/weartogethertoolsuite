<?php

/*
 * Modul "Schul-Onboarding": Produktkatalog und Einstellungen.
 * Der Katalog entspricht den Excel-Mastern parents.xltx/childs.xltx
 * (Musterschule-Vorlagen) — Preise/Farben/Größen sind pro Schule im
 * Konfigurator anpassbar, diese Werte sind nur die Startwerte.
 */

$pifTitle = "Individualisierungstext \n(falls \"Ja\" ausgewählt)";

$beschreibung = fn (string $pdfSlug, array $eigenschaften): string => implode("\n", [
    '<strong><a href="https://wear-together.at/wp-content/uploads/2023/02/Produkte-mit-Tabelle-202223_'.$pdfSlug.'.pdf">Link zu den Größeninformationen/Abmessungen</a></strong>',
    '\n',
    'Pro verkauftem Produkt wird ein Baum gepflanzt.',
    '\n \n',
    ...array_map(fn ($e) => '– '.$e.' \n', $eigenschaften),
    '\n',
    '\n',
    'Aufpreis für die Individualisierung: 7,99€',
]);

return [

    // FluentForms-Webhook: Secret ist Teil der URL (/webhooks/fluentforms/{secret})
    'webhook_secret' => env('FLUENTFORMS_WEBHOOK_SECRET', ''),

    // WordPress REST (wp/v2) für Medien + Pods-CPT "schule": Application Password
    'wordpress' => [
        'user' => env('WP_APP_USER', ''),
        'password' => env('WP_APP_PASSWORD', ''),
        'schule_post_type_rest_base' => 'schule',
    ],

    // WooCommerce Schreibzugriff (separater Read/Write-Schlüssel!)
    'woocommerce_write' => [
        'consumer_key' => env('WC_RW_CONSUMER_KEY', ''),
        'consumer_secret' => env('WC_RW_CONSUMER_SECRET', ''),
    ],

    'printify' => [
        'api_token' => env('PRINTIFY_API_TOKEN', ''),
        'shop_id' => env('PRINTIFY_SHOP_ID', ''),
        // Verkaufspreis muss mindestens (Produktionskosten + Versand) * (1 + Marge) sein
        'min_margin' => 0.10,
    ],

    // Übergeordnete Produktkategorie im Shop
    'parent_category_name' => 'Schulen',

    // Versandklassen-Slugs
    'shipping_class_ondemand' => env('SHIPPING_CLASS_ONDEMAND', 'on-demand'),

    // Bestellfenster-Dauer in Tagen (Ende = Start + X), im Konfigurator änderbar
    'default_window_days' => 25,

    // Standard-Bestellfenster-Status beim Anlegen des CPT "schule"
    'pods' => [
        'bestellfenster_offen_default' => 'NEIN',
    ],

    // Mapping Formular-Produktname -> Katalog-Key
    'form_product_map' => [
        'Hoodie' => 'schulpullover',
        'T-Shirt' => 'schulshirt',
        'Zoodie' => 'schulzoodie',
        'College-Jacket' => 'schuljacke',
        'Sweater' => 'schulsweater',
        'Polo-Shirt (nur bei Sammelbestellungen)' => 'schulpolo',
        'Sportshirt' => 'sportshirt',
        'Match-Polo' => 'matchpolo',
        'Umhängetasche' => 'schultasche',
        'Kinder T-Shirt' => 'schulshirt_kids',
        'Kinder Hoodie' => 'schulpullover_kids',
    ],

    // Produkt-Eingabefeld (Plugin "Product Input Fields for WooCommerce"),
    // identisch für alle Produkte — Grundlage des Individualisierungstexts.
    'pif_meta' => [
        '_alg_wc_pif_enabled_local_1' => 'yes',
        '_alg_wc_pif_type_local_1' => 'text',
        '_alg_wc_pif_required_local_1' => 'no',
        '_alg_wc_pif_title_local_1' => $pifTitle,
        '_alg_wc_pif_placeholder_local_1' => 'z.B. dein Name (keine Icons/Emojis)',
        '_alg_wc_pif_required_message_local_1' => 'Field "%title%" is required!',
        '_alg_wc_pif_input_restrictions_maxlength_local_1' => '40',
        '_alg_wc_pif_input_restrictions_pattern_local_1' => '^[a-zA-Z0-9ßäöüÄÖÜ :)(_.-]*$',
        '_alg_wc_pif_local_total_number' => '1',
    ],

    /*
     * Produktkatalog (Startwerte je Produkt; alles im Konfigurator änderbar).
     * supplier_code = Artikelmapping für die Bestellemail (wie Modul 1).
     */
    'catalog' => [
        'schulpullover' => [
            'label' => 'Schulpullover (Hoodie)',
            'name_suffix' => 'Schulpullover',
            'base_price' => 39.99,
            'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
            'default_size' => 'M',
            'supplier_code' => 'JH001',
            'description' => $beschreibung('01_Hoody', [
                'Zertifiziert durch WRAP', 'Passform Unisex', 'Größen XS-XXXL',
                '80% Baumwolle, 20% Polyester', 'Mit Kapuze', 'Ösen aus Metall',
                'Gleichfarbige, runde Kordel', 'Knoten an Kordelenden', 'Känguru Tasche vorne',
            ]),
        ],
        'schulzoodie' => [
            'label' => 'Schulzoodie (Zip-Hoodie)',
            'name_suffix' => 'Schulzoodie',
            'base_price' => 44.99,
            'sizes' => ['S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
            'default_size' => 'M',
            'supplier_code' => 'JH050',
            'description' => $beschreibung('02_Zoodie', [
                'Zertifiziert durch WRAP', 'Passform Unisex', 'Größen S-XXXL',
                '80% Baumwolle, 20% Polyester', 'Mit Kapuze und Reißverschluss',
            ]),
        ],
        'schuljacke' => [
            'label' => 'Schuljacke (College-Jacket)',
            'name_suffix' => 'Schuljacke',
            'base_price' => 49.99,
            'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
            'default_size' => 'M',
            'supplier_code' => 'JH043',
            'description' => $beschreibung('03_Jacke', [
                'Zertifiziert durch WRAP', 'Passform Unisex', 'Größen XS-XXL',
                '80% Baumwolle, 20% Polyester', 'Druckknöpfe',
            ]),
        ],
        'schulsweater' => [
            'label' => 'Schulsweater',
            'name_suffix' => 'Schulsweater',
            'base_price' => 37.99,
            'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
            'default_size' => 'M',
            'supplier_code' => 'JH030',
            'description' => $beschreibung('04_Sweater', [
                'Zertifiziert durch WRAP', 'Passform Unisex', 'Größen XS-XXL',
                '80% Baumwolle, 20% Polyester', 'Sehr angenehmer Rundhalsausschnitt',
            ]),
        ],
        'schulshirt' => [
            'label' => 'Schulshirt (T-Shirt)',
            'name_suffix' => 'Schulshirt',
            'base_price' => 24.99,
            'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
            'default_size' => 'M',
            'supplier_code' => 'B&C #E150',
            'description' => $beschreibung('05_Tshirt', [
                'Zertifiziert durch Ökotex Standard 100', 'Passform Unisex', 'Größen XS-XXXL',
                '100% Baumwolle', 'Sehr angenehmer Rundhalsausschnitt',
            ]),
        ],
        'schulpolo' => [
            'label' => 'Schulpolo',
            'name_suffix' => 'Schulpolo',
            'base_price' => 29.99,
            'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
            'default_size' => 'M',
            'supplier_code' => 'B&C ID.001',
            'description' => $beschreibung('06_Polo', [
                'Zertifiziert durch Ökotex Standard 100', 'Passform Unisex', 'Größen XS-XXXL',
                '100% Baumwolle', 'Gleichfarbiger Kragen', 'Gleichfarbige Knöpfe',
            ]),
        ],
        'sportshirt' => [
            'label' => 'Sportshirt',
            'name_suffix' => 'Sportshirt',
            'base_price' => 29.99,
            'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
            'default_size' => 'M',
            'supplier_code' => 'JC001',
            'description' => $beschreibung('07_Sportshirt', [
                'Passform Unisex', 'Größen XS-XXL', '100% Polyester (atmungsaktiv)',
            ]),
        ],
        'matchpolo' => [
            'label' => 'Match-Polo',
            'name_suffix' => 'Match-Polo',
            'base_price' => 29.99,
            'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
            'default_size' => 'M',
            'supplier_code' => 'JC021',
            'description' => $beschreibung('08_MatchPolo', [
                'Passform Unisex', 'Größen XS-XXL', '100% Polyester (atmungsaktiv)',
            ]),
        ],
        'schultasche' => [
            'label' => 'Schultasche (Umhängetasche)',
            'name_suffix' => 'Schultasche',
            'base_price' => 19.99,
            'sizes' => ['Einheitsgröße'],
            'default_size' => 'Einheitsgröße',
            'supplier_code' => '',
            'description' => $beschreibung('09_Tasche', ['Umhängetasche', 'Einheitsgröße']),
            'no_individualisierung' => true, // Master hat nur die Nein-Variante
        ],
        'schulpullover_kids' => [
            'label' => 'Schulpullover Kids',
            'name_suffix' => 'Schulpullover Kids',
            'base_price' => 39.99,
            'sizes' => ['M', 'L', 'XL'],
            'default_size' => 'M',
            'supplier_code' => '',
            'description' => $beschreibung('01_Hoody', [
                'Kindergrößen', '80% Baumwolle, 20% Polyester', 'Mit Kapuze',
            ]),
        ],
        'schulshirt_kids' => [
            'label' => 'Schulshirt Kids',
            'name_suffix' => 'Schulshirt Kids',
            'base_price' => 24.99,
            'sizes' => ['M', 'L', 'XL'],
            'default_size' => 'M',
            'supplier_code' => '',
            'description' => $beschreibung('05_Tshirt', [
                'Kindergrößen', '100% Baumwolle',
            ]),
        ],
    ],

    // Standardwerte für neue Produkte im Konfigurator
    'default_colors' => ['schwarz', 'weiß', 'burgundy'],
    'indiv_surcharge' => 7.99,
    'default_klassen_extra' => ['LehrerIn ', 'AbsolventIn ', 'Verwaltung', 'Nicht angeführt'],
];
