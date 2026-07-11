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
entspricht **exakt** dem Legacy-Skript `wear_together_toolsuite.py` @ `cff1227` —
abgesichert durch Golden-File-Tests, die jede Zelle der erzeugten Excel-Dateien
gegen mit dem Legacy-Skript erzeugte Referenzdateien vergleichen.
Details: `../AGENTIC_INTENT_SPEC.md`.

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

## Deployment auf RunCloud

1. **Web Application anlegen**: Typ „PHP", PHP **8.3+** (Extensions `zip`, `gd`,
   `mbstring`, `xml`, `fileinfo`, `intl` — bei RunCloud standardmäßig aktiv),
   **Web Application Root** auf den Ordner `order-suite`, **Public Path / Document
   Root** auf `order-suite/public` stellen. Stack: Nginx + PHP-FPM (NativeNginx).
2. **Code deployen**: Git-Deployment auf dieses Repository/diesen Ordner
   einrichten (oder Dateien hochladen). Deployment-Script:

   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

3. **.env anlegen** (im Ordner `order-suite`):

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

   Danach einmalig `php artisan key:generate` ausführen.
4. **Schreibrechte**: `storage/` und `bootstrap/cache/` müssen für den
   Web-App-User beschreibbar sein (RunCloud-Standard passt in der Regel).
5. **Cronjob** (RunCloud → Cron Jobs), minütlich — führt u. a. die
   DSGVO-Bereinigung `orders:cleanup` aus:

   ```
   * * * * * cd /home/runcloud/webapps/DEINE-APP/order-suite && php artisan schedule:run >> /dev/null 2>&1
   ```

6. **SSL aktivieren** (RunCloud → SSL, Let's Encrypt) und HTTPS-Redirect einschalten.

## Datenschutz

Die Exporte enthalten personenbezogene Daten (teils Minderjähriger). Deshalb:
`TOOL_PASSWORD` in Produktion **immer** setzen, HTTPS erzwingen, Aufbewahrung
kurz halten (`ORDER_RETENTION_HOURS`). Uploads und generierte Reports werden
vom stündlichen `orders:cleanup`-Lauf automatisch gelöscht.
