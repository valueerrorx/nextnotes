# M3a2 speccritic
Auftrag: Diff-Review M3a2 (Arbeitsbaum gegen 91435688 + untracked) gegen M3-plan M3a2, SPEC, ADRs.
Status: fertig
Erledigt r1:
- Plan, SPEC, ADR 0017, build.mjs, bootstrap.js, PageController, IndexViewService und Templates geprüft → docs/reviews/M3a2-diff-r1.md (BLOCKER: Pseudo-Element)
Erledigt r2:
- css-bump.mjs, check-css.mjs, check-upstream.sh (e/e2), SPEC-/Plan-Diff gelesen
- Grenzfälle per Node-Skript (Scratch t.mjs) gegen bumpSpecificity: alle ok
- OUT_DIR=<scratch>/out node build/build.mjs → Exit 0; check-css.mjs gegen Scratch-Ausgabe → Exit 0
- Negativprobe mit manipulierten Bundles → check-css Exit 1, beide Fehlerarten gemeldet
- r1-Hinweise erledigt (§1.2 Ausnahme, partials, B27/B30, ADR-Liste 0017)
- Ergebnis: docs/reviews/M3a2-diff-r2.md (FREIGABE + 4 Hinweise)
Nächster Schritt: keiner
