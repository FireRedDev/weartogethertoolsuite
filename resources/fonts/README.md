# Schriften

**Source Sans 3** (Adobe, SIL Open Font License 1.1 — siehe `LICENSE.md`).

Ersetzt das lizenzpflichtige **Myriad Pro** der InDesign-Vorlage des
Präsentationsblatts. Beide stammen vom selben Schriftgestalter (Robert
Slimbach) und sind in Laufweite und Anmutung nahezu deckungsgleich — die
Textpositionen des erzeugten Blatts weichen von der Vorlage um weniger als
0,4 pt ab (Überschrift 2,6 pt, weil die beiden Originalzeilen selbst nicht
exakt deckungsgleich zentriert sind).

Eingebunden werden die Dateien per `@font-face` in
`resources/views/presentation-sheet/sheet.blade.php`. dompdf kann **nur TTF**
lesen — OTF-Dateien (wie die Original-Myriad-Schnitte) funktionieren nicht.

| Datei | Schnitt |
|---|---|
| `SourceSans3-Regular.ttf` | 400 |
| `SourceSans3-It.ttf` | 400 kursiv |
| `SourceSans3-Semibold.ttf` | 600 |
| `SourceSans3-Bold.ttf` | 700 |
