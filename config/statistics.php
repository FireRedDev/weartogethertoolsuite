<?php

/*
 * Modul "Statistiken": Auswertung des Shop-Umsatzes nach österreichischem
 * Schuljahr. Alle Werte hier sind Startwerte — die wichtigsten lassen sich
 * auf der Seite selbst als Filter übersteuern.
 */
return [

    /*
     * Das Geschäftsjahr, hier weiterhin „Schuljahr" genannt: 1. August bis
     * 31. Juli. Dieser Stichtag ist die Vorgabe des Hauses — so rechnen die
     * Jahresbilanzen („Bruttoumsatz 1. August bis 31. Juli") und so rechnet die
     * Auftragsbilanz. Beide Module MÜSSEN denselben Stichtag verwenden, sonst
     * zeigen sie unterschiedliche Jahressummen.
     *
     * Der Schnitt liegt bewusst vor Schulbeginn: Die Sommerferien zählen damit
     * ans ABLAUFENDE Jahr — Nachzügler- und Ferienbestellungen gehören zu dem
     * Bestellfenster, das im Juni endete, nicht zur neuen Saison. Der August
     * dagegen ist der erste Monat der neuen Saison, weil dort die Bestellfenster
     * für das kommende Schuljahr aufgehen.
     *
     * Achtung beim Ändern: Der Stichtag steckt in den Cache-Schlüsseln der
     * Monatsdaten nicht drin. Nach einer Änderung einmal „Daten neu laden"
     * anstoßen, sonst mischen sich alte und neue Jahreszuordnungen.
     */
    'school_year' => [
        'start_month' => 8,
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
        'products_hours' => 6,
    ],

    /*
     * Zeitbudget für die Shop-Abrufe EINES Seitenaufrufs, in Sekunden.
     *
     * Abgerufen wird monatsweise und jeder fertige Monat wird gespeichert.
     * Ist das Budget aufgebraucht, bricht die Seite den Rest ab und zeigt an,
     * wie viele Monate schon geladen sind; der nächste Aufruf macht dort
     * weiter. Das ist der Schutz davor, dass ein Seitenaufruf minutenlang
     * läuft, in den Zeitablauf des Webservers rennt und dabei eine PHP-
     * Arbeitskraft blockiert — passiert das mehrfach, antwortet die gesamte
     * Anwendung nicht mehr.
     *
     * Muss deutlich unter dem PHP-/nginx-Zeitablauf liegen (typisch 60 s).
     */
    'budget_seconds' => 20,

    /*
     * Hintergrund-Aufbau (StatisticsWarmer). Der Aufbau läuft, NACHDEM die
     * Antwort beim Browser ist, und läuft weiter, wenn jemand die Seite
     * verlässt. Immer nur ein Durchgang gleichzeitig.
     *
     * `warm_budget_seconds` — wie lange ein Durchgang höchstens Monate holt.
     * `pause_ms` — Pause zwischen zwei Shop-Anfragen. Der Webshop läuft auf
     *   demselben Server und darf durch die Auswertung nicht langsam werden;
     *   lieber etwas länger aufbauen als den Shop ausbremsen.
     * `poll_seconds` — wie oft die Ladeseite den Fortschritt abfragt.
     */
    'warm_budget_seconds' => 25,

    /*
     * Wie lange ein Fehler den Aufbau anhält, in Sekunden. Solange er
     * gespeichert ist, wird kein neuer Durchgang angestoßen. Kurz genug, dass
     * sich ein vorübergehendes Zucken des Shops von selbst erledigt, lang
     * genug, dass ein dauerhaft kaputter Shop nicht im Sekundentakt angefragt
     * wird.
     */
    'error_retry_seconds' => 120,
    'pause_ms' => 400,
    'poll_seconds' => 3,

    /*
     * Zeitablauf einer EINZELNEN Shop-Anfrage der Statistik. Bewusst kürzer als
     * der allgemeine Wert (ordersuite.woocommerce.timeout_seconds = 30): hier
     * laufen viele Anfragen nacheinander, eine hängende darf nicht das ganze
     * Budget aufbrauchen.
     */
    'request_timeout_seconds' => 12,

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
     * Produktarten für die Rangline „meistverkaufte Produkte".
     *
     * Gefragt ist, ob mehr Schulshirts oder mehr Schulpullover verkauft wurden.
     * Im Shop heißt jedes Produkt anders (der Schulname steckt im Namen), also
     * wird der Positionsname nach Suchbegriffen durchsucht.
     *
     * Die Begriffe aus `schoolshop.catalog` (name_suffix) gelten automatisch —
     * hier stehen nur ZUSÄTZLICHE Schreibweisen, die im Shop vorkommen, aber
     * nicht im Katalog. Groß-/Kleinschreibung egal; der längste passende
     * Begriff gewinnt, damit „Schulpullover Kids" nicht bei „Schulpullover"
     * landet.
     *
     * Taucht in der Rangliste ein Produkt doppelt oder falsch benannt auf,
     * gehört die dort gezeigte Schreibweise hier ergänzt.
     */
    'product_group_aliases' => [
        'Schulhoodie' => ['schulhoodie', 'stick-hoodie', 'kapuzenpullover', 'hoodie'],
        'Schulzoodie' => ['zoodie', 'zip-hoodie', 'zipper'],
        'Schuljacke' => ['softshell', 'jacke'],
        'Schulsweater' => ['sweater', 'sweatshirt'],
        'Schulshirt' => ['t-shirt', 'tshirt', 'shirt'],
        'Schulpolo' => ['polo'],
        'Schultasche' => ['tasche', 'beutel', 'turnsack', 'rucksack'],
        'Mütze' => ['mütze', 'beanie', 'haube'],
    ],
];
