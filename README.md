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

## Konfiguration (`.env`)

| Variable | Bedeutung | Default |
|---|---|---|
| `TOOL_PASSWORD` | Team-Passwort für den Zugang. **Leer = kein Login** (nur lokal empfohlen!) | leer |
| `ORDER_RETENTION_HOURS` | Automatische Löschung von Uploads/Reports nach X Stunden (DSGVO) | 24 |

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

## Datenschutz

Die Exporte enthalten personenbezogene Daten (teils Minderjähriger). Deshalb:
`TOOL_PASSWORD` in Produktion **immer** setzen, HTTPS erzwingen, Aufbewahrung
kurz halten (`ORDER_RETENTION_HOURS`). Uploads und generierte Reports werden
vom stündlichen `orders:cleanup`-Lauf automatisch gelöscht.
