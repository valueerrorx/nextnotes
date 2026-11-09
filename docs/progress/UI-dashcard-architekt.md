# UI-dashcard – architekt
Status: fertig
Auftrag: Dashboard: Klick auf Aufgabenlisten-Karte wechselt wie Notizkarte in die Hauptansicht `index.php?note=&workspace=` und merkt `last_opened_workspace`; Modal entfällt. SPEC §4.3 Nr. 29, E2E dashboard-ws.mjs.
Worktree: .claude/worktrees/agent-a2fb159579e63f44a (auf nextcloud-port e2beab5c zurückgesetzt, war upstream main)
## Erledigt
- Analyse: Upstream `dashboard-page.js` öffnet Modal nur, wenn `window.poznoteDashboardNoteModal.canOpen` (tasklist); sonst normaler Link `note.url` (hat `workspace=`), den der vorhandene Klick-Hörer in nextnotes-late.js schon merkt. Karte hat keine bedienbaren Häkchen (nur Lucide-Icons). Auswahlmodus fängt Klicks in Capture-Phase ab (unverändert).
- Entscheidung: `js/dashboard-note-modal.js` per `omit` aus Bündel `dashboard` (bundles.json), Modal-Markup + `DASHBOARD_NOTE_MODAL_TXT` aus templates/dashboard.php, `#dashboardNoteModalOpen` aus late.js. Kein src-Patch.
- Umgesetzt: build/bundles.json (omit), templates/dashboard.php (Modal + DASHBOARD_NOTE_MODAL_TXT raus), js-src/nextnotes-bootstrap.js (Global-Name raus), js-src/nextnotes-late.js (#dashboardNoteModalOpen raus), SPEC §4.3 Nr. 29, E2E dashboard-ws.mjs Schritt 4 neu
- make build 0, check-upstream grün; Instanz nextnotes-uidc sqlite :11401 (up 0, install 0, enable 0)
- E2E dashboard-ws hell+dunkel grün (je 43), 0 CSP/Konsole
- Gegenprobe (bundles.json + Template von HEAD): rot (Modal-API vorhanden, kein Wechsel, waitForURL-Timeout); danach wiederhergestellt, neu gebaut/installiert
## Nächster Schritt
- keiner (Commit)
## Offen
- Auswahlmodus (Touch-Langdruck) nicht per E2E geprüft; Upstream-Capture-Handler unverändert
