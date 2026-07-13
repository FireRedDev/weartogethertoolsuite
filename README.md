# Wear Together Order Suite

Web-Nachfolger der Wear Together Toolsuite (Python/Tkinter). Die Startseite
(`/`) verlinkt zu den beiden Modulen: **Auftragsdokumente** (Modul 1) und
**Schul-Onboarding** (Modul 2).

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
| `TOOL_PASSWORD` | Team-Passwort für den Zugang. **Leer = kein Login** (nur lokal empfohlen!) | leer |
| `ORDER_RETENTION_HOURS` | Automatische Löschung von Uploads/Reports nach X Stunden (DSGVO) | 24 |
| `WC_STORE_URL` | Shop-Adresse für Weg 1 (ohne `/wp-json`) | leer (Weg 1 deaktiviert) |
| `WC_CONSUMER_KEY` / `WC_CONSUMER_SECRET` | Read-only-API-Schlüssel des Shops | leer |

Fachliche Defaults (Größenliste, Kartongröße 20, Artikelmapping,
Provisionsstaffel, PDF-Spaltenfilter) liegen in `config/ordersuite.php` —
Änderungen dort ändern den Standard-Output!

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
2. **Konfigurator:** Produkte (Vorlagenkatalog aus den bisherigen
   Musterschule-Excel-Vorlagen), Preise, Individualisierungs-Aufpreis, Größen,
   Farben, Klassenliste, Bestellfenster und Lieferart anpassen — alles
   vorbefüllt aus den Formularwünschen. Über „+ Produkt hinzufügen" lassen
   sich auch Produkte anlegen, die nicht im Vorlagenkatalog stehen (Name,
   Preis, Größen, Farben frei eintragen). Bestellfenster und Klassenliste
   werden bei Lieferart On-Demand ausgeblendet (siehe unten).
3. **Shop-Anlage** (ein Klick, mit Vorschau/Dry-Run): Produktkategorie
   „Schulen > {Name}", variable Produkte mit Variationen (Individualisierung
   Ja/Nein), Individualisierungs-Eingabefeld (Product Input Fields),
   Versandklasse (On-Demand) und Pods-CPT-Eintrag „schule". Jeder Schritt wird
   protokolliert; bei Fehlern bricht die Anlage ab und kann nach Behebung
   fortgesetzt werden (bereits Angelegtes wird übersprungen).
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
   (Tooltip an den Spaltenköpfen fasst das zusammen). Ablauf:
   „Im Shop anlegen" prüft automatisch die Marge (Verkaufspreis ≥
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

   On-Demand-Produkte werden laufend einzeln an die Privatadresse der
   Kund:innen verschickt — es gibt kein Bestellfenster und keine Klassenliste
   (die für die Sammelbestellung sonst als Lieferziel dient). Beide Felder
   sind im Konfigurator bei Lieferart On-Demand ausgeblendet; im Pods-Eintrag
   wird stattdessen ein durchgehend offenes Fenster (01.01.2000–01.01.2099)
   hinterlegt.

**Hinweis „Im Checkout anzeigen" (German Market):** Größe, Farbe, Klasse und
Individualisierung werden als Variationsattribute angelegt — die Auswahl der
Kund:innen erscheint dadurch automatisch im Warenkorb/Checkout und in der
Bestellung. Die zusätzliche German-Market-Checkbox pro Eigenschaft ist über
die WooCommerce-API nicht setzbar (internes Meta); falls sie gebraucht wird:
entweder pro Produkt manuell setzen oder global unter WooCommerce →
German Market → Allgemein → Produkte die Option für Produkteigenschaften im
Checkout aktivieren.

**Produktfotos (Mockups, optional):** Im Konfigurator lässt sich pro Schule
„Produktfotos erzeugen" anhaken (Standard: aus). Beim Anlegen rendert die App
dann über die **Dynamic-Mockups-API** pro Produkt 1–2 Model-Fotos (bevorzugt
eine Frau und ein Mann; die Auswahl wechselt von Schule zu Schule, bleibt aber
pro Schule stabil) sowie Detailansichten in den gewählten Schulfarben — jeweils
mit dem Schullogo an der gewählten Position (Brust links/rechts/mitte, Mitte
volle/halbe Breite, unten) — und setzt sie als Produktbild + Produktgalerie.
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
   Protokoll). Fehler beim Rendern brechen die Shop-Anlage nie ab; bereits
   gerenderte Produkte werden bei erneutem Anlegen übersprungen (keine
   doppelten Credits). Gilt für Sammelbestellfenster-Produkte; On-Demand-
   Produkte bekommen ihre Bilder von Printify.

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

## Admin-Informationen

Eigener Navigationspunkt „Admin-Informationen" — bei jedem Aufruf werden alle
API-Anbindungen live geprüft und angezeigt: WooCommerce (Lesen/Schreiben),
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
