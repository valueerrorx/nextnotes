# Plan M6-wsbind: Aufgabe fest an den Workspace binden

Stand: Plan r3 (architekt), Basis `nextcloud-port` e258fcd5 (UI-tplpick), nach Review `docs/reviews/M6-wsbind-plan-r1.md`. Entscheidungen Thomas/Lead 2026-11-09: Aufgabe gehört genau einem Team-Workspace; Aufgaben gibt es nur für Teams; kein Rückwärtskompatibilitäts-Code; make-private bleibt erlaubt (Variante a).

## 0. Leitidee
- `workspace_id` ist der **Ort** der Aufgabe (Liste, Links, Vorlagen-Quelle, Lebensdauer) im aktiven Team.
- **Rechte bleiben team-basiert** (§9.7: Level ≥ 4, `owner_uid`, `studentAccess`). Team-Workspaces sind für jedes Mitglied lesbar; eine Workspace-Prüfung zusätzlich ergäbe dieselbe Menge. Kein neuer Rechteweg.
- **Invariante (nur aktives Team):** `workspace_id` zeigt auf einen Workspace `owner_type=team`, `kind=normal`, `owner_id = team_id`. Gesichert durch Anlegen (§2) und Löschen mit/ohne Ziel (§3). Nach Verwaisung bleibt `workspace_id` unverändert stehen, auch wenn `make-private` den Workspace in einen privaten umwandelt; die Aufgaben gehen mit der Team-Löschfrist (ADR 0008, SPEC §8). Kein Umhängen, kein NULL.

## 1. Datenmodell und Migration
- `nextnotes_assignments.workspace_id` bigint **NOT NULL**, kein Default; Index `nn_asg_ws`(workspace_id); keine FK (Kaskade im Service). `team_id` bleibt (Quote, Sperren, Orphan, Rechte).
- Migration `Version000800Date20261112000000`, App 0.9.2 → **0.9.3**:
  - `preSchemaChange` (Konstruktor-Injektion `AssignmentDeletion`, `IDBConnection`; geprüft im Review gegen `.ref`): nur wenn die Spalte fehlt, alle vorhandenen Aufgaben über `AssignmentDeletion::purge(id)` löschen (Lead-Entscheid: Dev/Demo-Daten; gleicher Pfad wie manuelles Löschen: Vorlage, Abgaben, Kopien, Anhänge samt AppData-Dateien, Snapshots, Aufgaben-Ordner, leere Schüler-Workspaces). Danach Leerprüfung der Tabelle; nicht leer → `\RuntimeException` mit klarer Meldung (Upgrade bricht ab, nichts am Schema geändert).
  - Kommentar in der Migration: `purge` nutzt aktuellen Code gegen das Schema vor 0.9.3; spätere Migrationen, die Mapper/Services von `purge` ändern, müssen das berücksichtigen.
  - `changeSchema`: `addColumn('workspace_id', BIGINT, notnull=true)`, `addIndex`. Neuinstallation überspringt `preSchemaChange` (leer, passt).
  - Begründung (eine Zeile): eine Schleife über den vorhandenen Löschpfad, keine Befüllungs- oder NULL-Logik; NOT NULL auf leerer Tabelle geht auf SQLite, MariaDB, PostgreSQL.
- Entity `Db/Assignment.php` (`workspaceId`), Mapper `Db/AssignmentMapper.php`: `idsForWorkspace(int)`, `rebindWorkspace(int $from, int $to)`.
- SPEC §2.1 (Spalte, Index, Invariante nur aktives Team); Schema-Erwartung in `tests/nextnotes/schema/` (tester).

