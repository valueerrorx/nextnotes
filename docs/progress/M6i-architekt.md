# M6i Architekt – Aufgaben an den Team-Workspace binden
Status: fertig

## Auftrag
assignments.php folgt dem Frame-Workspace (Team → nur dieses Team, sonst leerer Zustand), Rail-Eintrag nur in Team-Workspaces, Austeilen-Menü nur Team-WS + Lehrkraft, Links mit Team-Workspace, Teams-App-Ressource „Aufgaben“.

## Erledigt
- Kontext gelesen (STATUS M6, SPEC §3/§9, ADR 0003, AssignmentsViewService, icon_sidebar, assignments.php)

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte
- Entwurf: Heimat = erster lesbarer Team-WS (kleinste ID); Seite Team-WS → nur dieses Team; sonst leerer Zustand + Liste „ohne Team-Workspace“ (Team ohne lesbaren Team-WS: Austritt, verwaist, eingefroren, O6) → [THOMAS]
- WorkspaceService::teamWorkspaceKeys/assignmentsKey ergänzt
- Code: AssignmentsViewService (railLabel(ws), page, teacherTeam, createSource, assignmentUrl; railVisible/teachesAny/teacherTeams/createTeam entfernt), PageController, IndexViewService (canAssignFrom, railAssignments), Notifier (assignmentLink per uid, prepareTeam), WorkspaceResourceProvider (Ressource assignments:<team>), templates/assignments.php (page_title-Chip, leerer Zustand), nextnotes-assignments.js (inScope, kein Team-Select, Suche je Team-WS), CSS
- l10n de/de_DE: 4 neue Zeilen; SPEC §4.2-Tabelle, §3 Teams-Ansicht, UI-rail 26, §9.1, §9.6
- Tests: AssignmentsViewTest (neu testPageFollowsTeamWorkspace, Banner-Link), AssignmentMembersCacheTest (teacherTeam), TeamResourceProviderTest (Aufgaben-Ressource, O6)
- Lead-Nachtrag Icon Doktorhut currentColor: bereits umgesetzt
- E2E assignments-m6g + smoke-m6 angepasst (node --check ok)
- make build rc=0, check-upstream rc=0
- Instanz nextnotes-m6i sqlite :9001; PHPUnit AssignmentsView|MembersCache|TeamResourceProvider|AssignmentNotification 6 OK
- PHPUnit sqlite Assignments|Teams|Page|Material|Notification|Search: 221 OK (rc 0)
- E2E assignments-m6g hell grün 139
- E2E smoke-m6 hell grün 48; Instanz nextnotes-m6i abgebaut
