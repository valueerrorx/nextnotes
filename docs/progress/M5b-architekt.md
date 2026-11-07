# M5b – Architekt (Team-Lebenszyklus)
Status: fertig

## Auftrag
M5-plan §M5b (Events Löschung/Austritt, eingefroren/verwaist, make_private, Benachrichtigungen, Austritts-Aufräumen R15, Kontolöschung) + M5a-r2 H1 (Sperrreihenfolge), H2 (forLeave Bytes in Tx), H4 (SPEC §9 memberTeams).

## Erledigt
- Plan/Review/STATUS gelesen

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen/Annahmen
- DestroyingCircleEvent verwaist schon vor dem Löschen; scheitert das Löschen danach, hilft erst der Rückweg in M5c
- Umwandeln setzt auch den eigenen Hintergrund zurück (Ordner einzelner Konten nicht auflistbar)
- Done 5a: Fehler direkt über handle() mit Stubs geprüft (Listener-Instanz im Server-Container gecacht), echte Circles-Aufrufe zusätzlich
- nginx-Loopback nur manuell geprüft (PHPUnit nur Apache)
- Instanz nextnotes-m5b (NN_PORT_BASE=8220, sqlite/mariadb/pgsql/nginx) up rc=0
- Circles-Quelle nach scratchpad/circles; Befund: CircleDestroy/MemberRemove async → manage() im Loopback (Web) bzw. synchron (CLI/PHPUnit); DestroyingCircleEvent aus manage(), CircleDestroyedEvent aus result(); MembershipsRemovedEvent aus onUpdate (circleId, singleId)
- Entwurf: Listener für Destroying+Destroyed (markOrphaned idempotent); Austritt nur wenn exists()!=null und Level strikt 0; make_private räumt fremde Erinnerungen/Erwähnungen/Hintergründe/Freigaben/Sperren; Quote: enforce sperrt Quell- und Ziel-Besitzer sortiert, Bytes unter Sperre
- dev/nextcloud/up.sh: Circles-Loopback nginx (trusted_domains web-nginx, loopback_tmp_* je Versuch), circles:check mit 5 Versuchen (401 direkt nach Konfig-Schreiben; Fehlschlag leert loopback_tmp_*); 4 Umgebungen bereit
- Code: TeamService (EVENT_*, currentLevel strikt, destroyedTeam, removedMemberships), TeamMapper::markOrphaned, TeamStateService::markOrphaned, Service/TeamLifecycleService.php (teamDeleted+Benachrichtigung, memberLeft, makePrivate, hasTeamWorkspaces), Listener/CircleDestroyedListener + MembershipsRemovedListener (onLeft), Application-Registrierung, Route POST /api/v1/workspaces/{name}/make-private, Notifier team_orphaned/team_deletion_soon (+ l10n de/de_DE), Reminder/MentionHandled/LockMapper::deleteForNotesExcept, WorkspaceMapper::teamWorkspaceIds, UserDataService Hintergründe Team-Workspaces
- QuotaService: forLeave immer bei Besitzerwechsel (leave im Array), enforce sperrt Besitzer sortiert, Bytes unter Sperre (H1/H2)
- Migration Version000300 (nn_ws_cby), info.xml 0.2.0, schema expected-m1.json; build rc=0; pgsql Upgrade 0.1.2→0.2.0 ok (Index da); App 0.2.0 auf allen 4 Umgebungen
- tests: Support/Teams.php destroy(keepData)/track; Teams/TeamLifecycleTest.php (Done 1–6, 5a, H1 Deadlock, H2 Bytes unter Sperre, Loopback); schema expected-m1 nn_ws_cby
- sqlite TeamLifecycleTest: 10 OK (Loopback-Event Apache kommt an); Befund/Fix: TeamService::exists/currentLevel warfen bei Container-Fehler rohe Ausnahme → TeamUnavailableException
- Doku: SPEC §2.2 Sperrreihenfolge/Bytes, §3 Tabelle + Abschnitt Lebenszyklus, §4.1/§4.2 Route make-private, §6 team_orphaned/deletion_soon, §8 Austritt/Löschung/Hintergründe, §9.6 memberTeams (H4), §12 R15 erledigt + R17; ADR 0003 Nachtrag M5b
- mariadb TeamLifecycle|TeamQuota|UserDeletion: 16 Tests, 1 F (Loopback-Test: Rennen Zustand vs. Benachrichtigung im Test → Poll auf beides); Deadlock-Test grün. TeamLifecycleService: try je Empfänger. check-forbidden-sql 0, check-upstream grün
- Gegenprobe: alter QuotaService (HEAD) auf mariadb → Deadlock-Test rot (503 Database busy, Runde 1); neuer Code grün; App danach neu installiert
- pgsql TeamLifecycle|TeamQuota|UserDeletion: 16 OK (444 Ass.)
- nginx-Probe (manuell, PHPUnit nur Apache): Kreis per OCS, Team-Workspace per API, Löschen per OCS → state=orphaned nach <1 s, 1 Benachrichtigung (Loopback web-nginx); Probe-Daten entfernt
- tests: RouteDiff PORT_ONLY, CoreScenario, IsolationTest (120 Routen, make-private control 400) um neue Route ergänzt
- sqlite Regression (Teams|UserDeletion|Reminder|Mention|RouteDiff|StrippedRoutes|ControllerAttribute|IsolationTest|FilterReadable|CoreScenario): 209 Tests, 4 F: 3× neue Route nicht in Routentabellen (behoben), 1× UserDeletionTest durch Reste aus TeamLifecycleTest (Kontolöschung ohne Job) → Test räumt per sweep(60) auf; Reste in sqlite/mariadb/pgsql entfernt
- läuft: sqlite TeamLifecycleTest, danach UserDeletion|RouteDiff|CoreScenario|IsolationTest
- mariadb + pgsql TeamLifecycleTest|UserDeletionTest (nach Testfixes): je 13 OK (381 Ass.)
- Abschluss: build rc=0, check-forbidden-sql 0, check-upstream grün; sqlite TeamLifecycleTest 10 OK, UserDeletion|RouteDiff|CoreScenario|IsolationTest 147 OK; Instanz nextnotes-m5b abgebaut (down all rc=0)

