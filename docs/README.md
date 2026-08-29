# Dokumente zum Weitergeben

| Datei | Für wen | Inhalt |
|---|---|---|
| `Praesentationsblatt-Anleitung-Grafik.pdf` | Grafiker:in | Wie der Hintergrund und die Produkt-Icons für das automatische Präsentationsblatt aufzubereiten sind |

Die PDF wird aus `praesentationsblatt-anleitung-grafik.html` erzeugt
(WeasyPrint, die beiden PNGs liegen daneben):

```bash
python3 -c "import weasyprint; weasyprint.HTML('docs/praesentationsblatt-anleitung-grafik.html', base_url='docs').write_pdf('docs/Praesentationsblatt-Anleitung-Grafik.pdf')"
```
