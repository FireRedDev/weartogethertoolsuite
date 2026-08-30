# Changelog

Alle nennenswerten Änderungen der Wear Together Order Suite.

## [Unreleased]

### Modul 2: Schul-Onboarding
- FluentForms-Webhook (Webshopstartfragebogen) legt Onboarding-Anträge automatisch an; manuelle Anlage möglich
- Konfigurator: Produkte/Preise/Größen/Farben/Klassenliste/Bestellfenster aus dem Musterschule-Excel-Master, vorbefüllt aus den Formularwünschen
- Shop-Anlage per Klick (idempotent, mit Dry-Run-Vorschau und Schritt-Protokoll): Kategorie „Schulen > {Name}", variable Produkte — alle Attribute als Variationsattribute wie im Excel-Master (Variationen „Any" außer Individualisierung Ja/Nein), Standard-Größe M, PIF-Individualisierungsfeld, Pods-CPT „schule" inkl. Feld-Verifikation und Logo als Beitragsbild
- Sammelbestellfenster: Bestellemail nach Druckerei-Vorlage (Copy + mailto)
- On-Demand: Printify-Integration — Produkte anlegen + publishen mit Margen-Prüfung (Verkaufspreis ≥ (Kosten + Versand) × 1,10), Backprint-Unterstützung, Nachbearbeitung setzt Versandklasse „on-demand" + Kategorie auf den von Printify erstellten Shop-Produkten
- `php artisan printify:check` für Verbindungstest, Shop-ID, Blueprint-Suche (`--blueprints`), Print-Provider-Suche (`--providers`) und Katalog-Beschreibung eines Blueprints (`--description`, kommagetrennt)
- On-Demand-Produktbeschreibung optional pro Produkt überschreibbar (`printify_description` in `config/schoolshop.php`) — deutsche Übersetzung der offiziellen Printify-Katalogbeschreibung des jeweiligen Blueprints statt der generischen Sammelbestellfenster-Beschreibung; ohne Eintrag unverändertes Verhalten (Fallback auf `description`)
- Printify-Katalog vorbefüllt: Blueprint-ID/Provider-ID für alle Produkte in `config/schoolshop.php` hinterlegt (Textildruck Europa wo verfügbar, sonst bester US-Provider), Konfigurator übernimmt sie automatisch als Default
- Konfigurator zeigt je On-Demand-Produkt live Provider-Region und tatsächliche Versandkosten (Printify-API, 24h gecacht) an, inkl. Warnhinweis bei Providern außerhalb der EU
- Konfigurator: Blueprint-/Provider-Suche direkt in der App (🔍-Button, live gegen den Printify-Katalog) — kein SSH/Terminal mehr nötig; Tooltip an den Spaltenköpfen erklärt alle drei Wege (Suche, Terminal, printify.com)
- Konfigurator: „+ Produkt hinzufügen" erlaubt frei benannte Zusatzprodukte außerhalb des Vorlagenkatalogs (Name/Preis/Größen/Farben/Printify-IDs)
- On-Demand: Bestellfenster und Klassenliste entfallen (Versand direkt an die Privatadresse) — im Konfigurator ausgeblendet, Pods-Eintrag bekommt automatisch ein durchgehend offenes Fenster (01.01.2000–01.01.2099)
- Produktfotos (Mockups, optional, Standard aus): Dynamic-Mockups-Integration — pro Produkt 1–2 Model-Fotos (Frau/Mann, wechselnd je Schule, stabil pro Schule) + Detailansichten in den Schulfarben, Logo-Platzierung wählbar (Brust links/rechts/mitte, Mitte voll/halb, unten), automatisch als Produktbild + Galerie gesetzt; `php artisan mockups:check` zum Kuratieren der Vorlagen; Render-Fehler brechen die Anlage nie ab, keine doppelten Credits bei Wiederholung
- Schullogo & Druck: Logo je Druck (Frontprint/Backprint) im Tool hochladen, als Vorschaubild ansehen, herunterladen und austauschen — das Formular-Logo gilt als Standard für beide Drucke. Frontprint/Backprint einzeln an-/abwählbar (vorbelegt aus dem Formularwunsch), Position (mittig, mittig links/rechts, links/rechts oben, links/rechts unten) und Größe (klein/mittel/groß) je Druck einstellbar — gilt für Printify wie für die Mockup-Erzeugung. Hochgeladene Logos landen zusätzlich in der WordPress-Mediathek, damit Printify und Dynamic Mockups sie laden können
- On-Demand: Printify legt nur noch Varianten in den gewählten Farben/Größen an (DE→EN-Zuordnung über `printify.color_aliases`/`size_aliases`). Behebt den API-Fehler „max. 100 Varianten" beim Anlegen mehrerer Produkte und die Vorschaubilder in nicht bestellten Farben; passt keine Farbe/Größe, bricht die Anlage mit einer Liste der verfügbaren Werte ab
- Konfigurator zeigt je On-Demand-Produkt zusätzlich Einkaufspreis (Produktionskosten-Spanne der angelegten Varianten) und Marge an; Versandkosten-Tooltip nennt Herkunfts- und Zielländer des Versandprofils
- Versandkosten-Ermittlung korrigiert: ein Versandprofil, das Österreich ausdrücklich nennt, gilt jetzt immer vor dem Sammelprofil „Rest der Welt" (vorher gewann je nach Reihenfolge das falsche Profil)
- Antragsliste: „Öffnen"-Button ganz nach links verschoben und beim horizontalen Scrollen fixiert — er war bei breiten Tabellen nicht mehr sichtbar
- Fehlertransparenz überall: erklärte Fehlermeldungen mit kopierbaren technischen Details statt 500er-Seiten; Schutz vor Redirect-Verlust bei Schreibzugriffen (www vs. ohne www)

