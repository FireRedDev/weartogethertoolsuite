# Wear Together Order Suite

Web-Nachfolger der Wear Together Toolsuite (Python/Tkinter). Die Startseite
(`/`) zeigt, was gerade zu tun ist, und verlinkt die fünf Module:
**Auftragsdokumente** (Modul 1), **Schul-Onboarding** (Modul 2),
**Bestellfenster schließen** (Modul 3), **Auftragsbilanz** (Modul 4) und
**Statistiken** (Modul 5). Dazu die **Admin-Informationen** als Prüfstand für
alle Schnittstellen.

Modul 1 verwandelt den Bestell-Export aus dem Wear-Together-Shop in einem
geführten 3-Schritte-Flow in vier fertige Auftragsdokumente:

| Dokument | Datei |
|---|---|
| Lieferanten-Report | `{Name}_orderreport_supplier.xlsx` |
| Interner Report (mit Prüfspalte) | `{Name}_orderreport_internal.xlsx` |
| Kunden-Report (mit Provision) | `{Name}_orderreport_customer.xlsx` |
| Verteil-PDF | `{Name}_orderreport.pdf` |

Die fachliche Logik (Transformation, Kartons, Provision, Pivot-Übersichten)
entspricht **exakt** dem Legacy-Skript `wear_together_toolsuite.py` (siehe Branch
`backup/pre-runcloud-atomic`) — abgesichert durch Golden-File-Tests, die jede
Zelle der erzeugten Excel-Dateien gegen mit dem Legacy-Skript erzeugte
Referenzdateien vergleichen. Details: `AGENTIC_INTENT_SPEC.md`.

Dieses Repository enthält direkt im Root die Laravel-Anwendung (kein
Unterordner) — das ist die Voraussetzung für RunCloud Git/Atomic Deployment,
siehe unten.

## Hilfe im Tool: ⓘ und ausklappbare Banner

Damit die Seiten nicht in Erklärtext untergehen, steht die Hilfe dort, wo sie
gebraucht wird — nicht dauerhaft auf dem Schirm:

- **ⓘ** neben einer Überschrift, Tabellenspalte oder Schaltfläche: antippen
  (oder anklicken) öffnet einen kurzen Erklärkasten. Erneutes Antippen, ein
  Tipp daneben oder `Esc` schließt ihn wieder. Funktioniert ausdrücklich auch
  am Telefon — die früheren Mouseover-Tooltips taten das nicht.
