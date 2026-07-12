# Changelog

Alle nennenswerten Änderungen der Wear Together Order Suite.

## [Unreleased]

### Modul 2: Schul-Onboarding
- FluentForms-Webhook (Webshopstartfragebogen) legt Onboarding-Anträge automatisch an; manuelle Anlage möglich
- Konfigurator: Produkte/Preise/Größen/Farben/Klassenliste/Bestellfenster aus dem Musterschule-Excel-Master, vorbefüllt aus den Formularwünschen
- Shop-Anlage per Klick (idempotent, mit Dry-Run-Vorschau und Schritt-Protokoll): Kategorie „Schulen > {Name}", variable Produkte — alle Attribute als Variationsattribute wie im Excel-Master (Variationen „Any" außer Individualisierung Ja/Nein), Standard-Größe M, PIF-Individualisierungsfeld, Pods-CPT „schule" inkl. Feld-Verifikation und Logo als Beitragsbild
- Sammelbestellfenster: Bestellemail nach Druckerei-Vorlage (Copy + mailto)
- On-Demand: Printify-Integration — Produkte anlegen + publishen mit Margen-Prüfung (Verkaufspreis ≥ (Kosten + Versand) × 1,10), Backprint-Unterstützung, Nachbearbeitung setzt Versandklasse „on-demand" + Kategorie auf den von Printify erstellten Shop-Produkten
- `php artisan printify:check` für Verbindungstest, Shop-ID und Blueprint-Suche
- Fehlertransparenz überall: erklärte Fehlermeldungen mit kopierbaren technischen Details statt 500er-Seiten; Schutz vor Redirect-Verlust bei Schreibzugriffen (www vs. ohne www)

### Modul 1: Auftragsdokumente
- Weg 1: Bestell-Import direkt über die WooCommerce REST API (Schule = Produktkategorie, Statusfilter, Zeitraum) — repliziert den Plugin-Export exakt (live gegen St.-Johannis-Schule validiert, 0 Zell-Diffs)
- Weg 2: Datei-Upload wie bisher
- 3 Excel-Reports + Verteil-PDF zellgenau identisch zum Legacy-Python-Tool (Golden-File-Tests)
- Prüfbericht (unbekannte Größen, fehlende Individualisierungstexte u. a.), ZIP-Download, DSGVO-Auto-Löschung

### Infrastruktur
- Laravel 13 auf RunCloud (Git Atomic Deployment), SQLite, Login per Team-Passwort
- GitHub-Actions-CI (php artisan test bei Push/PR)
