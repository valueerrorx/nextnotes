# M8c – speccritic

## r2 (Diff-Review, letzte Runde)
Status: fertig
Auftrag: Fix aus r1 prüfen (Warteschlange, Vorprüfung, Einheiten-Regel, Build-Patch, Lizenzen, Produktfolge); Ergebnis docs/reviews/M8c-r2.md.

### Erledigt
- r1, Architekt-Abschnitt fix gelesen
- Code gelesen: blocks.js Queue (pump je setTimeout, Folgeschritte vorn, spent-Reset bei leerer Schlange), calc.js draw (probe/paint Jobs), mathjs.mjs plotFn/assigned
- Einheiten-Regel: assigned verengt nur (Zusatzbedingung), keine Lücke
- Build-Patch: im Bündel vorhanden (e.length?[e[0]]:[]), 1 Treffer in dist; Plugin meldet nicht, wenn Datei gar nicht geladen wird (Hinweis)
- calc3/run.mjs (Chromium headless, echte js/, Long-Task-Observer): m1 school 30 view/edit alle chart, LT max 98/104; heavy 12 view/edit 4 gerechnet, LT max 157/156
- m2 Moduswechsel alle 3 s (Sperre): je Wechsel neues Budget, 40 LT, max 153, Summe 4,3 s in 12 s (Hinweis)
- m3 Zoom heraus mit x-abhängig teurer fn: 1 LT 104 ms → heavy (ok)
- Lizenzen lic.cjs: internmap/quickselect/lruQueue gelistet; es6-shim-Snippets (hypot/cbrt) nicht (Hinweis), CSparse nicht im Output
- Drosselung 4×/6×: school 30 alle chart; budgetierte Tasks ≤178 ms; Lib-Parse 385/584 ms (Hinweis)
- u.mjs Einheiten/reservierte Namen ok; node calc-engine+block-queue 13/13
- Review geschrieben: docs/reviews/M8c-r2.md (FREIGABE, 6 Hinweise)
- function-plot Zoom/Tip: je Ereignis ein Lauf mit 100 ms Budget (Microtask), dead → heavy

### Nächster Schritt
- keiner

## r1
Status: fertig (VERDICT BLOCKER B1/B2, docs/reviews/M8c-r1.md)
