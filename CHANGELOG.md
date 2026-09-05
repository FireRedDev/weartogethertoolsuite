# Changelog

Alle nennenswerten Änderungen der Wear Together Order Suite.

## [Unreleased]

### Auftragsbilanz: Bearbeiten und Löschen in der Zeile (v29)
- **Beide Aktionen stehen jetzt unter dem Auftragsnamen**, in der fixierten ersten Spalte — der einzigen, die beim seitlichen Scrollen stehen bleibt. Vorher war „Bearbeiten" die **15. Spalte** (am Desktop außerhalb des Sichtfelds, am Telefon ganz ausgeblendet), und „Löschen" gab es ausschließlich unten auf der Bearbeiten-Seite. Beides existierte, war aber nicht zu finden.
- **Löschen fragt mit dem Namen des Auftrags nach** — in einer Liste von 35 Zeilen muss erkennbar sein, welche man gerade erwischt. Der Name steht in einem `data-confirm`-Attribut statt in `onsubmit`, damit ein Apostroph im Schulnamen das Attribut nicht zerreißt.
- Zeilen heben sich beim Überfahren hervor, die alte leere Aktionsspalte ist weg.

### Bedienbarkeit: Auftragsbilanz und Statistik durchgesehen (v28)

Beide Module wurden mit den Augen von jemandem angesehen, der die Software nicht kennt — Bildschirmfotos in Desktop- und Telefonbreite, mit den 384 übernommenen Aufträgen als echtem Inhalt.

**Behobene Fehler**
- **Die Quellenschalter waren wirkungslos.** Der Link zum Ausschalten trug kein `shop=0`, und der zum Einschalten wurde es nicht mehr los: `StatisticsFilters::query()` verband die Filter per `+` mit den Übersteuerungen, und dabei gewinnt der linke Operand. Die Schalter sahen aus wie Schalter, taten aber nichts.
- **Ein per JavaScript verstecktes Element blieb sichtbar**, sobald es ein inline gesetztes `display` trug — jetzt gilt `[hidden] { display: none !important; }`.
- **„Datenstand: unbekannt Uhr"** war kein Satz. Drei ehrliche Zustände: Zeitpunkt, „noch nichts aus dem Shop geladen" oder „Auftragsbilanz, laufend gepflegt".

**Keine Sackgassen mehr**
- **Ohne Shop-Zugang** zeigte die Statistik nur eine rote Fehlermeldung — obwohl die Auftragsbilanz mit voller Gewinnrechnung danebenliegt. Jetzt steht dort, was ohne Shop trotzdem geht (und was fehlt), mit dem Knopf „Ohne Shop-Zahlen auswerten". Der Ausweg war vorher nur über `?shop=0` in der Adresszeile zu finden.
- **Vom Bestellfenster in die Auftragsbilanz:** Auf der Antragsseite steht jetzt ein Kasten mit den Aufträgen dieses Fensters und dem Knopf „Auftrag anlegen" — mit vorbefüllter Schule, Datum, Lieferart und Verknüpfung. Der Weg war fertig gebaut, nur nirgends verlinkt.
- **Das Saisonziel richtet sich nicht mehr nach den Schaltern.** Ist eine Quelle abgeschaltet, gibt es keinen Zielvorschlag mehr: Der Vorjahresumsatz wäre dann nur der Ausschnitt der eingeschalteten Quelle (mit abgeschalteter Shop-Quelle 4.400 € statt 48.166 €). Ein Ziel gilt für alle im Team und darf nicht davon abhängen, welche Schalter gerade jemand gesetzt hat.

**Auftragsbilanz**
- **Am Telefon wird jede Zeile zur Karte.** Von der 15-spaltigen Tabelle blieb bei 390 px nur die fixierte Namensspalte übrig — eine Liste ohne eine einzige Zahl. Am Desktop bleibt die Tabelle unverändert.
- **Sortieren nach Auftrag, Datum, Einnahmen, Ausgaben, Gewinn, Marge und Teilen** durch Klick auf die Spaltenüberschrift.
- **Arbeitsvorrat „Zu prüfen":** Aufträge ohne eingetragene Ausgaben lassen sich mit einem Klick herausfiltern. In 2025/26 sind das 12 Aufträge über 20.201,94 € — sie erzeugen sonst rechnerische Margen von 83 % und stehen damit in jeder Rangliste ganz oben.
- **Ohne Ausgaben bleibt die Marge leer** statt eine Zahl nahe 100 % zu zeigen.
- **Geschätzte Daten** heißen jetzt „Schuljahresende (geschätzt)" statt in jeder Zeile dasselbe Datum zu wiederholen. Aufträge ganz ohne Beträge zeigen ihre Anmerkung direkt hinter dem Namen (Musterpakete, Gutscheineinlösungen) und stehen gedämpft.
- **Der Gewinn steht am Ende der Kachelreihe**, nicht mittendrin, und alle Kacheln tragen die Veränderung zum Vorjahr.
- **Das Formular rechnet mit:** Einnahmen gesamt, Umsatzsteuer, Gewinn und Marge stehen beim Tippen unter dem Block „Geld" — wie die Formelspalten der Excel. Gespeichert wird davon nichts. Steht die Quelle auf „Webshop", sagt das Feld „Einnahmen Online" jetzt, dass die Software es nachträgt; ohne verknüpftes Bestellfenster warnt es, dass nichts nachgetragen werden kann.

