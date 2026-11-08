# M7-fix – Architekt
Status: fertig

## Auftrag
1. Listenzeichen/-nummern in Notizinhalt wiederherstellen (NC-Reset `ul,ol{list-style:none}`), CSS-Override nach ADR 0017. 2. KaTeX-404 bei `index.php?note=<id>` beheben, Mermaid mitprüfen.

## Erledigt
- Ursache 2: NC lädt Skripte mit `defer`; `math-renderer.js` (in nextnotes-app) rendert sofort (`readyState` = interactive), ruft Upstream-`poznoteEnsureKatex` aus `lazy-libs.js` (seitenrelativ) vor `nextnotes-late.js`. Fehler → `_katexLoading` zurück, serverseitig gerenderte Notiz ohne Mutation bleibt ungerendert. Mermaid: gleiche Lücke bei frühem `setTimeout` möglich.
- Entscheidung 2: Asset-Helfer (assetUrl, ensureScript, ensureMermaid/Katex) nach `nextnotes-bootstrap.js`; `lazy-libs` nicht mehr laden/bauen (exclude, ersetzt durch Adapter); late.js setzt nur noch `poznoteAssetUrl` nach `globals.js` zurück.

## Nächster Schritt
- keiner (Übergabe an Tester: Spec aus Scratchpad nach tests/, assets.mjs:90 anpassen)

## Offen
- Regel: `tests/` darf ich nicht ändern → E2E-Spec als Entwurf im Scratchpad, Tester übernimmt; `tests/nextnotes/e2e/assets.mjs:90` erwartet `lazy-libs` in der Reihenfolge → Tester anpassen.
- Umsetzung 2: js-src/nextnotes-bootstrap.js (§15 Asset-Helfer, NEXTNOTES.assetUrl), js-src/nextnotes-late.js (nur poznoteAssetUrl zurück), PageAssets.php (lazy-libs raus), bundles.json (jsSingle/exclude/adapterHooks), SPEC §1.4 + §4.3.9. make build Exit 0.
- Instanz nextnotes-m7fix (9700, sqlite) up + install Exit 0.
- Befund 1 (Messung vor Fix, sqlite hell): Richtext-ul none/pl 0 (NC-Reset), Richtext-ol none + ::before-Zähler (Upstream-Absicht), Markdown-ul/ol disc/decimal ok. KaTeX nach Fix 2: gerendert, keine 4xx.
- Umsetzung 1: css-src/nextnotes-layout.css (Regel 0,0,1 `:where(.noteentry,.diary-journal-body,.paste-markdown-preview,.insert-markdown-preview) :is(ul,ol)` → revert), css-src/nextnotes-printview.css (gleiche Regel für Druck/Abgabe-iframe).
- Spec-Entwurf scratchpad e2e/lists-katex-m7fix.mjs: sqlite hell+dunkel grün (16/16 je Theme), Screenshots ok. Nächster: Gegenprobe KaTeX mit HEAD-JS, dann check-upstream, Abbau.
- Gegenprobe HEAD-JS (stash, build, install): Formel-Notiz ?note= → 404 js/katex/katex.min.js (lazy-libs.js onerror), nichts gerendert, Spec rot (7 Fehler). Neuer Stand wieder installiert: Spec grün hell+dunkel (28/28). Hinweis: data-math nur an span (Sanitizer), Blockformel als span.math-block.
- make -C nextnotes check-upstream Exit 0 (grün). Kein src/-Patch → kein Eintrag SPEC §11.1 / upstream-patches.txt; Port-Fehler, kein Upstream-Bug → kein §11.2.
- Läuft: run-e2e sqlite assets editor smoke-m6 (hell+dunkel), Erwartung: assets.mjs:90 rot wegen lazy-libs in Reihenfolge.
- run-e2e sqlite assets editor smoke-m6 hell+dunkel: editor 119 ok, smoke-m6 48 ok, assets 81 ok + 1 erwarteter FAIL (Skriptreihenfolge erwartet lazy-libs, Index -1).
- ADR 0018 Satz zu lazy-libs angepasst. Instanz nextnotes-m7fix abgebaut (down.sh all Exit 0, 0 Container, 0 Volumes).
