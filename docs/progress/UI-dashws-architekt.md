# UI-dashws – architekt
Status: fertig
Auftrag: Dashboard (Mehrfach-Bereich): Klick auf Workspace-Kopf/Kennzeichen und auf Karten bringt in den Workspace (`?workspace=`) und merkt `last_opened_workspace`. E2E dashboard-ws.mjs. Kein neuer Endpunkt, möglichst kein src-Patch.
Worktree: .claude/worktrees/agent-a9258b237c495cd7d (Branch worktree-agent-a9258b237c495cd7d, auf nextcloud-port fba89c32 zurückgesetzt, war upstream main)
## Erledigt
- Analyse: Notizkarten-URL hat schon `workspace=` (DashboardViewService::notes `url`), Modal-Link „Im Editor öffnen“ = note.url. Kaputt: (1) Gruppenkopf `.dash-group-title`, Brotkrumen `.bc-workspace`, Karten-Kennzeichen `.board-card-ws`/`.dash-card-ws` nicht klickbar; (2) `last_opened_workspace` wird nie gesetzt (B28: GET ohne Seiteneffekt) → andere Ansichten ohne Parameter/App-Start bleiben im alten Workspace.
- Entscheidung: Ziel Kopf = Hauptansicht `index.php?workspace=` (dort Seitenleiste/Ordner des Workspace; „nur diesen im Dashboard“ bietet schon der Bereichs-Dialog). Umsetzung nur `js-src/nextnotes-late.js` (Kopf/Brotkrumen als echte Links per MutationObserver, Kennzeichen per Klick-Delegation, PUT last_opened bei jedem Wechsel-Klick) + `css-src/nextnotes-layout.css`. Aufgabenlisten-Karte öffnet weiter das Upstream-Modal (kein Wechsel), erst „Im Editor öffnen“ wechselt. Gespeicherter Dashboard-Bereich bleibt unverändert.
- Umgesetzt: js-src/nextnotes-late.js (rememberWorkspace gemeinsam mit UI-ws, Dashboard-Block dashInit), css-src/nextnotes-layout.css (UI-dashws-Block)
- Instanz nextnotes-dashws sqlite :10401 läuft (up.sh 0); make build 0
- install-app 0, app:enable 0; E2E tests/nextnotes/e2e/dashboard-ws.mjs (Auftrag Lead) hell grün (42); Testfix: Board stellt letzten Ordner aus localStorage wieder her → Spec klickt zurück zur Wurzel
- dashboard-ws dunkel grün (42); Regression hell: workspace-chip 140, views-m4g 71, ui-nav 85, smoke-m4 148 grün
- SPEC §4.3 Nr. 29 ergänzt; make build check-upstream 0; kein src-Patch
- Instanz nextnotes-dashws abgebaut (down.sh all 0), Symlinks entfernt
## Nächster Schritt
- keiner
## Offen
- Aufgabenlisten-Karte wechselt nicht (Modal bleibt), erst „Im Editor öffnen“
- Hauptansicht mit ?workspace= aus anderen Quellen (Erwähnungen, Lesezeichen) merkt weiter nichts (B28), nicht im Scope
