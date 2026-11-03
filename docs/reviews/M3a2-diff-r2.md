# M3a2 Diff-Review r2 (speccritic)

VERDICT: FREIGABE

Blocker r1 (Zusatz hinter Pseudo-Element) ist behoben und wird abgesichert.
- `nextnotes/build/css-bump.mjs:11-13,56-62`: `PSEUDO_TAIL` erfasst das Pseudo-Element am Ende samt aller folgenden `:`/`::`-Teile (auch mit Argumenten). Der Zusatz steht davor. Grenzfälle mit Node gegen das Modul geprüft, alle richtig: `::before:hover`, `::-webkit-scrollbar-track:horizontal:hover`, `::before:not(.x)`, `::after:is(:hover)`, `::part(label):hover`, `::slotted(.b)`, `::highlight()`, `::view-transition-old(root)`, Legacy `:before`/`:first-letter`, `:is(.a,.b)::before`, `.a:not(.b)::before`, Kombinator vor Pseudo-Element (`.a > ::before` → `*`), Selektorlisten mit gemischten Fällen, verschachteltes `@media`/`@supports`, `@keyframes`/`@font-face` unverändert, Kommentare und Strings mit `::` und `{`.
- Build mit `OUT_DIR=` ins Scratch-Verzeichnis lief mit Exit 0 (123 Dateien). `check-css.mjs` gegen diese Ausgabe ebenfalls Exit 0: Regelzahl in Chromium vor/nach Zusatz gleich (core 2061, modals 1456, dark 632, mobile 100, syntax 46). Die r1-Fälle (`#right_col`, `pre.code-block`, `body.note-col-fits …::-webkit-scrollbar-thumb:hover`) sind jetzt gültig.
- Negativprobe: Ungültige Zusätze in eine Kopie der Bundles eingeschleust. `check-css.mjs` meldet beide Fehlerarten (hinter Pseudo-Element, Regel nur durch Zusatz ungültig) und endet mit Exit 1. `check-upstream.sh:55` übernimmt das per `|| fail=1`. Zusätzlich bricht `build.mjs` schon beim Bauen ab (`checkBumped` vor dem Minify, `findBumpAfterPseudo` nach dem Minify).

Hinweise aus r1:
- ensureDefault-Ausnahme: SPEC §1.2 (`docs/SPEC.md:317`) erledigt. §4.4 hat keinen eigenen Satz dazu, der Verweis aus §1.2 genügt.
- `partials/`: M3-plan angeglichen, mit Begründung (`.gitignore`).
- B27/B30: SPEC §11.2 und M3-plan R7/Done M3b.3 sind konsistent (B30 vorgemerkt).
- ADR-Liste im SPEC-Kopf: `0002`–`0017`.

## Hinweise (kein Blocker)
- Die Chromium-Prüfung (e2) sieht nur, was Chromium parst. Ein falsch platzierter Zusatz in einer `-moz-`-Regel fällt dort ohnehin weg. Solche Fälle deckt nur die Regex-Prüfung (`BUMP_AFTER_PSEUDO`) ab. Das genügt, sollte aber bekannt sein.
- CSS-Nesting (`&`) wird nicht umgeschrieben. Verschachtelte Regeln erben den Zusatz über `&`, das passt. Das gezählte Ergebnis wäre bei Nesting aber unvollständig. Upstream nutzt derzeit kein Nesting (Regelzahlen gleich).
- `check-upstream` braucht jetzt Chromium auf dem Host (`CHROMIUM=`). Fehlt es, endet die Prüfung rot (Exit 2). Das sollte in `dev/nextcloud/README.md` bzw. der Testanleitung stehen, falls noch nicht geschehen.
- Tests `tests/nextnotes/e2e/frame.mjs` und `stripped.mjs` sind vorhanden, Inhalt nicht bewertet (Tester arbeitet noch). Der Lead prüft Done 4/5/6 sowie die `#unified-search`-Probe aus r1 vor dem Commit.
