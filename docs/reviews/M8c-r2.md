# M8c – Diff-Review r2 (speccritic)

VERDICT: FREIGABE

Grundlage: r1-Blocker B1/B2, Architekt-Abschnitt „fix“, Arbeitsbaum gegen HEAD af1b3cc0.
Messung im echten Browser (Chromium headless, playwright-core aus dev/nextcloud) mit den gebauten Dateien
`js/nextnotes-blocks.js`, `js/nextnotes-calc.js`, `js/vendor/mathjs`, `js/vendor/function-plot`. Long Tasks per PerformanceObserver.
Statische Testseite, kein Container. Skripte: `/tmp/claude-1000/-home-valueerror-STUFF-DEV-GITHUB-nextnotes/64382532-f18a-4842-8051-e84227576752/scratchpad/calc3/` (`run.mjs`, `m1`–`m3.mjs`, `lic.cjs`, `u.mjs`).

## r1-Blocker: behoben
- B1 (`m1.mjs heavy 12`): Je Schritt läuft eine eigene Task (setTimeout je Job). Muster der Long Tasks: ~155 / ~105 ms abwechselnd (Einhängen / Vorprüfung).
  - Leser: max 157 ms, Inhaber: max 156 ms.
  - 4 von 12 Blöcken werden gerechnet, 8 zeigen den Knopf.
  - Bei 4-facher CPU-Drosselung bleiben die budgetierten Tasks ≤ 178 ms.
- B2: `plotFn` prüft mit einem gemeinsamen Budget vor. Zoom heraus in einen x-abhängig teuren Bereich (`m3.mjs`) ergibt eine Task mit 104 ms, danach „zu aufwendig“. function-plot sampelt bei Zoom, Pan und Tip über dieselben Wrapper. Je Ereignis gilt ein 100-ms-Lauf (Microtask-Grenze), danach `dead`.
- Ohne Klick erreicht ein Schreibberechtigter bei anderen keine Task über 300 ms. Geprüft: Leser, Inhaber, Moduswechsel, Zoom/Hover. `fresh` und `rec.user` entstehen nur durch Einfügen oder Klick.

## Weitere Prüfpunkte
- Einheiten-Regel: `assigned` ist nur eine zusätzliche Ablehnungsbedingung, es öffnet sich keine Lücke. `constructor`, `toString`, `__proto__`, `hasOwnProperty` bleiben reserviert (`u.mjs`). Inhaber und Leser haben dieselbe Semantik.
- Build-Patch: Die Zeichenkette kommt in `dist/samplers/builtIn.js` genau einmal vor. Im Bündel steht `s=e.length?[e[0]]:[]`. Ersetzt wird nur in dieser Datei, `to` enthält kein `$`.
- Lizenzen (`lic.cjs`, Metafile mit bytesInOutput > 0): internmap, quickselect und lruQueue sind gelistet. CSparse ist nicht im Output. ISC.txt, BSL-1.0.txt und BSD-3-Clause.txt liegen vor.
- Produktfolge (`m1.mjs school`): 30 bzw. 100 typische Schulblöcke (Mittelwert, Prozent, Zinsen, Einheiten, 2 Funktionen mit Graph) werden als Leser und Inhaber alle gerechnet und gezeichnet. Das gilt auch bei 4- und 6-facher Drosselung (30 Blöcke).
- Node: `calc-engine` + `block-queue` 13/13.

## Hinweise (kein Blocker)
1. Seitenbudget ist je leerer Schlange, nicht je Seite (`m2.mjs`). Bei einem Sperrwechsel (contenteditable) alle 3 s, so oft prüft note-edit-lock, startet das Budget jeweils neu. Ergebnis: 40 Long Tasks, max 153 ms, Summe 4,3 s in 12 s. Die Tasks sind kurz, der Tab bleibt bedienbar, aber die CPU-Last ist dauerhaft. Dazu kommen höchstens 2–3 zusätzliche Budgets, wenn Bibliotheken nach dem Leeren der Schlange eintreffen. Das passiert z. B., wenn die fn-Regex `\w\s*\(` Unicode-Namen wie `κ(x)=` nicht erkennt. Optional: Budget je Notiz oder Entry merken.
2. Der Build-Patch bricht nur ab, wenn die Datei geladen wird und nicht genau ein Treffer vorkommt. Wird sie nach einem Paket-Update gar nicht mehr geladen, fehlt der Patch still. Empfehlung: Nach dem Build prüfen, dass jeder Patch angewandt wurde. Die Version ist exakt gepinnt.
3. Library-Parse ist eine eigene Task: ~100 ms normal, ~385 ms (4×) bzw. ~584 ms (6×) bei Drosselung, auch bei einem einzigen Block. Sie gehört nicht zum Fix und ist nicht angreiferabhängig.
4. Graph-Knopf mit offener Eingabe (`calc.js` bGraph): zweimal `evaluate()` in einem Klick, bis 2×150 ms. Das wird vom Nutzer ausgelöst.
5. Lizenzen: Die Snippets aus es6-shim (MIT, Paul Miller) in `mathjs/utils/number.js` (cbrt) und `hypot.js` sind nicht genannt. Das ist geringfügig, Nennung optional.
6. Semantik der Einheiten-Regel: In `x = 5 m` und danach `m = 2` ist jetzt die erste Zeile „unbekannt: m“. Das ist konsistent, sollte für Schüler aber in user.md stehen (prüfen).
