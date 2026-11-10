# UI-taskfocus – Architekt
Status: fertig
Auftrag: `.task-input` (Aufgabenliste) ohne sichtbaren Fokus; Ursache finden, NC-Fokus geben; andere App-Eingaben prüfen. E2E hell+dunkel, Gegenprobe, SPEC §4.3 Nr. 23. Ein Commit.
## Erledigt
- Worktree auf nextcloud-port 234d5771 (reset --hard)
- Instanz nextnotes-uitf (sqlite, :11501) hoch, npm ci build, make build 0, install+enable
- Probe-Scan (computed vor/nach Fokus, hell+dunkel): Ursache .task-input: Upstream-Ring rgba(--pz-accent-text-rgb fest Upstream-Blau, .1) unsichtbar; hell Rahmen 1px nur ~237→dunkelblau; dunkel überstimmt `html[data-theme=dark] .task-input` (1,2,1) den `:focus`-Rahmen (1,2,0) → nur unsichtbarer Ring. Gleich: .task-edit-textarea (dunkel), .searchbar-input (dunkel nichts, hell grau 219→176). #inputModalInput (body, Inline-Rahmen #ddd): nur NC-Ring in Hintergrundfarbe. .css-title (randloser Titel) bewusst ohne. NC-Formular-Reset nicht Ursache.
- Fix css-src/nextnotes-layout.css (Block UI-taskfocus nach UI-cering-Regel)
- Spec tests/nextnotes/e2e/focus-ring.mjs geschrieben
- Gegenprobe final (neue Spec, alter Build): hell 7 / dunkel 10 FAIL, rot; Fix zurückkopiert
- Fix zurück, make build 0, check-upstream grün, install 0
- SPEC §4.3 Nr. 23 ergänzt, B71 in §11.2
- Regression run-e2e sqlite focus-ring ce-ring editor dialogs diary-tasks mention-tasks-m6c layout-left × hell/dunkel: grün (Exit 0), 0 CSP/Konsole
- down.sh all (nextnotes-uitf) Exit 0; Commit
## Offen
- .tasklist-embed-add-input und .task-subitem-input nicht per E2E geprüft (nur Regel)
