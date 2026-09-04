<?php

/*
 * Modul „Auftragsbilanz": die gepflegte Auftragsliste, Nachfolgerin der
 * Excel-Datei `Auftragsbilanz_gesamt.xlsx`. Hier wird eingetragen und
 * angezeigt — ausgewertet wird ausschließlich im Statistikmodul.
 */
return [

    /*
     * Übernimmt die Migration die Altdaten aus `database/data/auftragsbilanz.json`?
     *
     * In der Anwendung ja — sonst stünde das Modul nach dem Deploy leer da, bis
     * jemand einen Befehl ausführt, den er längst vergessen hat.
     *
     * In der Testumgebung nein (`phpunit.xml`): Sonst lägen in jedem einzelnen
     * Test 384 Aufträge herum und jede Umsatz- oder Stückzahlprüfung rechnete
     * gegen einen Datenberg, der mit dem Test nichts zu tun hat. Die Übernahme
     * selbst wird trotzdem geprüft — `AuftragsbilanzTest` ruft sie ausdrücklich auf.
     */
    'import_on_migrate' => (bool) env('AUFTRAGSBILANZ_IMPORT_ON_MIGRATE', true),

    /*
     * Die Produktarten der Excel, in genau dieser Reihenfolge (so stehen sie
     * dort nebeneinander). Der Schlüssel landet im JSON-Feld `products`, die
     * Beschriftung in Formular, Liste und Auswertung.
     *
     * „Individualisierungen" steht bewusst NICHT hier: Namen und Nummern sind
     * kein Kleidungsstück, sondern ein Zusatz auf einem — sie zählen in der
     * Excel deshalb auch nicht in die Spalte „Produkte" hinein.
     */
    'product_types' => [
        'hoodies' => 'Hoodies',
        'zoodies' => 'Zoodies',
        'jackets' => 'Jacken',
        'sweaters' => 'Sweater',
        'tshirts' => 'T-Shirts',
        'polos' => 'Polos',
        'sportshirts' => 'Sportshirts',
        'sportpolos' => 'Sportpolos',
        'shirts' => 'Hemden',
        'bags' => 'Taschen',
        'gymbags' => 'Gymbags',
        'caps' => 'Mützen',
        'masks' => 'Masken',
        'pants' => 'Hosen',
    ],

    /*
     * Umsatzsteuer auf den Verkaufspreis. Die Einnahmen werden BRUTTO
     * eingetragen (so steht es im Shop und so stand es in der Excel); die
     * Umsatzsteuer wird daraus herausgerechnet: brutto × 20/120.
     *
     * Der Wert je Auftrag ist trotzdem gespeichert und überschreibbar — die
     * Altdaten brauchen das: vor der GmbH-Gründung (bis in die Saison 2020/21
     * hinein) fiel keine Umsatzsteuer an, dort steht 0,00 €.
     */
    'vat_rate' => 0.20,

    /*
     * Ab welchem Schuljahr die Online-Einnahmen aus dem eigenen Webshop kommen.
     *
     * Das ist die Trennlinie, damit kein Umsatz doppelt gezählt wird: Für diese
     * Schuljahre holt sich die Statistik die Online-Zahlen aus WooCommerce und
     * lässt die Spalte „Einnahmen Online" der Auftragsbilanz beiseite. Für
     * frühere Jahre gab es den Shop noch nicht — dort ist der eingetragene Wert
     * die einzige Quelle und wird gezählt.
     *
     * Der eigene Webshop ging Ende 2020 in Betrieb, also mitten in der Saison
     * 2020/21. Die Linie liegt deshalb auf der ERSTEN vollständig im Shop
     * abgewickelten Saison, 2021/22 — für 2020/21 wäre sonst der halbe
     * Jahresumsatz weder im Shop noch in der Auftragsbilanz gezählt worden.
     *
     * Ob die Linie richtig liegt, zeigt die Vergleichstabelle im Modul: Sie
     * stellt je Schuljahr den Shop-Wert dem eingetragenen Wert gegenüber.
     */
    'shop_online_from_year' => 2021,

    /*
     * Ab wann eine Abweichung zwischen Shop und Eintrag gemeldet wird —
     * als Anteil (0,02 = 2 %) und als Betrag. Gemeldet wird erst, wenn BEIDE
     * Schwellen überschritten sind: ein paar Euro Rundungsunterschied auf
     * hunderttausend Euro Jahresumsatz ist keine Meldung wert, zwei Euro
     * Unterschied auf einen Zehn-Euro-Auftrag dagegen schon.
     */
    'mismatch' => [
        'share' => 0.02,
        'amount' => 25.0,
    ],
];