- **▸ Ausklappbare Banner** (z. B. „Wie Logo und Druck zusammenhängen", „Was
  bei On-Demand zu beachten ist") enthalten die längeren Erklärungen und sind
  standardmäßig zugeklappt.
- Die **Startseite** bleibt bewusst ausführlich: dort steht, was die Toolsuite
  ist, was jedes Modul kann und wie der Ablauf einer Schule von der Anfrage bis
  zu den Auftragsdokumenten aussieht.

## Stack

- PHP ≥ 8.3, Laravel 13
- PhpSpreadsheet (XLSX), dompdf (PDF)
- Kein Node-Build nötig (CSS/JS sind in den Blade-Views eingebettet)
- Keine Datenbank nötig (Sessions/Cache als Dateien; Jobs liegen in `storage/app/private/jobs`)

## Lokal ausführen

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

→ http://127.0.0.1:8000

### Tests (inkl. Golden-File-Abnahme)

```bash
php artisan test
```


### Kommandozeile

```bash
php artisan orders:generate export.xlsx AHS_Korneuburg ./output --info="Liefertermin Ende Juni"
```

## Zwei Wege, Bestellungen zu laden

1. **Weg 1 — direkt aus dem Shop (empfohlen):** Die App holt die Bestellungen
   über die WooCommerce REST API (nur Lesezugriff). Auswählbar sind
   Schule/Organisation (= Produktkategorie), Bestellstatus (vorausgewählt wie
   der bisherige Plugin-Export: In Bearbeitung, In Wartestellung,
   Abgeschlossen) und optional der Bestellzeitraum. Die erzeugte Rohtabelle
   ist identisch zum bisherigen Plugin-Export (gleiche Spalten, gleiche
   Formate, Bestellungen nach Order-ID absteigend, eine Zeile pro
   Bestellposition) und kann im Ergebnis auch heruntergeladen werden.
2. **Weg 2 — Datei hochladen (wie bisher):** XLSX-Export aus dem
   WordPress-Plugin „Advanced Order Export For WooCommerce" hochladen.

Beide Wege laufen ab dem Prüfbericht identisch weiter.

## Shop-Verbindung einrichten (für Weg 1)

1. In WordPress: **WooCommerce → Einstellungen → Erweitert → REST-API →
   „Schlüssel hinzufügen"**. Beschreibung z. B. „Order Suite",
   Benutzer: ein Admin-Konto, Berechtigung: **Lesen** (mehr braucht die App
   nicht und sollte sie aus Sicherheitsgründen auch nicht bekommen).
2. Den angezeigten **Consumer Key** (`ck_…`) und das **Consumer Secret**
   (`cs_…`) sofort kopieren — das Secret wird nur einmal angezeigt.
3. In der `.env`-Datei der App eintragen und danach
   `php artisan config:cache` ausführen (bzw. neu deployen):

   ```ini
   WC_STORE_URL=https://wear-together.at
   WC_CONSUMER_KEY=ck_xxxxxxxx
   WC_CONSUMER_SECRET=cs_xxxxxxxx
   ```

Verbindungsfehler zeigt die App direkt auf der „Aus dem Shop laden"-Seite an —
mit einer verständlichen Erklärung für häufige Ursachen (falscher Schlüssel,
Shop nicht erreichbar, Firewall/Sicherheits-Plugin, Wartungsmodus) und
aufklappbaren technischen Details für den Support.

## Konfiguration (`.env`)

| Variable | Bedeutung | Default |
|---|---|---|
| `TOOL_PASSWORD` | Team-Passwort für den Zugang. **Leer = kein Login** (nur lokal empfohlen!). Die Anmeldung ist auf 5 Fehlversuche je Minute gedrosselt. | leer |
| `ORDER_RETENTION_HOURS` | Automatische Löschung von Uploads/Reports nach X Stunden (DSGVO) | 24 |
| `WC_STORE_URL` | Shop-Adresse für Weg 1 (ohne `/wp-json`) | leer (Weg 1 deaktiviert) |
| `WC_CONSUMER_KEY` / `WC_CONSUMER_SECRET` | Read-only-API-Schlüssel des Shops | leer |

Fachliche Defaults (Größenliste, Kartongröße 20, Artikelmapping,
Provisionsstaffel, PDF-Spaltenfilter) liegen in `config/ordersuite.php` —
Änderungen dort ändern den Standard-Output!

Werte, die man selten, aber gezielt anfassen will:

| Schlüssel | Bedeutung | Default |
|---|---|---|
| `ordersuite.woocommerce.max_pages` | Notbremse für seitenweise Abrufe. Ohne sie blättert eine Schleife endlos weiter, sobald der Shop immer wieder volle Seiten liefert. | 200 |
| `schoolshop.printify.vat_rate` | USt.-Satz für den Vergleich Verkaufspreis (brutto) ↔ Printify-Kosten (netto). Für alle Produkte der Normalsatz. | 0,20 |
| `schoolshop.status_timeout_seconds` | Zeitablauf der Verbindungstests auf der Admin-Seite | 5 |
| `schoolshop.mockups.budget_seconds` | Zeitbudget für die Mockups eines Produkts je Durchgang | 120 |
| `statistics.error_retry_seconds` | Wie lange ein Shop-Fehler den Statistik-Aufbau anhält | 120 |

## Deployment auf RunCloud (Git Atomic Deployment)

Jeder Deploy klont den Branch in einen neuen `releases/<timestamp>/`-Ordner,
führt das Deployment-Script darin aus und schaltet den `current`-Symlink erst
danach um — ein fehlgeschlagener Deploy legt die alte Version nie lahm, und
ein Rollback ist ein Klick zurück auf die vorherige Release. Referenzen:
[Einführung: Git & Atomic Deployment](https://runcloud.io/docs/an-introduction-to-git-atomic-deployment),
[Git-Application einrichten](https://runcloud.io/docs/setting-up-a-git-application-on-runcloud).

### 1. Web Application anlegen

Typ „PHP", PHP **8.3+** (Extensions `zip`, `gd`, `mbstring`, `xml`,
`fileinfo`, `intl` — bei RunCloud standardmäßig aktiv), Stack Nginx + PHP-FPM.

### 2. Git-Application einrichten

RunCloud → **Git** → Web Application auswählen → Repository verbinden
(GitHub) → **Branch `master`** wählen (dieses Repo hat die Laravel-App direkt
im Root, kein Unterordner — Public Path bleibt einfach `public`). Deploy-Key
bzw. Webhook gemäß RunCloud-Anleitung im GitHub-Repo hinterlegen, damit
automatisch bei jedem Push auf `master` deployt werden kann.

### 3. Atomic Deployment aktivieren

RunCloud → **Atomic Deployment** → „Deploy a Project" → die eben angelegte
Web Application auswählen → „Save Project". *Das lässt sich danach nicht mehr
rückgängig machen* — für dieses Repo ist es aber genau der gewünschte Weg.

### 4. Symlinks konfigurieren (Projekt → Symlink)

Diese Dateien/Ordner dürfen **nicht** in jeder Release neu erzeugt werden,
sondern müssen über alle Releases hinweg bestehen bleiben:

| Typ | Quelle (persistenter Ordner) | Ziel in der Release | Zweck |
|---|---|---|---|
| Config Symlink | `.env` | `.env` | Secrets/Config bleiben über Deploys hinweg gleich |
| Directory Symlink | `storage` | `storage` | Sessions/Cache/Logs & temporäre Auftragsdateien überleben einen Deploy |

### 5. Deployment-Script (Projekt → Deployment Scripts, Schritt „Before Activate New Release")

`{RELEASEPATH}` ist RunClouds Platzhalter für den neuen Release-Ordner:

```bash
cd {RELEASEPATH}
composer install --no-dev --optimize-autoloader --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
```

(`migrate` ist aktuell ein No-Op — die App nutzt derzeit keine Datenbank —,
schadet aber nicht und ist für spätere Features wie den optionalen
Auftragsverlauf vorbereitet.)

### 6. `.env` einmalig auf dem Server anlegen

Im **persistenten** Ordner, auf den der Config-Symlink zeigt (nicht in einer
`releases/`-Kopie!):

```ini
APP_NAME="Wear Together Order Suite"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://deine-domain.at
APP_LOCALE=de
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
TOOL_PASSWORD=ein-sicheres-team-passwort
ORDER_RETENTION_HOURS=24
```

Danach einmalig im aktiven Release `php artisan key:generate` ausführen (Key
landet in der `.env`, bleibt dank Symlink für alle künftigen Releases erhalten).

### 7. Cronjob (RunCloud → Cron Jobs), minütlich

Wichtig: Der Pfad muss auf den **stabilen** Pfad der Web Application zeigen
(RunCloud hält dort automatisch den `current`-Symlink aktuell), **nicht** auf
einen `releases/<timestamp>`-Ordner:

```
* * * * * cd /home/runcloud/webapps/DEINE-APP && php artisan schedule:run >> /dev/null 2>&1
```

Das führt u. a. die stündliche DSGVO-Bereinigung `orders:cleanup` aus.

### 8. SSL aktivieren

RunCloud → SSL (Let's Encrypt) und HTTPS-Redirect einschalten.

### Rollback

Bei Problemen mit einer Release: RunCloud → Atomic Deployment → Projekt →
vorherige Release auswählen → „Activate". Der `current`-Symlink zeigt dann
sofort wieder auf die alte, funktionierende Release.

## Modul 2: Schul-Onboarding

Automatisiert den Bestellablauf für neue Schulen — vom Webshopstartfragebogen
(FluentForms) bis zur fertigen Shop-Anlage. Eigener Bereich in der Navigation.

**Ablauf:**

1. **Eingang:** FluentForms-Webhook (Formular „Webshopstartfragebogen") legt
   automatisch einen Onboarding-Antrag an. In FluentForms unter
   Integrationen → Webhook die URL
   `https://DEINE-TOOL-DOMAIN/webhooks/fluentforms/<FLUENTFORMS_WEBHOOK_SECRET>`
   eintragen (Request Format JSON, alle Felder senden). Alternativ: Schule
   manuell anlegen.

   **URL testen:** Dieselbe URL im Browser öffnen (GET). Kommt
   `{"ok":true,...}`, stimmen URL und Secret — dann liegt ein Problem an der
   FluentForms-Seite (Trigger/Feldübertragung). Kommt **404**, ist das Secret
   in der URL falsch oder `FLUENTFORMS_WEBHOOK_SECRET` nicht gesetzt; kommt
   **503**, ist auf dem Server gar kein Secret konfiguriert. Jeder Aufruf wird
   in `storage/logs/laravel.log` protokolliert. Schlägt die automatische
   Zuordnung einer Einsendung fehl, geht sie **nicht verloren**: Der
   Rohdatensatz wird trotzdem als Antrag gespeichert (mit Warnhinweis und
   einsehbaren Rohdaten in „Anfrage-Daten"), sodass er in der Schulliste
   auftaucht und manuell nachbearbeitet werden kann.

   **Doppelte Einsendungen** werden erkannt: Kommt dieselbe Submission ein
   zweites Mal an (Wiederholung durch FluentForms, doppelt gedrückter
   Absendeknopf), verweist die Antwort auf den bestehenden Antrag, statt einen
   zweiten anzulegen. Der Webhook ist zudem auf 60 Aufrufe je Minute
   gedrosselt.

   **Das Bestellfenster-Datum wird streng gelesen.** Unmögliche oder
   mehrdeutige Angaben (`31.02.2026`, `04/16/2026`) werden **nicht** stumm
   umgerechnet, sondern als unbekannt behandelt — PHP würde daraus sonst den
   03.03.2026 bzw. den 04.04.**2027** machen, und dieser falsche Tag stünde
   später im Schule-Eintrag, auf dem gedruckten Präsentationsblatt und in der
   Statistik. Solche Anträge erscheinen auf der Startseite unter
   „Bestellzeitraum fehlt" und wollen von Hand ergänzt werden.
2. **Konfigurator:** Produkte (Vorlagenkatalog aus den bisherigen
   Musterschule-Excel-Vorlagen), Preise, Individualisierungs-Aufpreis, Größen,
   Farben, Klassenliste, Bestellfenster und Lieferart anpassen — alles
   vorbefüllt aus den Formularwünschen. Über „+ Produkt hinzufügen" lassen
   sich auch Produkte anlegen, die nicht im Vorlagenkatalog stehen (Name,
   Preis, Größen, Farben frei eintragen). Bestellfenster und Klassenliste
   werden bei Lieferart On-Demand ausgeblendet (siehe unten).

   **Klassenliste:** Eine Klasse je Zeile oder durch Komma bzw. Semikolon
   getrennt — alle drei Schreibweisen funktionieren. Aus jeder Klasse wird eine
   Auswahloption im Shop.

   **Grenzen der Eingaben:** Preise zwischen 0 und 9.999 €, höchstens 60
   Größen bzw. Farben à 40 Zeichen. Das ist keine Schikane: Aus Farben und
   Größen entstehen in WooCommerce **shopweite** Attribut-Terms, die für alle
   Schulen gelten und sich über das Tool nicht wieder löschen lassen.
3. **Shop-Anlage** (ein Klick, mit Vorschau/Dry-Run): Produktkategorie
   „Schulen > {Name}", variable Produkte mit Variationen (Individualisierung
   Ja/Nein), Individualisierungs-Eingabefeld (Product Input Fields),
   Versandklasse (On-Demand) und Pods-CPT-Eintrag „schule". Jeder Schritt wird
   protokolliert; bei Fehlern bricht die Anlage ab und kann nach Behebung
   fortgesetzt werden (bereits Angelegtes wird übersprungen).

   Der Vorgang läuft **nur einmal gleichzeitig** je Schule. Ein zweiter Klick
   während des laufenden Vorgangs wird mit einer Meldung abgewiesen, statt
   alles ein zweites Mal anzulegen. Jede extern vergebene ID (Kategorie,
   Produkt, Printify-Produkt, jedes Mockup) wird sofort nach dem Aufruf
   gespeichert — bricht der Vorgang danach ab, kennt das Tool das bereits
   Angelegte und legt es beim nächsten Versuch nicht erneut an.
4. **Sammelbestellfenster:** Bestellemail an die Partnerdruckerei nach Vorlage
   (inkl. Lieferanten-Artikelnummern), zum Kopieren oder per mailto.
   **On-Demand:** Die Produkte werden in Printify angelegt und in den Shop
   published (statt direkt in WooCommerce). Blueprint-ID und Print-Provider-ID
   sind für den ganzen Katalog bereits in `config/schoolshop.php` hinterlegt
   und werden im Konfigurator automatisch vorbefüllt — bei Bedarf pro Schule
   änderbar. Neue IDs lassen sich direkt im Konfigurator suchen (🔍-Button
   neben den beiden Feldern, sucht live im Printify-Katalog — kein SSH/Terminal
   nötig), alternativ am Server mit `php artisan printify:check
   --blueprints=… / --providers=…` oder direkt auf printify.com nachsehen
   (das ⓘ an den Spaltenköpfen fasst das zusammen). Ablauf:
   „Im Shop anlegen" prüft automatisch die Marge (Verkaufspreis **netto** ≥
   (Produktionskosten + Versand) × 1,10, sonst Abbruch mit Rechnung) und
   published → einige Minuten warten, bis Printify die Shop-Produkte erstellt
   hat → „On-Demand-Nachbearbeitung" klicken: setzt Versandklasse `on-demand`
   und die Schul-Kategorie auf allen Produkten der Schule und meldet das im
   Pods-Eintrag als erledigt.

   Provider-Wahl je Produkt (Stand heute; bei neuen Blueprints ggf. anders):
   Hoodie, Zoodie, Sweater, Kids-Hoodie, Schulshirt(-Kids) laufen über
   **Textildruck Europa** (EU-Versand). Für Schuljacke, Schulpolo, Sportshirt
   und Match-Polo bietet Printify aktuell **keinen EU-Provider** an — dort ist
   ein US-Provider hinterlegt (längere Lieferzeit/höhere Versandkosten in die
   Marge einkalkulieren, oder im Konfigurator auf einen anderen Provider
   umstellen, falls verfügbar).

   **Produktbeschreibung bei Printify-Produkten:** Verwendet standardmäßig
   dieselbe Beschreibung wie die Sammelbestellfenster-Produkte
   (`config/schoolshop.php` → `catalog.<key>.description`). Passt diese nicht
   exakt zum tatsächlich gewählten Blueprint, lässt sich pro Produkt eine
   eigene `printify_description` hinterlegen (deutsche Übersetzung der
   offiziellen Printify-Katalogbeschreibung des Blueprints) — abrufbar mit
   `php artisan printify:check --description=92,91,…` (kommagetrennte
   Blueprint-IDs, zeigt die Original-Beschreibung aus dem Printify-Katalog,
   meist Englisch).

   **Varianten:** Angelegt werden nur Varianten in den im Konfigurator
   gewählten **Farben und Größen** (deutsche Namen werden über
   `config/schoolshop.php` → `printify.color_aliases` / `size_aliases` auf die
   englischen Katalogwerte abgebildet). Das ist doppelt wichtig: Printify
   erlaubt maximal 100 Varianten pro Produkt, und die automatisch erzeugten
   Vorschaubilder zeigen sonst Farben, die die Schule gar nicht bestellt hat.
   Findet sich zu keiner gewünschten Farbe/Größe ein Treffer, bricht die
   Anlage mit einer Meldung ab, die die tatsächlich verfügbaren Werte auflistet.
   Einzelne nicht gefundene Farben/Größen werden nur im Protokoll vermerkt.
   Übersteigt die Auswahl die 100 Varianten, wird **reihum je Farbe** gekürzt,
   damit keine gewünschte Farbe ganz herausfällt; passiert das trotzdem (mehr
   Farben als Variantenplätze), stehen die entfallenen Farben im Protokoll.

   **Kostenübersicht im Konfigurator:** Je On-Demand-Produkt zeigt die Tabelle
   Region des Print-Providers, **Einkaufspreis** (Produktionskosten je Stück,
   als Spanne über die angelegten Varianten), **Versand** (erster Artikel nach
   Österreich — das ⓘ nennt Herkunfts- und Zielländer des Versandprofils)
   und die **Marge**. Rot bedeutet: unter der Mindestmarge, die Shop-Anlage
   würde das Produkt ablehnen; daneben steht der nötige Mindestpreis (brutto,
   also direkt vergleichbar mit dem Preis im Konfigurator). Die Werte kommen
   live aus dem Printify-Katalog und sind 24 h gecacht; unmittelbar vor dem
   Anlegen werden sie frisch geholt, damit die Preisprüfung nicht mit
   tagesalten Kosten läuft.

   **Brutto und netto — wichtig für die Marge:** Der Preis im Konfigurator ist
   der Bruttopreis, den die Kundin im Shop sieht. Die Kosten von Printify sind
   Nettopreise. Verglichen wird deshalb netto gegen netto: Der Verkaufspreis
   wird vor dem Vergleich durch `1 + schoolshop.printify.vat_rate` geteilt
   (Standard 20 % USt., für alle Produkte der Normalsatz). Ohne diese
   Umrechnung sähe jede Marge rund 20 Prozentpunkte besser aus, als sie ist —
   ein Produkt mit „+10 % Marge" wäre in Wahrheit ein Verlustgeschäft.

   On-Demand-Produkte werden laufend einzeln an die Privatadresse der
   Kund:innen verschickt — es gibt kein Bestellfenster und keine Klassenliste
   (die für die Sammelbestellung sonst als Lieferziel dient). Beide Felder
   sind im Konfigurator bei Lieferart On-Demand ausgeblendet; im Pods-Eintrag
   wird stattdessen ein durchgehend offenes Fenster (01.01.2000–01.01.2099)
   hinterlegt.

### Status eines Antrags

Der Status beschreibt, was im Shop tatsächlich passiert ist — er ist kein frei
setzbares Etikett:

| Status | Bedeutung | Wie man hinkommt |
|---|---|---|
| **Neu** | Antrag eingegangen, noch nicht bearbeitet. Nichts im Shop. | automatisch beim Formular-Eingang |
| **In Bearbeitung** | Konfigurator wird befüllt. Im Shop existiert noch nichts. | erstes Speichern im Konfigurator |
| **Im Shop angelegt** | Kategorie, Produkte und Schule-Eintrag stehen — es kann bestellt werden. | nur über **Im Shop anlegen** |
| **Abgeschlossen** | Bestellfenster zu, Produkte auf privat. | nur über **Bestellfenster schließen** |

Die letzten beiden lassen sich **nicht von Hand** im Auswahlfeld setzen: sonst
stünde im Tool „angelegt", ohne dass es im Shop etwas gäbe. Möglich sind dagegen:

* *Neu* → *In Bearbeitung*
* *In Bearbeitung* → *Abgeschlossen*, solange **nichts** angelegt ist (Absage, Dublette)
* *Im Shop angelegt* → *In Bearbeitung* (nachbessern; die Shop-Inhalte bleiben,
  ein erneutes Anlegen überspringt Vorhandenes)
* *Abgeschlossen* → *Im Shop angelegt* nur über **Bestellfenster wieder öffnen**

### Automatische Nachfrist für Sammelbestellfenster

Läuft ein Sammelbestellfenster ab, verlängert das Tool es **einmalig** um eine
einstellbare Zahl von Tagen (Standard 7) — und zwar erst, wenn es tatsächlich
abgelaufen ist. Nachzügler können dann noch bestellen; endgültig geschlossen
wird weiterhin bewusst über Modul 3. Im Konfigurator lässt sich das je Schule
abwählen und die Dauer ändern. Das neue Ende wird auch in den Schule-Eintrag
geschrieben.

Ausgeführt wird das beim Aufruf der Startseite (höchstens stündlich) und
zusätzlich per Cron — letzteres empfohlen, damit es auch dann greift, wenn
tagelang niemand das Tool öffnet:

```
0 6 * * *  cd /pfad/zur/app && php artisan windows:extend >> /dev/null 2>&1
```

Ein von Hand geändertes Enddatum gibt die Verlängerung wieder frei.

### Was das Tool sonst noch abnimmt

* **Startseite = Aufgabenliste.** Was heute ansteht: Fenster, die ablaufen oder
  abgelaufen aber noch offen sind, Anträge ohne Bestellzeitraum, unbearbeitete
  Anträge, fehlende Präsentationsblätter, geschlossene Fenster ohne
  Auftragsdokumente.
* **Bestellzahlen** je Schule (Bestellungen und Teile im Bestellzeitraum),
  abgeglichen mit der im Formular erwarteten Anzahl. Sie werden **nach** dem
  Seitenaufbau geholt und 15 Minuten gehalten — beim allerersten Aufruf einer
  Schule erscheinen sie deshalb erst beim nächsten Laden. Grund: Der Abruf
  braucht eine eigene Abfrage je Produkt der Schule und würde die Seite sonst
  minutenlang aufhalten.
* **Auftragsdokumente per Klick** aus dem Antrag heraus — Kategorie und Zeitraum
  vorbefüllt; der Export wird am Antrag vermerkt.
* **E-Mail an die Schule** als Vorlage: Link, Zeitraum, Produktliste; das
  Präsentationsblatt kommt als Anhang dazu.
* **Folgejahr per Klick** — Produkte, Preise, Farben und Logos werden
  übernommen, Bestellfenster und Klassenliste beginnen neu.
* **Bestellseite prüfen** — ruft die Adresse ab, auf die der QR-Code zeigt, und
  meldet 404 oder eine Seite ohne Produkte, bevor das Blatt aushängt. Die
  Adresse stammt aus dem echten Kategorie-Slug des Shops; nur bei Schulen, die
  vor dieser Version angelegt wurden, wird sie noch aus dem Namen abgeleitet
  (bei Umlauten schreibt WordPress anders um — dort lohnt die Prüfung
  besonders, oder einmal „Im Shop anlegen" erneut ausführen).
* **Logo-Qualitätsprüfung** beim Upload: Warnung bei zu geringer Auflösung oder
  nicht freigestelltem Hintergrund. Beides sieht man erst auf dem Textil.
* **Datensicherung** — Datenbank und Uploads als ZIP, im Admin-Bereich
  herunterladbar oder per Cron:
  `30 3 * * * cd /pfad/zur/app && php artisan backup:create`

**Hinweis „Im Checkout anzeigen" (German Market):** Größe, Farbe, Klasse und
Individualisierung werden als Variationsattribute angelegt — die Auswahl der
Kund:innen erscheint dadurch automatisch im Warenkorb/Checkout und in der
Bestellung. Die zusätzliche German-Market-Checkbox pro Eigenschaft ist über
die WooCommerce-API nicht setzbar (internes Meta); falls sie gebraucht wird:
entweder pro Produkt manuell setzen oder global unter WooCommerce →
German Market → Allgemein → Produkte die Option für Produkteigenschaften im
Checkout aktivieren.

**Schullogo & Druck:** Das Logo ist im FluentForms-Formular **kein
Pflichtfeld** — im Antrag lässt es sich deshalb jederzeit selbst hochladen,
als Vorschaubild ansehen, herunterladen und austauschen. Es gibt zwei Drucke:

* **Frontprint** und **Backprint** lassen sich einzeln an-/abwählen
  (vorbelegt aus dem Formularwunsch, danach frei änderbar).
* Der Logo-Upload der Kund:innen gilt automatisch für **beide** Drucke; pro
  Druck kann aber eine eigene Datei hinterlegt werden (z. B. kleines Logo
  vorne, großer Schriftzug hinten).
* Je Druck sind **Position** (mittig, mittig links/rechts, links/rechts oben,
  links/rechts unten) und **Größe** (klein ≈ 25 %, mittel ≈ 50 %, groß ≈ 90 %
  der Druckbreite) einstellbar. Diese Werte steuern sowohl die
  Printify-Platzierung als auch die Mockup-Erzeugung (Mockups nutzen den
  Frontprint).

Erlaubt sind PNG, JPG und WebP bis 5 MB (kein SVG — Printify und Dynamic
Mockups brauchen ein Pixelformat). Jedes hochgeladene Logo wird zusätzlich in
die **WordPress-Mediathek** gelegt: nur diese Adresse ist von außen erreichbar,
und Printify bzw. Dynamic Mockups laden die Datei selbst herunter. Scheitert
das (z. B. WordPress nicht erreichbar), bleibt die lokale Kopie erhalten und
der Fehler wird angezeigt.

**Produktfotos (Mockups, optional):** Im Konfigurator lässt sich pro Schule
„Produktfotos erzeugen" anhaken (Standard: aus). Beim Anlegen rendert die App
dann über die **Dynamic-Mockups-API** pro Produkt 1–2 Model-Fotos (bevorzugt
eine Frau und ein Mann; die Auswahl wechselt von Schule zu Schule, bleibt aber
pro Schule stabil) sowie Detailansichten in den gewählten Schulfarben — jeweils
mit dem Schullogo an der Position und in der Größe des **Frontprints**
(siehe „Schullogo & Druck" oben) — und setzt sie als Produktbild + Produktgalerie.
Einrichtung:

1. `DYNAMIC_MOCKUPS_API_KEY` in der `.env` setzen (app.dynamicmockups.com → API),
   `php artisan config:cache`.
2. Einmalig Vorlagen kuratieren: im Dynamic-Mockups-Dashboard passende
   Mockups (Model-Fotos + Produktfotos, idealerweise den echten
   AWDIS/Gildan-Produkten ähnlich — eigene PSD-Uploads sind möglich) zu
   „My Templates" hinzufügen, dann `php artisan mockups:check` (Liste) bzw.
   `--mockup=UUID` (Smart-Object-UUIDs) ausführen und die UUIDs in
   `config/schoolshop.php` → `mockups.templates` je Produkt eintragen
   (`model: female/male` bei Model-Fotos, `color` bei Detailfotos — mehrere
   Einträge pro Produkt = mehr Abwechslung zwischen Schulen).
3. Fertig — Produkte ohne Vorlagen werden einfach übersprungen (mit Hinweis im
   Protokoll). Fehler beim Rendern brechen die Shop-Anlage nie ab. Gilt für
   Sammelbestellfenster-Produkte; On-Demand-Produkte bekommen ihre Bilder von
   Printify.

   **Credits werden nie doppelt bezahlt.** Jedes fertige Bild wird sofort
   vermerkt, nicht erst das Produkt als Ganzes. Scheitert ein späteres Bild
   oder die Zuweisung am Produkt, bleiben die bereits erzeugten erhalten und
   ein erneuter Klick rendert nur die fehlenden. Pro Produkt gilt außerdem ein
   Zeitbudget (`schoolshop.mockups.budget_seconds`, Standard 120 s) — was
   liegen bleibt, meldet das Protokoll und holt der nächste Klick nach.

### Präsentationsblatt (A4)

Zu jedem Bestellfenster gehört ein Präsentationsblatt für die Schule. Das
erzeugt das Tool im Antrag unter **Präsentationsblatt** — optisch deckungs-
gleich mit der bisherigen InDesign-Vorlage.

Hochzuladen sind nur **zwei Mockups**: eine Rückenansicht (oben rechts, mit
Backprint) und eine Vorderansicht (unten links, mit Frontprint). Alles andere
kommt aus dem Antrag: Schulname, Produktzeilen samt Farben, Bestellzeitraum,
QR-Code und die Adresse der Bestellseite.

Einstellbar sind:

* **Bildausschnitt je Mockup** (X, Y, Zoom) — Placeit-Vorlagen sind
  unterschiedlich angeschnitten, deshalb pro Bild justierbar.
* **Detailkreis** („Print your name!"): standardmäßig ein herangezoomter
  Ausschnitt der Vorderansicht; über X/Y/Zoom auf den Brustdruck ziehen oder
  ein eigenes Bild hochladen.
* **Produktzeilen**: vorbelegt mit der Marketing-Bezeichnung (Premium
  Zip-Hoodie statt Schulzoodie) und der ausgeschriebenen Farbliste, jede Zeile
  frei überschreibbar, Icon wählbar. Es passen drei Produkte plus die feste
  Zeile „1 Produkt = 1 Baum".
* **Vorname im Kreis** und optional eine abweichende **Adresse der Bestellseite**.

„Vorschau öffnen" zeigt das Blatt im Browser, „PDF herunterladen" liefert die
Druckdatei. Beides erscheint erst, wenn beide Mockups, das Bestellfenster und
mindestens ein Produkt vorhanden sind.

**Hintergrund austauschen:** Das Statische (roter Kopf und Fuß, Diagonal-
rahmen, „Print your name!", Logos) liegt als eine PNG-Datei unter
`resources/presentation-sheet/background.png` — mit transparenten Fenstern an
den drei Bildplätzen, durch die die Fotos scheinen. Eine neue Fassung vom
Grafiker wird so eingespielt:

```bash
php artisan sheet:background pfad/zum/export.png
```

Der Export ist eine normale PNG-Datei in A4 bei 300 dpi (2481 × 3508 px), bei
der die drei Bildplätze mit reinem Magenta (#FF00FF) gefüllt sind; der Befehl
stanzt das Magenta heraus und sichert die bisherige Fassung als `.bak`.

**Produkt-Icons** liegen als PNG mit Transparenz in
`resources/presentation-sheet/icons/`. Der Dateiname ohne Endung ist der Name,
der in `config/presentation_sheet.php` unter `icons` je Produkt zugeordnet
wird; fehlt eine Datei, greift `icon_fallbacks`.

**Schrift:** Source Sans 3 (SIL Open Font License, liegt in
`resources/fonts/`) — der freie Ersatz für das lizenzpflichtige Myriad Pro der
Originalvorlage, praktisch nicht zu unterscheiden.

**Benötigte Zugänge (.env):**

| Variable | Zweck |
|---|---|
| `FLUENTFORMS_WEBHOOK_SECRET` | Frei wählbares Secret, Teil der Webhook-URL |
| `WC_RW_CONSUMER_KEY` / `WC_RW_CONSUMER_SECRET` | WooCommerce-API-Schlüssel mit **Lesen/Schreiben** (separat vom Read-only-Schlüssel!) |
| `WP_APP_USER` / `WP_APP_PASSWORD` | WordPress-Anwendungspasswort (Benutzer → Profil → Anwendungspasswörter) für den CPT „schule" (wp/v2) — dort gelten WooCommerce-Schlüssel nicht. Im Pods-Admin muss beim Pod „schule" die REST-API aktiviert sein. |
| `PRINTIFY_API_TOKEN` / `PRINTIFY_SHOP_ID` | Printify (My Profile → Connections); Shop-ID = Zahl in der Printify-URL |
| `SHIPPING_CLASS_ONDEMAND` | Slug der On-Demand-Versandklasse (Default `on-demand`) — muss im Shop existieren |
| `DYNAMIC_MOCKUPS_API_KEY` | Dynamic Mockups (optionale Produktfotos; app.dynamicmockups.com → API) |

Produktkatalog, Preise-Startwerte und Formular-Mapping: `config/schoolshop.php`.

## Modul 3: Bestellfenster schließen

Wenn die Bestellfrist einer Schule abgelaufen ist (bzw. direkt nachdem die
Auftragsdokumente in Modul 1 exportiert wurden): Im Bereich „Bestellfenster
schließen" die Schule auswählen und schließen. Das erledigt in einem Schritt:

1. **Produkte auf privat setzen** — alle Produkte der Schul-Kategorie werden
   in WooCommerce auf `status=private` (zusätzlich `catalog_visibility=hidden`)
   gestellt, sind also für Kund:innen nicht mehr sichtbar oder bestellbar.
   Bereits private Produkte werden übersprungen (idempotent).
2. **CPT-Feld aktualisieren** — im Schule-Eintrag („schule") wird
   „Bestellfenster offen" auf `NEIN` gesetzt.

Angeboten werden nur Schulen, für die bereits ein Shop angelegt wurde. Jeder
Schritt wird protokolliert; Fehler werden verständlich erklärt. Nutzt dieselben
Zugänge wie Modul 2 (`WC_RW_*`, `WP_APP_*`).

**Der Status folgt den Produkten, nicht dem Protokoll.** Sind am Ende alle
Produkte privat, gilt das Fenster als geschlossen — auch wenn der CPT-Schritt
scheitert (etwa weil kein Schule-Eintrag hinterlegt ist). Die Schule erscheint
dann trotzdem in der Öffnen-Liste. Umgekehrt gilt dasselbe beim Wieder-Öffnen.
Ein wieder geöffnetes Fenster beginnt heute, wenn der alte Start längst vorbei
ist.

**Ein erneutes „Shop anlegen" schließt kein offenes Fenster.** Es aktualisiert
nur die Stammdaten des Schule-Eintrags (Zeitraum, Shortcode, On-Demand-Kennzeichen,
Kategorie). „Bestellfenster offen" und die Versandklassen-Marke gehören den
jeweiligen Aktionen und bleiben unberührt.

## Modul 4: Auftragsbilanz

Aufruf: **Auftragsbilanz** in der Navigationsleiste (`/auftragsbilanz`).

Die Nachfolgerin der Excel `Auftragsbilanz_gesamt.xlsx`. Jeder Auftrag ist eine
Zeile, die Spalten sind dieselben wie bisher. **Hier wird nur eingetragen und
angesehen** — ausgewertet wird im Statistikmodul. Die Trennung ist gewollt: Wer
Zahlen pflegt, will eine Tabelle und keine Diagramme, und wer auswertet, soll
nichts versehentlich ändern.

**Was eingetragen wird:** Einnahmen Online, Einnahmen Bar und direkt, Provision
an die Schule, Ausgaben (Produktion, Druck, Versand), Umsatzsteuer und die
Stückzahlen je Produktart. **Was gerechnet wird:** Einnahmen gesamt, Einnahmen
ohne USt., Gewinn/Verlust und die Marge. Das waren in der Excel Formeln und
bleiben es hier — gespeicherte Summen könnten irgendwann nicht mehr zu ihren
Teilen passen.

**Umsatzsteuer:** Leer gelassen wird sie aus dem Bruttobetrag herausgerechnet
(× 20/120). Eine ausdrückliche **0** bleibt 0 — das brauchen die Aufträge vor
der GmbH-Gründung, bei denen keine anfiel.

**Verknüpfung mit dem Webshop.** Ein Auftrag kann an einem Bestellfenster aus
dem Schul-Onboarding hängen. Steht er dann auf „Online-Einnahmen kommen aus dem
Webshop", pflegt die Software diesen Betrag selbst: Er ist der Umsatz, den der
Shop im Zeitraum dieses Fensters für die Kategorie der Schule gemeldet hat —
dieselbe Rechnung wie im Statistikmodul, damit beide Module nie verschiedene
Zahlen zeigen. Nachgetragen wird **nach** dem Seitenaufruf; angezeigt wird, was
gespeichert ist. Ohne Verknüpfung bleibt der Auftrag reine Handeintragung, was
für Barverkäufe, Vereine und Altdaten genau richtig ist.

**Abgleich mit dem Shop.** Oben steht je Schuljahr, was der Shop meldet und was
eingetragen ist. Weichen beide ab, sagt der Kasten das mit beiden Zahlen. Die
Altwerte aus der Excel bleiben dabei unverändert stehen — sie sind der Stand,
mit dem bisher gerechnet wurde. Der Kasten fragt den Shop nie selbst; er zeigt,
was die Statistik ohnehin schon geladen hat, und sagt es, wenn noch Monate
fehlen.

**Die Altdaten.** 384 Aufträge aus den Schuljahren 2019/20 bis 2025/26 wurden
übernommen (`php artisan auftragsbilanz:import`). Die Excel kannte kein Datum je
Auftrag, nur ein Schuljahr — diese Zeilen tragen deshalb das Schuljahresende und
sind als *geschätzt* gekennzeichnet. Für Jahres- und Schulsummen macht das
keinen Unterschied; im Monatsverlauf sitzen sie alle am Jahresende. Wer ein
Datum kennt, kann es beim Auftrag eintragen — dann gilt es als gesichert.

> **In der Excel steckte ein Fehler, der die laufende Saison betraf.** Ab Zeile
> 365 war die Spalte „Schuljahr" als Zahlenreihe fortgeschrieben (`2025-27`,
> `2025-28`, …), sodass 18 Aufträge aus keiner Auswertung mehr auftauchten. Die
> Schuljahresbilanz wies für 2025/26 deshalb **1.275,84 €** aus statt der
> tatsächlichen **48.166,49 €**. Beim Übernehmen ist das begradigt worden.

## Modul 5: Statistiken

Aufruf: **Statistiken** in der Navigationsleiste (`/statistiken`).

Ausgewertet werden zwei Quellen: die echten Bestellungen aus dem
WooCommerce-Shop und die Auftragsbilanz — immer nach **Schuljahr** und immer im
Vergleich zum Vorjahr.

**Was „Schuljahr" hier heißt:** 1. August bis 31. Juli, das Geschäftsjahr des
Hauses. Die Sommerferien zählen damit ans *ablaufende* Schuljahr — Nachzügler-
und Ferienbestellungen gehören zu dem Bestellfenster, das im Juni endete. Der
August dagegen ist der erste Monat der neuen Saison, weil dort die Fenster für
das kommende Schuljahr aufgehen.

**Die zwei Quellenschalter** oben auf der Seite bestimmen, was zählt:

| Schalter | Was er zuschaltet |
|---|---|
| **Shop-Umsätze** | die Bestellungen aus dem Webshop |
| **Sonstige Umsätze** | Bargeld, Direktverkäufe und händisch erfasste Aufträge aus der Auftragsbilanz |

Doppelt gezählt wird nichts: Ein Auftrag, dessen Online-Einnahmen aus dem
Webshop stammen, steuert hier nur seinen Bargeldanteil bei. Mindestens ein
Schalter muss an bleiben. Mit ausgeschalteter Shop-Quelle läuft die Seite auch
dann, wenn der Shop gerade nicht erreichbar ist.

**Wirtschaftlichkeit** (Gewinn, Marge, Ausgaben, Provision, Ø je Auftrag,
größte Aufträge, Schulen mit Gewinn, Schuljahresbilanz über alle Jahre und
verkaufte Teile je Schuljahr) kommt immer aus der Auftragsbilanz und hängt
nicht an den Schaltern: Was ein Auftrag gekostet hat, weiß der Webshop nicht.

**Kennzahlen** (jede mit Vorjahresvergleich):

- Gesamtumsatz im Schuljahr, und beim laufenden Jahr zusätzlich der
  Vorjahresumsatz **zum selben Tag im Schuljahr** — sonst stünde ein halbes
  Jahr gegen ein volles.
- Ø Umsatz je Bestellung
- Ø Umsatz je Sammelbestellfenster
- Ø Umsatz je On-Demand-Shop (dort gibt es kein Fenster — gerechnet wird je
  On-Demand-Schule und Schuljahr)
- Verkaufte Teile

**Erstattungen** werden gezählt, aber **nicht abgezogen**. Unter dem Umsatz
steht, wie viele Bestellungen eine Erstattung enthalten und über welchen
Betrag. Abgezogen wird nichts, weil eine Erstattung oft nur den Versand oder
eine einzelne Position betrifft und sich keiner Produktart zuordnen ließe —
die Ranglisten würden dadurch falsch. Eine ganz stornierte Bestellung fällt
ohnehin über den Bestellstatus heraus.

**Ein Bestellfenster zählt je Antrag, nicht je Schule.** Bestellt eine Schule
im selben Schuljahr zweimal (etwa über „Folgejahr"), sind das zwei Fenster im
Durchschnitt — nicht eines.

**Diagramme:** Monatsumsatz (gruppierte Säulen ab August), kumulierter
Jahresverlauf mit Hochrechnung und Zielmarke, und drei Ranglisten —
meistverkaufte Produkte, beliebteste Farben und umsatzstärkste Schulen. Unter
jedem Diagramm steht „Als Tabelle" mit allen Zahlen.

**Woher die Schulen kommen:** aus den **Produktkategorien des Shops**
(alles unterhalb von „Schulen"), nicht aus den Onboarding-Anträgen. So
erscheinen auch Schulen, die von Hand im Shop angelegt wurden oder aus der Zeit
vor der Toolsuite stammen. Der Antrag liefert nur die Angaben, die es nur dort
gibt: Lieferart und Bestellfenster-Zeitraum. Deshalb beruhen die beiden
Fenster-Durchschnitte auf weniger Schulen als die Umsatzrangliste — das ⓘ an
der Kachel nennt die Zahl.

**Produkte werden nach Produktart gruppiert:** Die Frage ist ja, ob mehr Shirts
oder mehr Pullover verkauft wurden. Weil im Shop jedes Produkt den Schulnamen
im Namen trägt, wird der Name nach Stichwörtern durchsucht — alles mit „Hoodie"
zählt als Schulhoodie, alles mit „Shirt" als Schulshirt. Taucht in der
Rangliste ein Produkt falsch oder doppelt auf, gehört die Schreibweise in
`config/statistics.php` unter `product_group_aliases` ergänzt.

**Was die Filter beeinflussen:** Schuljahr, Lieferart, Schule und Bestellstatus
wirken auf die ganze Seite. Vorlauf/Nachlauf wirken **nur** auf „Ø je
Sammelbestellfenster" und „Ø je On-Demand-Shop". Im Tool steht das unter „Was
die Filter beeinflussen".

### Saisonziel und Planung

Das **Saisonziel ist kein Filter**, sondern eine Vereinbarung: Es wird
gespeichert, gilt für alle im Team und bleibt stehen, bis es jemand ändert.
Eingetragen wird es in der Karte „Saisonziel" über der Auswertung, je
Schuljahr. Ohne Eintrag gilt der tatsächlich erreichte Umsatz des Vorjahres.

Dort stehen auch die **Umsätze außerhalb des Webshops** — Listenbestellungen,
Direktverkäufe, Rechnungen an Vereine:

| Feld | Wirkung |
|---|---|
| Bereits erzielt außerhalb des Shops | zählt zum **Ist** und damit zur Zielerreichung |
| Zusätzlich erwartet außerhalb des Shops | zählt **nur in die Hochrechnung** |
| Notiz | freier Text, erscheint im ⓘ neben „Bereits erreicht" |

In keiner Rangliste tauchen diese Beträge auf — dazu gibt es keine
Bestellpositionen.

**Wie viele Bestellfenster fehlen noch?** Unter der Prognose steht, wie viele
Bestellfenster es bis zum Ziel noch braucht. Gerechnet wird: was bis zum Ziel
noch offen ist, geteilt durch den durchschnittlichen Umsatz eines
Bestellfensters. Zwei Dinge sind daran wichtig:

* **Je Art getrennt.** Ein Sammelbestellfenster bringt typischerweise deutlich
  mehr als ein On-Demand-Shop, deshalb steht je Art eine eigene Zahl. Es sind
  **Alternativen** („entweder 5 Sammelfenster oder 20 On-Demand-Shops") — in
  der Praxis wird es eine Mischung.
* **Nur abgeschlossene Fenster zählen in den Durchschnitt**, und zwar aus der
  laufenden Saison *und* dem Vorjahr zusammen. Ein noch laufendes Fenster hat
  naturgemäß weniger Umsatz und würde die Rechnung zu pessimistisch machen. Je
  mehr Fenster die laufende Saison schon abgeschlossen hat, desto stärker
  bestimmt sie den Wert.

Bei den beiden Fenster-Kacheln steht außerdem, **wie viele Fenster der Saison
schon gelaufen sind** und wie viele gerade laufen — etwa „7 von 12 Fenstern
gelaufen, 2 laufend".

**Datenstand:** Über der Filterzeile steht, von wann die angezeigten Daten
stammen. Das ist der Zeitpunkt des *ältesten* Bausteins — alles Angezeigte ist
also mindestens so aktuell. Abgeschlossene Monate ändern sich nicht mehr und
werden 24 Stunden gehalten, der laufende Monat höchstens 30 Minuten.

**Der Fensterpuffer (wichtig zu verstehen):** Für „Ø je Bestellfenster" wird
jede Bestellung dem Fenster ihrer Schule zugeordnet. Der Zeitraum wird dabei
absichtlich **breiter** genommen als im Antrag eingestellt — Standard 7 Tage
davor und 21 danach, in der Filterzeile änderbar. Grund: nach Ablauf wird ein
Fenster oft noch um eine Woche verlängert (automatische Nachfrist), und
Nachzügler bestellen auch danach. Da nie mehrere Bestellfenster derselben
Schule direkt aneinander liegen, kann der Puffer keine fremden Bestellungen
einsammeln. Das ⓘ neben dem Feld erklärt das auch im Tool.

**Die Seite ruft den Shop nie selbst auf.** Beim ersten Aufruf (und nach
„↻ Daten neu laden") erscheint eine Ladeseite mit Fortschrittsbalken; geholt
wird monatsweise im Hintergrund, bewusst langsam und immer nur ein Paket nach
dem anderen, damit der Webshop auf demselben Server nicht ausgebremst wird.
Der Aufbau läuft weiter, wenn du die Seite verlässt. Solange nicht alles da
ist, zeigt die Seite **keine** Kennzahlen — halbe Zahlen wären schlimmer als
keine.

**Prognose:** Nicht linear hochgerechnet, sondern über den gemittelten
*Saisonverlauf* der abgeschlossenen Vorjahre — ein Schuljahr verläuft stark
ungleichmäßig, die meisten Bestellfenster liegen im Herbst und im Frühjahr.
Der Umsatz bis heute wird durch den nach diesem Muster erwarteten Anteil
geteilt. Zur Hochrechnung kommen die außerhalb des Shops erwarteten Umsätze
aus der Saisonziel-Karte dazu.

**Filter für die ganze Seite:** Schuljahr, Lieferart, einzelne Schule,
Puffertage und Bestellstatus. Alles steht in der Adresszeile — eine Auswertung
lässt sich als Lesezeichen speichern und weitergeben. Das Saisonziel gehört
bewusst nicht dazu (siehe oben).

**Der erste Aufruf:** Beim allerersten Mal — oder nach „↻ Daten neu laden" —
sind noch keine Bestelldaten gespeichert. Dann zeigt die Seite eine
**Ladeanzeige mit Fortschrittsbalken** („12 von 39 Datenpaketen geladen") und
sonst nichts: keine Zahlen, keine Diagramme. Eine halbe Auswertung wäre
irreführender als gar keine.

Der Aufbau läuft **von selbst** weiter, es ist kein Klick nötig; die Seite
aktualisiert sich und öffnet die Auswertung, sobald alles da ist. **Die Seite
darf dabei auch geschlossen werden** — der Abruf läuft im Hintergrund zu Ende.
Je nach Datenmenge dauert der erste Aufbau ein bis wenige Minuten, danach ist
die Auswertung sofort da (vergangene Monate bleiben 24 Stunden gespeichert, der
laufende 30 Minuten).

Das ist bewusst langsam: Abgerufen wird monatsweise, immer nur ein Durchgang
gleichzeitig und mit einer kurzen Pause zwischen den Anfragen. Der Webshop
läuft auf demselben Server und soll für Kund:innen nicht langsamer werden,
während die Auswertung aufgebaut wird.

**Optional, empfohlen:** ein nächtlicher Cron baut die Daten vorab auf, dann
ist die Seite schon beim ersten Aufruf des Tages sofort da:

```
15 4 * * * cd /pfad/zur/app && php artisan statistics:warm --runs=20
```

Danach — ebenfalls empfohlen — trägt ein zweiter Cron die Online-Einnahmen der
verknüpften Aufträge in der Auftragsbilanz nach. Dasselbe passiert gedrosselt
nach jedem Aufruf der Auftragsbilanz; der Cron sorgt dafür, dass die Zahlen auch
dann aktuell sind, wenn tagsüber niemand die Seite geöffnet hat. Er braucht die
Statistik-Daten und gehört deshalb zeitlich dahinter:

```
45 4 * * * cd /pfad/zur/app && php artisan auftragsbilanz:sync
```

## Admin-Informationen

Eigener Navigationspunkt „Admin-Informationen" — bei jedem Aufruf werden alle
API-Anbindungen live geprüft und angezeigt (mit kurzem Zeitablauf von 5
Sekunden je Prüfung, damit ausgerechnet diese Seite nicht selbst hängt, wenn
ein System klemmt): WooCommerce (Lesen/Schreiben),
WordPress (CPT „schule"), Printify, Dynamic Mockups sowie der FluentForms-
Webhook (dieser empfängt nur — hier wird stattdessen der letzte protokollierte
Treffer aus `webhook_logs` angezeigt, kein aktiver Verbindungstest möglich).
Nicht eingerichtete, optionale Schnittstellen (Printify, Dynamic Mockups)
werden neutral als „nicht eingerichtet" markiert, nicht als Fehler.

**Ausfall-Benachrichtigung:** Wechselt eine konfigurierte Schnittstelle von OK
auf fehlgeschlagen, verschickt die Toolsuite **einmalig pro Ausfall-Episode**
(nicht bei jedem erneuten Seitenaufruf; nach Wiederherstellung meldet ein
erneuter Ausfall wieder einmal) eine Benachrichtigung — **ausschließlich über
die WordPress-REST-API**, niemals direkt per E-Mail aus der Toolsuite. Dafür
ruft die App einen eigenen REST-Endpunkt auf der WordPress-Seite auf, der dort
`wp_mail()` auslöst. Voraussetzung: das mitgelieferte mu-Plugin
`wordpress-mu-plugin/weartogether-notify.php` nach
`wp-content/mu-plugins/` auf dem WordPress-Server kopieren (mu-Plugins sind
automatisch aktiv, keine Aktivierung nötig). Es nutzt dasselbe
WordPress-Anwendungspasswort wie der CPT „schule" (`WP_APP_USER`/
`WP_APP_PASSWORD`) — dieses Konto braucht Administrator-Rechte
(`manage_options`). Ist das mu-Plugin nicht installiert, funktioniert alles
andere trotzdem — die Admin-Informationen-Seite zeigt dann bei „Benachrichtigung"
einen Hinweis, dass die Zustellung fehlgeschlagen ist, statt die Seite zu
blockieren.

## Versionsnummer

Die Navigationsleiste zeigt oben links „v{Nummer}" (Datei `VERSION` im
Projekt-Root, eine einzelne Zeile mit einer Ganzzahl). So lässt sich nach
einem Push auf einen Blick prüfen, ob das automatische Deployment schon
gelaufen ist — einfach die Zahl auf der Live-Seite mit dem letzten Commit
vergleichen. Die Zahl wird bei jedem Push erhöht.

## Datenschutz

Die Exporte enthalten potenziell personenbezogene Daten. Deshalb:
`TOOL_PASSWORD` in Produktion **immer** setzen, HTTPS erzwingen, Aufbewahrung
kurz halten (`ORDER_RETENTION_HOURS`). Uploads und generierte Reports werden
vom stündlichen `orders:cleanup`-Lauf automatisch gelöscht.
