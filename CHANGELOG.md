# Changelog

Alle nennenswerten Änderungen der Wear Together Order Suite.

## [Unreleased]

### Modul 2: Schul-Onboarding
- FluentForms-Webhook (Webshopstartfragebogen) legt Onboarding-Anträge automatisch an; manuelle Anlage möglich
- Konfigurator: Produkte/Preise/Größen/Farben/Klassenliste/Bestellfenster aus dem Musterschule-Excel-Master, vorbefüllt aus den Formularwünschen
- Shop-Anlage per Klick (idempotent, mit Dry-Run-Vorschau und Schritt-Protokoll): Kategorie „Schulen > {Name}", variable Produkte — alle Attribute als Variationsattribute wie im Excel-Master (Variationen „Any" außer Individualisierung Ja/Nein), Standard-Größe M, PIF-Individualisierungsfeld, Pods-CPT „schule" inkl. Feld-Verifikation und Logo als Beitragsbild
- Sammelbestellfenster: Bestellemail nach Druckerei-Vorlage (Copy + mailto)
- On-Demand: Printify-Integration — Produkte anlegen + publishen mit Margen-Prüfung (Verkaufspreis ≥ (Kosten + Versand) × 1,10), Backprint-Unterstützung, Nachbearbeitung setzt Versandklasse „on-demand" + Kategorie auf den von Printify erstellten Shop-Produkten
- `php artisan printify:check` für Verbindungstest, Shop-ID, Blueprint-Suche (`--blueprints`) und Print-Provider-Suche (`--providers`)
- Printify-Katalog vorbefüllt: Blueprint-ID/Provider-ID für alle Produkte in `config/schoolshop.php` hinterlegt (Textildruck Europa wo verfügbar, sonst bester US-Provider), Konfigurator übernimmt sie automatisch als Default
- Konfigurator zeigt je On-Demand-Produkt live Provider-Region und tatsächliche Versandkosten (Printify-API, 24h gecacht) an, inkl. Warnhinweis bei Providern außerhalb der EU
- Konfigurator: Blueprint-/Provider-Suche direkt in der App (🔍-Button, live gegen den Printify-Katalog) — kein SSH/Terminal mehr nötig; Tooltip an den Spaltenköpfen erklärt alle drei Wege (Suche, Terminal, printify.com)
- Konfigurator: „+ Produkt hinzufügen" erlaubt frei benannte Zusatzprodukte außerhalb des Vorlagenkatalogs (Name/Preis/Größen/Farben/Printify-IDs)
- On-Demand: Bestellfenster und Klassenliste entfallen (Versand direkt an die Privatadresse) — im Konfigurator ausgeblendet, Pods-Eintrag bekommt automatisch ein durchgehend offenes Fenster (01.01.2000–01.01.2099)
- Fehlertransparenz überall: erklärte Fehlermeldungen mit kopierbaren technischen Details statt 500er-Seiten; Schutz vor Redirect-Verlust bei Schreibzugriffen (www vs. ohne www)

### Modul 1: Auftragsdokumente
- Weg 1: Bestell-Import direkt über die WooCommerce REST API (Schule = Produktkategorie, Statusfilter, Zeitraum) — repliziert den Plugin-Export exakt (live gegen St.-Johannis-Schule validiert, 0 Zell-Diffs)
- Weg 2: Datei-Upload wie bisher
- 3 Excel-Reports + Verteil-PDF zellgenau identisch zum Legacy-Python-Tool (Golden-File-Tests)
- Prüfbericht (unbekannte Größen, fehlende Individualisierungstexte u. a.), ZIP-Download, DSGVO-Auto-Löschung
- Modul jetzt unter `/auftragsdokumente` (vorher `/`)

### Neu: Startseite
- `/` zeigt jetzt eine Startseite mit Links + Beschreibung zu beiden Modulen (Auftragsdokumente, Schul-Onboarding)

### Infrastruktur
- Laravel 13 auf RunCloud (Git Atomic Deployment), SQLite, Login per Team-Passwort
- GitHub-Actions-CI (php artisan test bei Push/PR)
