# Review M8-plan r2 (speccritic)

VERDICT: BLOCKER

Gelesen: docs/slices/M8-plan.md (r2, vollständig), docs/reviews/M8-plan-r1.md.

## Abgleich r1
- B1 (Aufwand Komposition): Konzept behoben – Zähler 10 000, Tiefe 32, Frist 150 ms in jedem Wrapper, eigenes Budget je Zeichenlauf (50 000/32/100 ms) mit `queueMicrotask`-Abgrenzung, `NaN` statt Wurf, kein Folgelauf, Leser-Pfad unter Budget. Die Done-Kriterien prüfen den Zähler aber nicht (Blocker 1).
- B2 (Aufrufliste/SymbolNode/Referenzen): behoben – abschließende Liste, Ziel nur `SymbolNode`, Operator-Liste, Namensregel, Reihenfolge sichern → `import`, Done 2/2a/2b mit Node-Test gegen das Bündel.
- Hinweise r1: alle umgesetzt (Done M8a 9/10/11, §1.1a, R2/Done M8b 6, `data-nextnotes-readonly`, `is_finite`, `HEUTE`, ADR-0018-Nachtrag a–d).

## Blocker
1. **Done M8c 3 und 3a – Testzahlen passen nicht zu den Grenzen (§4 „Aufwandsgrenzen“, „Grenze je Zeichenlauf“, „Vorprüfung“).**
   - Done 3: Die Kette `f0 … f40` hat Aufruftiefe 41. Die Tiefengrenze 32 greift schon nach 33 Aufrufen, der Aufrufzähler (10 000) wird nie erreicht. Eine Umsetzung **nur** mit Tiefengrenze besteht Done 3. Die Kette `f0 … f30` (Tiefe 31, 2^31 Aufrufe) friert sie trotzdem ein, also genau der r1-Fall. Es fehlt ein Fall mit Tiefe < 32 und Aufrufen > 10 000, z. B. `f0 … f20`, `f20(1)`, Abbruch durch den Zähler (Fehlerursache im Test unterscheidbar). Gleiches gilt für `calc-guard.mjs`: Zähler, Tiefe und Frist brauchen je einen eigenen Fall, der nur diese Grenze auslöst.
   - Done 3a: Auch hier hat `f40` Tiefe 41, der Zeichenlauf-Zähler (50 000) wird also nicht geprüft. Der „Grenzfall knapp unter dem Budget (`f10`) wird gezeichnet“ widerspricht der Vorprüfung. `f10` braucht 2^11−1 = 2 047 Aufrufe, × 300 Stützstellen = 614 100 > 50 000, also kein Graph. Unter dem Budget liegt höchstens `f6` (127 × 300 = 38 100), `f7` liegt schon darüber (76 500). So wie es dasteht, ist das Kriterium nicht erfüllbar. Grenzfall korrigieren (`f6` gezeichnet, `f7` „Graph zu aufwendig“) und den Abbruch durch den Zeichenlauf-Zähler ohne Tiefenauslösung prüfen (z. B. `f12`, Vorprüfung abschalten oder `x`-abhängigen Zweig nutzen, damit der Laufzähler greift).

## Hinweise
- §4 Schicht 3: math.js `FunctionNode.forEach/traverse` besucht auch `node.fn`. Wird der Checker per `traverse` gebaut, muss `fn` im Aufrufkontext geprüft werden (Liste), nicht mit der `SymbolNode`-Regel. Sonst wird `sin(1)` abgelehnt, oder die Regel wird aufgeweicht. Fall `sin(30 deg)` steht in Done 1, die Abdeckung reicht. Bitte in der Umsetzung ausdrücklich beachten.
- Vorprüfung bei x = 0 ist nur eine Heuristik (`ConditionalNode` mit teurem Zweig für x ≠ 0). Die harte Grenze bleibt das Laufbudget. Ein Testfall dafür würde Blocker 1/3a gleich mit abdecken.
- Done M8c 3 „200 Zeilen schwer, erlaubt“: Offen ist, ob die Frist 150 ms zwischen den Zeilen dabei „nicht berechnet“ auslösen darf. Erwartetes Ergebnis festlegen (alle Zeilen berechnet oder Abbruch mit Hinweis).
- Keine neuen Lücken bei Rechten/Sperre, Sanitizer (§1.1a), CSP oder `src/`-Patches (weiter nur P38).