## 2. Anlegen (`AssignmentService::create`)
- POST `{workspace, title, due_at, source_note_id, max_points?}`; **`workspace` Pflicht**, `team` entfällt (Team = `owner_id` des Workspace).
- Prüfreihenfolge: fehlt/leer → 400 `workspace is required`; `requireByKey(read)` → 404; nicht `owner_type=team`/`kind=normal` → 400 `not_a_team_workspace`; `requireTeamForCreate(uid, owner_id)` (aktiv, Level ≥ 4, sonst 403); Quelle `requireNote(read)`/`resolveLinked`; `requireSourceAllowed`: Quelle genau in diesem Workspace, sonst 403 `source_not_allowed`.
- Transaktion (Sperr-Reihenfolge Team → Aufgabe → Workspace): Team-Zeile (`enforceQuota`), Insert der Aufgabe, dann `workspaces->lockRows([wsId])`; Rückgabe 0 → 404, Rollback; danach erneut lesen (noch `owner_type=team`, `owner_id = team`, `kind=normal`, sonst 404); **erst dann** `copyAsTemplate`.
- API-Sicht (`view`): neu `workspace_id` und `workspace` (Schlüssel) **nur, wenn uid den Workspace lesen darf** (`listVisible`/`keyFor`, Cache je Request), sonst beide null (keine ID-Preisgabe, SPEC §3).

## 3. Rechte, Sichtbarkeit, Lebenszyklus
| Punkt | Wirkung |
|---|---|
| `assignmentAllows`, `requireAssignment`, `visibleAssignmentIds`, `requireSubmission`, Vorlage-/Abgabe-Notizlevel, Austeilen, Frist, Nachzügler, Quote, Export, Benachrichtigungs-Empfänger | unverändert (team-basiert) |
| Schüler | unverändert: Kopie im eigenen Abgabe-Workspace `kind=assignments` je Team; sieht die Aufgabe auf der Aufgabenseite ihres Team-Workspace (aktives Team) |
| Team-Workspace löschen (`WorkspaceDeletion::delete`), beide Zweige | Transaktion beginnt mit `teams->lockRow(team)` (ohne `nextnotes_teams`-Zeile sperrt sie nichts; unkritisch, weil `create` per `lockRows` nachprüft), dann wie bisher `lockOwner` und Nachprüfung |
| … **mit Ziel** (nur gleiches Team) | in der Transaktion nach der Team-Sperre `rebindWorkspace(source, target)`, dann `lockOwner` und Nachprüfungen; ändert sich das Ziel unter der Sperre → 409, Rollback inkl. Umhängen |
| … **ohne Ziel** (letzter, `confirm`) | **Aufgaben samt Abgaben werden gelöscht** (Thomas F1; manuelles Löschen durch die Lehrkraft, Level ≥ 4 im aktiven Team = Recht `delete`). Reihenfolge: (1) `confirm` gesetzt, sonst 400 wie bisher; (2) Vorprüfung „kein anderer Team-Workspace“; (3) je Aufgabe `AssignmentDeletion::purge` (eigene Transaktion, Dateien nach Commit); (4) Workspace-Transaktion: Team-Sperre, `lockOwner`; gibt es jetzt doch einen Kandidaten oder noch Aufgaben (`idsForWorkspace`) → 409 `workspace_changed`; sonst `purge` des Workspace. **Teilabbruch zulässig**: bereits gelöschte Aufgaben bleiben gelöscht, der Workspace bleibt; eine Wiederholung setzt fort |
| `make-private` (verwaistes, gelöschtes Team) | **unverändert erlaubt** (SPEC §3/§8, Thomas 2026-11-07); `workspace_id` bleibt, Aufgaben bleiben am Team und gehen mit dessen Frist |
| Team-Cleanup, Aufgabe löschen, Kontolöschung, `delete-user-data` | unverändert (`workspace_id` keine UID-Spalte; Cleanup löscht Aufgaben vor den Workspaces) |
| Workspace in anderes Team | gibt es nicht (Besitzer ändert sich nur bei Anlegen, `make-private`, Kontolöschung `owner_type=user`) |
| Umbenennen | keine Wirkung (ID-basiert) |
- ADR 0008 Nachtrag „Löschen des Team-Workspace“: weiterer manueller Löschweg für Abgaben (Lehrkraft, bestätigt); SPEC §8 „Keine automatische Löschung…/Manuell: …“ ergänzen. SPEC §8 „auch Teams nur mit Aufgaben“ bleibt (nach `make-private` möglich).