**Statistik**
- **Ein frisch begonnenes Schuljahr erklärt sich.** Am 4. September ist 2026/27 fünf Wochen alt; die Seite zeigte Nullen, Striche und dreimal „keine Verkäufe erfasst". Jetzt steht dort, dass die Saison am 1. August begonnen hat, mit einem Knopf ins Vorjahr.
- **Kein „−100 % gegenüber Vorjahr" mehr im laufenden Jahr.** Verglichen wird mit dem Vorjahr zum selben Zeitpunkt; das Ganzjahres-Delta erst, wenn das Jahr abgeschlossen ist.
- **Leere Ranglisten nennen den Grund:** „Die Shop-Quelle ist gerade ausgeschaltet" statt einer Aussage über die Daten.
- **Hinweis unter dem Monatsverlauf**, wenn die dargestellten Jahre geschätzte Auftragsdaten enthalten — die übernommenen Excel-Aufträge sitzen alle am 31. Juli und erzeugen dort sonst eine Saisonspitze, die es nie gab.
- **Sichtbare Trennlinie „Ab hier: aus der Auftragsbilanz"** vor den Karten, die bewusst nicht an den Quellenschaltern hängen.
- **„Vorlauf/Nachlauf (Tage)"** heißt jetzt „Puffer: Tage davor / Tage danach".
- **Die zwei Umsatzbegriffe** auf der Seite sind benannt: „Einnahmen ges." ist das Eingetragene, „Umsatz" das, was der Webshop meldet plus alles daneben — sie dürfen abweichen, die Spalte „Shop meldet" ist der Vergleich.
- **Beide Module verweisen aufeinander:** „Hier wird eingetragen" / „Hier wird ausgewertet".

### Neu: Modul „Auftragsbilanz" (v26)
- **Die Excel `Auftragsbilanz_gesamt.xlsx` ist in die Software gezogen.** Neues Modul `/auftragsbilanz`: eine Zeile je Auftrag, Spalten wie bisher. Eingetragen werden Einnahmen (online/bar), Provision, Ausgaben, Umsatzsteuer und die Stückzahlen je Produktart; Einnahmen gesamt, netto, Gewinn und Marge rechnet die Software — das waren in der Excel Formeln und bleiben es.
- **384 Altaufträge übernommen** (Schuljahre 2019/20 bis 2025/26, `php artisan auftragsbilanz:import`). Die Excel kannte kein Datum je Auftrag; diese Zeilen tragen das Schuljahresende und sind als geschätzt gekennzeichnet.
- **Fund beim Übernehmen:** In der Excel war die Spalte „Schuljahr" ab Zeile 365 als Zahlenreihe fortgeschrieben (`2025-27`, `2025-28`, …). Dadurch fielen 18 Aufträge aus jeder Auswertung — die Schuljahresbilanz wies für 2025/26 **1.275,84 €** aus statt **48.166,49 €**. Beim Import begradigt, ein Test hält die Jahresverteilung fest.
- **Online-Einnahmen werden automatisch gepflegt:** Hängt ein Auftrag an einem Bestellfenster, füllt die Software den Online-Betrag aus dem Webshop — dieselbe Rechnung wie im Statistikmodul. Nachgetragen wird nach dem Seitenaufruf und über `php artisan auftragsbilanz:sync`; die Seite wartet nie auf den Shop.
- **Abweichungshinweis:** Oben im Modul steht je Schuljahr, was der Shop meldet und was eingetragen ist — mit Warnung, wenn beides auseinanderläuft. Die Altwerte aus der Excel bleiben als Vergleich stehen. Die Schuljahresbilanz in den Statistiken hat dafür eine eigene Spalte „Shop meldet".
- **Die Übernahme läuft beim Deploy von selbst** (Migration) — kein Befehl von Hand. `php artisan auftragsbilanz:import` gibt es weiterhin, es überschreibt aber nur mit `--overwrite`, damit ein zweiter Lauf keine von Hand nachgetragenen Beträge zurücksetzt.
- **Neuer Kontrollbefehl `php artisan auftragsbilanz:abgleich`** — hält die eingetragenen Online-Einnahmen gegen das, was der Shop meldet: je Schuljahr, mit `--schulen` auch je Schule, dazu die Liste der Aufträge, für die nichts nachgetragen werden kann. Er darf als einziger Weg fehlende Monate nachladen, weil er auf der Konsole läuft.

