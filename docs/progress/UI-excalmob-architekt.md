# UI-excalmob – architekt
Status: fertig
Auftrag: Excalidraw bei 390 px: Knopf „Beenden ohne zu speichern“ abgeschnitten; alle Knöpfe der App-Kopfleiste vollständig sichtbar/bedienbar, Desktop unverändert. SPEC §4.3 Nr. 34, E2E excalidraw-toolbar.mjs (390 px).
Worktree: .claude/worktrees/agent-a2fb159579e63f44a (nach UI-dashcard f6bec01e)
## Erledigt
- Ursache (Lesen): Upstream excalidraw.css ≤800 px: ganze `.poznote-toolbar` scrollt waagrecht (`overflow-x: auto`, Titel natürliche Breite), Knöpfe `white-space: nowrap`, ≤480 px 11 px/4×8 px Padding → drei Knöpfe + Titel breiter als 390 px, letzter Knopf ragt aus der Leiste
- E2E excalidraw-toolbar: je Breite App-Knöpfe im Fenster/in der Leiste, Mitte trifft Knopf, nicht abgeschnitten, keine Überlappung, #app unter Leiste
- Gegenprobe vor Fix (hell): rot („Beenden ohne zu speichern“ r=421 > 390)
- Fix css-src/nextnotes-layout.css (≤800 px): Leiste ohne Scroll, Padding !important (Upstream `padding … !important`, Höhe 48 px !important), Knöpfe umbrechend gleich hoch, Titel flex 1 1 0 scrollt; erster Versuch mit min-width:0 am Knopf schnitt „Speichern“ ab → entfernt
- E2E excalidraw-toolbar (39) + excalidraw (71) hell+dunkel grün; make build 0, check-upstream grün; SPEC §4.3 Nr. 34 ergänzt
## Nächster Schritt
- keiner (Commit)
## Offen
- Titel bei 390 px praktisch unsichtbar (Platz geht an die Knöpfe)
