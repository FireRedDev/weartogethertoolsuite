# CLAUDE.md — Wear Together Order Suite

Orientierung für Code-Änderungen. Für tiefe Details der Report-Logik: `AGENTIC_INTENT_SPEC.md`. Nutzer-Doku: `README.md`, Änderungen: `CHANGELOG.md`.

## Was das ist
Laravel-13-App (PHP ≥ 8.3, SQLite), Web-Nachfolger einer Python/Tkinter-Toolsuite. Direkt im Repo-Root (kein Unterordner) wegen RunCloud Atomic Deployment. Deutschsprachige UI. CSS/JS inline in Blade-Views (kein Node-Build). Login per gemeinsamem `TOOL_PASSWORD` (Middleware `ToolAuth`; leer = kein Login).

Drei Module, verlinkt von der Startseite (`/` → `HomeController`):
1. **Auftragsdokumente** (`/auftragsdokumente`, `tool.*`/`shop.*`/`job.*`) — Bestell-Export → 3 Excel-Reports + Verteil-PDF. Kern-Logik in `app/Services/*` (nicht `SchoolShop/`). **Zellgenau identisch zum Legacy-Python-Tool — abgesichert durch Golden-File-Tests (`tests/Feature/GoldenFileTest.php`). Diese Logik/Defaults (`config/ordersuite.php`) NICHT verändern, ohne die Golden-Files bewusst neu zu erzeugen.**
2. **Schul-Onboarding** (`/schulen`, `schools.*`) — FluentForms-Webhook → Konfigurator → Shop-Anlage (WooCommerce + Pods-CPT „schule" + optional Printify).
3. **Bestellfenster schließen** (`/bestellfenster-schliessen`, `close-window.*`) — Produkte einer Schule auf privat setzen + CPT „Bestellfenster offen" = NEIN.

## Befehle
```bash
php artisan test                 # gesamte Suite (muss vor jedem Push grün sein)
php artisan test --filter=XyzTest
php artisan serve                # lokal; Background-Runs über die Task-Mechanik, nicht `&`
php artisan printify:check --blueprints=JH001   # Printify: Shops/Blueprints/Provider nachschlagen
php artisan printify:check --providers=92
```

## Architektur (Modul 2/3 — hier passieren die meisten Anpassungen)
- **Models:** `SchoolOnboarding` (Onboarding-Antrag; `$guarded=[]`, JSON-Casts für `products`/`address`/etc.; `enabledProducts()`, `isProvisioned()`), `WebhookLog` (Diagnose-Log).
- **`app/Services/SchoolShop/`:**
  - `FluentFormsMapper` — Webhook-Payload → `SchoolOnboarding`. Feld-Keys (`input_text_6`, `email`, `multi_select_4`…) stammen aus einem echten FluentForms-Export. Wirft nie (fällt auf „Unbenannte Schule" zurück).
  - `ProductConfigurator` — `products`-JSON aufbauen/normalisieren. `preset($product)` liefert Name/Beschreibung/Code (bevorzugt aus `products`-JSON, Fallback `config/schoolshop.php`). Unterstützt im Konfigurator hinzugefügte Custom-Produkte (`new`).
  - `ShopProvisioner` — Orchestrator: `plan()` (Dry-Run), `apply()` (idempotent, Schritt-Protokoll, bricht bei Fehler ab), `ondemandSync()`, `closeOrderWindow()`.
  - `WooCommerceWriteClient` / `WordPressClient` / `PrintifyClient` / `PrintifyProvisioner` — API-Clients (Read/Write-Key bzw. WP App-Password bzw. Printify-Token).
  - `OrderEmailGenerator` — Bestellemail (Sammelbestellfenster).
- **Katalog & Defaults:** `config/schoolshop.php` (12+ Produkte inkl. vorbefüllter Printify Blueprint/Provider-IDs, Preise, Pods-Defaults, Feld-Mapping).
- **Views:** `resources/views/schools/{index,show,create}.blade.php`, `close-window/index.blade.php`, `home.blade.php`, Layout `layouts/app.blade.php`.

## Wichtige Gotchas (teuer erkauft — bitte beachten)
- **www vs. ohne www:** `WC_STORE_URL` muss EXAKT die Endadresse sein. Bei 301-Redirect macht der HTTP-Client aus POST ein GET → Schreibzugriffe verschwinden still. Beide Write-Clients nutzen `allow_redirects=false` und brechen bei 3xx mit Klartext ab. Nicht „vereinfachen".
- **Pods REST-Rechte:** Der CPT „schule" braucht REST-Aktivierung am Pod UND Schreibrechte **pro Feld** (sonst werden Felder still ignoriert). `ShopProvisioner::verifySchuleFields()` liest zurück und meldet fehlende Felder.
- **Config-Cache:** Nach `.env`-Änderungen `php artisan config:cache` (bzw. neu deployen). Werte werden über `config('schoolshop.…')` gelesen, nie `env()` außerhalb von `config/`. Häufigste Ursache für „Webhook 404 trotz korrektem Secret": veralteter Config-Cache.
- **RunCloud Basic Auth blockt Server-to-Server:** Basic-Auth der Web-App wird VOR Laravel (nginx) erzwungen → externe POSTs (FluentForms) bekommen 401, bevor die App sie sieht. Lösung: FluentForms `Authorization: Basic <base64>`-Header, oder Basic Auth entfernen (Tool hat eigenes `TOOL_PASSWORD`).
- **PHP-Version-Pin:** `composer.json` hat `config.platform.php = 8.3.99`. Immer so lassen — sonst zieht `composer update` symfony-Pakete, die PHP ≥8.4.1 verlangen, und die CI (PHP 8.3) bricht beim Install.
- **On-Demand-Besonderheiten:** kein Bestellfenster/keine Klassenliste (Versand an Privatadresse) → im Konfigurator ausgeblendet, serverseitig erzwungen; Pods bekommt festes Fenster `2000-01-01`–`2099-01-01` (Konstanten in `SchoolOnboarding`).
- **Printify Marge:** Verkaufspreis ≥ (max. Variantenkosten + Versand) × (1 + `min_margin`, default 0,10). Vier Produkte (Jacke/Polo/Sportshirt/Match-Polo) haben nur Nicht-EU-Provider → längere Lieferzeit/Versand einkalkulieren.
- **Webhook ist verlustsicher + protokolliert:** Jeder Treffer wird in `webhook_logs` gespeichert (sichtbar unter Schul-Onboarding), bevor irgendeine Logik läuft. Schlägt das Mapping fehl, wird der Rohdatensatz trotzdem als Antrag gesichert. GET auf die Webhook-URL = Browser-Test (200/404/503).

## Deployment & Versionsnummer
- RunCloud Git Atomic Deployment vom Branch; Deploy-Script macht `composer install --no-dev`, `config:cache`, `route:cache`, `view:cache`, `migrate --force`. `.env` und `storage` sind persistente Symlinks.
- **Versionsnummer:** Datei `VERSION` (eine Ganzzahl), angezeigt in der Navbar als „v{N}". **Regel: bei JEDEM Push die Zahl um 1 erhöhen** — so sieht der Nutzer auf der Live-Seite, ob der Push schon deployt wurde.

## Konventionen
- Vor jedem Push `php artisan test` grün halten; neue Funktionen bekommen einen Feature-Test.
- Fehler nie als kahler 500er: erklärte Meldung + kopierbare technische Details (`errors/friendly.blade.php`, `WooCommerceApiException`, Session-`provisionError`).
- Entwicklung/Push auf Branch `claude/python-modernization-spec-cqxq2g`. Commit-/PR-Texte ohne Modell-Identifier.
- Deutschsprachige UI-Texte und Kommentare (bestehendem Stil folgen).