## 4. Oberfläche und Links
- `View/AssignmentsViewService::page`: Insel `workspaceId` (Frame-Workspace, wenn Team-Workspace, sonst null) statt `homeTeams`/`teamWorkspaces`; `createSource` nur Notiz aus dem Frame-Workspace (`$here` Pflicht).
- `js-src/nextnotes-assignments.js` `inScope` (L1, Lead entschieden: nach Team-Zustand):
  - Team-Workspace-Seite → `a.team_state === 'active' && a.workspace_id === island.workspaceId`.
  - Jede andere Seite → `a.team_state !== 'active'` (verwaist/eingefroren: `owner_uid`, Schüler ohne Austrittsmarke; Export und eigene Abgabe in der Frist).
  - `?assignment=` folgt derselben Regel. POST ohne `team`, `workspace` = Frame-Schlüssel. Icon-Leiste unverändert (nur Team-Workspaces).
- Links: `WorkspaceService::assignmentKey(uid, Assignment)` = Schlüssel des Aufgaben-Workspace bei aktivem Team und Leserecht, sonst erster eigener Workspace (dort erscheint sie nach L1); genutzt von `AssignmentsViewService::assignmentUrl` (Banner) und `Notifier::assignmentLink`. Meldung „Team verwaist“ bleibt team-weit (`assignmentsKey`).
- `Teams/WorkspaceResourceProvider` (Thomas F2): je Team-Workspace eine Ressource „Aufgaben – {Workspace}“ (ID `assignments:<wsId>`, Link `assignments.php?workspace=<Langform>`); `getTeamsForResource`/`isSharedWithTeam` lösen `assignments:<wsId>` nur über einen Workspace `owner_type=team`, `kind=normal` auf; alte ID `assignments:<teamId>` (kein Ziffern-ID-Workspace) → leer.
- Lösch-Dialog Team-Workspace (`templates/settings.php`, `SettingsViewService`, `js-src/nextnotes-teams.js`): Attribut `data-team-assignments` (Zahl der Aufgaben des Workspace); ohne Ziel und Zahl > 0 Text `deleteLastAssignments` „Löscht auch {n} Aufgaben samt aller Abgaben endgültig. Exportiere sie vorher auf der Aufgabenseite.“; mit Ziel Hinweis „Aufgaben ziehen mit um“; 409 → Meldung „Der Workspace hat sich geändert, bitte erneut versuchen.“
- Dashboard, Erwähnungen, Suche zeigen keine Aufgaben → keine Änderung.

### 4.1 Review-Hinweise UI-tplpick r1 (bleibt im Slice, Lead)
- (1) Lupe: neuer Wert `sort=recent` in `NoteService` (flach nach `updated_at` desc, dann ID; ohne Ordner-Gruppierung), reine Erweiterung der Whitelist; Lupe nutzt ihn. `updated_desc` (Upstream) bleibt.
- (2) Hinweis bei 1-Zeichen-Filter und > 200: neuer Text `sourceMoreFiltered` „Nur die {loaded} zuletzt geänderten Notizen durchsucht – weiter tippen zum Eingrenzen“; `sourceMore` nur ungefiltert mit Server-`total`.
- (3) SPEC 403/404: in e258fcd5 erledigt.

## 5. Export, IMigrator, purge, delete-user-data, export-user
Keine Änderung (kein Workspace-Bezug im Export; `purge` löscht ganze Tabellen; keine UID-Spalte).

## 6. Dateien
Code: `lib/Migration/Version000800Date20261112000000.php` (neu), `appinfo/info.xml`, `lib/Db/Assignment.php`, `lib/Db/AssignmentMapper.php`, `lib/Service/AssignmentService.php`, `lib/Service/WorkspaceService.php`, `lib/Service/WorkspaceDeletion.php`, `lib/Service/View/AssignmentsViewService.php`, `lib/Service/View/SettingsViewService.php`, `templates/settings.php`, `lib/Notification/Notifier.php`, `lib/Teams/WorkspaceResourceProvider.php`, `lib/Service/NoteService.php`, `js-src/nextnotes-assignments.js`, `js-src/nextnotes-teams.js`, `l10n/de.json`, `l10n/de_DE.json`, Build-Artefakte. `TeamLifecycleService` unverändert. Kein `src/`-Patch.
Doku: `docs/SPEC.md` §2.1, §3 (Team-Ressourcen, Löschen Q2), §4.2 (`assignments.php`), §4.3 Nr. 30, §8, §9.1 Nr. 1, §9.5, §9.6; ADR **0021-aufgabe-an-workspace.md**; ADR 0008 Nachtrag; `docs/app/*` (Teams-Ressource, Workspace löschen) falls beschrieben.
Tests (tester): Schema-Erwartung; `AssignmentTestCase::createAssignment` (Workspace statt Team) und Aufrufer; `AssignmentsViewTest`, `AssignmentSourceWorkspaceTest`, `AssignmentLifecycleTest`, `TeamResourceProviderTest`; neu `AssignmentWorkspaceBindTest`, `AssignmentMigrationTest`; `dev/nextcloud/upgrade-path.sh`: Seed sendet `team` und `workspace`; Erwartung bei Ausgangsversion < 0.9.3: Aufgabe, Abgabe, Abgabe-Notiz → 404, Schüler-Workspace weg, Team-Workspace und Material unverändert; ab 0.9.3 wie bisher lesbar. E2E `assignments-m6g`, `assignment-tplpick`, `teams-m5d`, `smoke-m6`, neu `assignment-wsbind.mjs`.

