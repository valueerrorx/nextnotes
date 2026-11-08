# M8-plan – Architekt
Status: fertig (r2)
Auftrag r1: Plan `docs/slices/M8-plan.md` für M8a/M8b/M8c nach ADR 0018; nur Plan, kein Code.
Auftrag r2: Review `docs/reviews/M8-plan-r1.md` einarbeiten (Blocker 1 Kompositions-Budget, Blocker 2 math.js-Aufrufliste, Hinweise), Q1 schließen (Lead: Tabelle fest deutsch).
## Erledigt
- r1: ADRs/SPEC/src gelesen, npm-Proben im Scratchpad, Plan r1 geschrieben
- r2: Review gelesen; Node-Probe `scratchpad/probe-r2.mjs` (exit 0): gesicherte parse/compile/evaluate laufen nach `import`-Stubs, Ausdruck `evaluate("1")`/`parse`/`config` → Stub-Fehler; `f(1)(2)` und `(sin)(1)` parst math.js als implizite Multiplikation; Kette f0..f22 ohne Grenze = 1345 ms (Blocker 1 bestätigt)
- r2: `data-nextnotes-readonly` gesetzt in note_display.php bei !canWrite (bestätigt); MathLive `MathfieldElement.openUrl` statisch (d.ts)
- r2: Plan überarbeitet: Änderungsvermerk, §1.1 Modus, neuer §1.1a (Attribut statt Klasse, Verschachtelung entfernen, is_finite, Zaunlänge/`$`), M8a openUrl + Done 9–11 + ADR-0018-Nachtrag (a–d), M8b Sprache/HEUTE/Done 6/7, M8c Reihenfolge, Aufrufliste, Operatoren, Namen, Aufwandsgrenzen (10 000/32/150 ms; Graph 50 000/32/100 ms je Zeichenlauf), Done 2/2a/2b/3/3a/6/7, R2/R5, Q1 geschlossen
## Nächster Schritt
- keiner (Review r2 durch speccritic)
## Offen
- B69 vermutet, Beleg in M8a
- Node-Skript `tests/nextnotes/e2e/calc-guard.mjs` neu (tester)
