# UI-excal – architekt

Auftrag: Excalidraw-Haupttoolbar (oben Mitte) zu kleine/überlappende Icons (Thomas 2026-11-09); Ursache finden, eng begrenzt beheben, E2E hell+dunkel.
Status: fertig

## Erledigt
- Worktree auf 7f64db29 (nextcloud-port ist inzwischen 72885eaf: SPEC.md und nextnotes-layout.css dort geändert, beim Mergen beachten)
- Befund (Vorher: tests/nextnotes/e2e/out/excalidraw-toolbar-*-vorher-*.png, nicht im Repo):
  1. App-Reset nextnotes-layout.css (0,5,1, revert) überstimmte Excalidraw: versteckte Werkzeug-Radios opacity 1 (native Kreise über Icons), Knöpfe UA-Rahmen, 21 px
  2. Darunter NC-Kern core/css: margin 3px, min-height 34, Hover/Fokus-Hintergrund (Farbfelder hellblau), kbd/h2, cursor default
  3. NC-Wurzelschrift 15px -> rem 6 % kleiner (bleibt)
- Abwägung: CSS-Gegenregeln (Experiment) lassen Farbfeld-Hover/Fokus und 0,1,0-Regeln falsch; iframe ohne NC-CSS: eigene Route/Vorlage, Theme-/Speichern-Brücke, gegen „kein iFrame“ -> verworfen; gewählt: CSSOM-Ausnahme mit fester Liste (Lead-Bedingungen 1–5 erfüllt)
- Code: nextnotes-layout.css (Reset :not(.excalidraw *)), nextnotes-late.js (EXCAL_NC_PARTS, nur core/css, beim Mounten von .excalidraw, idempotent, try/catch)
- Doku: SPEC §4.3 Punkt 34 (beim Übernehmen umnummeriert), ADR 0017 Nachtrag UI-excal, ADR 0012 Verweis
- Test: tests/nextnotes/e2e/excalidraw-toolbar.mjs (31 Prüfungen) hell+dunkel grün; Gegenprobe alt rot (5), nur CSS rot (3)
- build Exit 0, check-upstream Exit 0
- Regression hell excalidraw + smoke-m4 grün

## Offene Punkte
- 390 px: Upstream-Leiste „Beenden ohne zu speichern“ abgeschnitten (andere Ursache, nur gelistet)
- .excalidraw-tooltip an body nicht ausgenommen