### Admin-Informationen
- Prüfstand deutlich auffindbar gemacht: abgesetzter Knopf in der Navigationsleiste, Kachel auf der Startseite und Links in einer neuen Fußzeilen-Navigation
- Die vollständige Webhook-Diagnose steht jetzt auf der Admin-Seite (nicht mehr nur unter Schul-Onboarding, wo sie niemand gesucht hat) — als gemeinsame Vorlage an beiden Stellen eingebunden, inklusive der Webhook-URL zum Selbsttest im Browser
- Neuer Block „Version & Umgebung": Versionsnummer, Shop-Adresse, ob Webhook-Secret und Zugangsschutz gesetzt sind, PHP-Version und ob der Konfigurations-Cache aktiv ist

### Startseite, Status und Bestellfenster-Automatik
- **Startseite ist jetzt eine Aufgabenübersicht**: abgelaufene, aber im Shop noch offene Bestellfenster, Fenster mit Ablauf in den nächsten 7 Tagen, neue Anträge, noch nicht angelegte Schulen, fehlende Präsentationsblätter und geschlossene Fenster ohne Auftragsdokumente. Die Modulerklärungen und ein neuer Abschnitt „Der Ablauf einer Schule" bleiben darunter erhalten
- **Status haben eine feste Bedeutung und erlaubte Übergänge**: jeder Status wird im Antrag erklärt; „Im Shop angelegt" und „Abgeschlossen" lassen sich nicht mehr von Hand setzen, sondern entstehen nur durch die jeweilige Aktion — sonst behauptet der Status etwas, das im Shop fehlt. Ein Antrag ohne Shop-Anlage kann weiterhin abgehakt werden (Absage/Dublette), ein angelegter zurück in Bearbeitung
- **Automatische Nachfrist**: abgelaufene Sammelbestellfenster werden einmalig um X Tage verlängert (Standard 7), erst nach Ablauf. Im Konfigurator abwählbar, Dauer einstellbar; das neue Ende wird auch in den Schule-Eintrag geschrieben. Läuft per `php artisan windows:extend` (Cron) und zusätzlich gedrosselt beim Aufruf der Startseite. Ein von Hand geändertes Enddatum gibt die Verlängerung wieder frei
- **Bestellfenster wieder öffnen** (Umkehrung von Modul 3): Produkte wieder öffentlich, „Bestellfenster offen" auf JA, neues Enddatum

