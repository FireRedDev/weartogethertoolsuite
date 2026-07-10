# Agentic Intent Spezifikation — „Wear Together Order Suite" (Nachfolger der Wear Together Toolsuite)

**Version:** 1.0 · **Datum:** 2026-07-10 · **Status:** Zur Umsetzung freigegeben
**Referenz-Implementierung:** `wear_together_toolsuite.py` @ Commit `cff1227` (HEAD von `master`)
**Referenzdaten:** AHS Korneuburg (Input `orders20260522091722.xlsx` + 3 Excel-Reports + 1 PDF)

---

## 1. Mission / Kurzbeschreibung

Die **Wear Together Order Suite** ist eine Web-Anwendung, die Bestell-Exporte aus dem
Wear-Together-Shop (WooCommerce-XLSX-Export) in einem Schritt in vier fertige
Auftragsdokumente verwandelt:

1. **Lieferanten-Report** (`*_orderreport_supplier.xlsx`) — Produktionsgrundlage für den Textil-Lieferanten
2. **Interner Report** (`*_orderreport_internal.xlsx`) — Arbeits- und Prüfdokument inkl. Qualitäts-Prüfspalte
3. **Kunden-Report** (`*_orderreport_customer.xlsx`) — Übersicht + Provisionsinformation für die Schule/Organisation
4. **Verteil-PDF** (`*_orderreport.pdf`) — druckbare Stückliste zum Abhaken bei der Ausgabe

Sie ersetzt das bestehende lokale Python/Tkinter-Desktop-Tool durch eine moderne,
auf **RunCloud** gehostete Web-App. Die fachliche Transformationslogik und die
Standard-Outputs werden **exakt** vom Bestandsskript übernommen (Kapitel 4 ist
normativ); Bedienung, Robustheit und Konfigurierbarkeit werden modernisiert.

**Zielgruppe:** 1–5 interne Mitarbeiter:innen von Wear Together. Keine öffentliche Nutzung.

---

## 2. Ist-Zustand (Analyse des Bestandssystems)

### 2.1 Bestehendes Tool

- Python 3 + Tkinter-GUI (`wear_together_toolsuite.py`, ~380 Zeilen), lokal ausgeführt oder als PyInstaller-EXE.
- Ablauf: Datei-Dialog → Eingabe Kundenname → Eingabe Lieferanteninfo → Zielordner → Verarbeitung → 3×XLSX + 1×PDF → Vorschau-Tabelle (pandastable).
- Bibliotheken: pandas, openpyxl, matplotlib (PDF), pandastable.

### 2.2 Schwächen des Bestandssystems (Motivation der Ablöse)

