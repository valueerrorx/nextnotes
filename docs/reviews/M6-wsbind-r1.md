VERDICT: FREIGABE

Diff-Review M6-wsbind r1 (speccritic) gegen Plan r3, H1–H3, SPEC, ADR 0008/0021. Statisch geprüft, `php -l` auf Migration, WorkspaceDeletion, AssignmentService, WorkspaceService, WorkspaceResourceProvider ohne Fehler; kein Docker.

Geprüft, ohne Befund:
- Migration `Version000800…`: DI über `Server::get` (siehe Plan-Review), `preSchemaChange` nur bei fehlender Spalte, Purge über `AssignmentDeletion::purge`, Leerprüfung mit `RuntimeException`, Warnkommentar; `changeSchema` NOT NULL plus `nn_asg_ws` (SQLite: Tabellen-Neuaufbau wegen Index); Neuinstallation idempotent. `AssignmentMigrationTest` prüft echt: simuliertes Schema (H2), Zeilen, AppData-Dateien, Ordner, Schüler-Workspaces, Abbruch.
- `create`: `workspace` Pflicht, 404/400/403/`source_not_allowed` wie Plan; in der Transaktion Team (`enforceQuota`) → Insert → `lockRows` (0 → 404) → erneut lesen → erst dann `copyAsTemplate`. Race-Test vorhanden.
- `WorkspaceDeletion`: confirm vor Purge; Team-Sperre → `rebindWorkspace` → `lockOwner`; Ziel/Kandidat unter der Sperre neu bestimmt, Abweichung oder Restaufgaben → 409 `workspace_changed` mit Rollback des Umhängens; Teilabbruch dokumentiert und getestet.
- Rechte: `visibleAssignmentIds`/`requireAssignment` unverändert; `workspace_id`/`workspace` nur über `keyFor` (lesbar), sonst null; Lösch-Dialog-Zahl nur bei `manage`.
- L1 (`inScope` nach `team_state`), `assignmentKey` (aktiv und lesbar, sonst eigener), Notifier/Banner, Ressourcen `assignments:<wsId>` (alte Team-ID → `ctype_digit` → leer), `sort=recent` (Sortierung vor `limit`).
- SPEC §2.1/§3/§8/§9, ADR 0008-Nachtrag, ADR 0021 mit Grenze (H1). Kein Kompatibilitätscode (der Seed in `upgrade-path.sh` sendet `team` nur für den alten Stand).

Abweichungen des Architekten:
- (a) Versteckter Kreis → 404: konform zur Probe-Regel SPEC §3 („wie nicht Mitglied, 404“).
- (b) HTTP-409 auf SQLite übersprungen, deterministischer Test auf allen DBs: akzeptiert.
- (c) `assignments.php` ohne `?workspace=` → zuletzt geöffneter Workspace: unkritisch, alle erzeugten Links tragen den Schlüssel.
- (d) CHANGELOG 0.9.3 mit leerem „[Unreleased]“: passt (M9d/M9e standen nach dem 0.9.2-Schnitt unter Unreleased).

Hinweise (keine Blocker):
1. MINOR: `AssignmentMigrationTest::testPreSchemaChangePurgesAssignments` – `assertGreaterThanOrEqual(0, $snapshots)` prüft nichts. Entweder im Fixture einen Snapshot anlegen und `> 0` fordern oder die Zeile streichen.
2. HINWEIS: Die Quellprüfung in `create` vergleicht nur `workspace_id`; eine Notiz im Papierkorb dieses Workspace ist zulässig wie bisher (Verhalten unverändert, kein Leck).