### Hilfen rund um den Antrag
- **Live-Bestellzahlen je Schule** (Bestellungen und Teile aus der WooCommerce-API im Bestellzeitraum, 15 Minuten gecacht), inklusive Abgleich mit der erwarteten Anzahl
- **Auftragsdokumente per Klick aus dem Antrag** — Kategorie und Zeitraum sind vorbefüllt; der Export wird am Antrag vermerkt, damit die Startseite nicht weiter daran erinnert
- **E-Mail an die Schule** als Vorlage (Link zur Bestellseite, Zeitraum, Produktliste) — Gegenstück zur Bestellemail an die Druckerei
- **Folgejahr per Klick**: Antrag mit Produkten, Preisen, Farben und Logos duplizieren; Bestellfenster, Klassenliste, Shop-IDs und Mockups beginnen neu
- **Logo-Qualitätsprüfung** beim Upload: warnt bei unter 1000 px Kantenlänge und bei nicht freigestelltem Hintergrund (blockiert nicht)
- **Bestellseite prüfen**: ruft die Adresse ab, auf die der QR-Code zeigt, und meldet 404, Fehler oder fehlende Produkte
- **Datensicherung**: Datenbank und Uploads als ZIP — im Admin-Bereich herunterladbar oder per `php artisan backup:create` (Cron, die letzten fünf bleiben liegen). Die `.env` ist bewusst nicht enthalten

### Modul 4: Statistiken (neu)
- **Neues Modul `/statistiken`** — Umsatzauswertung nach **österreichischem Schuljahr** (1. September bis 31. August; die Sommerferien zählen bewusst ans ablaufende Schuljahr, weil Nachzügler- und Ferienbestellungen zum Bestellfenster vom Juni gehören). Jede Zahl steht neben dem Wert des Vorjahres
- **Kennzahlen**: Gesamtumsatz, Vorjahr **zum selben Zeitpunkt** (ein halbes Schuljahr wird nicht gegen ein volles gestellt), Ø Umsatz je Bestellung, Ø je Sammelbestellfenster, Ø je On-Demand-Shop, verkaufte Teile — jeweils mit prozentualer Veränderung
- **Diagramme** (Inline-SVG, kein Node-Build): Monatsumsatz als gruppierte Säulen ab September, kumulierter Jahresverlauf als Linie mit Hochrechnung und Zielmarke, Ranglisten der meistverkauften Produkte und beliebtesten Farben als waagrechte Balken. Jedes Diagramm hat Legende **und** Tabellenansicht, damit die Farbe nie der einzige Informationsträger ist; die Serienfarben sind gegen Farbsehschwäche geprüft
- **Prognose bis Schuljahresende**: hochgerechnet über den gemittelten **Saisonverlauf der Vorjahre**, nicht linear — ein Schuljahr verläuft stark ungleichmäßig. Dazu Zielumsatz (Standard: Vorjahresumsatz, frei überschreibbar), Zielerreichung und nötiger Umsatz je Restmonat
- **Bestellfenster-Puffer**: für „Ø je Bestellfenster" wird der Zeitraum je Schule absichtlich breiter genommen als eingestellt (Standard 7 Tage davor, 21 danach, in der Filterzeile änderbar) — die automatische Nachfrist verlängert oft um eine Woche, und Nachzügler bestellen auch danach. Per ⓘ erklärt
- **On-Demand wird getrennt gewertet**: dort gibt es kein Bestellfenster, gerechnet wird je On-Demand-Schule und Schuljahr. Farbattribute erkennt die Auswertung deutsch (Sammelbestellung) wie englisch (Printify); die Produkt-Rangliste fasst über Schulen hinweg zusammen (Schulname und Druckzusätze fallen aus dem Namen)
- **Filter für die ganze Seite**: Schuljahr, Lieferart, einzelne Schule, Puffertage, Bestellstatus und Zielumsatz — alles in der Adresszeile, die Auswertung ist damit als Lesezeichen speicherbar
- Ein Bestellabruf je Schuljahr statt einem je Schule; abgeschlossene Schuljahre 24 h gecacht, das laufende 30 Minuten, mit „↻ Daten neu laden". Ist der Shop nicht erreichbar, erscheint eine erklärte Meldung mit kopierbaren Details statt eines 500ers
- Auf dem Telefon schrumpfen die Diagramme nicht mit, sondern scrollen waagrecht im eigenen Kasten — bei 390 px wäre die Beschriftung sonst 6 px groß

