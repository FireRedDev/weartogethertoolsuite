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
- Produktfotos (Mockups, optional, Standard aus): Dynamic-Mockups-Integration — pro Produkt 1–2 Model-Fotos (Frau/Mann, wechselnd je Schule, stabil pro Schule) + Detailansichten in den Schulfarben, Logo-Platzierung wählbar (Brust links/rechts/mitte, Mitte voll/halb, unten), automatisch als Produktbild + Galerie gesetzt; `php artisan mockups:check` zum Kuratieren der Vorlagen; Render-Fehler brechen die Anlage nie ab, keine doppelten Credits bei Wiederholung
- Fehlertransparenz überall: erklärte Fehlermeldungen mit kopierbaren technischen Details statt 500er-Seiten; Schutz vor Redirect-Verlust bei Schreibzugriffen (www vs. ohne www)

### Modul 3: Bestellfenster schließen
- Schule auswählen → alle Produkte der Schule im Shop auf privat setzen (nicht mehr sichtbar/bestellbar, `status=private` + `catalog_visibility=hidden`) und im CPT „schule" „Bestellfenster offen" auf NEIN — idempotent (bereits private Produkte werden übersprungen), mit Schritt-Protokoll und erklärten Fehlern
- Produkte werden über die eindeutige Schul-Kategorie gefunden (Fallback: Namenssuche)

### Modul 1: Auftragsdokumente
- Weg 1: Bestell-Import direkt über die WooCommerce REST API (Schule = Produktkategorie, Statusfilter, Zeitraum) — repliziert den Plugin-Export exakt (live gegen St.-Johannis-Schule validiert, 0 Zell-Diffs)
- Weg 2: Datei-Upload wie bisher
- 3 Excel-Reports + Verteil-PDF zellgenau identisch zum Legacy-Python-Tool (Golden-File-Tests)
- Prüfbericht (unbekannte Größen, fehlende Individualisierungstexte u. a.), ZIP-Download, DSGVO-Auto-Löschung
- Modul jetzt unter `/auftragsdokumente` (vorher `/`)
- Nach dem Export: Erinnerung + Link, das Bestellfenster der Schule zu schließen (Modul 3)

### Neu: Startseite
- `/` zeigt jetzt eine Startseite mit Links + Beschreibung zu allen drei Modulen (Auftragsdokumente, Schul-Onboarding, Bestellfenster schließen)

### Neu: Admin-Informationen
- Neuer Navigationspunkt „Admin-Informationen": prüft bei jedem Aufruf live den Status aller API-Anbindungen (WooCommerce Lesen/Schreiben, WordPress/Pods, Printify, Dynamic Mockups) sowie den FluentForms-Webhook (letzter protokollierter Treffer, kein aktiver Test möglich, da eingehend)
- Fällt eine konfigurierte Schnittstelle aus, wird einmalig pro Ausfall-Episode eine Benachrichtigung ausgelöst — ausschließlich über die WordPress-REST-API (`wp_mail()` auf der WordPress-Seite via neuem mu-Plugin `wordpress-mu-plugin/weartogether-notify.php`); die Toolsuite selbst hat keinen Mailer und verschickt nie direkt E-Mails

### Infrastruktur
- Laravel 13 auf RunCloud (Git Atomic Deployment), SQLite, Login per Team-Passwort
- GitHub-Actions-CI (php artisan test bei Push/PR)