# Nacharbeit r1 (docs/reviews/M5b-r1.md)
Auftrag: BLOCKER makePrivate nur bei exists()===null; H1 Kontolöschung Hintergründe gezielt/im Job ohne deleteUserConfig; H2 teamWorkspaceIds kind=normal; H3 Test Gruppenaustritt; H4 M6-Vormerkung Teams ohne normal-Workspace.
Status: fertig
- r1 Code: makePrivate exists()===null vor Tx (sonst 403); WorkspaceMapper::teamWorkspaceIdsFor(teamIds, uid) kind=normal (ersetzt teamWorkspaceIds); BackgroundFiles::queueUser(withConfig); Listener/BeforeUserDeletedListener merkt memberTeams → UserDataService::rememberTeams, neutralize queued nur diese Team-Workspaces + created_by, ohne deleteUserConfig
- r1 Tests: Teams::lastGroup; TeamLifecycleTest +testUserRemovedFromGroup, +testMakePrivateRefusedWhileTeamExists, Kontolöschung einfaches Mitglied + fremdes Team unberührt
- r1 Doku: SPEC §3 Lebenszyklus, §8 Hintergründe + M6-Vormerkung, §12 R15; ADR 0003; Plan O6
- r1: Instanz neu (sqlite/mariadb/pgsql), build rc=0; sqlite TeamLifecycleTest 12 OK (215 Ass.)
- r1 Abschluss: sqlite Teams|UserDeletion 39 OK (1135 Ass.), mariadb + pgsql TeamLifecycleTest je 12 OK (215 Ass.); check-forbidden-sql 0, check-upstream grün, build rc=0; Instanz abgebaut
- Abweichung H1: Aufräumen bleibt synchron (Job arbeitet nur mit Löschkennung, nie mit UID), aber gezielt (Teams vor der Löschung + created_by), Dateien nach Commit, ohne deleteUserConfig