### Statistiken: richtige Schulzuordnung, Produktarten, Schul-Rangliste
- **Behoben: „Ø je Sammelbestellfenster" und „Ø je On-Demand-Shop" waren 0.** Die Auswertung kannte Schulen nur aus den Onboarding-Anträgen — also nur die, die die Toolsuite selbst angelegt hat. Alles, was vorher von Hand im Shop entstand, war unsichtbar. Jetzt sind die **Produktkategorien des Shops** die Quelle; der Antrag liefert nur noch Lieferart und Bestellfenster-Daten
- **Meistverkaufte Produkte gehen nach Produktart, nicht nach Produktname.** Im Shop heißt jedes Produkt anders, weil der Schulname darin steckt — die Rangliste hatte deshalb je Schule eigene Zeilen. Jetzt fallen „BG Korneuburg Schulhoodie", „HAK Wien STICK-Hoodie + Backprint" und „VS Wolkersdorf Kapuzenpullover" in eine Zeile „Schulhoodie". Die Stichwörter kommen aus dem Produktkatalog und aus `statistics.product_group_aliases`
- **Neu: Rangliste „Umsatzstärkste Schulen"** (Umsatz je Schul-Kategorie im Schuljahr, mit Vorjahresvergleich) — sie ersetzt die Tabelle „Bestellfenster im Detail", die zu wenige Schulen und dort 0 € zeigte. Auch Schulen ohne Antrag in der Toolsuite erscheinen darin
- **„Zielumsatz (Vorjahr)" war missverständlich** — der Wert ist der tatsächlich erreichte Vorjahresumsatz, der als Ziel übernommen wird. Steht jetzt so da: „= Umsatz 2024/25 (kein eigenes Ziel eingetragen)"
- **Neu erklärt, was die Filter beeinflussen**: Schuljahr, Lieferart, Schule und Bestellstatus wirken auf alles; Vorlauf/Nachlauf ausschließlich auf die beiden Fenster-Durchschnitte; der Zielumsatz ausschließlich auf die Prognose

