<?php

/*
 * Modul "Statistiken": Auswertung des Shop-Umsatzes nach österreichischem
 * Schuljahr. Alle Werte hier sind Startwerte — die wichtigsten lassen sich
 * auf der Seite selbst als Filter übersteuern.
 */
return [

    /*
     * Österreichisches Schuljahr. Es beginnt je nach Bundesland am ersten oder
     * zweiten Montag im September; für eine Umsatzauswertung ist dieser
     * Unterschied bedeutungslos, ein fester Stichtag dagegen über die Jahre
     * vergleichbar. Ende ist der Tag davor im Folgejahr — die Sommerferien
     * zählen damit ans ABLAUFENDE Schuljahr (so gewünscht: Nachzügler- und
     * Ferienbestellungen gehören zum Bestellfenster, das im Juni endete).
     */
    'school_year' => [
        'start_month' => 9,
        'start_day' => 1,
        // Wie viele Schuljahre die Auswahl anbietet (inkl. laufendem)
        'history_years' => 6,
    ],

    /*
     * Puffer um jedes Sammelbestellfenster. Bestellungen kommen erfahrungsgemäß
     * noch nach dem eingestellten Ende herein (automatische Nachfrist von einer
     * Woche, Nachzügler, händisch wieder geöffnete Fenster). Da nie mehrere
     * Fenster derselben Schule direkt aneinander liegen, ist ein großzügiger
     * Zeitraum unproblematisch und trifft die Wirklichkeit besser.
     */
    'window_padding' => [
        'before' => 7,
        'after' => 21,
        // Obergrenze für die Eingabe auf der Seite
        'max' => 120,
    ],

    /*
     * Umsatz = Bruttoumsatz der Bestellpositionen (inkl. USt.). Auf false
     * gestellt wird netto gerechnet. Versandkosten und Gebühren zählen nie mit,
     * weil sie keiner Schule und keinem Produkt zuzuordnen sind.
     */
    'revenue_includes_tax' => true,

    /*
     * Zwischenspeicher. Abgeschlossene Schuljahre ändern sich nicht mehr und
     * werden deutlich länger gehalten als das laufende.
     */
    'cache' => [
        'current_minutes' => 30,
        'past_hours' => 24,
    ],

    // Wie viele Plätze die Ranglisten zeigen
    'ranking_limit' => 10,

    /*
     * Prognose: über wie viele abgeschlossene Vorjahre der saisonale Verlauf
     * gemittelt wird. Jedes zusätzliche Jahr bedeutet einen weiteren Abruf beim
     * ersten Aufruf (danach 24 h gecacht, weil sich abgeschlossene Jahre nicht
     * mehr ändern).
     */
    'forecast' => [
        'history_years' => 2,
    ],

    /*
     * Farbe einer Bestellposition. Sammelbestellfenster-Produkte legt die
     * Toolsuite selbst an (Attribut „Farbe"/`pa_color`), On-Demand-Produkte
     * kommen von Printify und heißen dort oft englisch. Verglichen wird ohne
     * Rücksicht auf Groß-/Kleinschreibung, erst exakt, dann als Teilstring.
     */
    'color_meta_keys' => ['pa_color', 'farbe', 'color', 'colors', 'colour', 'farben'],

    /*
     * Kleines Farbmuster neben dem Namen in der Farb-Rangliste — reines
     * Wiedererkennungszeichen. Die BALKEN behalten die Serienfarbe: ein
     * schwarzer oder weißer Balken wäre keine lesbare Skala mehr. Nicht
     * gefundene Farben bekommen einfach kein Muster.
     * Verglichen wird kleingeschrieben, erst exakt, dann als Teilstring.
     */
    'color_swatches' => [
        'schwarz' => '#111827',
        'black' => '#111827',
        'weiß' => '#f8fafc',
        'weiss' => '#f8fafc',
        'white' => '#f8fafc',
        'navy' => '#1e293b',
        'marine' => '#1e293b',
        'dunkelblau' => '#1e3a8a',
        'blau' => '#2563eb',
        'blue' => '#2563eb',
        'hellblau' => '#7dd3fc',
        'petrol' => '#0e7490',
        'türkis' => '#14b8a6',
        'grün' => '#15803d',
        'green' => '#15803d',
        'oliv' => '#4d7c0f',
        'gelb' => '#facc15',
        'yellow' => '#facc15',
        'orange' => '#f97316',
        'rot' => '#dc2626',
        'red' => '#dc2626',
        'bordeaux' => '#7f1d1d',
        'burgundy' => '#7f1d1d',
        'pink' => '#ec4899',
        'lila' => '#7c3aed',
        'violett' => '#7c3aed',
        'purple' => '#7c3aed',
        'grau' => '#9ca3af',
        'grey' => '#9ca3af',
        'gray' => '#9ca3af',
        'anthrazit' => '#374151',
        'charcoal' => '#374151',
        'beige' => '#e7d8bf',
        'sand' => '#e7d8bf',
        'natur' => '#e7d8bf',
        'braun' => '#78350f',
        'brown' => '#78350f',
    ],

    /*
     * Zusätze, die aus dem Produktnamen fallen, damit die Rangliste Produkte
     * schulübergreifend zusammenfasst. Der Schulname wird ohnehin entfernt
     * (Produkte heißen „{Schule} {Produkt}"); hier stehen nur allgemeine Reste.
     */
    'product_name_noise' => ['STICK-', '+ Backprint', '+ Frontprint', 'Backprint', 'Frontprint'],
];
