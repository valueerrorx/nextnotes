# M5a – Architekt (Umsetzung)
Auftrag: M5a laut docs/slices/M5-plan.md (§2.1 TeamService, §2.2 AccessService Teams/requireLeave, §2.3 Team-Workspaces API, §2.8, §4 soweit M5a). Basis ea50d52a. Nächste Nummern P30, B65. Eigene Test-Instanz, am Ende abbauen.
Status: in Arbeit
## Erledigt
- Plan, SPEC §2/§3, Code gesichtet; Circles-Quellcode aus Image nach scratchpad/circles (nur lesen)
- Db/Team.php, Db/TeamMapper.php (insertIgnore, updateSnapshot) – nextnotes_teams existiert seit Migration 0.1.0, keine Migration
- Service/TeamService.php (isAvailable, memberTeams, getLevel, membership, isNormalTeam, members, exists, uidForSingleId, resetCache; guarded try/finally stopSession), Exception/TeamUnavailableException.php
- Service/TeamStateService.php (stateOf, ensure, snapshotName, resetCache)
- WorkspaceMapper: findByOwner, teamAccess (1 SQL, LEFT JOIN teams), findForTeam
- NoteMapper::leaveRows; Service/LeaveRequest.php; AccessService: teamLevel, visibility-Map, canManageTeam (lazy), requireLeave, resetCache inkl. Teams
- Service/QuotaService.php (Quote je Besitzer inkl. team; AttachmentService delegiert, vermeidet DI-Zyklus)
- WorkspaceService: create(team), requireTeamForCreate, listVisible (Team via visibility, Reihenfolge fremder IUserConfig foreign_workspace_order), ownerName Team, update (Eindeutigkeit je Besitzer, Snapshot), share 403 Team, reorder je Benutzer, moveNotes (Quelle manage, Ziel write, requireLeave, Quote Ziel), archiveFor(source, uid) Team-Archiv
- WorkspaceDeletion (Team-Löschen Q2, purge); UserDataService nutzt purge; Controller WorkspacesApi/Compat delete (target, confirm)
- requireLeave in NoteService update/moveToFolder/archive, FolderService move/moveFiles/archive, DuplicateService note/folder, AttachmentService move; grep workspace_id-Schreiber: alle Wege abgedeckt (Anlegen/Import/Konvertierung schreiben nur ins Ziel)
- TeamsApiController + Route GET /api/v1/teams; Teams/WorkspaceResourceProvider + registerTeamResourceProvider; PageController teamsAvailable; SettingsService BLOCKED foreign_workspace_order
- make build rc=0; Instanz nextnotes-m5a (8201–8204) sqlite up, App aktiv, /teams 200
- tests: Support/Teams.php (Circles lokal=false, sonst Gruppe 131), Support/TeamTestCase.php, Teams/TeamAccessTest.php; sqlite --filter TeamAccessTest: 5 Tests/211 OK
- Teams/TeamWorkspaceTest.php 5 OK (Circles-Namen gleich nur per DB, Circles hängt Zusatz an); Teams/TeamLeaveTest.php 9 OK (sqlite)
- FilterReadableTest +testFilterReadableEqualsNoteLevelForTeams; IsolationTest 'GET /teams'; RouteDiffTest PORT_ONLY ['GET /teams']
- SPEC §2.1 Snapshot, §2.2 Team-Quote, §3 Tabelle + Abschnitt Team-Workspaces/Verlassen-Regel, §4.2 /workspaces + /teams + workspaces.php, §11.2 B17, §12 R8, §14 OCP/Circles; ADR 0003 Nachtrag M5a
- check-forbidden-sql rc=0 (0 Treffer); make check-upstream rc=0
- läuft: PHPUnit sqlite voll (Hintergrund), up mariadb+pgsql (Hintergrund)
- mariadb/pgsql gefiltert (Teams|FilterReadable|WorkspaceDelete|UserDeletion|WorkspaceName) Lauf 1: je 30 Tests, 2 E UserDeletionTest (baut UserDataService per new → Konstruktor geändert) → UserDataService zurückgesetzt (git checkout), WorkspaceDeletion::purge eigener Körper; 1 F getSharedWith: Befund Circles setzt beim Gruppen-Kreis eine App-Sitzung (FederatedUserService::getGroupCircle), Super-Session-getCircle findet Kreis dann nicht → TeamService lädt Kreise mit Owner als Initiator (startOccSession('',SINGLE,teamId)), exists() ohne ITeamManager::getTeam, guarded stellt vorherige Sitzung wieder her
- Teams::cleanup zerstört als Owner (Super-Session-Destroy scheitert: Initiator does not exist)
- mariadb/pgsql gefiltert Lauf 2 (neuer Code): je 30 Tests/929 Assertions OK, rc=0
- sqlite-Volllauf (alter Code) abgebrochen (zu langsam, ~30 %/25 min, Code inzwischen geändert)
- TTFB index.php mariadb (scratchpad/ttfb-setup.php): 0 Teams Median 240 ms, 20 Teams/20 Team-Workspaces Median 227 ms (kein messbarer Aufschlag; ≤ +30 ms erfüllt); /workspaces zeigt 21
- läuft: sqlite gezielt (Access|Isolation|PageIsolation|IndirectRead|Teams|RouteDiff|StrippedRoutes|CoreScenario|AttachmentTest|WorkspaceDelete|WorkspaceName|UserDeletion|ExportTest)
- sqlite gezielt (197 Tests): 4 F → Tests angepasst (IsolationTest 119 Routen; PageIsolation reorder lesend 200 je Benutzer; CoreScenario PORT_ONLY GET /teams; ExportTest Schlüssel vorab) → Nachlauf 5 Tests OK
- SPEC §3/§14 Befund Circles-Sitzung (exists ohne getTeam)
- make build rc=0, check-upstream grün, check-forbidden-sql 0 Treffer; Instanz nextnotes-m5a abgebaut
## Status
fertig
## Offene Punkte
- sqlite-Volllauf nicht vollständig (alter Lauf abgebrochen); eingefroren per HTTP → M5b; Quote bei Besitzerwechsel nur move_notes

