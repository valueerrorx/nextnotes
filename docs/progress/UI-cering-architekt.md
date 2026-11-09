# UI-cering – architekt
Status: fertig
Auftrag: NC-Kern inputs.scss: div[contenteditable=true] :hover/:focus/:active → box-shadow-Ring; prüfen, ob im App-Bereich (Editor, Aufklappbereich, Tabellen/Rechenblöcke, Aufgabenliste, Kanban) noch greift; ggf. in nextnotes-layout.css neutralisieren, sonst nur Test. SPEC §4.3 Nr. 23.
## Erledigt
- Kern-Regel gelesen (.ref core/css/inputs.scss): `div[contenteditable=true]:not(:disabled):hover|focus|active` (0,3,1) box-shadow 0 0 0 2px main-background; border-color dort ungültig (`2px solid …`) → nur Ring
- App-CSS: nur `.toggle-content` hat box-shadow: none (UI-toggle); `.noteentry` ohne Reset
- E2E-Entwurf tests/nextnotes/e2e/ce-ring.mjs (Notiz, Aufgabenliste, Markdown, Kanban; Hover+Fokus box-shadow = none)
- Ist (ce-ring hell, vor Fix): Ring greift an `.noteentry` (Hover+Fokus `rgb(255,255,255) 0 0 0 2px`) und CodeMirror `.cm-content` (Markdown) = Gegenprobe rot; `.toggle-content` hat kein contenteditable-Attribut (Kind des Editors); Aufgabenliste: Editor contenteditable=false, Eingaben `input`; Kanban ohne contenteditable
- Fix css-src/nextnotes-layout.css nach dem contenteditable=false-Reset: `:where(#nextnotes-root) div[contenteditable="true"]:not(:disabled):is(:hover,:focus,:active):not(.excalidraw *)` box-shadow none (0,4,1)
- make build 0, install 0; ce-ring hell+dunkel grün (je 15); SPEC §4.3 Nr. 23 ergänzt
- Regression hell+dunkel grün: toggle-ui 39, editor 119, excalidraw-toolbar 39, excalidraw 71, dashboard-ws 43; check-upstream grün
## Nächster Schritt
- keiner (Commit, Instanz abbauen)
## Offen
- Befund außerhalb Scope: Aufgaben-Eingabefeld (`.task-input`) zeigt beim Fokus weder outline noch Rahmen/Ring (outline-style none) – nicht geändert