## 7. Done-Kriterien
1. Migration: Spalte NOT NULL und `nn_asg_ws` angelegt; `check-schema` grün auf SQLite, MariaDB, PostgreSQL; `upgrade-path.sh` von 0.9.2 grün mit angepasster Erwartung.
2. `AssignmentMigrationTest` ruft `preSchemaChange` direkt auf (Schema ohne Spalte simuliert): Aufgaben, Abgaben, Kopie-Notizen, Anhang-Zeilen **und AppData-Dateien**, Snapshots, Aufgaben-Ordner, leere Schüler-Workspaces weg; Leerprüfung wirft bei verbliebener Zeile. (Kein Kriterium zu `markProcessed` in der Migration.)
3. POST: ohne `workspace` 400; unbekannt 404; kein Team-Workspace 400 `not_a_team_workspace`; Schüler 403; Quelle aus anderem Workspace 403 `source_not_allowed`; Erfolg speichert `workspace_id`; Workspace zwischen Prüfung und Sperre gelöscht → 404, keine Aufgabe, keine Vorlage.
4. `AssignmentWorkspaceBindTest`: Team mit A, B; Aufgaben je Workspace mit richtigem `workspace_id`; Rechte und `GET /assignments` unverändert; `workspace_id`/`workspace` null für Benutzer ohne Leserecht am Workspace; Löschen A mit Ziel B → Aufgaben an B, Abgaben unverändert; Löschen des letzten Workspace mit `confirm` → Aufgaben, Abgaben, Kopie-Notizen, Anhänge (Zeilen und Dateien), Aufgaben-Ordner, leere Schüler-Workspaces weg; ohne `confirm` → 400, nichts gelöscht; neuer Kandidat unter Sperre → 409; `make-private` mit Aufgaben erlaubt, `workspace_id` unverändert, Aufgaben weiter über `owner_uid` lesbar; Banner-/Notifier-Link zeigt bei aktivem Team auf den Aufgaben-Workspace, sonst auf den eigenen.
5. Teams-Ressourcen: je Team-Workspace ein Eintrag; `assignments:<teamId>` (alt) nicht aufgelöst; `isSharedWithTeam` für Workspace eines fremden Teams false.
6. E2E: Liste in B zeigt Aufgabe aus A nicht (Lehrkraft, Schüler), in A schon; L1: `owner_uid` und Schüler eines verwaisten bzw. eingefrorenen Teams sehen die Aufgabe im eigenen Workspace, nicht im (noch lesbaren) Team-Workspace; `?assignment=` dito; Lösch-Dialog nennt die Aufgabenzahl; hell/dunkel.
7. Lupe: > 200 Notizen → zuletzt geänderte zuerst; Hinweistext mit Filter korrekt.
8. `make -C nextnotes build check-upstream` Exit 0, `check-forbidden-sql` 0 Treffer; bestehende Assignment-/Distribute-/Lifecycle-/Team-Tests grün.
9. Kein NULL-Zweig, keine Kompatibilitäts- oder Befüllungslogik.

## 8. Offene Punkte
- Keine. L1 entschieden (Team-Zustand, Lead 2026-11-09); `.ref`-Prüfung der Migration im Review erledigt.
- Risiko: Demo-/Dev-Instanzen verlieren beim Upgrade auf 0.9.3 alle Aufgaben und Abgaben (gewollt, Lead); vor dem Upgrade der Demo Thomas informieren.