# Nacharbeit r1 (docs/reviews/M5a-r1.md)
Auftrag: BLOCKER ADR/SPEC exists(); H1 Quote alle Wege; H2 exists() remote=Fehler; H3 members() nach read (M5d); H4 Eigene-Notiz-Prüfung in Transaktion; H5 eingefroren HTTP + TTFB.
## Status
fertig
## Erledigt
- Code: LeaveRequest::changesOwner; AttachmentMapper::sizeLeaving; QuotaService::forLeave/combine; Quote in NoteService PATCH (mit Bildern kombiniert) + moveToFolder, FolderService::move + moveFiles, moveNotes; WorkspaceService ohne AttachmentMapper
- Code H4: AccessService::moveLeaving (requireLeave unter Sperre, Nachprüfung angekommener IDs); FolderService::move sperrt Quelle+Ziel in Tx; moveNotes nutzt moveLeaving
- Code H2: TeamService::exists null nur bei 'Circle not found', sonst TeamUnavailableException
- Doku: ADR 0003 Nachtrag, SPEC §2.2 Quote, §3 Ohne Teams-App/Verlassen, §5 CleanupJob, §9 transferOwnership; Plan M5c/M5d Hinweise
## Nächster Schritt
- Tests: Quote PATCH/Ordner/moveToFolder/moveFiles, eingefroren HTTP, exists; Instanz hoch
- Tests: neu Teams/TeamQuotaTest.php (Quote alle Wege, moveLeaving-Nachprüfung, exists gelöscht); TeamAccessTest 6b um eingefroren (occ app:disable circles) ergänzt
## Nächster Schritt
- Instanz nextnotes-m5a (8200) sqlite/mariadb/pgsql hoch, Läufe
- Befund H5: Circles aus → Fatal „Class Circle not found“ (TeamService::EXCLUDED mit Circle::CFG_*) → Zahlenwerte; danach eingefroren-Test grün
- sqlite TeamQuotaTest + 6b: 4 Tests/145 Assertions OK
- sqlite Teams|Access|Isolation|WorkspaceDelete|IndirectRead|Attachment|Folder|CoreScenario|Export: 209 Tests/9123 Assertions OK
- mariadb/pgsql Teams|Quota: je 27 Tests/773 Assertions OK
- TTFB index.php mariadb (neuer Code): 0 Teams 221 ms, 20 Teams 226 ms (Median aus 15)
- check-forbidden-sql 0, check-upstream grün, build rc=0; Instanz abgebaut
- Status Nacharbeit r1: fertig
