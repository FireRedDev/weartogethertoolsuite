# Wear Together Order Suite

Web-Nachfolger der Wear Together Toolsuite (Python/Tkinter). Verwandelt den
Bestell-Export aus dem Wear-Together-Shop in einem geführten 3-Schritte-Flow in
vier fertige Auftragsdokumente:

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
2. **Konfigurator:** Produkte (Vorlagenkatalog aus den bisherigen
   Musterschule-Excel-Vorlagen), Preise, Individualisierungs-Aufpreis, Größen,
   Farben, Klassenliste, Bestellfenster und Lieferart anpassen — alles
   vorbefüllt aus den Formularwünschen.
3. **Shop-Anlage** (ein Klick, mit Vorschau/Dry-Run): Produktkategorie
   „Schulen > {Name}", variable Produkte mit Variationen (Individualisierung
   Ja/Nein), Individualisierungs-Eingabefeld (Product Input Fields),
   Versandklasse (On-Demand) und Pods-CPT-Eintrag „schule". Jeder Schritt wird
   protokolliert; bei Fehlern bricht die Anlage ab und kann nach Behebung
   fortgesetzt werden (bereits Angelegtes wird übersprungen).
4. **Sammelbestellfenster:** Bestellemail an die Partnerdruckerei nach Vorlage
   (inkl. Lieferanten-Artikelnummern), zum Kopieren oder per mailto.
   **On-Demand:** Die Produkte werden in Printify angelegt und in den Shop
   published (statt direkt in WooCommerce). Ablauf: Im Konfigurator pro Produkt
   Blueprint-ID und Print-Provider-ID eintragen (nachschlagen mit
   `php artisan printify:check --blueprints=JH001`) → „Im Shop anlegen" prüft
   automatisch die Marge (Verkaufspreis ≥ (Produktionskosten + Versand) × 1,10,
   sonst Abbruch mit Rechnung) und published → einige Minuten warten, bis
   Printify die Shop-Produkte erstellt hat → „On-Demand-Nachbearbeitung"
   klicken: setzt Versandklasse `on-demand` und die Schul-Kategorie auf allen
   Produkten der Schule und meldet das im Pods-Eintrag als erledigt.

**Hinweis „Im Checkout anzeigen" (German Market):** Größe, Farbe, Klasse und
Individualisierung werden als Variationsattribute angelegt — die Auswahl der
Kund:innen erscheint dadurch automatisch im Warenkorb/Checkout und in der
Bestellung. Die zusätzliche German-Market-Checkbox pro Eigenschaft ist über
die WooCommerce-API nicht setzbar (internes Meta); falls sie gebraucht wird:
entweder pro Produkt manuell setzen oder global unter WooCommerce →
German Market → Allgemein → Produkte die Option für Produkteigenschaften im
Checkout aktivieren.

**Benötigte Zugänge (.env):**

| Variable | Zweck |
|---|---|
| `FLUENTFORMS_WEBHOOK_SECRET` | Frei wählbares Secret, Teil der Webhook-URL |
| `WC_RW_CONSUMER_KEY` / `WC_RW_CONSUMER_SECRET` | WooCommerce-API-Schlüssel mit **Lesen/Schreiben** (separat vom Read-only-Schlüssel!) |
| `WP_APP_USER` / `WP_APP_PASSWORD` | WordPress-Anwendungspasswort (Benutzer → Profil → Anwendungspasswörter) für den CPT „schule" (wp/v2) — dort gelten WooCommerce-Schlüssel nicht. Im Pods-Admin muss beim Pod „schule" die REST-API aktiviert sein. |
| `PRINTIFY_API_TOKEN` / `PRINTIFY_SHOP_ID` | Printify (My Profile → Connections); Shop-ID = Zahl in der Printify-URL |
| `SHIPPING_CLASS_ONDEMAND` | Slug der On-Demand-Versandklasse (Default `on-demand`) — muss im Shop existieren |

Produktkatalog, Preise-Startwerte und Formular-Mapping: `config/schoolshop.php`.

## Datenschutz

Die Exporte enthalten personenbezogene Daten (teils Minderjähriger). Deshalb:
`TOOL_PASSWORD` in Produktion **immer** setzen, HTTPS erzwingen, Aufbewahrung
kurz halten (`ORDER_RETENTION_HOURS`). Uploads und generierte Reports werden
vom stündlichen `orders:cleanup`-Lauf automatisch gelöscht.
