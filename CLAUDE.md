# CLAUDE.md — Wear Together Order Suite

Orientierung für Code-Änderungen. Für tiefe Details der Report-Logik: `AGENTIC_INTENT_SPEC.md`. Nutzer-Doku: `README.md`, Änderungen: `CHANGELOG.md`.

## Was das ist
Laravel-13-App (PHP ≥ 8.3, SQLite), Web-Nachfolger einer Python/Tkinter-Toolsuite. Direkt im Repo-Root (kein Unterordner) wegen RunCloud Atomic Deployment. Deutschsprachige UI. CSS/JS inline in Blade-Views (kein Node-Build). Login per gemeinsamem `TOOL_PASSWORD` (Middleware `ToolAuth`; leer = kein Login).

Drei Module, verlinkt von der Startseite (`/` → `HomeController`):
1. **Auftragsdokumente** (`/auftragsdokumente`, `tool.*`/`shop.*`/`job.*`) — Bestell-Export → 3 Excel-Reports + Verteil-PDF. Kern-Logik in `app/Services/*` (nicht `SchoolShop/`). **Zellgenau identisch zum Legacy-Python-Tool — abgesichert durch Golden-File-Tests (`tests/Feature/GoldenFileTest.php`). Diese Logik/Defaults (`config/ordersuite.php`) NICHT verändern, ohne die Golden-Files bewusst neu zu erzeugen.**
2. **Schul-Onboarding** (`/schulen`, `schools.*`) — FluentForms-Webhook → Konfigurator → Shop-Anlage (WooCommerce + Pods-CPT „schule" + optional Printify).
3. **Bestellfenster schließen** (`/bestellfenster-schliessen`, `close-window.*`) — Produkte einer Schule auf privat setzen + CPT „Bestellfenster offen" = NEIN.

Dazu im Schul-Antrag ein **Präsentationsblatt** (A4-PDF je Bestellfenster, siehe unten) und ein Diagnose-Bereich **Admin-Informationen** (`/admin-informationen`, `admin.*`) — Live-Status aller API-Anbindungen, siehe unten.

## Befehle
```bash
php artisan test                 # gesamte Suite (muss vor jedem Push grün sein)
php artisan test --filter=XyzTest
php artisan serve                # lokal; Background-Runs über die Task-Mechanik, nicht `&`
php artisan printify:check --blueprints=JH001   # Printify: Shops/Blueprints/Provider nachschlagen
php artisan printify:check --providers=92
php artisan printify:check --description=92,91  # Blueprint-Katalogbeschreibung (für printify_description in config/schoolshop.php)
php artisan windows:extend --dry-run            # fällige Fenster-Nachfristen anzeigen (Cron: täglich ohne --dry-run)
php artisan backup:create                       # Datenbank + Uploads sichern (Cron: nächtlich)
php artisan sheet:background export.png         # Grafiker-Export -> Hintergrund des Präsentationsblatts
```

## Architektur (Modul 2/3 — hier passieren die meisten Anpassungen)
- **Models:** `SchoolOnboarding` (Onboarding-Antrag; `$guarded=[]`, JSON-Casts für `products`/`address`/etc.; `enabledProducts()`, `isProvisioned()`), `WebhookLog` (Diagnose-Log).
- **`app/Services/SchoolShop/`:**
  - `FluentFormsMapper` — Webhook-Payload → `SchoolOnboarding`. Feld-Keys (`input_text_6`, `email`, `multi_select_4`…) stammen aus einem echten FluentForms-Export. Wirft nie (fällt auf „Unbenannte Schule" zurück).
  - `ProductConfigurator` — `products`-JSON aufbauen/normalisieren. `preset($product)` liefert Name/Beschreibung/Code (bevorzugt aus `products`-JSON, Fallback `config/schoolshop.php`). Unterstützt im Konfigurator hinzugefügte Custom-Produkte (`new`).
  - `ShopProvisioner` — Orchestrator: `plan()` (Dry-Run), `apply()` (idempotent, Schritt-Protokoll, bricht bei Fehler ab), `ondemandSync()`, `closeOrderWindow()`.
  - `LogoManager` — Schullogo je Druck (`front`/`back`) hochladen/austauschen/entfernen. Speichert doppelt: lokal auf der `public`-Platte (Vorschau/Download im Tool) **und** in der WordPress-Mediathek (nur diese Adresse ist von außen erreichbar — Printify/Dynamic Mockups laden die Datei selbst). Auflösung/Platzierung liegen im Model: `logoUrl($slot)`, `prints($slot)`, `activePrintSlots()`, `logoPlacement($slot)`.
  - `WooCommerceWriteClient` / `WordPressClient` / `PrintifyClient` / `PrintifyProvisioner` — API-Clients (Read/Write-Key bzw. WP App-Password bzw. Printify-Token).
  - `DynamicMockupsClient` / `MockupGenerator` — optionale Produktfotos (Model + Detail) via Dynamic Mockups; Vorlagen-UUIDs in `config/schoolshop.php` → `mockups.templates` (kuratieren: `php artisan mockups:check`). Render-Fehler brechen `apply()` nie ab; `mockup_images` am Onboarding verhindert doppelte Credits.
  - `OrderEmailGenerator` — Bestellemail (Sammelbestellfenster).
- **Katalog & Defaults:** `config/schoolshop.php` (12+ Produkte inkl. vorbefüllter Printify Blueprint/Provider-IDs, Preise, Pods-Defaults, Feld-Mapping).
- **Views:** `resources/views/schools/{index,show,create}.blade.php`, `close-window/index.blade.php`, `admin/status.blade.php`, `home.blade.php`, Layout `layouts/app.blade.php`.
- **Erklärtexte:** zwei Blade-Komponenten in `resources/views/components/` — `<x-info label="…">kurzer Hinweis</x-info>` (antippbares ⓘ neben Überschrift/Spalte/Knopf) und `<x-explain title="…">längerer Block</x-explain>` (ausklappbares `<details>`). **Nie `title="…"` für Erklärungen** — Touchgeräte zeigen das nicht an; `HelpUiTest` prüft das. Für reine Symbolknöpfe `aria-label`. Außer der Startseite (bewusst ausführlich) soll überall möglichst wenig Dauertext stehen.
- **`app/Services/SchoolShop/OnboardingStatus`** — Bedeutung und erlaubte Wechsel der Antrags-Status. `angelegt`/`abgeschlossen` sind **nur** über die jeweilige Aktion erreichbar (Shop-Anlage bzw. Modul 3), nie über das Auswahlfeld; `manualOptions()` liefert, was gerade zulässig ist, der Controller validiert dagegen.
- **`app/Services/SchoolShop/OrderWindowExtender`** — verlängert abgelaufene Sammelbestellfenster **einmalig** (`auto_extended_at`) um `auto_extend_days`. Läuft über `php artisan windows:extend` (Cron) **und** gedrosselt beim Aufruf der Startseite, damit es auch ohne Cron greift. `resetFor()` gibt die Verlängerung frei, sobald jemand das Enddatum von Hand ändert oder das Fenster wieder geöffnet wird.
- **`app/Services/SchoolShop/Dashboard`** — die Aufgabenliste der Startseite. Bewusst **ohne** API-Aufrufe: die Startseite muss auch laden, wenn WooCommerce/WordPress klemmen.
- **`app/Services/SchoolShop/SchoolOrderStats`** — Bestellzahlen je Schule über `ShopOrderFetcher::summary()`, 15 min gecacht; Fehler liefern `null` statt zu werfen.
- **`app/Services/BackupCreator`** — Datenbank + Uploads als ZIP (ohne `.env`, ohne `render/`-Zwischenstände).
- **`app/Services/PresentationSheet/`** — A4-Präsentationsblatt je Bestellfenster (ersetzt den InDesign-Handsatz):
  - `PresentationSheetRenderer` — baut aus dem Onboarding eine flache Liste fertig positionierter Elemente (`data()`), daraus HTML bzw. PDF (dompdf). Die Blade-Vorlage `presentation-sheet/sheet.blade.php` enthält bewusst keinerlei Rechnerei.
  - `SheetImages` — GD: Fotos „cover" auf die Fensterrechtecke zuschneiden (X/Y/Zoom je Bild), Detailkreis rund maskieren, QR-Code erzeugen.
  - Layout: `config/presentation_sheet.php` — alle Koordinaten in pt, 1:1 aus der InDesign-Vorlage vermessen.
  - `php artisan sheet:background <datei.png>` — Grafik-Export (Bildplätze magenta) → Hintergrund mit transparenten Fenstern.
- **`app/Services/IntegrationStatusChecker`** — prüft live alle API-Clients (`testConnection()`-Methode je Client) + `WordPressAdminNotifier` (E-Mail-Alarm **nur** über einen WordPress-REST-Endpunkt, siehe Gotchas). Model `IntegrationStatus` speichert den letzten Stand pro Schnittstelle (verhindert Mehrfach-Benachrichtigung).

## Wichtige Gotchas (teuer erkauft — bitte beachten)
- **www vs. ohne www:** `WC_STORE_URL` muss EXAKT die Endadresse sein. Bei 301-Redirect macht der HTTP-Client aus POST ein GET → Schreibzugriffe verschwinden still. Beide Write-Clients nutzen `allow_redirects=false` und brechen bei 3xx mit Klartext ab. Nicht „vereinfachen".
- **Pods REST-Rechte:** Der CPT „schule" braucht REST-Aktivierung am Pod UND Schreibrechte **pro Feld** (sonst werden Felder still ignoriert). `ShopProvisioner::verifySchuleFields()` liest zurück und meldet fehlende Felder.
- **Config-Cache:** Nach `.env`-Änderungen `php artisan config:cache` (bzw. neu deployen). Werte werden über `config('schoolshop.…')` gelesen, nie `env()` außerhalb von `config/`. Häufigste Ursache für „Webhook 404 trotz korrektem Secret": veralteter Config-Cache.
- **RunCloud Basic Auth blockt Server-to-Server:** Basic-Auth der Web-App wird VOR Laravel (nginx) erzwungen → externe POSTs (FluentForms) bekommen 401, bevor die App sie sieht. Lösung: FluentForms `Authorization: Basic <base64>`-Header, oder Basic Auth entfernen (Tool hat eigenes `TOOL_PASSWORD`).
- **PHP-Version-Pin:** `composer.json` hat `config.platform.php = 8.3.99`. Immer so lassen — sonst zieht `composer update` symfony-Pakete, die PHP ≥8.4.1 verlangen, und die CI (PHP 8.3) bricht beim Install.
- **On-Demand-Besonderheiten:** kein Bestellfenster/keine Klassenliste (Versand an Privatadresse) → im Konfigurator ausgeblendet, serverseitig erzwungen; Pods bekommt festes Fenster `2000-01-01`–`2099-01-01` (Konstanten in `SchoolOnboarding`).
- **Printify Marge:** Verkaufspreis ≥ (max. Variantenkosten + Versand) × (1 + `min_margin`, default 0,10). Vier Produkte (Jacke/Polo/Sportshirt/Match-Polo) haben nur Nicht-EU-Provider → längere Lieferzeit/Versand einkalkulieren.
- **Printify max. 100 Varianten:** Der Blueprint-Katalog hat oft hunderte Varianten (alle Farben × Größen). `PrintifyProvisioner::selectVariants()` filtert deshalb auf die im Konfigurator gewählten Farben/Größen (DE→EN über `printify.color_aliases`/`size_aliases`; erst exakt, dann Teilstring). Das ist **kein** Kosmetik-Filter — ohne ihn schlägt die API fehl und Printify erzeugt Vorschaubilder in nicht bestellten Farben. Passt nichts, wird bewusst abgebrochen (mit Liste der verfügbaren Werte) statt still alles anzulegen.
- **Printify-Versandprofile:** Ein Provider hat oft mehrere Profile. Ein Profil mit explizitem `AT` muss immer vor `REST_OF_THE_WORLD` gewählt werden (`PrintifyClient::shippingProfile()`), sonst stimmt der angezeigte Versandpreis nicht. Katalogdaten (Provider + Varianten + Versand) werden gemeinsam 24 h gecacht (`printify.catalog.{bp}.{pv}`).
- **Logo ist im Formular optional:** Viele Anträge kommen ohne Logo an. Alles, was ein Logo braucht (Printify, Mockups, Beitragsbild), muss `SchoolOnboarding::logoUrl($slot)` verwenden — nie direkt `logo_files[0]`.
- **Checkbox-Marker im Konfigurator:** Ein nicht angehaktes Kästchen wird nicht mitgeschickt. Die Druck-Häkchen (`print_front`/`print_back`) werden deshalb nur übernommen, wenn das versteckte Feld `print_slots_submitted` dabei ist — sonst würde jedes Speichern ohne den Logo-Bereich beide Drucke abschalten. Die Felder des Logo-Bereichs hängen per `form="configurator-form"` am Konfigurator-Formular (HTML erlaubt keine verschachtelten Formulare, die Upload-Formulare müssen eigenständig sein).
- **Webhook ist verlustsicher + protokolliert:** Jeder Treffer wird in `webhook_logs` gespeichert (sichtbar unter Schul-Onboarding), bevor irgendeine Logik läuft. Schlägt das Mapping fehl, wird der Rohdatensatz trotzdem als Antrag gesichert. GET auf die Webhook-URL = Browser-Test (200/404/503).
- **Toolsuite verschickt NIE selbst E-Mails.** Kein Mailer/SMTP in Laravel konfiguriert (bewusst so lassen). Ausfall-Alarme laufen über `WordPressAdminNotifier` → POST an einen custom WP-REST-Endpunkt (`wordpress-mu-plugin/weartogether-notify.php`, muss auf dem WP-Server als mu-Plugin liegen), der dort `wp_mail()` aufruft. Fehlt das mu-Plugin, schlägt der Call einfach fehl (404) — kein Absturz, nur kein Alarm.
- **Status sind keine Etiketten:** `angelegt`/`abgeschlossen` dürfen nur durch die tatsächliche Aktion entstehen (`ShopProvisioner::apply()` bzw. `closeOrderWindow()`). Neue Statuswerte immer in `OnboardingStatus` beschreiben UND in `manualOptions()`/`actionOnly()` einsortieren, sonst lässt sich im Konfigurator etwas behaupten, das im Shop fehlt.
- **Startseite darf nie an einer API hängen:** `Dashboard` liest ausschließlich die eigene Datenbank. Die Fensterverlängerung dort läuft über `runDueOpportunistically()` — gedrosselt, in try/catch, Fehler nur ins Log.
- **Präsentationsblatt — drei Ebenen:** Fotos zuerst, darüber der Hintergrund (`resources/presentation-sheet/background.png`, PNG mit transparenten Fenstern), darüber Texte/Icons/QR. Der Hintergrund übernimmt das Freistellen der schrägen Rahmen und des Kreises — deshalb **kein** Polygon-Masking im Code. Die Detailaufnahme muss trotzdem rund maskiert werden, sonst überdeckt ihr Quadrat das darunterliegende Mockup (beide liegen unter dem Hintergrund).
- **Blade: `@php(...)` und `@php … @endphp` nie in derselben Datei mischen.** Blades Regex für den Blockform-`@php` greift vom ersten inline `@php(` bis zum nächsten `@endphp` und verschluckt alles dazwischen — die Vorlage kompiliert dann still falsch. Gilt auch für ein `@php(` in einem Blade-Kommentar.
- **dompdf setzt Text tiefer als angefragt** (`factor × Schriftgröße − offset`, gemessen in `presentation_sheet.text_top_correction`). Die `top`-Werte in der Config sind Buchstaben-Oberkanten wie in InDesign; `PresentationSheetTest` rechnet zurück und prüft gegen die Vorlagenkoordinaten.
- **dompdf liest nur innerhalb des Projektverzeichnisses** (chroot). Erzeugte Bilder müssen deshalb unter `storage/app/public` liegen, nicht in `/tmp`.
- **`Http::fake()` in Tests überschreibt eine bereits registrierte URL NICHT** (erste Registrierung gewinnt) — für Tests, die denselben Endpunkt über mehrere Aufrufe hinweg unterschiedlich antworten lassen müssen (z. B. Status-Wechsel OK→Fehler→OK), `Http::fake(['url' => Http::sequence()->push(...)->push(...)])` verwenden, nicht `Http::fake()` mehrfach mit derselben URL aufrufen.

## Deployment & Versionsnummer
- RunCloud Git Atomic Deployment vom Branch; Deploy-Script macht `composer install --no-dev`, `config:cache`, `route:cache`, `view:cache`, `migrate --force`. `.env` und `storage` sind persistente Symlinks.
- **Versionsnummer:** Datei `VERSION` (eine Ganzzahl), angezeigt in der Navbar als „v{N}". **Regel: bei JEDEM Push die Zahl um 1 erhöhen** — so sieht der Nutzer auf der Live-Seite, ob der Push schon deployt wurde.

## Konventionen
- Vor jedem Push `php artisan test` grün halten; neue Funktionen bekommen einen Feature-Test.
- Fehler nie als kahler 500er: erklärte Meldung + kopierbare technische Details (`errors/friendly.blade.php`, `WooCommerceApiException`, Session-`provisionError`).
- Entwicklung/Push auf Branch `claude/python-modernization-spec-cqxq2g`. Commit-/PR-Texte ohne Modell-Identifier.
- Deutschsprachige UI-Texte und Kommentare (bestehendem Stil folgen).
