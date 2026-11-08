# Review M8a r1 (Diff gegen HEAD 46f575e4, Arbeitsbaum)

VERDICT: FREIGABE

Geprüft: BlockMarkup, NextBlocks, HtmlSanitizer, ConversionService, nextnotes-blocks.js, nextnotes-formula.js, bootstrap (poznoteEnsureMathLive), build/bundles/check-notices, note_display.php, PageAssets, P38, SPEC §4.3.9/§4.7/§11.1/§11.2/§13, ADR 0018 Nachtrag, Tests (BlockMarkupTest, BlocksConversionTest, FormulaB69Test, blocks-formula.mjs), mathlive 0.111.4 (openUrl, htmlData-href, validateInlineStyle, \newcommand nur Register).

Keine Blocker. Sanitizer: Host am Attribut, Länge/Leere, Mehrfachattribut, Host-in-Host, feste Attributreihenfolge (idempotent, getestet), Kinder → Textknoten, Ausgabe über saveHTML escaped Text und Attribut; Host nur span/div, Rohtext-Eltern (noscript/textarea/style) sind STRIPPED → kein mXSS-Pfad gefunden. Kein innerHTML im neuen JS; KaTeX trust:false; openUrl nur http/https + noopener; Fonts absolut, keine Sounds; Laden per ensureScript (strict-dynamic), keine CDN-Quelle. Leser/Sperre: Popover, Slash-Einträge, Enter und Werkzeugleiste prüfen writable(); Server-PATCH bleibt einzige Schreibstelle. Markdown-Abweichungen (Zaun nur ```, `\char36{}` inline) folgen aus dem Port-Renderer (Inline-Regex ohne `$`, nur Dreifach-Zäune), sind in SPEC §4.7.4 dokumentiert und getestet: akzeptabel.

## Hinweise
1. Done 4 nennt „Leser (Lesefreigabe, Material)“; E2E prüft nur Material. Code-Pfad identisch (canWrite → data-nextnotes-readonly), trotzdem einen Lesefreigabe-Fall ergänzen (billig).
2. Client begrenzt `data-math` nicht auf 10 000 Zeichen. Längere Formel → Server entfernt `data-math`, die im Editor gerenderte KaTeX-Ausgabe wird als Text gespeichert (B69-Muster). In commitFormula ablehnen bzw. Hinweis zeigen.
3. SPEC §11.1 Zeile P38 hat 3 statt 5 Spalten (Begründung, Upstream-PR-Kandidat fehlen).
4. `docs/app/user.md` steht in der Plan-Dateiliste M8a, wurde nicht geändert (Bericht: M7h-Bereich). Lead: nachziehen oder bewusst nach M8b verschieben.
5. Shadow-DOM-Mount und Fallback-Neuerzeugung (§1.1) erst in M8b: im M8b-Auftrag ausdrücklich nennen.
6. Sperre geht bei offenem Popover einer *neuen* Formel verloren → Platzhalter wird entfernt und `input` auf der gesperrten Notiz ausgelöst (folgenlos, Server lehnt ab; ggf. ohne changed()).
7. Kleinkram ohne Handlungsbedarf: `\\$` in Inline-LaTeX wird zu `\\char36{}` (ungültiges LaTeX ohnehin); SPEC-Diff verschiebt nebenbei Zeile R18 aus §13 nach §12 (Korrektur aus M7h, unkritisch).
