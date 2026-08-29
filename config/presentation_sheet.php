<?php

/*
 * Präsentationsblatt (A4) je Bestellfenster einer Schule.
 *
 * Alle Maße in PostScript-Punkten (1 pt = 1/72 Zoll), Nullpunkt links oben —
 * exakt die Koordinaten der InDesign-Vorlage, damit das erzeugte Blatt
 * deckungsgleich mit dem bisherigen Handsatz ist.
 *
 * Aufbau in drei Ebenen (in dieser Reihenfolge gezeichnet):
 *   1. die zugeschnittenen Fotos
 *   2. der statische Hintergrund als PNG — er hat an den Fotostellen
 *      transparente Fenster und übernimmt damit das Freistellen der schrägen
 *      Rahmen und des Kreises
 *   3. alle variablen Texte, Produkt-Icons und der QR-Code
 */

return [

    // A4 hochkant
    'page' => ['width' => 595.28, 'height' => 841.89],

    'background' => resource_path('presentation-sheet/background.png'),
    'icon_dir' => resource_path('presentation-sheet/icons'),
    'font_dir' => resource_path('fonts'),

    'colors' => [
        'red' => '#8c171a',        // Headline, Bestellzeitraum, Abschluss-CTA
        'dark_grey' => '#575756',  // Produktnamen
        'mid_grey' => '#706f6f',   // Untertitel, URL
    ],

    /*
     * Fenster im Hintergrund. Die Fotos werden exakt auf diese Rechtecke
     * zugeschnitten und darunter gelegt; die transparenten Stellen des
     * Hintergrunds geben sie in der schrägen Rahmenform wieder frei.
     */
    'windows' => [
        'mockup_back' => ['left' => 306.2, 'top' => 87.8, 'width' => 288.8, 'height' => 356.3],
        'mockup_front' => ['left' => 0.0, 'top' => 422.4, 'width' => 280.9, 'height' => 331.3],
        'detail_circle' => ['left' => 187.5, 'top' => 553.8, 'width' => 130.0, 'height' => 130.0],
    ],

    /*
     * Zweizeilige Überschrift: "Schulmerchandise" + Schulname, beide auf
     * x = 143,7 zentriert (wie in der Vorlage). Der Kasten ist breiter als der
     * Text, damit auch lange Schulnamen Platz haben, bevor die Schrift kleiner
     * gerechnet wird.
     */
    'headline' => [
        'left' => 13.7, 'top' => 107.2, 'width' => 260.0,
        'size' => 24.0, 'min_size' => 14.0, 'line_height' => 26.0,
    ],

    /*
     * Produktblock links. Drei Produktzeilen plus die feste Baum-Zeile;
     * bei weniger Produkten rückt die Baum-Zeile nach oben.
     */
    'products' => [
        'first_top' => 178.5,
        'row_height' => 58.5,
        'max_products' => 3,
        'icon' => ['left' => 26.0, 'size' => 46.0, 'offset' => -1.4],
        'name' => ['left' => 81.6, 'size' => 18.0],
        'sub' => ['left' => 117.6, 'size' => 12.0, 'offset' => 24.4],
        'tree_row' => ['icon' => 'tree', 'name' => '1 Produkt = 1 Baum', 'sub' => '- Regenwaldaufforstung'],
    ],

    /*
     * Rechte Spalte — alles auf x = 449.3 zentriert (wie in der Vorlage).
     * "Jetzt online bestellen" und der Abschluss-Satz stehen bereits im
     * Hintergrund, hier kommen nur die variablen Teile dazu.
     */
    'dates' => ['left' => 319.3, 'top' => 488.4, 'width' => 260.0, 'size' => 22.0, 'line_height' => 22.0],
    'qr' => ['left' => 385.9, 'top' => 546.5, 'size' => 126.8],
    'url' => ['left' => 319.3, 'top' => 682.1, 'width' => 260.0, 'size' => 12.0, 'line_height' => 13.0],

    // Vorname im Detailkreis ("Print your name!")
    'name_badge' => ['left' => 187.5, 'top' => 641.1, 'width' => 130.0, 'size' => 12.0, 'color' => '#ffffff'],

    'qr_color' => '#8c171a',

    // Auflösung der zugeschnittenen Fotos (dpi). 300 = Druckqualität.
    'image_dpi' => 300,

    /*
     * Produkt-Key (config/schoolshop.php → catalog) → Icon-Datei.
     * Fehlt ein Icon, bleibt der Platz leer statt ein falsches zu zeigen.
     */
    'icons' => [
        'schulpullover' => 'hoodie',
        'schulzoodie' => 'zoodie',
        'schuljacke' => 'jacket',
        'schulsweater' => 'sweater',
        'schulshirt' => 't-shirt',
        'schulpolo' => 'polo',
        'sportshirt' => 't-shirt',
        'matchpolo' => 'polo',
        'schultasche' => 'bag',
        'schulpullover_kids' => 'hoodie',
        'schulshirt_kids' => 't-shirt',
    ],
    // Solange ein Icon noch nicht geliefert ist, wird ersatzweise dieses genommen.
    'icon_fallbacks' => [
        'hoodie' => 'zoodie',
        'sweater' => 'zoodie',
        'jacket' => 'zoodie',
        'bag' => 't-shirt',
    ],

    /*
     * Produktbezeichnung auf dem Blatt. Bewusst nicht der Katalogname aus
     * config/schoolshop.php — auf dem Präsentationsblatt steht die
     * Marketing-Bezeichnung ("Premium Zip-Hoodie" statt "Schulzoodie").
     * Im Tool ist jede Zeile zusätzlich frei überschreibbar.
     */
    'product_names' => [
        'schulpullover' => 'Premium Hoodie',
        'schulzoodie' => 'Premium Zip-Hoodie',
        'schuljacke' => 'Premium College-Jacke',
        'schulsweater' => 'Premium Sweater',
        'schulshirt' => 'Casual T-Shirt',
        'schulpolo' => 'Premium Poloshirt',
        'sportshirt' => 'Sportshirt',
        'matchpolo' => 'Match-Polo',
        'schultasche' => 'Umhängetasche',
        'schulpullover_kids' => 'Premium Hoodie Kids',
        'schulshirt_kids' => 'Casual T-Shirt Kids',
    ],

    // Adresse der Schul-Bestellseite (QR-Ziel + Textzeile darunter)
    'shop_url_pattern' => 'https://wear-together.at/schule/{slug}/',

    /*
     * Alle 'top'-Werte oben sind Oberkanten der Buchstaben, so wie sie in der
     * InDesign-Vorlage stehen. dompdf positioniert dagegen den Zeilenkasten und
     * setzt die Buchstaben um "factor × Schriftgröße − offset" tiefer. Beide
     * Werte sind gemessen (Vergleich der Buchstaben-Oberkanten zwischen der
     * Original-PDF und dem erzeugten Blatt) — der Renderer rechnet sie heraus.
     *
     * Nachmessen: tests/Feature/PresentationSheetTest.php prüft die Abweichung
     * gegen die Vorlagenkoordinaten.
     */
    'text_top_correction' => ['factor' => 0.2458, 'offset' => 0.40],
];