- Installation/Update auf jedem Arbeitsrechner nötig (Python, pip, PyInstaller); README dokumentiert genau diese Hürden.
- Keine Validierung des Inputs → kryptische Fehler-Popups (`messagebox` „Fehler bei der Verarbeitung") mit Traceback nur auf der Konsole.
- Hartkodierte Fachdaten im Code (Lieferanten-Artikelmapping, Größenliste, Kartongröße 20, Provisionstabelle).
- Stille Datenfehler möglich (z. B. unbekannte Größen werden zu `NaN` und fallen aus der Pivot-Übersicht heraus, siehe 4.9).
- Kein Verlauf, keine Nachvollziehbarkeit, keine zentrale Version.

### 2.3 Wichtiger Befund zu den Referenzdateien

Die mitgelieferten AHS-Korneuburg-**Output**-Beispiele stammen nachweislich von einer
**älteren Programmversion** (Stand ~Commit `8da7d3b`), nicht vom aktuellen HEAD:

| Merkmal | Beispieldateien (alt) | Aktueller Code HEAD (normativ) |
|---|---|---|
| `ID`-Spalte in Orders | fehlt | vorhanden (Spalte 1, fortlaufend ab 1) |
| Spalte `Unterschrift` | vorhanden (internal) | existiert nicht mehr |
| Prüfspalte `⚠ Fehlender Individualisierungstext` | fehlt | vorhanden (nur internal) |
| Customer-Report | nur `Übersicht_Tabelle` + `Provisionsinformationen` | alle 4 Sheets |
| Supplier-Orders ohne Namensspalten | ja | nein — alle Reports enthalten identische Orders-Spalten |
| Pandas-Indexspalte in Orders/Liste | mitgeschrieben („Unnamed: 0") | `index=False` |

**Festlegung:** Normativ ist der **aktuelle Code (HEAD, `cff1227`)**. Die Beispieldateien dienen
zur Verifikation der Transformationslogik (Sortierung, Kartonzuteilung, Individualisierungstexte,
Pivotwerte — alle verifiziert identisch), nicht der Sheet-/Spaltenstruktur.
Die alte, empfängerspezifische Spaltenreduktion kehrt als **konfigurierbares Feature** zurück (Kap. 7), Standard bleibt HEAD-Verhalten.

---

## 3. Kernfunktionen

| # | Funktion | Beschreibung |
|---|---|---|
| K1 | **Upload & Parsing** | XLSX-Upload (Drag & Drop + Dateiauswahl, `.xlsx`/`.xltx`), Parsing des Shop-Exports gemäß Eingabekontrakt (4.2). |
| K2 | **Auftragskopf erfassen** | Kundenname (Schule/Organisation, wird Dateinamens-Präfix) und Freitext „Informationen für den Lieferanten". |
| K3 | **Transformation** | Exakte Umsetzung der Referenz-Pipeline (4.3): Klassen-Extraktion, Mengen-Expansion, Sortierung, Individualisierungstext-Extraktion, Prüfspalte, Kartonzuteilung, Pivot-Übersichten, Lieferanten-Artikelmapping. |
| K4 | **Provisionsberechnung** | Exakte Staffel gemäß 4.4; Ergebnis landet im Kunden-Report. |
| K5 | **Dokumentgenerierung** | 3 XLSX-Reports + 1 PDF gemäß 4.5–4.8; Download einzeln und als ZIP-Paket. |
| K6 | **Validierung & Prüfbericht** *(neu)* | Vor der Generierung: Pflichtspalten-Check, Wertelisten-Check (Größen, Individualisierung), Warnliste (fehlende Individualisierungstexte, unbekannte Größen, leere Klassen). Blockiert nie den Standardfall, macht Probleme aber sichtbar. |
| K7 | **Ergebnis-Vorschau** *(modernisiert)* | Interaktive Tabellen-Vorschau der transformierten Bestellliste und der Übersichts-Pivot im Browser (ersetzt pandastable-Fenster). |
| K8 | **Output-Profile** *(neu)* | Konfigurierbare Abweichungen vom Standard-Output (Spaltenauswahl je Empfänger, Kartongröße, Artikelmapping, Größensortierung) — Standardprofil erzeugt exakt den Legacy-Output. |
| K9 | **Auftragsverlauf** *(neu, optional aktivierbar)* | Liste der zuletzt generierten Aufträge (Kundenname, Datum, Stückzahl, erneuter Download bis zur Aufbewahrungsfrist). |

---

## 4. Fachliche Referenzlogik (NORMATIV — exakt aus dem Bestandsskript)

> Dieses Kapitel ist der Kern der Spezifikation. Jede Abweichung im Standardprofil ist ein Bug.
> „Exakt gleich" bedeutet: **identische Sheets, Sheet-Reihenfolge, Spalten, Spaltenreihenfolge,
> Zellwerte, Zeilenreihenfolge, Spaltenbreiten und benannte Formatierungen** — nicht Byte-Identität
> der Dateien (unterschiedliche Writer-Bibliotheken erzeugen unterschiedliche interne XML-/PDF-Streams).

### 4.1 Ein-/Ausgaben im Überblick

- **Input:** 1 XLSX (erstes Sheet) — Shop-Export, 1 Zeile = 1 Bestellposition mit Menge.
- **Parameter:** `Kundenname` (String), `Auftragsinformationen` (String, für Lieferant/intern).
- **Output-Dateien:**
  - `{Kundenname}_orderreport_supplier.xlsx`
  - `{Kundenname}_orderreport_internal.xlsx`
  - `{Kundenname}_orderreport_customer.xlsx`
  - `{Kundenname}_orderreport.pdf`

### 4.2 Eingabekontrakt (Pflichtspalten des Shop-Exports)

| Spalte (exakter Header) | Bedeutung |
|---|---|
| `Item Name(löschen)` | Produktname (wird zu `Produktname`) |
| `Anzahl` **oder** `Anzahl ` (mit Leerzeichen) | Bestellmenge der Position (Header `Anzahl ` wird zu `Anzahl` normalisiert) |
| `Größe` | Konfektionsgröße (`XS`–`XXXL`) |
| `Farbe` | Farbvariante |
| `Individualisierung` | `Ja` / `Nein` |
| `Input Fields` | Roher WooCommerce-Individualisierungstext inkl. 50-Zeichen-Präfix |
| `Product Variation` | Pipe-getrennter Variationstext; 3. Segment enthält `Klasse: …` |
| `Bestellnotiz` | wird verworfen |
| `Bestellung Gesamtsumme(löschen)` | wird verworfen |
| `Vorname`, `Nachnahme (Rechnungsadresse)` | Besteller:in (Schreibweise „Nachnahme" ist im Shop-Export so!) |

Weitere Spalten (z. B. `Karton`, `Klasse`, `Individualisierungstext(…)` aus dem Export, Finanzspalten)
dürfen vorhanden sein: `Klasse`, `Karton` und `Individualisierungstext(…)` werden von der Pipeline
**überschrieben**, alle übrigen unbekannten Spalten laufen unverändert durch die Excel-Orders-Sheets mit
(im PDF werden bestimmte Finanzspalten gefiltert, siehe 4.8).

### 4.3 Transformations-Pipeline (Reihenfolge ist normativ)

1. **Klasse extrahieren:** `Klasse` = 3. Pipe-Segment von `Product Variation` (Split an `|`, max. 5 Teile), darin Literal-Ersetzung `"Klasse:"` → `""`. Ergebnis behält führende/nachfolgende Leerzeichen des Segments (z. B. `" 1a "` → wird faktisch als `" 1a "` sortiert; identisch zum Bestand).
2. **Header normalisieren:** `Item Name(löschen)` → `Produktname`; `Anzahl ` → `Anzahl`.
3. **Mengen-Expansion:** Jede Zeile wird `Anzahl`-mal dupliziert (1 Ausgabezeile = 1 physisches Stück). Reihenfolge der Duplikate: direkt hintereinander.
4. **Spalten verwerfen:** `Anzahl`, `Product Variation`, `Bestellnotiz`, `Bestellung Gesamtsumme(löschen)`.
5. **Größen-Ordnung:** `Größe` wird kategorial mit der geordneten Liste `XS < S < M < L < XL < XXL < XXXL`. Werte außerhalb der Liste werden „unbekannt" (Legacy: `NaN`, siehe 4.9).
6. **Sortierung 1:** stabil nach `Klasse`, `Produktname`, `Farbe`, `Größe` (aufsteigend, Index neu ab 0).
7. **Individualisierungstext:** neue/überschriebene Spalte `Individualisierungstext(zählt nur wenn Individualisierung Ja)` = `Input Fields`, wenn `Individualisierung == "Ja"`, sonst `""`. Danach: als String die **ersten 50 Zeichen abschneiden** (entfernt das WooCommerce-Präfix `\nIndividualisierungstext \n(falls "Ja" ausgewählt): `), Literal `"nan"` → `""`, dann `trim`. Ergebnis-Beispiele (verifiziert): `Marie`, `Luki`, `Jajings`.
8. **Prüfspalte:** `⚠ Fehlender Individualisierungstext` = `"TRUE"` wenn `Individualisierung == "Ja"` **und** Text nach Schritt 7 leer, sonst `""` (leerer String).
9. **Kartonzuteilung:** `Karton` = `floor(Zeilenindex / 20) + 1` — auf Basis der Sortierung aus Schritt 6, **20 Stück pro Karton**.
10. **Spalte verwerfen:** `Input Fields`.
11. **Sortierung 2:** stabil nach `Karton`, `Klasse`, `Produktname`, `Farbe`, `Größe` (Index neu ab 0).
12. **Konstantspalten:** `Checkbox` = `☐` (U+2610), `Anzahl` = `1` (jede Zeile = 1 Stück).
13. **Pivot „Übersicht_Tabelle":** Gruppierung nach (`Produktname`, `Farbe`, `Größe`) — nur tatsächlich vorkommende Kombinationen (observed) —, Werte: `Anzahl gesamt` = Zeilenanzahl, `Davon Personalisierungen` = Anzahl Zeilen mit `Individualisierung == "Ja"`; **Summenzeile** `Grand Totals` am Ende. Zusätzlich drei leere Spalten `Kartonnummer`, `Ausschuss`, `Anmerkungen`.
14. **„Übersicht_Liste":** die Pivot als flache Liste (Gruppenschlüssel als normale Spalten, inkl. `Grand Totals`-Zeile) plus Spalte `Produktname-Lieferant` = `Produktname` mit **Teilstring-Ersetzungen** gemäß Artikelmapping:

    | Suchbegriff (Teilstring) | Ersatz |
    |---|---|
    | Schulpullover | JH001 |
    | Schulshirt | B&C #E150 |
    | Schulzoodie | JH050 |
    | Schuljacke | JH043 |
    | Schulsweater | JH030 |
    | Schulpolo | B&C ID.001 |
    | Sportshirt | JC001 |
    | Match-Polo | JC021 |

    (Kein Treffer ⇒ Produktname unverändert, z. B. „AHS Korneuburg STICK-Hoodie".)
15. **ID-Spalte:** `ID` = fortlaufend `1..n` als **erste** Spalte der finalen Bestellliste (nach Sortierung 2).

**Finale Spaltenreihenfolge der Bestellliste** (bei Standard-Input, verifiziert):
`ID, Produktname, Karton, Vorname, Nachnahme (Rechnungsadresse), Größe, Farbe, Klasse, Individualisierung, Individualisierungstext(zählt nur wenn Individualisierung Ja), ⚠ Fehlender Individualisierungstext, Checkbox, Anzahl`
(Allgemein: `ID` vorn; übrige Spalten in Reihenfolge des Inputs nach Umbenennung/Löschung; die in Schritt 7/8/12 erzeugten Spalten hinten in Erzeugungsreihenfolge. Zusätzliche Input-Spalten bleiben an ihrer Position.)

### 4.4 Provisionsberechnung (exakt)

`n` = Anzahl Zeilen der expandierten Bestellliste (= Gesamtstückzahl). Für jedes Stück mit 0-basiertem Index `i`:

| Index `i` | Provision pro Stück |
|---|---|
| 0–49 | 0,00 € |
| 50–99 | 0,50 € |
| 100–199 | 1,00 € |
| 200–299 | 1,25 € |
| 300–499 | 1,50 € |
| ab 500 | 2,00 € |

**Mindestprovision:** Wenn Summe < 20 und `n ≥ 50` ⇒ Provision = 20.
Verifizierte Stützwerte: `n=32 → 0` · `n=49 → 0` · `n=50 → 20` · `n=120 → 45` · `n=250 → 187,5` · `n=600 → 750`.
Ausgabe als Zahl (nicht formatiert, keine Währungsangabe) in Zelle A1 des Sheets `Provisionsinformationen` (nur customer).

### 4.5 Excel-Reports — gemeinsame Struktur (alle 3 Dateien)

Sheet-Reihenfolge und Inhalt:

| # | Sheet | Inhalt | Besonderheiten |
|---|---|---|---|
| 1 | `Übersicht_Tabelle` | Pivot aus 4.3/13 **mit** Gruppenindex (3-stufig: Produktname/Farbe/Größe); wiederholte Gruppenwerte als **vertikal verbundene Zellen** (pandas-Verhalten `merge_cells=True`) | Spaltenbreite **22** für alle Spalten |
| 2 | `Übersicht_Liste` | Flache Liste aus 4.3/14, **ohne** Indexspalte | Spaltenbreite **22** |
| 3 | `Orders` | Finale Bestellliste aus 4.3, **ohne** Indexspalte. Spalte `⚠ Fehlender Individualisierungstext` **nur im internal-Report**; in supplier und customer wird sie entfernt | Spaltenbreite **20** |
| 4 | `Provisionsinformationen` (customer) bzw. `Auftragsinformationen` (supplier, internal) | Einzelne Zelle A1: Provisionsbetrag (customer) bzw. Freitext „Auftragsinformationen" (supplier/internal) | keine Breitenanpassung |

Dateiformat: `.xlsx` (Office Open XML). Keine weiteren Formatierungen (keine Farben, keine Filter, keine Freeze Panes) — Legacy-treu.

### 4.6 Lieferanten-Report (`*_supplier.xlsx`)

Wie 4.5; Sheet 4 = `Auftragsinformationen` mit Freitext. Orders ohne Prüfspalte.

### 4.7 Interner Report (`*_internal.xlsx`)

Wie 4.5; Sheet 4 = `Auftragsinformationen`; Orders **mit** Prüfspalte `⚠ Fehlender Individualisierungstext`.

### 4.8 Verteil-PDF (`*_orderreport.pdf`)

- **Inhalt:** die finale Bestellliste als Tabelle, **ohne** folgende Spalten (falls vorhanden): `⚠ Fehlender Individualisierungstext`, `Order Total Amount without Tax`, `Order Total Fee`, `Order Line (w/o tax)`, `Order Line Subtotal`, `paypal fee`, `Stripe fee`.
- **Paginierung (exakt):** `Seiten = ceil(n / 40)`; `Zeilen_pro_Seite = floor(n / Seiten) + 1`; Seite `i` zeigt Zeilen `[i·Zeilen_pro_Seite, min((i+1)·Zeilen_pro_Seite, n))`. (Beispiel: n=34 → 1 Seite; n=80 → 2 Seiten à 41/39.)
- **Layout:** Querformat US-Letter-Proportion (11×8,5 Zoll), Tabelle zentriert, Schriftgröße 8, Spaltenbreiten automatisch am Inhalt.
- **Farben:** Kopfzeile `#b1dce4` + fett; `ID`-Spalte `#b1dce4`; Datenzeilen alternierend `#ffffff` / `#e4e4e4` (Zebra, beginnend weiß).
- **Fußzeile:** `Seite {i} von {gesamt}` zentriert, Schriftgröße 8, auf jeder Seite.
- Kopfzeile der Tabelle wird auf **jeder** Seite wiederholt.

### 4.9 Dokumentierte Legacy-Eigenheiten (im Standardprofil beibehalten, aber in K6 als Warnung sichtbar)

1. **Unbekannte Größen** (nicht in `XS…XXXL`, z. B. Kindergrößen `134/140`): sortieren ans Ende und **fehlen in beiden Übersichts-Sheets** (Pivot ignoriert unbekannte Größen). Neue Software: identisches Standardverhalten + **rote Warnung** im Prüfbericht mit Zeilenliste. Über Output-Profil (K8) kann die Größenliste erweitert werden.
2. **50-Zeichen-Schnitt** beim Individualisierungstext ist positionsbasiert. Ändert der Shop das Präfix, werden Texte verstümmelt. Prüfbericht warnt, wenn `Input Fields` bei „Ja"-Zeilen nicht mit dem bekannten Präfix beginnt.
3. **Klasse mit Leerzeichen:** Extraktion trimmt nicht; Sortierung/Anzeige entsprechen exakt dem Bestand.
4. **`Klasse`-Spalte des Inputs wird ignoriert/überschrieben** — Quelle ist ausschließlich `Product Variation`.
5. **Artikelmapping per Teilstring** (Regex-Replace): trifft auch mitten im Produktnamen.
6. Provisionswert in A1 ist **unformatiert** (z. B. `187.5`).

---

## 5. UI/UX

### 5.1 Leitidee

Ein einziger, geführter **3-Schritte-Flow** auf einer Seite — kein Fenster-Ping-Pong wie bei Tkinter (4 nacheinander aufpoppende Dialoge), keine Installation:

**Schritt 1 — Hochladen:** Großzügige Drag&Drop-Zone („Shop-Export hierher ziehen"), Dateiauswahl-Fallback, sofortiges Parsing mit Anzeige: erkannte Positionen, Gesamtstückzahl, erkannte Produkte/Größen/Klassen.

**Schritt 2 — Auftrag & Prüfung:** Formular Kundenname* + Auftragsinformationen (Freitext); darunter der **Prüfbericht** (K6): grüne Häkchen / gelbe Warnungen (z. B. „3 Positionen mit Individualisierung=Ja ohne Text — erscheinen im internen Report als TRUE") / rote Hinweise (unbekannte Größen, fehlende Pflichtspalten ⇒ blockierend nur bei Pflichtspalten). Aufklappbare Detailtabellen je Warnung. Optional: Output-Profil wählen (Standard vorausgewählt).

**Schritt 3 — Ergebnis:** Vier Download-Karten (Supplier / Internal / Customer / PDF) + „Alles als ZIP", daneben Kennzahlen (Stückzahl, Kartons, Personalisierungen, Provision) und die interaktive **Vorschau** (Tabs: Bestellliste · Übersicht) mit Suche und Spaltensortierung — Ersatz für das pandastable-Fenster.

### 5.2 UX-Anforderungen

- Vollständig **deutschsprachige** Oberfläche; Begriffe identisch zur Fachdomäne (Karton, Individualisierung, Provision …).
- Responsive (Desktop-first, tablet-tauglich); moderne, ruhige Optik; Wear-Together-Akzentfarbe (Bestand nutzt `#fb0`).
- Verarbeitung < 5 s für 1.000 Positionen; Fortschrittsanzeige während der Generierung.
- Fehlermeldungen konkret und handlungsleitend („Spalte ‚Product Variation' fehlt — bitte den Standard-Shop-Export verwenden"), niemals nur „Fehler bei der Verarbeitung".
- Keyboard-freundlich, Grundzugänglichkeit (Labels, Kontraste, Fokus-Reihenfolge).
- Login (einfaches Auth, da personenbezogene Daten von Schüler:innen verarbeitet werden).

---

## 6. Technische Leitplanken

### 6.1 Stack-Entscheidung

**Empfehlung: PHP / Laravel 12** (statt Node.js) — und ausdrücklich: **Python ist nicht erforderlich.**
Die gesamte Fachlogik ist Tabellen-Transformation + XLSX/PDF-Erzeugung; dafür ist das PHP-Ökosystem voll ausreichend:

| Baustein | Technologie | Begründung |
|---|---|---|
| Framework | **Laravel 12** (PHP ≥ 8.3) | RunCloud unterstützt PHP/Laravel erstklassig (nativer PHP-Stack, Deploy via Git, Zero-Downtime möglich); Batteries included (Auth, Validation, Storage, Queue). |
| XLSX lesen/schreiben | **PhpSpreadsheet** | Vollständige Unterstützung für Sheets, MultiIndex-artige verbundene Zellen, Spaltenbreiten. |
| PDF | **dompdf** (via `barryvdh/laravel-dompdf`), HTML/CSS-Template | Reproduziert Tabellenlayout, Zebra, Kopfwiederholung (`thead`), Seitenfuß exakt spezifikationsgemäß; kein Headless-Browser nötig. |
| UI | **Blade + Livewire 3** (+ Tailwind CSS) | Reaktiver 3-Schritte-Flow ohne separates SPA-Frontend; minimaler Betriebsaufwand. |
| Datenbank | **SQLite** (Users, optional Auftragsverlauf, Output-Profile) | Kein separater DB-Dienst nötig; auf MySQL umstellbar (RunCloud-Standard). |
| Dateien | Lokaler Storage (`storage/app`), automatische Bereinigung | s. 6.3. |

*Node.js-Alternative* (exceljs + einer HTML-zu-PDF-Lösung) wäre machbar, bietet aber keinen Vorteil und PDF-Erzeugung ohne Headless-Chrome ist dort schwächer. *Python* bliebe nur relevant, wenn Byte-Nähe der matplotlib-PDF gefordert wäre — ist sie nicht (siehe „exakt gleich"-Definition 4.0); inhaltlich/strukturell ist Parität mit PhpSpreadsheet/dompdf vollständig erreichbar.

### 6.2 Architektur

- **Schichten:** `OrderImportService` (Parsing + Validierung) → `OrderTransformationService` (Pipeline 4.3, pure PHP, ohne I/O) → `CommissionCalculator` (4.4) → `ExcelReportWriter` / `PdfReportWriter` (4.5–4.8) → Controller/Livewire nur Orchestrierung.
- Fachlogik **framework-frei und deterministisch** (reine Funktionen über Arrays/Collections) ⇒ vollständig unit-testbar, Golden-File-Tests möglich.
- Verarbeitung synchron im Request (Datenmengen sind klein); Queue-Worker optional für sehr große Dateien.
- Konfiguration (Kartongröße, Größenliste, Artikelmapping, Provisionstabelle, PDF-Spaltenfilter) als **versionierte Defaults im Code** + überschreibbar per Output-Profil (DB) — Standardprofil = exakt die Werte aus Kapitel 4.

### 6.3 Sicherheit & Datenschutz (DSGVO)

- Es werden personenbezogene Daten von (teils minderjährigen) Besteller:innen verarbeitet ⇒ **Login verpflichtend**, HTTPS erzwungen, EU-Hosting (RunCloud-Server in EU-Region wählen).
- Uploads und generierte Reports werden **standardmäßig nach 24 h automatisch gelöscht** (Scheduler); Auftragsverlauf (K9) ist opt-in und speichert nur Metadaten + Dateien bis zur konfigurierten Frist (Default 30 Tage).
- Keine Weitergabe an Dritt-APIs; Verarbeitung ausschließlich serverseitig.
- Upload-Härtung: max. 20 MB, nur `.xlsx`/`.xltx`, Parsing in try/catch mit sauberen Fehlermeldungen, kein Formel-/Makro-Execute (PhpSpreadsheet liest Werte).
- CSRF-, Session- und Rate-Limit-Schutz via Laravel-Standard.

### 6.4 Deployment (RunCloud)

- RunCloud-Webapp „PHP/Laravel", PHP 8.3+, Nginx; Deploy per Git-Push (RunCloud Git-Deployment) mit Build-Schritt `composer install --no-dev && npm ci && npm run build && php artisan migrate --force`.
- `php artisan schedule:run` als RunCloud-Cronjob (minütlich) für die Datei-Bereinigung.
- Staging- und Produktions-Umgebung; `.env` je Umgebung, keine Secrets im Repo.

### 6.5 Qualität

- PHPUnit/Pest-Tests: Unit (Pipeline-Schritte, Provision inkl. Stützwerte aus 4.4), Feature (Upload→Download-Flow), **Golden-File-Tests** (siehe DoD).
- Statische Analyse (PHPStan Level ≥ 6), Pint/PHP-CS-Fixer, CI via GitHub Actions.
- Semantische Versionierung; CHANGELOG.

---

## 7. Verbesserungen gegenüber dem Bestand (Standard-Output bleibt unverändert!)

1. **Prüfbericht (K6):** fehlende Individualisierungstexte, unbekannte Größen, unerwartetes `Input-Fields`-Präfix, leere Klassen, unbekannte `Individualisierung`-Werte — je mit betroffenen Zeilen.
2. **Output-Profile (K8):** pro Profil einstellbar, Standardprofil = Legacy exakt:
   - Spaltenauswahl je Empfänger (z. B. Supplier ohne `Vorname`/`Nachnahme`, Customer ohne Orders-Sheet — entspricht dem Verhalten der alten Beispiel-Reports, s. 2.3),
   - Kartongröße (Default 20), Größenliste/-reihenfolge, Artikelmapping (editierbar in der UI statt hartkodiert), Provisionstabelle, PDF-Spaltenfilter,
   - optionale Extras: `Unterschrift`-Spalte, Freeze-Header + Autofilter in Excel, formatierte Provision.
3. **ZIP-Gesamtdownload** aller vier Dokumente.
4. **Auftragsverlauf (K9)** mit erneutem Download und Kennzahlen.
5. **Bessere PDF-Typografie** nur als opt-in Profiloption (Standard bleibt Legacy-Look gemäß 4.8).
6. **Mehrbenutzer-fähig** (zentrale Instanz, immer aktuelle Version — kein EXE-Rollout mehr).
7. **API-Endpoint (optional, später):** `POST /api/orders` für automatisierte Generierung direkt aus dem Shop.

---

## 8. Getroffene Festlegungen

| # | Festlegung | Begründung |
|---|---|---|
| F1 | **Normative Referenz ist HEAD (`cff1227`)**, nicht die Beispiel-Outputs (ältere Version, s. 2.3). | Nutzer-Vorgabe „Logik des bestehenden Skripts exakt übernehmen"; Beispieldaten dienten der Logik-Verifikation. |
| F2 | „Outputs exakt gleich" = inhalts- und strukturidentisch (Sheets, Spalten, Werte, Reihenfolgen, Breiten, spezifizierte Farben/Layout), **nicht byte-identisch**. | Anderer XLSX-/PDF-Writer; Byte-Identität ist weder erreichbar noch fachlich relevant. |
| F3 | **Laravel/PHP statt Node.js oder Python.** | Beste RunCloud-Unterstützung, komplette Ökosystem-Abdeckung (PhpSpreadsheet, dompdf), geringster Betriebsaufwand. Python nicht nötig. |
| F4 | PDF wird über HTML/CSS-Template (dompdf) erzeugt und repliziert das Legacy-Layout (Farben, Zebra, Paginierung, Fußzeile) — Pixel-Identität zur matplotlib-Ausgabe ist nicht gefordert. | s. F2. |
| F5 | Legacy-Eigenheiten aus 4.9 bleiben im Standardprofil erhalten (inkl. stillem Pivot-Ausschluss unbekannter Größen), werden aber im Prüfbericht sichtbar gemacht. | Output-Parität vor Verhaltens-„Korrektur"; Transparenz statt stiller Änderung. |
| F6 | Kundenname wird für Dateinamen sanitisiert (nur Datei-System-sichere Zeichen; Leerzeichen → `_` optional aus), sonst unverändert übernommen. | Web-Kontext erfordert sichere Dateinamen; Legacy übernahm Eingabe roh. |
| F7 | Login verpflichtend, Auto-Löschung der Dateien nach 24 h (bzw. Verlauf-Frist). | DSGVO, Daten Minderjähriger. |
| F8 | Datenbank SQLite als Default. | Ein-Team-Tool, minimaler Betrieb; MySQL-Migration jederzeit möglich. |
| F9 | Provisionstabelle, Artikelmapping, Kartongröße=20 und Größenliste werden als konfigurierbare Defaults implementiert, mit exakt den Werten aus Kapitel 4 als Standard. | Modernisierung ohne Output-Änderung. |
| F10 | Die Web-App ersetzt das Desktop-Tool vollständig; das Python-Repo wird nach Abnahme archiviert. | Eine Quelle der Wahrheit. |

---

## 9. Definition of Done

Die Nachfolgersoftware gilt als fertig, wenn **alle** folgenden Kriterien erfüllt sind:

### 9.1 Output-Parität (Golden-File-Abnahme)
- [ ] Für den Referenz-Input `orders20260522091722.xlsx` (Kundenname „AHS_Korneuburg", Auftragsinfo beliebig) erzeugt die App drei XLSX + ein PDF, die einem mit HEAD (`cff1227`) frisch erzeugten Referenzsatz **zell-für-zell entsprechen** (Sheets, Sheet-Namen & -Reihenfolge, Spaltenköpfe & -reihenfolge, alle Zellwerte, Zeilenreihenfolge, verbundene Zellen in `Übersicht_Tabelle`, Spaltenbreiten 20/22, Sheet-4-Inhalt A1). Automatisierter Vergleichstest im CI.
- [ ] PDF enthält identische Spalten (inkl. Filter aus 4.8), identische Zeilen in identischer Reihenfolge, Paginierung nach 4.8-Formel, Kopf auf jeder Seite, Fußzeile `Seite X von Y`, Farbschema `#b1dce4`/Zebra.
- [ ] Unit-Tests decken alle Pipeline-Schritte (4.3/1–15) und die Provisions-Stützwerte (`32→0`, `49→0`, `50→20`, `120→45`, `250→187,5`, `600→750`) ab; zusätzlich mind. je ein Test für: `Anzahl`>1-Expansion, Kartonwechsel bei Stück 21/41, fehlender Individualisierungstext ⇒ `TRUE` nur internal, unbekannte Größe ⇒ Pivot-Ausschluss + Warnung, Artikelmapping-Teilstring.

### 9.2 Funktion & UX
- [ ] 3-Schritte-Flow (Upload → Prüfung/Auftrag → Downloads) vollständig; ZIP-Download; Vorschau-Tabellen; Prüfbericht mit allen Warnungstypen aus 7.1.
- [ ] Deutsche UI; verständliche Fehlermeldungen für: falsches Dateiformat, fehlende Pflichtspalten, leere Datei.
- [ ] Output-Profile: Standardprofil aktiv „ab Werk"; mindestens Spaltenauswahl je Empfänger, Kartongröße und Artikelmapping editierbar; Standardprofil ist nicht löschbar/änderbar.
- [ ] Verarbeitung eines 1.000-Positionen-Exports < 5 s (Serverzeit) im Zielsetup.

### 9.3 Betrieb & Qualität
- [ ] Deployment auf RunCloud (Staging + Prod) per Git-Deploy dokumentiert und einmal durchgeführt; Scheduler-Cron aktiv; HTTPS erzwungen.
- [ ] Login/Auth aktiv; Uploads & Outputs werden nach 24 h automatisch gelöscht (nachgewiesen per Test/Log).
- [ ] CI grün: Tests, PHPStan (≥ Level 6), Code-Style.
- [ ] README mit Setup-, Deploy- und Bedienungsanleitung (deutsch); CHANGELOG initialisiert.
- [ ] Kurze Abnahme durch Fachanwender:in mit einem realen Neu-Export (nicht dem Referenzdatensatz).

---

## Anhang A — Verifizierte Fakten aus den Referenzdaten (AHS Korneuburg)

- Input: 32 Positionen → nach Expansion **34 Stück** → **2 Kartons**.
- Provision: 0 (unter 50 Stück) — deckungsgleich mit `Provisionsinformationen!A1 = 0` im Beispiel.
- Individualisierungstexte nach 50-Zeichen-Schnitt korrekt extrahiert (`Marie`, `Luki`, `Jajings`, `Anja`, `Vali` …).
- Sortierung und Kartonzuteilung der Nachbildung deckungsgleich mit dem Beispiel-`internal`-Orders-Sheet.
- Beispiel-Produktnamen („AHS Korneuburg STICK-Hoodie" usw.) matchen keinen Mapping-Eintrag ⇒ `Produktname-Lieferant` = `Produktname` (im Beispiel bestätigt).

## Anhang B — Glossar

| Begriff | Bedeutung |
|---|---|
| Position | Zeile im Shop-Export (Produkt+Variante+Menge einer Bestellung) |
| Stück | Eine physische Textilie; Positionen werden per `Anzahl` in Stück expandiert |
| Karton | Verpackungseinheit à 20 Stück (Zuteilung nach Sortierung 1) |
| Individualisierung | Personalisierung (z. B. Name-Stick); Text kommt aus WooCommerce `Input Fields` |
| Provision | Vergütung an die Schule/Organisation gemäß Staffel 4.4 |
| Output-Profil | Benannte Konfiguration der Dokumenterzeugung; „Standard" = Legacy-exakt |
