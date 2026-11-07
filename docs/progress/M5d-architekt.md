# M5d – Architekt

Status: fertig

## Auftrag
Teams-Oberfläche: Freigabe-Dialog mit Suche (`users/search`, `ShareTargetPolicy`), nur lesen, Neuer Workspace mit Team, Kennzeichen, Banner, Umwandeln, Contacts-Link, Erwähnungen Team, R16, Team-Löschen Q2 UI, Erstellen-Menü Escape; Demo-Seed `dev/demo/update.sh`. Plan: `docs/slices/M5-plan.md` §2.7/M5d.

## Erledigt
- Plan gelesen

## Nächster Schritt
- Code lesen

## Offene Punkte/Annahmen
- Code gelesen (CompatController workspaces, WorkspaceService share/create/index/keys, UsersApi, TeamsApi, MentionService, NotePalette, SettingsViewService, settings.php, shared_modals, workspaces-share/create/actions/core.js, utils-export-create.js)
- Instanz NN_PROJECT=nextnotes-m5d NN_PORT_BASE=8240 sqlite: up.sh im Hintergrund gestartet (Log scratchpad/up-sqlite.log)

## Entwurf
- Backend: Service/ShareTargetPolicy; UsersApi::search (GET users/search, 30/min); WorkspaceService::setShares (Diff: neu → Richtlinie, bestehend → Update, fehlend → unshare) + shareState; Compat share_workspace (allowed_users JSON [{uid,permission}]) / unshare_workspace (alle)
- index() + Settings-Zeilen: team (Name), manage; Settings listet eigene + Team-Workspaces; Teilen-Knopf nur eigene
- Mentions: Team-Workspaces (members() nach read, notifyNew Team-Mitglieder ∩ Leserecht); R16 NotePalette::ownerOf(ws)
- Banner in #left_col (wird bei Workspace-Wechsel mitgeladen); Umwandeln über POST make-private
- JS: eigenes js-src/nextnotes-teams.js (Override showWorkspaceShareOptionsModal, displayWorkspaceMenu, handleDeleteButtonClick, openCreateWorkspaceModal; adapterHooks); src-Patches nur P30 (utils-export-create.js Escape/Außenklick, B65) und P31 (workspaces-create.js team)
- Contacts-Link: linkToRoute contacts.page.index, nur isEnabledForUser('contacts')
- Code M5d1: Service/ShareTargetPolicy (neu); WsShareMapper::updatePermission/permissionsFor; WorkspaceService share/unshare/setShares/shareStates (Richtlinie nur neue Empfänger, alles oder nichts); CompatController share_workspace (allowed_users) / unshare_workspace (alle), 501 entfernt; UsersApiController::search + Route
- Code: WorkspaceService::index `team`, teamName(); R16 NotePalette::ownerOf + Index/PageFrame/Dashboard/Kanban/Diary/InputValidator::colorHex/WorkspaceService::index; TeamStateService::deletionDate; IndexViewService teamBanner; SettingsViewService Zeilen eigene+Team (team, manage, share, teamTargets, ownCount); View/TeamsUiConfig (contactsUrl, Texte) → InitialState config.teams; MentionService Team-Workspaces (memberUids, mentionWorkspaceIds, WorkspaceMapper::teamIdsAmong, owner_path für Erledigt-Route, Gruppenname Team)
- Nächster Schritt: Templates (settings Zeilen, left_col Banner, shared_modals Team-Auswahl), js-src/nextnotes-teams.js, CSS, bundles adapterHooks, PageAssets, P30/P31
- Templates (settings Zeilen/Teilen/Kennzeichen/Contacts-Link, left_col Banner, shared_modals Team-Auswahl, mentions owner_path), js-src/nextnotes-teams.js, CSS layout, adapterHooks (6), PageAssets, P30 utils-export-create.js, P31 workspaces-create.js, upstream-patches.txt
- make build rc=0 (161), check-upstream grün, check-forbidden-sql 0
- tests/nextnotes/php/Teams/ShareDialogTest.php (3 Tests): sqlite 3 OK (85 Ass.)
- tests/nextnotes/php/Teams/TeamUiTest.php (4 Tests: Kennzeichen/Einstellungen, Banner verwaist + Umwandeln, Erwähnungen Team, R16): sqlite 4 OK (83 Ass.)
- tests/nextnotes/e2e/teams-m5d.mjs + lib/docker.mjs (occ, ensureUser, createTeam, orphanTeam): sqlite hell grün 62 Prüfungen
- l10n de/de_DE (26 Texte), CSS Feinschliff; E2E teams-m5d dunkel grün 62
- dev/demo/update.sh: Demo-Konten schueler1/2, Seed Teams per php -r (CirclesManager, idempotent); Snippet in eigener Instanz 2× ausgeführt (rc 0, Mitglieder ergänzt), Demo nicht angefasst
- Docs: SPEC §3 Freigabe, §4.2 users/search + workspaces.php, §4.3 Nr. 20, §4.5, §6 Erwähnung, §11.1 P30/P31, §11.2 B65/B66, §12 R16, §14; ADR 0014 Nachtrag; Plan M5d Umsetzung. mariadb+pgsql hochgefahren (rc 0)
- check-upstream grün, check-forbidden-sql 0; Umfang: Code ~1.280 Zeilen (+735/-78 Diff, 547 neu), Tests ~610 → gesamt >1.500 (nicht geteilt, im Bericht melden)
- laufen: phpunit sqlite Regression (1 F gesehen), E2E hell Regression, phpunit mariadb/pgsql gefiltert
- PHPUnit mariadb + pgsql ShareDialogTest|TeamUiTest: je OK 7 Tests, 168 Assertions (vorher app:enable nötig)
- 1. sqlite-Regression ungültig (parallel zu E2E → sqlite locked); echter Fund: IsolationTest routeTable ohne GET /users/search → ergänzt. menu-501 + workspace-create hell grün (501 gesamt 0). Neu: phpunit sqlite Regression + E2E hell nacheinander (b179mdr05)
- sqlite Regression (253 Tests): 5 Fails, alle Testerwartungen → angepasst: IsolationTest Count 121, RouteDiffTest PORT_ONLY users/search, allowed-diff.json workspaces[].team, PageIsolationTest share 501→200, ShareDialogTest (e) robust gegen E2E-Konto carol. Nachlauf gefiltert ausstehend
- Nachlauf: Isolation/PageIsolation/RouteDiff/CoreScenario/ShareDialog grün (CoreScenario Port-Route ergänzt, 3 OK); E2E hell smoke-m4/editor/folder-actions/settings-m4h grün
- Mutation: Gruppenprüfung in ShareTargetPolicy entfernt → ShareDialogTest (b) rot (200 statt 400); Datei wiederhergestellt, App neu installiert
- Instanz nextnotes-m5d abgebaut (down.sh all); Demo/gftest unberührt
- Nächster Schritt: keiner (Bericht an Lead)
