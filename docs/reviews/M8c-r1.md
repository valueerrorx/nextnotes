# M8c – Diff-Review r1 (speccritic)

VERDICT: BLOCKER

Grundlage: M8-Plan §1, §1.1, §1.1a, §4 (Done 1–8), ADR 0018 mit Nachtrag M8c, SPEC §4.7, Arbeitsbaum gegen HEAD af1b3cc0.
Messungen in Node gegen `nextnotes/js/vendor/mathjs/mathjs.min.js` (ohne Test-Flag), Skripte unter
`/tmp/claude-1000/-home-valueerror-STUFF-DEV-GITHUB-nextnotes/64382532-f18a-4842-8051-e84227576752/scratchpad/calc/` (`a1`–`a8.mjs`, `v.php`, `meta.cjs`).

## Blocker

1. **Rechenbudget gilt je Block, nicht je Task. Viele Blöcke frieren den Leser ein** (`js-src/nextnotes-blocks.js` `mount`, `js-src/nextnotes-calc.js` `view`/`draw`, Plan R5, Done 3/3a „kein Long Task > 300 ms, Tab bleibt bedienbar“).
   Alle Hosts hängen am selben deduplizierten `ensureScript`-Promise. Ihre `then`-Callbacks und die inneren `poznoteEnsureFunctionPlot().then` laufen deshalb im selben Microtask-Checkpoint, also in **einer** Task. Jeder Block bekommt ein frisches Auswertungsbudget (150 ms), eigene Vorprüfungen und ein eigenes Laufbudget (100 ms).
   Messung `a7.mjs`: 669 Byte `src` je Block (`p` = 19× `permutations(10000;10000)`, `q` = 30× `p`, drei Zeilen `q(1)+q(1)`, `k(x)=q(x)`, `graph` 1). Synchrone Zeit: **K=1: 313 ms, K=5: 1 569 ms, K=20: 6 199 ms**. Sie wächst linear mit der Blockzahl und ist nur durch die Notizgröße begrenzt.
   Treffer: Leser fremder Notizen, z. B. eine Lehrkraft, die eine Abgabe öffnet. Bei Inhabern gilt dasselbe, denn `evaluate()` läuft beim Mount jedes Blocks und bei jedem Moduswechsel.
   Gefordert: Jeder Block rechnet in einer eigenen Task, z. B. über eine Warteschlange mit `setTimeout` je Block und vor `draw`. Zusätzlich braucht es eine Obergrenze für die Rechenzeit je Seite bzw. je Moduswechsel. Danach zeigen weitere Blöcke nur den Fallback.
   Nötig ist ein E2E-Fall mit mindestens 10 solcher Blöcke als Leser: kein Long Task > 300 ms.

2. **Vorprüfung mit frischem 100-ms-Budget je Funktion: ein Block allein überschreitet 300 ms** (`build/vendor-entries/mathjs.mjs` `plotFn`, ADR-0018-Nachtrag M8c Punkt 2, Done 3a).
   Messung `a5.mjs`: `p` wie oben, `q1`…`q5` = 50× `p`, Leser-Pfad. **probe 391 ms + Zeichenlauf 102 ms = 492 ms synchron.** Obergrenze: etwa 5 × 100 + 100 + 150 (Auswertung) ≈ 750 ms. Im Inhabermodus kommen `evaluate()` und `draw()` im selben Task dazu, sobald function-plot schon geladen ist.
   Gefordert: ein gemeinsames Vorprüfungsbudget für alle Funktionen eines Blocks (ein `newBudget(PLOT)` vor der Schleife) und `draw` in einer eigenen Task. Damit ist der Block im Worst Case unter 300 ms (150 / 100 / 100 in getrennten Tasks).
   Testfall: 5 Funktionen mit je etwa 90 ms Einzelaufruf, Long Task ≤ 300 ms.

