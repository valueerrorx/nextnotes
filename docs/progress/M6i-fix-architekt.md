# M6i-fix Architekt – Rail „Aufgaben“ beim WS-Wechsel ohne Reload
Status: fertig

## Auftrag
Rail-Eintrag „Aufgaben“ und Austeilen-Menü sollen beim Workspace-Wechsel ohne Reload sofort passen; E2E assignments-m6g ergänzen.

## Erledigt
- Start, Kontext M6i gelesen

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte
- Ursache 1: switchToWorkspace (workspaces-create.js) ersetzt nur #left_col und Rail-Links; Rail-Eintrag/nn-teacher bleiben vom Seitenaufruf
- Plan 1: Eintrag auf index.php immer rendern (hidden außerhalb Team), Marker in left_col.php, nextnotes-teams.js MutationObserver auf #left_col synct hidden + body data-nn-teacher (Muster upstream Git-Knöpfe)
- Lead-Nachtrag: Bug 2 „Neue Aufgabe“ (#nnAsgNew) ohne Funktion → nachstellen per Klick
- Fix 1 umgesetzt: AssignmentsViewService::railText, IndexViewService (scope, railAssignments immer Text), PageController (railAssignmentsHidden), icon_sidebar.php (hidden), left_col.php (Marker .nn-ws-scope), nextnotes-teams.js (syncWorkspaceScope + Observer)
- Instanz nextnotes-m6ifix :9501 wird hochgefahren (Hintergrund)
- Bug 2 nachgestellt (Klick, frischer Browser): funktioniert → kein Code-Fehler im aktuellen Stand
- Befund Bug 2: ?v= hängt nur an App-Version (0.3.3 unverändert seit M6d) + Theming-Cachebuster; JS mit Cache-Control max-age=15778463, immutable → Browser behält altes nextnotes-assignments.js (M6g: teamSelect.addEventListener auf #nnAsgTeam, das M6i aus dem Template entfernt hat) → TypeError vor newBtn-Listener
- Bug 2 mit altem Skript per page.route nachgestellt: PAGEERR „Cannot read properties of null (reading 'addEventListener')“, Knopf sichtbar, kein Dialog
- Fix 2: assignments.php #nnAsgNew hidden, nextnotes-assignments.js blendet nach Verdrahtung ein (nur mit teacher), CSS [hidden]-Regel
- Nächster Schritt: E2E assignments-m6g ergänzen, PHPUnit-Assert Zeile 60 anpassen, Build, Lauf
- Build rc=0, check-upstream rc=0 (nach Fix 1+2); E2E 7b + PHPUnit-Asserts ergänzt
- Lead-Nachtrag 3: „Austeilen“ nur bei draft; distributed nur mit not_distributed>0 als „An fehlende austeilen ({n})“
- Fix 3: nextnotes-assignments.js Austeilen-Knopf (draft / distributed mit state.pending.n>0, .nn-asg-distribute), distribute(a,label); l10n „Hand out to missing students ({n})“ de+de_DE je eine Zeile; AssignmentsViewService txt distributeMissing; E2E-Check nach Austeilen
- Build rc=0, check-upstream rc=0 (nach Fix 3); install-app; E2E assignments-m6g hell sqlite grün 159 (rc 0)
- SPEC §9.1 (neuer Punkt 5 Knopf, Rest umnummeriert), §9.6 (Marker, hidden, Neue Aufgabe) ergänzt
- PHPUnit --filter 'AssignmentsView|Page' läuft
- PHPUnit sqlite --filter 'AssignmentsView|Page': OK 70 Tests (rc 0)
- Instanz nextnotes-m6ifix abgebaut (down.sh rc 0, kein Container mehr)
## Offene Punkte
- Cache-Buster ?v= = App-Version + Theming-Cachebuster; ohne Versionssprung bleiben alte JS/CSS 6 Monate (immutable) → Empfehlung Lead: update.sh setzt theming cachebuster, Releases heben Version
