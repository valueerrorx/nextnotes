# M3a1 speccritic (Diff-Review r1)
Auftrag: uncommitteten Baum vs 5f98f548 gegen M3-plan r2 / SPEC / ADRs prüfen, Ergebnis docs/reviews/M3a1-diff-r1.md.
Erledigt: git status/diff (src/ unverändert); Adapter, Build, check-upstream, PageController/Assets/Translator, CspFactory, css-src gelesen; OCP-Stichprobe (IURLGenerator, IAppConfig, EmptyCSP); Inter-Fundstellen gezählt; Review geschrieben.
Ergebnis: BLOCKER (1 Inter→var(--font-face) im Build, 2 ADR-0016-Nachtrag accent-text).
Nächster Schritt: keiner (r2 nach Nachbesserung).
Status: fertig

## r2
Auftrag: Nachbesserung Blocker 1/2 + Hinweise prüfen → docs/reviews/M3a1-diff-r2.md
Erledigt r2: build.mjs/bundles.json/CspFactory/bootstrap.js/ADR 0016/SPEC/Plan geprüft, Regex-Probe per node (Scratchpad). Ergebnis FREIGABE → docs/reviews/M3a1-diff-r2.md
Status: fertig