## Geprüft ohne Befund (Messung `a1`–`a3`, `a8`)
- Sandbox:
  - Gesperrt sind `constructor`, `__proto__`, `toString`, `valueOf` (Symbol, Ziel, Parameter), Accessor-, Index-, Object-, Array- und RangeNode, BlockNode, optionaler Aufruf, Zeichenketten inkl. `\u`-Escapes und `null`.
  - Ebenso gesperrt: `typed`, `config`, `import`, `chain`, `help`, `resolve`, `simplify`, `matrix`, `number`, `add(…)` und Bit-Operatoren, `'`, `.*`.
  - Callbacks und Funktionswerte sind nicht übergebbar (`sum(f)`, `y=f`, `g(h)=h(1)` → abgelehnt).
  - `(sin)(1)` und `(f)(2)` werden abgelehnt, `f(1)(2)` ergibt `f(1)·2`. Der FunctionNode wird im Aufrufkontext gegen `CALLS` bzw. eine definierte Funktion geprüft.
  - Verdecken von Einheiten (`deg = 2`, `m = 2`, `km = 5`) führt zu keiner gesperrten Funktion: Funktionsnamen kommen nie aus dem Scope, weil `name in math` für Ziele und Parameter gilt.
- Größen und Wachstum:
  - `m^1e308` ist harmlos. Ausgaben > 200 Zeichen ergeben `type`, das Ergebnis wird nicht gespeichert.
  - Deckel greift: `170000!` und `combinations(1e9;5e8)` brechen ab.
  - Eine schwere Zeile ohne Benutzerfunktion braucht ≤ 5,5 ms. 199 schwere Zeilen: 86 ms.
- Budgets:
  - f20: 11,5 ms → `heavy` + `skip`. g60 → `heavy`.
  - f6/f7/g40 bei 300 Stützstellen wie spezifiziert.
  - `node --test tests/nextnotes/node/calc-engine.mjs`: 10/10.
- function-plot (`helpers/eval.js`): Eine Funktion als `fn` wird direkt aufgerufen. Es gibt keine Optionen mit Nutzertext, Titel, Labels oder Annotations. `checkAsymptote`/`tip` rufen `fn` zusätzlich auf, laufen aber über dasselbe Budget.
- Sanitizer, `validCalc` per Host-PHP (`v.php`):
  - Ungültig: `1.0`, `true`, `"1"`, Zusatzschlüssel, `""`-Schlüssel, `\ud800`, `1e999`, 201 Zeilen, 501 Zeichen, > 64 KiB (Escapes zählen).
  - Doppelschlüssel nimmt in PHP und JS den letzten Wert.
- Markdown:
  - `codeBlock` passt zur Renderer-Regex (`MarkdownRenderer.php:830` schließt nur bei ```` ``` ```` + `\n`).
  - ```` ```` ```` schließt nicht.
- Shadow DOM und Text:
  - In `nextnotes-calc.js` und `nextnotes-blocks.js` kein `innerHTML`, nur `textContent`.
  - Leser haben kein Textfeld; `<slot>` bleibt.

## Hinweise
- Abweichung 1, Semantik: Ein Name, der eine Einheit verdeckt, wirkt rückwirkend auf früher definierte Funktionen. `f(x) = x * b`, `f(2)` → `2 b`, dann `b = 3`, `f(2)` → `6`. Der ADR-Nachtrag sagt dagegen „wirkt erst ab der Folgezeile“. Kein Sicherheitsproblem. Entweder Einheiten in Funktionsrümpfen bei der Definition binden oder den ADR-Text korrigieren.
- Lizenzen:
  - `internmap@2.0.3` (ISC, Mike Bostock) steckt inline in `d3-array/dist/d3-array.mjs` und damit im Bündel (`_intern`). Es steht nicht in THIRD-PARTY-NOTICES. Die Metafile-Prüfung sieht es nicht.
  - Sonst passt die Aufstellung zum Lockfile: BSL-1.0 für `interval-arithmetic`, Apache-2.0 inkl. NOTICE für math.js, BSD-3 für `d3-ease`.
- Plan-Testnachweis nennt `tests/nextnotes/e2e/calc-guard.mjs`, umgesetzt ist `tests/nextnotes/node/calc-engine.mjs`. Der Plan sollte das nachziehen.
- CSP und 0 Verletzungen habe ich nicht selbst gemessen (keine Container). Der Nachweis liegt beim Tester-E2E. Im Bündel ist `new Function` nur über String-`fn` erreichbar (math-codegen).