### Statistiken laden jetzt im Hintergrund
- **Die Seite wartet nicht mehr auf den Shop.** Sie antwortet sofort und zeigt, solange Daten fehlen, eine Ladeanzeige mit **Spinner und Fortschrittsbalken** („12 von 39 Datenpaketen geladen"). Kennzahlen und Diagramme bleiben bis dahin vollständig verborgen — eine halbe Auswertung wäre irreführender als gar keine
- **Der Aufbau läuft automatisch weiter**, ohne Klick. Die Ladeseite fragt den Fortschritt alle paar Sekunden ab und öffnet die Auswertung selbstständig, sobald alles da ist. Das frühere manuelle „Weiterladen" entfällt
- **Er läuft auch weiter, wenn die Seite geschlossen wird.** Der Abruf startet erst, nachdem die Antwort beim Browser ist (`ignore_user_abort`), und hängt damit nicht am geöffneten Tab
- **Rücksicht auf den Webshop**, der auf demselben Server läuft: immer nur ein Durchgang gleichzeitig (Sperre) und eine einstellbare Pause zwischen zwei Shop-Anfragen (`statistics.pause_ms`, Standard 400 ms). Lieber etwas länger aufbauen als den Shop für Kund:innen langsam machen
- Neuer Befehl `php artisan statistics:warm` — als nächtlicher Cron eingerichtet steht die Auswertung schon beim ersten Aufruf des Tages sofort bereit
- Fehler beim Aufbau (Shop nicht erreichbar, falscher Schlüssel) erscheinen auf der Ladeseite mit kopierbaren technischen Details, statt still im Hintergrund zu verschwinden

### Stabilität: Ausfall durch endloses Blättern behoben
- **Notbremse beim Blättern durch die Shop-API** (`ordersuite.woocommerce.max_pages`, Standard 200 Seiten). Ohne sie lief die Schleife endlos weiter, sobald der Shop den Seitenzähler `X-WP-TotalPages` nicht mitschickt (Caching-Plugin oder vorgelagerter Proxy) und jede Seite voll ist — der PHP-Prozess hing dann dauerhaft, und nach wenigen Aufrufen antwortete die **gesamte Anwendung** nicht mehr. Jetzt bricht der Abruf mit einer erklärten Meldung ab
- **Statistik lädt monatsweise und speichert jeden fertigen Monat einzeln.** Vorher holte ein Seitenaufruf drei komplette Schuljahre am Stück; bei einem echten Shop läuft das minutenlang, rennt in den Zeitablauf des Webservers und speichert dabei nichts — jeder neue Versuch begann wieder bei null. Jetzt hat jeder Aufruf ein Zeitbudget (Standard 20 Sekunden); reicht es nicht, zeigt die Seite „Die Auswertung wird gerade aufgebaut — X von Y Monaten geladen" samt „Weiterladen". Der nächste Aufruf macht dort weiter, nach ein bis zwei Aufrufen ist alles da und danach sofort verfügbar
- Zusätzliche Vorjahre für die Prognose werden erst geholt, wenn die eigentliche Auswertung vollständig ist; einzelne Shop-Anfragen der Statistik haben einen kürzeren Zeitablauf (12 s statt 30 s), und der Controller setzt zusätzlich ein hartes `set_time_limit`
- **Monatsgrenzen korrigiert:** WooCommerce behandelt `after`/`before` ausschließend. Eine Bestellung, die exakt um Mitternacht des Monatsersten eingeht, wäre beim monatsweisen Abruf durchs Raster gefallen — die Grenzen liegen jetzt auf der letzten Sekunde des Vormonats bzw. dem ersten Augenblick des Folgemonats

### Bedienung: weniger Dauertext, Erklärungen auf Abruf
- **Erklärungen sind jetzt am Telefon bedienbar**: die bisherigen `title="…"`-Tooltips zeigt ein Touchgerät nie an (kein Mouseover). Ersetzt durch ein antippbares Info-Symbol (ⓘ) — antippen öffnet den Kasten, erneut antippen, ein Tipp daneben oder Esc schließt ihn. Der Kasten wird waagrecht ins Bild geschoben, damit er auf schmalen Schirmen nicht abgeschnitten wird
- **Deutlich weniger Dauertext auf allen Seiten außer der Startseite**: lange Erklärblöcke stecken in ausklappbaren Bannern („Wie Logo und Druck zusammenhängen", „Was bei On-Demand zu beachten ist", „Voraussetzungen für Mockups", „Was dabei im Shop entsteht", „Was passiert, wenn eine Schnittstelle ausfällt?", „Automatisch sichern (Cron)"), kurze Hinweise hinter dem Info-Symbol an der jeweiligen Überschrift, Spalte oder Schaltfläche
- Die Startseite bleibt bewusst ausführlich — dort steht weiterhin offen, was die Toolsuite ist, was jedes Modul kann und wie der Ablauf einer Schule aussieht
- Neue Blade-Komponenten `<x-info>` und `<x-explain>` für beides; reine Symbolschaltflächen (🔍) haben jetzt `aria-label` statt `title`

### Präsentationsblatt (neu)
- Je Bestellfenster erzeugt das Tool das A4-Präsentationsblatt automatisch — deckungsgleich mit der bisherigen InDesign-Vorlage (größte Abweichung 2,6 pt bei der Überschrift, alles übrige unter 0,4 pt)
- Eingabe sind nur die beiden Mockups; Schulname, Produktzeilen, Farben, Bestellzeitraum, QR-Code und Adresse kommen aus dem Onboarding-Datensatz
- Bildausschnitt je Mockup mit Klick ins Bild einstellbar, mit Live-Vorschau des tatsächlichen Zuschnitts und Zoom-Regler; der Detailkreis („Print your name!") wird standardmäßig aus der Vorderansicht herangezoomt, alternativ eigenes Bild hochladen
- Produktzeilen sind vorbelegt (Marketing-Bezeichnung + ausgeschriebene Farbliste) und frei überschreibbar, Icon je Zeile wählbar
- Vorschau im Browser und PDF-Download; erzeugbar erst, wenn beide Mockups, Bestellfenster und mindestens ein Produkt vorhanden sind
- Statischer Hintergrund als PNG mit transparenten Fenstern — `php artisan sheet:background <datei.png>` macht aus einem Grafik-Export (Bildplätze magenta gefüllt) die fertige Datei
- Schrift: Source Sans 3 (OFL, im Repo) als Ersatz für das lizenzpflichtige Myriad Pro

### Modul 3: Bestellfenster schließen
- Schule auswählen → alle Produkte der Schule im Shop auf privat setzen (nicht mehr sichtbar/bestellbar, `status=private` + `catalog_visibility=hidden`) und im CPT „schule" „Bestellfenster offen" auf NEIN — idempotent (bereits private Produkte werden übersprungen), mit Schritt-Protokoll und erklärten Fehlern
- Produkte werden über die eindeutige Schul-Kategorie gefunden (Fallback: Namenssuche)

### Modul 1: Auftragsdokumente
- Weg 1: Bestell-Import direkt über die WooCommerce REST API (Schule = Produktkategorie, Statusfilter, Zeitraum) — repliziert den Plugin-Export exakt (live gegen St.-Johannis-Schule validiert, 0 Zell-Diffs)
- Weg 2: Datei-Upload wie bisher
- 3 Excel-Reports + Verteil-PDF zellgenau identisch zum Legacy-Python-Tool (Golden-File-Tests)
- Prüfbericht (unbekannte Größen, fehlende Individualisierungstexte u. a.), ZIP-Download, DSGVO-Auto-Löschung
- Modul jetzt unter `/auftragsdokumente` (vorher `/`)
- Nach dem Export: Erinnerung + Link, das Bestellfenster der Schule zu schließen (Modul 3)

### Neu: Startseite
- `/` zeigt jetzt eine Startseite mit Links + Beschreibung zu allen drei Modulen (Auftragsdokumente, Schul-Onboarding, Bestellfenster schließen)

### Neu: Admin-Informationen
- Neuer Navigationspunkt „Admin-Informationen": prüft bei jedem Aufruf live den Status aller API-Anbindungen (WooCommerce Lesen/Schreiben, WordPress/Pods, Printify, Dynamic Mockups) sowie den FluentForms-Webhook (letzter protokollierter Treffer, kein aktiver Test möglich, da eingehend)
- Fällt eine konfigurierte Schnittstelle aus, wird einmalig pro Ausfall-Episode eine Benachrichtigung ausgelöst — ausschließlich über die WordPress-REST-API (`wp_mail()` auf der WordPress-Seite via neuem mu-Plugin `wordpress-mu-plugin/weartogether-notify.php`); die Toolsuite selbst hat keinen Mailer und verschickt nie direkt E-Mails

### Infrastruktur
- Laravel 13 auf RunCloud (Git Atomic Deployment), SQLite, Login per Team-Passwort
- GitHub-Actions-CI (php artisan test bei Push/PR)