### Statistik: beide Welten verheiratet (v26)
- **Zwei Quellenschalter** über der Auswertung: Shop-Umsätze und sonstige Umsätze (Bargeld, Direktverkäufe, händisch erfasste Aufträge). Doppelt gezählt wird nichts — ein Auftrag, dessen Online-Einnahmen aus dem Shop stammen, steuert nur seinen Bargeldanteil bei. Mit ausgeschalteter Shop-Quelle läuft die Seite auch dann, wenn WooCommerce nicht erreichbar ist.
- **Neue Auswertungen aus der Excel:** Wirtschaftlichkeit der Saison (Gewinn, Marge, Ausgaben, Provision, Ø je Auftrag), größte Aufträge, Schulen mit Umsatz und Gewinn, Schuljahresbilanz über alle Jahre und verkaufte Teile je Schuljahr mit Ø je Auftrag.
- **Das Schuljahr läuft jetzt vom 1. August bis 31. Juli** statt 1. September bis 31. August — das Geschäftsjahr des Hauses, nach dem auch die Jahresbilanzen gerechnet werden. Beide Module verwenden denselben Stichtag. **Achtung beim Deploy:** Danach einmal „↻ Daten neu laden" anstoßen, damit sich alte und neue Jahreszuordnung nicht mischen.

### Modul 4: Saisonziel und Planung
- **Das Zielumsatz-Feld ist kein Filter mehr**, sondern eine gespeicherte Vorgabe je Schuljahr (`SeasonGoal`): einmal eingetragen gilt sie für alle im Team, bis sie jemand ändert.
- **Umsätze außerhalb des Webshops** lassen sich eintragen — bereits erzielte zählen zum Ist, zusätzlich erwartete nur in die Hochrechnung. Dazu eine freie Notiz.
- **Bedarfsrechnung:** Unter der Prognose steht, wie viele Bestellfenster bis zum Ziel noch fehlen — je Art getrennt, weil Sammelbestellfenster und On-Demand-Shops unterschiedlich viel bringen. Der Ø je Fenster kommt aus den abgeschlossenen Fenstern der laufenden Saison und des Vorjahres; laufende zählen nicht mit.
- **Fensterzahlen je Art:** Die beiden Fenster-Kacheln zeigen jetzt, wie viele Fenster der Saison schon gelaufen sind und wie viele gerade laufen.
- **Datenstand:** Über der Filterzeile steht, von wann die angezeigten Zahlen stammen (Zeitpunkt des ältesten Bausteins).

### Code-Review: behobene Befunde (v17–v22)
- **Marge rechnete Brutto gegen Netto.** Die Shop-Preise sind Bruttopreise, die Printify-Kosten netto — jede angezeigte Marge lag rund 20 Prozentpunkte zu hoch. Verglichen wird jetzt netto gegen netto (`schoolshop.printify.vat_rate`), angezeigt wird der Mindestpreis brutto.
- **Doppelanlage ausgeschlossen:** Sperre je Antrag um „Shop anlegen", Nachladen innerhalb der Sperre, und alle externen IDs (WooCommerce-Produkt, Printify-Produkt, jedes Mockup) werden sofort nach dem Aufruf gespeichert. Bezahlte Mockup-Renders gehen bei einem Abbruch nicht mehr verloren.
- **Fremde Schulen werden nicht mehr mit geschlossen:** Die Namenssuche im Shop ist eine Teilstring-Suche („HAK Wien" trifft „HAK Wien 13") und wird jetzt auf die Produktnamen dieser Schule eingegrenzt.
- **Datumswerte aus dem Formular werden zurückgerechnet:** `31.02.2026` galt vorher als 03.03., `04/16/2026` als 04.04.2027 — beides wanderte in den Schule-Eintrag, auf das gedruckte Blatt und in die Statistik. Unklare Werte gelten jetzt als unbekannt und erscheinen als Aufgabe auf der Startseite.
- **Keine Seite wartet mehr auf eine Schnittstelle:** Fensterverlängerung (Startseite), Bestellzahlen (Antragsseite) und Statistik laden nach der Antwort. Zwei unbegrenzte Seitenschleifen im Schreib-Client haben jetzt dieselbe Notbremse wie `fetchAllPages()`.
- **Zustandsfelder im CPT gehören ihren Aktionen:** Ein erneutes „Shop anlegen" nimmt „Fenster öffnen" und die On-Demand-Nachbearbeitung nicht mehr stumm zurück.
- **Bestellfenster-Durchschnitte zählen jetzt je Antrag** statt je Shop-Kategorie — der Umsatz früherer Fenster derselben Schule fehlte vorher.
- **Klassenlisten** werden an Zeilenumbrüchen getrennt (das Feld ist mehrzeilig); vorher entstand eine einzige Auswahloption mit Zeilenumbrüchen darin.
- **QR-Code des Präsentationsblatts** nutzt den echten Kategorie-Slug aus dem Shop statt einer aus dem Schulnamen abgeleiteten Adresse.
- **Erstattungen werden beziffert:** Unter dem Umsatz steht, wie viele Bestellungen eine Erstattung enthalten und über welchen Betrag. Abgezogen wird nichts — eine Erstattung betrifft oft nur den Versand oder eine einzelne Position und ließe sich keiner Produktart zuordnen.
- Weiter: Anmeldung und Webhook gedrosselt, Eingabegrenzen für Preise/Farben/Größen, Medien-Upload mit Typ- und Größenprüfung, Sicherung mit Sperre und Platzprüfung, kurze Zeitabläufe für die Verbindungstests, erklärte Fehlerseiten statt nackter 500er.

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
