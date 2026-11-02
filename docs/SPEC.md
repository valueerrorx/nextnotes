# SPEC: Next Notes – Poznote als Nextcloud-App

Stand: M0, 2026-11-02. App „Next Notes“, App-ID `nextnotes`, Namespace `OCA\NextNotes`, Tabellenpräfix `nextnotes_` (ADR 0001). Nextcloud 35 (35.0.x), PHP 8.3–8.5 (§1.3). Upstream: Poznote `6.136.2` / `7e2e0b6a` (`UPSTREAM.md`).
Grundlagen: `docs/INVENTORY.md` (Inventur A/B), `docs/adr/0002`–`0016`.

**Leitprinzipien** (entschieden, Thomas 2026-11-02)
1. Upstream-Nähe im Frontend, wo sie billig ist: Upstream-Updates sollen per `git merge` übernehmbar bleiben. Dateien in `src/` werden nur gezielt direkt geändert; jede Änderung steht in §11.1. Keine Build-Zeit-Patches (ADR 0007, 0013).
2. Das Original ist Vorlage, kein Zwang. Wo eine schlankere, wartbarere oder besser in Nextcloud integrierte Lösung möglich ist, wird sie gewählt – auch im Frontend. Einheitlichkeit vor 1:1-Treue.
3. Backend komplett neu nach Nextcloud-Konvention: Controller → Service → Mapper, Rechteprüfung zentral (§3). Kein Upstream-Backend-Code zur Laufzeit, keine Mischformen. `api/v1`-Pfade und JSON-Formate bleiben gleich, soweit das Frontend sie braucht.
4. Bugs im Original werden nicht übernommen, sondern gefixt (§11.2). Frontend-Bugfixes sind direkte Änderungen in `src/` (§11.1) und Kandidaten für Upstream-PRs.
5. Nur Hell/Dunkel nach NC-Theme; Zusatz-Themes, CSS-/Font-Upload, eigene Schriften gestrichen (ADR 0016).
6. Technische Entscheidungen (Struktur, Schnittstellen, Bibliotheken) treffen Architekt/Lead nach „schlank, wartbar, Nextcloud-nativ“. `[THOMAS]` nur für Produkt-, Datenmodell-mit-Nutzerwirkung- und Datenschutzfragen.

---

## 1. Zielarchitektur

### 1.1 Repo-Layout
```
/                         Fork von Poznote, Branch nextcloud-port
├─ src/                   Upstream; nur gezielte, in §11.1 gelistete Änderungen (Quelle für JS/CSS/i18n/Vendor)
├─ build/ tools/ docker/ mcp-server/ poznote-chrome-extension/   Upstream, unverändert
├─ nextnotes/             die Nextcloud-App (= Verzeichnis apps/nextnotes)
│  ├─ appinfo/info.xml, routes.php
│  ├─ lib/
│  │  ├─ AppInfo/Application.php        IBootstrap: Listener, Notifier, Search, TeamResourceProvider, UserMigrator
│  │  ├─ Controller/                    PageController (Ansichten), Api*Controller (api/v1), CompatController (api_*.php), AssignmentController
│  │  ├─ Service/                       AccessService, NoteService, FolderService, WorkspaceService, TagService,
│  │  │                                 AttachmentService, SnapshotService, LockService, ReminderService, SearchService,
│  │  │                                 ExportService, ImportService, ContentUrlRewriter, TeamService (Circles-Adapter),
│  │  │                                 AssignmentService, SubmissionStateService, ConversionService (HTML↔Markdown)
│  │  ├─ Db/                            Entities + QBMapper je Tabelle
│  │  ├─ Migration/                     Version000100Date…, je Meilenstein ein Schritt
│  │  ├─ BackgroundJob/  Listener/  Notification/  Search/  Teams/  Settings/  Command/  UserMigration/
│  │  ├─ Http/CspFactory.php            einzige Stelle für CSP-Abweichungen (ADR 0012)
│  ├─ templates/                        von src/public/*.php abgeleitete Ansichten + Partials (ADR 0004)
│  ├─ js-src/nextnotes-bootstrap.js     Adapter (§4.3), eigenes JS (Austeilen/Einsammeln)
│  ├─ css-src/nextnotes-tokens.css      Token-Mapping (ADR 0016), Layout-Korrekturen im NC-Rahmen
│  ├─ build/bundles.json, build.sh, check-upstream.sh, upstream-patches.txt (Dateiliste zu §11.1)
│  ├─ Makefile                          build | dist | check-upstream | lint
│  ├─ js/ css/ i18n/ img/ vendor-assets/   Build-Ausgabe (nicht eingecheckt)
│  └─ COPYING, LICENCE (Upstream-MIT), README.md, CHANGELOG.md
├─ dev/nextcloud/          Docker-Testsetup (NC 35, MariaDB/PostgreSQL/SQLite, Circles), nur Tests
├─ tests/nextnotes/        PHPUnit + Playwright (Rolle tester)
└─ docs/                   SPEC, INVENTORY, adr/, inventory/, reviews/
```

### 1.2 Build (`make -C nextnotes build`, reproduzierbar)
1. Kopiert `src/public/js/**` (ohne gestrichene Dateien laut `bundles.json`: `offline-*`, `pwa/*`, `sw.js`, `two-factor.js`, `login-page.js`, `ai-*.js`, `speech-to-text.js`, `backup-*.js`, `restore-import.js`, `git-*.js` …) in den Build (Upstream-Änderungen sind bereits direkt in `src/`, §11.1).
2. Konkateniert nach den Gruppen aus `src/public/index_js.php` (`head`, `app`) in `nextnotes/js/nextnotes-head.js`, `nextnotes/js/nextnotes-app.js` (klassische Skripte, Endung `.js`, nie `.mjs` – ADR 0006). Seiten-spezifische Skripte einzeln.
3. CSS analog (`index_css.php`, `css_assets.php`, `dark_mode_css.php`) → `nextnotes/css/*.css`; `nextnotes-tokens.css` danach.
4. Vendor (`codemirror-dist`, `excalidraw-dist` + Assets, `mermaid`, `katex`, `highlight`, `Sortable`) unverändert nach `nextnotes/vendor-assets/`; Excalidraw-Assetpfad per InitialState.
5. `src/i18n/*.json` → `nextnotes/i18n/`. PHP-Abhängigkeiten per Composer (`league/commonmark`, `league/html-to-markdown`, Namespace-Präfix per Build-Schritt gegen Konflikte mit anderen Apps); keine Upstream-PHP-Dateien im Paket. Ausgabe-Parität zur Upstream-Konvertierung über Fixture-Tests.
6. Keine Netzwerkzugriffe, keine Zeitstempel in Ausgaben; gleiche Eingabe → gleiche Prüfsummen.
`make dist` packt `nextnotes/` ohne `*-src`, `build`, `tests` als `nextnotes-<version>.tar.gz`.

### 1.3 Versionen
Zielversion nur Nextcloud 35 (entschieden, Thomas 2026-11-02): `info.xml` `<nextcloud min-version="35" max-version="35"/>`, `<php min-version="8.3" max-version="8.5"/>` (Spanne von NC 35, `lib/versioncheck.php`). Geprüft gegen `.ref` (35.0.2) und `github.com/nextcloud/circles` `stable35`.
- Skriptausgabe (`emit_script_tag`: klassisch + `defer` + Nonce, `.mjs` → Modul), CSP (`CSPMiddleware`, `buildPolicy`), InitialState (`<input type="hidden" id="initial-state-<app>-<key>" value="base64(json)">`, `core/templates/layout.initial-state.php`), Request-Token (`<head data-requesttoken>`), Theme-Attribute (`<body data-theme-<id>>`) – Fundstellen in ADR 0006 und §4.3.
- Circles `stable35`: `CirclesManager` (`getLocalFederatedUser`, `startSession`, `getCircle`, `getLink`), `Member::LEVEL_*` (0/1/4/8/9), `Member::STATUS_*`, `Membership::getLevel`, Events `CircleDestroyedEvent`, `CircleMemberAddedEvent`, `CircleMemberRemovedEvent`, `MembershipsCreatedEvent`, `MembershipsRemovedEvent`.
- Notiz: NC 33 wurde in Runde 2 geprüft; entfällt mit der Zielversion 35.

### 1.4 Laufzeit
- PageController liefert je Ansicht eine `TemplateResponse` (ADR 0004), lädt Bundles per `OCP\Util::addScript('nextnotes', …)` / `addStyle`, Daten per `IInitialState::provideInitialState`.
- Reihenfolge der Skripte: `nextnotes-bootstrap.js` → `nextnotes-head.js` → `nextnotes-app.js` → Seitenskript (alle `defer`, Reihenfolge bleibt).
- Settings: Benutzer über `OCP\Config\IUserConfig` (@since 32), Admin über `IAppConfig` + `OCP\Settings\ISettings` (Admin-Abschnitt: Embed-Domains, Anhangsgrenzen, Quota).
- i18n: Upstream-Wörterbücher unverändert; Sprache aus `IL10N::getLanguageCode()` abgebildet (`de_DE`→`de`, `zh_CN`→`zh-cn`, `pt_BR`/`pt_PT`→`pt`, sonst `en`). Wörterbuch per InitialState statt `/system/i18n`-Request (Route bleibt für Kompatibilität).

---

## 2. Datenmodell

Konventionen: IDs `BIGINT` Autoinkrement; Zeitpunkte `BIGINT` Unix-Sekunden UTC aus `ITimeFactory` (keine DB-Zeitfunktionen); Flags `SMALLINT` 0/1 (Upstream liefert 0/1); UIDs `STRING(64)`; Circle-`singleId` `STRING(64)`; lange Texte `TEXT` (`Types::TEXT`); Strings ≤ 4000. Keine Fremdschlüssel-Constraints (NC-üblich), Kaskaden im Service. NOT-NULL-Spalten nie mit Default `''`. Namen ≤ 63 Zeichen inkl. Präfix (`MigrationService::ensureOracleConstraints`). Index-Namen tabellenweit eindeutig (Präfix `nn_`).

### 2.1 Tabellen

**`nextnotes_workspaces`** – Eigentum (ADR 0003)
| Spalte | Typ | Null | Default | Zweck |
|---|---|---|---|---|
| id | bigint | nein | AI | |
| owner_type | string(8) | nein | 'user' | `user` \| `team` |
| owner_id | string(64) | nein | | UID oder Circle-singleId |
| kind | string(16) | nein | 'normal' | `normal` \| `assignments` (Schüler-Workspace je Team) |
| linked_team_id | string(64) | ja | | bei `assignments`: Team |
| name | string(255) | nein | | |
| color | string(32) | ja | | |
| tags | string(4000) | ja | | Upstream `workspaces.tags` |
| display_order | int | nein | 0 | Reihenfolge beim Besitzer |
| state | string(16) | nein | 'active' | `active` \| `orphaned` (gespeichert); „eingefroren“ wird nicht gespeichert, sondern berechnet (§3) |
| orphaned_at | bigint | ja | | |
| team_missing_count | smallint | nein | 0 | aufeinanderfolgende fehlerfreie Prüfungen mit „Team fehlt“ (O4) |
| created_by | string(64) | ja | | |
| created_at, updated_at | bigint | nein | | |
Indizes: `nn_ws_owner`(owner_type, owner_id); UNIQUE `nn_ws_asg`(owner_id, linked_team_id) (Schüler-Workspace eindeutig je Schüler und Team; bei `kind=normal` ist `linked_team_id` NULL und unbeschränkt); `nn_ws_state`(state, orphaned_at). Namen sind bei `kind=normal` je Besitzer eindeutig (Prüfung im `WorkspaceService`, wie Upstream); Schüler-Workspaces dürfen gleiche Anzeigenamen haben.

**`nextnotes_ws_shares`** (ADR 0014)
| Spalte | Typ | Null | Default |
|---|---|---|---|
| id | bigint | nein | AI |
| workspace_id | bigint | nein | |
| share_type | string(8) | nein | 'user' |
| share_with | string(64) | nein | |
| permission | string(8) | nein | 'write' |
| created_by | string(64) | nein | |
| created_at | bigint | nein | |
Indizes: UNIQUE `nn_wss_uniq`(workspace_id, share_type, share_with); `nn_wss_with`(share_type, share_with).

**`nextnotes_folders`**
| Spalte | Typ | Null | Default |
|---|---|---|---|
| id | bigint | nein | AI |
| workspace_id | bigint | nein | |
| parent_id | bigint | ja | |
| name | string(255) | nein | |
| display_order | int | nein | 0 |
| pinned, favorite, kanban_enabled, is_diary | smallint | nein | 0 |
| icon | string(64) | ja | |
| icon_color, color | string(32) | ja | |
| sort_setting | string(32) | ja | |
| assignment_id | bigint | ja | | (Ordner einer Aufgabe im Schüler-Workspace)
| trashed_at | bigint | ja | |
| created_at, updated_at | bigint | nein | |
Indizes: `nn_fold_ws_parent`(workspace_id, parent_id); `nn_fold_asg`(assignment_id). Eindeutigkeit (workspace_id, parent_id, name) im Service (NULL-Parent).

**`nextnotes_notes`**
| Spalte | Typ | Null | Default | Zweck |
|---|---|---|---|---|
| id | bigint | nein | AI | global eindeutig |
| workspace_id | bigint | ja | | NULL nur bei `role=template` |
| folder_id | bigint | ja | | |
| heading | string(1000) | ja | | |
| content | text | ja | | einzige Inhaltsquelle (ADR 0002) |
| search_text | text | ja | | Titel + Klartext, klein, ohne Akzente |
| type | string(16) | nein | 'note' | note/markdown/tasklist/excalidraw/… |
| role | string(16) | nein | 'note' | `note` \| `template` \| `submission` |
| assignment_id | bigint | ja | | bei template/submission |
| submission_id | bigint | ja | | bei submission |
| linked_note_id | bigint | ja | | |
| favorite, pinned | smallint | nein | 0 | |
| icon | string(64) | ja | | |
| icon_color, color, title_color | string(32) | ja | | |
| title_size, content_width | int | ja | | |
| display_order, dashboard_order, speed_dial_order, kanban_order | int | nein | 0 | |
| kanban_completed | bigint | ja | | |
| trashed_at | bigint | ja | | Papierkorb = NOT NULL |
| client_state_hash, client_state_version | string(64) | ja | | Upstream-Konfliktprüfung |
| created_by, updated_by | string(64) | ja | | |
| created_at, updated_at | bigint | nein | | `updated_at` = ETag-Basis |
Indizes: `nn_note_ws`(workspace_id, trashed_at); `nn_note_folder`(folder_id); `nn_note_upd`(updated_at); `nn_note_asg`(assignment_id); UNIQUE `nn_note_sub`(submission_id); `nn_note_link`(linked_note_id).

**`nextnotes_note_tags`**: id bigint AI; note_id bigint NN; tag string(255) NN. UNIQUE `nn_tag_uniq`(note_id, tag); `nn_tag_tag`(tag). API liefert weiter `tags` als kommagetrennten Text.

**`nextnotes_attachments`** (Datei in `IAppData`: `attachments/<note_id>/<id>`)
| Spalte | Typ | Null |
|---|---|---|
| id | bigint | nein (AI) |
| note_id | bigint | nein |
| original_name | string(255) | nein |
| mime | string(255) | nein |
| size | bigint | nein |
| created_by | string(64) | ja |
| created_at | bigint | nein |
Index `nn_att_note`(note_id). API erzeugt das Upstream-JSON `attachments` (`id`, `filename`, `original_filename`, `file_size`, `file_type`, `uploaded_at`) daraus.

**`nextnotes_snapshots`**: id bigint AI; note_id bigint NN; heading string(1000) NULL; content text NULL; type string(16) NN 'note'; kind string(16) NN 'auto'; created_by string(64) NULL; created_at bigint NN. Index `nn_snap_note`(note_id, created_at).

**`nextnotes_reminders`**: id bigint AI; note_id bigint NN; user_id string(64) NN (Empfänger); task_id string(64) NULL; type string(16) NN 'reminder'; message string(1000) NULL; trigger_at bigint NN; recurrence string(32) NULL; sent_at bigint NULL; created_at bigint NN. Indizes `nn_rem_due`(sent_at, trigger_at); `nn_rem_note`(note_id); `nn_rem_user`(user_id).

**`nextnotes_locks`** (ADR 0005): note_id bigint PK; holder_uid string(64) NN; holder_session string(64) NN; holder_kind string(16) NN 'user'; created_at, last_seen_at, expires_at bigint NN. Index `nn_lock_exp`(expires_at).

**`nextnotes_mentions_handled`**: id bigint AI; note_id bigint NN; user_id string(64) NN; handled_count int NN 0; updated_at bigint NN. UNIQUE `nn_men_uniq`(note_id, user_id).

**`nextnotes_assignments`** (§9, Migration in M6)
| Spalte | Typ | Null | Default | Zweck |
|---|---|---|---|---|
| id | bigint | nein | AI | |
| team_id | string(64) | nein | | Circle-singleId |
| title | string(255) | nein | | |
| source_note_id | bigint | ja | | Originalnotiz der Lehrkraft |
| template_note_id | bigint | nein | | eingefrorene Kopie (`role=template`) |
| due_at | bigint | nein | | Frist UTC |
| status | string(16) | nein | 'draft' | `draft` \| `distributed` \| `closed` |
| max_points | int | ja | | optional |
| created_by | string(64) | ja | | |
| distributed_at, deadline_notified_at | bigint | ja | | |
| created_at, updated_at | bigint | nein | | |
Indizes: `nn_asg_team`(team_id, status); `nn_asg_due`(status, due_at).

**`nextnotes_submissions`**
| Spalte | Typ | Null | Default | Zweck |
|---|---|---|---|---|
| id | bigint | nein | AI | |
| assignment_id | bigint | nein | | |
| student_uid | string(64) | nein | | |
| note_id | bigint | ja | | Kopie des Schülers |
| status | string(16) | nein | 'assigned' | §9.2 |
| due_override_at | bigint | ja | | Frist nach `reopen` |
| submitted_at, returned_at | bigint | ja | | |
| feedback | text | ja | | Freitext |
| points | int | ja | | optional |
| feedback_by | string(64) | ja | | |
| feedback_at | bigint | ja | | |
| left_team_at | bigint | ja | | Schüler ausgetreten |
| created_at, updated_at | bigint | nein | | |
Indizes: UNIQUE `nn_sub_uniq`(assignment_id, student_uid); `nn_sub_student`(student_uid, status); `nn_sub_due`(status, due_override_at).

### 2.2 Nicht übernommen
`settings` → `IUserConfig`; `global_settings` → `IAppConfig`; `users` → NC-Benutzer; `shared_notes`/`shared_folders`/`shared_links` (ADR 0011); `ai_conversations` (ADR 0010); `activity_log` (ADR 0008); alle Auth-/OAuth-/TOTP-/Push-/Webhook-Tabellen (gestrichen). Spalte `offline` entfällt (ADR 0015); `entries.folder` (Name), `entries.attachments` (JSON), `reminder_at`/`reminder_recurrence` werden in der API aus den neuen Tabellen erzeugt.

---

## 3. Rechte-Modell

**Prüfpunkt:** `Service\AccessService`. **Regel: Jeder Lesepfad auf Notizen, Anhänge und Abgaben läuft über `AccessService`**, auch indirekte: Ziele von `linked_note_id`, `/notes/resolve`, Backlinks, Graph, Suche (`/notes/search*`, Unified Search), Mentions, Tasks-Übersicht, Kalender/Diary, Änderungs-Polling (`/changes`), alle Seitenrouten (`index.php?note=`, `excalidraw_editor.php`, `revisions.php`, `audio_player.php`, `kanban_content.php`, `attachments.php` …) und alle Kompatibilitätsrouten (`api_download_note.php`, `api_export_*`, `api_note_info.php`, `api_save_excalidraw.php`, `workspaces.php`, `storage-stats-user.php`). Daten ohne Leserecht werden in Listen, Graph, Backlinks und Suchergebnissen still weggefiltert und bei Direktzugriff mit 404 beantwortet. Jeder Controller lädt Objekte ausschließlich über `requireWorkspace(id|key, perm)`, `requireNote(id, perm)`, `requireFolder(id, perm)`, `requireAssignment(id, perm)`, `requireSubmission(id, perm)`; Listen filtern in SQL über `visibleWorkspaceIds(uid)`. Kein Lesen per Mapper am Service vorbei. Keine Leseberechtigung → 404 (keine ID-Aufzählung); lesbar, aber nicht schreibbar → 403. Admins haben keinen impliziten Zugriff. UID kommt nur aus `IUserSession::getUser()->getUID()`.

| Objekt | lesen | schreiben | verwalten (umbenennen, löschen, teilen) |
|---|---|---|---|
| Workspace `owner_type=user` | Besitzer; `ws_shares` user=uid | dto. (`permission=write`) | Besitzer |
| Workspace `owner_type=team`, `state=active` | Team-Level ≥ 1 (Teams verfügbar) | Level ≥ 1 | Level ≥ 4 |
| Workspace `state=orphaned` (nach `CircleDestroyedEvent` oder zweifacher Bestätigung durch `CleanupJob`/`orphan-check`, §8) | `created_by` | – | `created_by`: innerhalb 30 Tagen in privat umwandeln (`owner_type=user`, `owner_id=created_by`, `state=active`) oder exportieren |
| Team-Workspace **eingefroren** (berechnet: Teams-App fehlt/deaktiviert, `state=active`) | `created_by` | – | – (keine Löschfrist; wird bei Reaktivierung automatisch wieder normal) |
| Workspace `kind=assignments` | Besitzer (Schüler) | nur über Notiz-Regeln | – (systemverwaltet) |
| Notiz `role=note` | wie Workspace | wie Workspace | – |
| Notiz `role=template` | Lehrkraft des Teams (Level ≥ 4) | dto., solange Aufgabe `draft` | – |
| Notiz `role=submission` | Schüler (`student_uid`) und Lehrkräfte des Teams | nur Schüler und nur wenn `SubmissionStateService::studentCanEdit` (§9.3) | – |
| Ordner, Tags, Anhänge, Snapshots, Aufgaben (Tasks), Erinnerungen, Sperren | abgeleitet von Notiz/Workspace | dto. | |
| Aufgabe (assignment) | Lehrkräfte des Teams; Schüler nur über eigene Abgabe | Lehrkräfte | Lehrkräfte |
| Aufgabe eines gelöschten (verwaisten) oder eingefrorenen Teams | `created_by` der Aufgabe (alle Abgaben) | keine manuellen Aktionen (Austeilen, Ändern, Rückgabe, Wiederöffnen, Feedback); Export und Löschen durch `created_by` | – |
| Abgabe eines gelöschten/eingefrorenen Teams oder ausgetretenen Schülers | Schüler (nur eigene, Feedback sichtbar), `created_by` der Aufgabe bzw. Lehrkräfte | Schüler: nichts (kein Bearbeiten, kein Abgeben); Fristablauf → `missed` wird weiter berechnet | – |
| Abgabe (submission) | Schüler (eigene), Lehrkräfte des Teams | Statuswechsel laut §9.2 | – |

**Rollen** (ADR 0003): Team-Level aus `TeamService::getLevel(teamId, uid)` = **effektives** Level (Circles `Membership::getLevel()` über `CirclesManager::getLink` bzw. `Circle::getInitiator()` nach `startSession`), d. h. inklusive Mitgliedschaft über Gruppen und verschachtelte Teams; nur Status `Member` zählt, `Invited`/`Requesting`/`Blocked` = 0. Owner 9, Admin 8, Moderator 4 = **Lehrkraft**; Member 1 = **Schüler**; 0 = kein Mitglied. Ergebnis wird pro Request gecacht, nie gespeichert → Mitgliederänderungen wirken sofort. Mitgliederlisten fürs Austeilen: `Circle::getInheritedMembers()`, gefiltert auf lokale Benutzer mit Status `Member` und effektivem Level 1.

**Ohne Teams-App:** `TeamService::isAvailable()` false → alle Team-Workspaces und Aufgaben gelten als **eingefroren** (Zeilen oben); private Workspaces unverändert. `TeamService` nutzt OCP, wo es reicht: `ITeamManager::hasTeamSupport()` (@since 34) für die Verfügbarkeit, `getTeam()` (@since 35) für Existenz/Name, `getTeamsForUser()` (@since 33) und `getMembersOfTeam()` (@since 34) für Mitgliederlisten. Circles-API nur für das, was OCP nicht liefert: effektives Rollen-Level und Mitgliedsstatus (`Membership::getLevel`, `Member::STATUS_*`) sowie Events. Ein Circles-Fehler oder „Team nicht gefunden“ beim Zugriff ändert nie den gespeicherten Zustand (nur 404 für diesen Request).

---

## 4. API

### 4.1 Basis-URL und Routen
- Registrierung in `appinfo/routes.php` (gleiche Reihenfolge wie `src/public/api/v1/index.php`, damit `check-upstream` diffen kann). Keine OCS-Routen (OCS-Hülle würde das JSON-Format ändern).
- Pfade: `/apps/nextnotes/api/v1/<Upstream-Pfad>`; Basis serverseitig per `IURLGenerator::linkToRoute('nextnotes.page.index')` (entspricht `generateUrl('/apps/nextnotes')` aus `@nextcloud/router` im Browser; `generateUrl` ist eine JS-Funktion, `IURLGenerator` hat sie nicht) → InitialState `nextnotes/config` `{ baseUrl, apiBase, uid, displayName, lang, teamsAvailable, excalidrawAssetPath, allowedIframeDomains, hiddenUiElements }`.
- Parameter `{id}`, `{noteId}`, `{taskId}` mit Requirement `\d+` bzw. Upstream-Format; `{name}`, `{tag}` `[^/]+`.
- Antworten: `JSONResponse` mit identischem Aufbau und Fehlerformat wie Upstream (`sendError`). ETag bei `GET /notes/{id}` wie Upstream.

### 4.2 Routen-Mapping
| Upstream | Nextcloud-Route | Controller | Hinweis |
|---|---|---|---|
| `/api/v1/notes…`, `/changes`, `/convert-*`, `/graph`, `/mentions…`, `/tasks`, `/notes/{id}/tasks…` | gleich unter `/apps/nextnotes/api/v1` | `NotesApiController`, `TasksApiController`, `MentionsApiController`, `GraphApiController` | A #1–24, #28–49 |
| `/notes/{id}/snapshot(s)…` | gleich | `SnapshotsApiController` | A #50–54, Speicher DB (ADR 0002) |
| `/notes/{id}/reminder`, `/task-reminder` | gleich | `RemindersApiController` | A #55–59 |
| `/reminders`, `/reminders/count`, `/reminders/*` | gleich, Kompatibilität: liefert `[]`/`0`/`success` | dto. | Anzeige über NC-Glocke (§6) |
| `/folders…`, `/notes/{id}/folder|remove-folder|archive|kanban-completed` | gleich | `FoldersApiController` | A #69–95 |
| `/trash…` | gleich | `TrashApiController` | |
| `/workspaces…` | gleich, `{name}` = Workspace-Schlüssel (§4.4) | `WorkspacesApiController` | |
| `/tags…` | gleich | `TagsApiController` | |
| `/notes/{noteId}/attachments…` | gleich | `AttachmentsApiController` | GET `#[NoCSRFRequired]` (wird als `<img src>` geladen); Antwort `FileDisplayResponse`, `Content-Disposition: attachment` außer für sichere Bildtypen; SVG/HTML nie inline |
| `/settings`, `/settings/{key}` | gleich, Whitelist der Upstream-Schlüssel → `IUserConfig` | `SettingsApiController` | |
| `/system/version`, `/system/whats-new*`, `/system/i18n` | gleich | `SystemApiController` | Version aus `IAppManager::getAppVersion` |
| `/users/me` | gleich (Felder aus NC-Benutzer) | `UsersApiController` | |
| `/users/lookup/{username}`, `/mentions/members` | gleich, über `Collaborators\ISearch` | dto. | |
| `/shared`, `/shared/with-me` | gleich, liefert `[]` (registriert, obwohl Inventur „offen (ADR)“) | `SystemApiController` | ADR 0011 |
| `/api/v1/calendar/*.php`, `/api/v1/diary/*.php` | gleiche Pfade inkl. `.php` | `DiaryApiController` | A #186–190 |
| `workspaces.php` (POST JSON-Aktionen) | `/apps/nextnotes/workspaces.php` POST | `CompatController` | gleiche Aktionen/Formate |
| `api_save_excalidraw.php`, `api_download_note.php`, `api_export_note.php`, `api_export_folder.php`, `api_export_entries.php`, `api_export_structured.php`, `api_export_attachments.php`, `api_import_dropped_notes.php`, `api_note_info.php`, `api_upload_background.php`, `api_attachments.php` (nur Download, Alt-URLs) | `/apps/nextnotes/<gleicher Dateiname>` | `CompatController` | Download-GETs `#[NoCSRFRequired]` |
| `storage-stats-user.php?fragment=1` | `/apps/nextnotes/storage-stats-user.php` | `CompatController` | ADR 0002 |
| Seiten (`index.php`, `dashboard.php`, …) | `/apps/nextnotes/<gleicher Dateiname>` | `PageController` | ADR 0004 (Spike M1) |
| `login.php`, `logout.php` | `/apps/nextnotes/login.php` → Redirect auf NC-Login mit `redirect_url`; `logout.php` → NC-Logout-URL | `PageController` | für `session-guard.js` |
| Alles mit Status „streichen“ in Inventur A/B | nicht registriert → 404 | – | |
| Neu: `/api/v1/assignments…`, `/api/v1/submissions…` | §9.5 | `AssignmentController` | M6 |

### 4.3 Adapter `nextnotes-bootstrap.js` (vor allen Upstream-Skripten)
1. Liest `nextnotes/config` aus dem InitialState-Element (`#initial-state-nextnotes-config`, Base64-JSON, §1.3) und setzt die bisherigen Globals (`ALLOWED_IFRAME_DOMAINS`, `EXCALIDRAW_ASSET_PATH`, `POZNOTE_I18N`, `__POZNOTE_HIDDEN_UI_ELEMENTS__`, `DASHBOARD_*`, `DIARY_DATA`, … – Inventur B §3).
2. Hüllt `fetch` und `XMLHttpRequest.open/send`: URLs `/api/v1/…`, `api/v1/…` und `/audio_player.php` → `apiBase`/`baseUrl`; für same-origin-Aufrufe an die App Header `requesttoken` (aktueller Token aus `document.head.dataset.requesttoken` bzw. `OC.requestToken`; Quelle `layout.user.php`, §1.3) und `X-Requested-With: XMLHttpRequest`.
3. Hüllt `navigator.sendBeacon`: URL umschreiben; bei `FormData` Feld `requesttoken` anhängen, sonst `?requesttoken=` an die URL. Grundlage: `OC\AppFramework\Http\Request::passesCSRFCheck()` akzeptiert den Token aus GET, POST (multipart → `$_POST`) oder Header `HTTP_REQUESTTOKEN`; Beacon sendet Cookies same-origin, `passesStrictCookieCheck` bleibt erfüllt.
4. Sicherheitsnetz: `MutationObserver` schreibt `src`/`href` mit `/api/v1/` oder `/audio_player.php` auf die App-Basis um (für im JS erzeugtes Markup).
5. Hell/Dunkel: setzt `html[data-theme]` aus den NC-Body-Attributen `data-theme-*` (ADR 0016) und beobachtet Änderungen.
6. Event-Delegation für Aktionen, deren Inline-Handler in Templates entfernt wurden (`data-action`-Muster wie Upstream).

### 4.4 Workspace-Schlüssel
Upstream adressiert Workspaces per Name (`/workspaces/{name}`, `?workspace=`). Schlüssel = Name bei eigenen Workspaces; bei Team- oder geteilten Workspaces `"<Name> (<Team- bzw. Besitzername>)"`, falls der Name in der sichtbaren Menge sonst doppelt wäre. `WorkspaceService::resolveKey(uid, key)` bildet zurück; mehrdeutig → 409. Rename-Bodies werden ohne Zusatz gespeichert.

### 4.5 CSRF und Attribute
- Alle schreibenden Routen (POST/PUT/PATCH/DELETE): CSRF-Prüfung aktiv (kein `NoCSRFRequired`). NC prüft CSRF bei jeder Methode ohne `NoCSRFRequired` (`SecurityMiddleware::isInvalidCSRFRequired`), daher auch GET-API-Aufrufe über den Adapter mit Token.
- `#[NoCSRFRequired]` nur für: Seiten (PageController), GET-Downloads/Medien (Anhänge, Exporte, Hintergrundbild, Audio-Player). Regel: kein GET mit Seiteneffekt.
- Alle Benutzerrouten `#[NoAdminRequired]`. Admin-Einstellungen nur über `ISettings`-Formular (Admin-geprüft).
- `#[UserRateLimit]` (@since 27) auf Import, Export-ZIP und Upload.

### 4.6 CSP
Jede `TemplateResponse` erhält ihre Policy aus `Http\CspFactory` (ADR 0006, 0012). Templates enthalten keine ausführbaren Inline-Skripte und keine `on…=`-Handler; Daten über InitialState oder JSON-Inseln (`type="application/json"`).

---

## 5. Hintergrundjobs

| Job | Typ | Intervall | Aufgabe |
|---|---|---|---|
| `ReminderJob` | `TimedJob` (@since 15) | 300 s | fällige `nextnotes_reminders` (`sent_at IS NULL AND trigger_at <= now`) → `Notification\IManager::notify`, `sent_at` setzen; Wiederholung → nächste Zeile |
| `AssignmentDeadlineJob` | `TimedJob` | 300 s | `SubmissionStateService::sweep(now)`: fällige Abgaben → `missed`; Nachzügler-Abgleich; einmalige Benachrichtigung an Lehrkräfte (`deadline_notified_at`) |
| `LockCleanupJob` | `TimedJob` | 3600 s | abgelaufene Sperren löschen (nur Aufräumen, Logik braucht ihn nicht) |
| `SnapshotRetentionJob` | `TimedJob` | 86400 s | Upstream-Aufbewahrung je Notiz, verwaiste Snapshots |
| `CleanupJob` | `TimedJob` | 86400 s | verwaiste Anhangsdateien in `IAppData`; Team-Existenzprüfung für aktive Team-Workspaces (§8, nur bei aktivem, fehlerfreiem Circles; zweimal „Team fehlt“ → `orphaned`); Löschung verwaister Team-Workspaces > 30 Tage mit Vorab-Benachrichtigung (ADR 0003) |
| `UserDataDeletionJob` | `QueuedJob` (@since 15) | einmalig | Löschung nach `UserDeletedEvent` (§8) |
| `ImportJob` | `QueuedJob` | einmalig | großer ZIP-Import (ADR 0009) |
Keine Logik darf von pünktlichem Cron abhängen (NC-Cron kann auf AJAX stehen).

## 6. Benachrichtigungen
`Notification\Notifier` (`INotifier`, @since 9/17; registriert per `registerNotifierService`, @since 22; unbekannte → `UnknownNotificationException`, @since 30). App `nextnotes`, Objekttypen `note`, `assignment`, `submission`. Subjects: `reminder`, `task_reminder` (Notiz-Link), `assignment_assigned` (Schüler), `assignment_deadline_passed` (Lehrkräfte), `submission_returned`, `submission_reopened` (Schüler), `mention` (Erwähnung in geteiltem/Team-Workspace). Beim Löschen eines Objekts `IManager::markProcessed` (aus `Notification\IApp`). Kein E-Mail-/Push-Versand eigener Art (NC-Notifications-App übernimmt).

## 7. Unified Search
`Search\NotesProvider` (`OCP\Search\IProvider`, @since 20; registriert per `registerSearchProvider`, @since 20). Suche in `heading`/`search_text` der sichtbaren Workspaces (gleiches SQL wie `/notes/search`, `LIKE` auf normalisierten Text über `IQueryBuilder`, kein DB-spezifisches SQL), ohne Papierkorb, mit Paginierung (`ISearchQuery::getCursor/getLimit`). Ergebnis-Link `…/index.php?note=<id>&workspace=<key>`. Filter über `AccessService` (§3): Schüler finden nur eigene Abgaben; Abgaben anderer erscheinen für niemanden außer Lehrkräften, und für diese nur in der Aufgabenansicht, nicht in der globalen Suche.

## 8. Lebenszyklus
| Ereignis | Mechanismus | Wirkung |
|---|---|---|
| Benutzer gelöscht | `OCP\User\Events\UserDeletedEvent` (@since 18) → `UserDataDeletionJob` | löscht private Workspaces (inkl. Notizen, Ordner, Tags, Anhänge/IAppData, Snapshots, Reminder, Locks, Mentions), `ws_shares` an/vom Benutzer, eigene Abgaben + Kopien + `assignments`-Workspaces [THOMAS, ADR 0008]; setzt `created_by`/`updated_by`=NULL in fremden Workspaces; behält Aufgaben der Lehrkraft im Team |
| Team gelöscht | Circles `CircleDestroyedEvent` (§1.3); Rückfallweg für verpasste Events: `CleanupJob`/`occ nextnotes:workspace:orphan-check` mit zweifacher fehlerfreier Bestätigung (s. u.); nie durch „nicht gefunden“ beim Zugriff | Team-Workspaces → `orphaned` (ADR 0003): `created_by` kann 30 Tage lang in privat umwandeln; Benachrichtigung an `created_by` beim Verwaisen und 7 Tage vor Löschung; danach löscht `CleanupJob`. Aufgaben/Abgaben des Teams: Rechte laut §3; gesperrt sind nur **manuelle Aktionen** (Austeilen, Abgeben, Rückgabe, Wiederöffnen, Feedback, Friständerung); der berechnete Fristablauf → `missed` (§9.3) läuft weiter. Keine automatische Löschung von Aufgaben/Abgaben (ADR 0008) |
| Mitglied tritt bei (auch indirekt) | Circles `CircleMemberAddedEvent`/`MembershipsCreatedEvent` (optional) + lazy + Job | Nachträgliches Austeilen (§9.4), idempotent |
| Mitglied tritt aus (auch indirekt über Gruppe/verschachteltes Team) | Circles `CircleMemberRemovedEvent`/`MembershipsRemovedEvent` (optional) + Prüfung des effektiven Levels bei jedem Zugriff | sofort: kein Zugriff auf Team-Workspaces, eigene Abgabe nur noch lesbar (kein Bearbeiten/Abgeben); `left_team_at` setzen; Abgabe bleibt für Lehrkräfte |
| Teams-App deaktiviert/fehlt | `TeamService::isAvailable()` | „eingefroren“ (§3): lesbar für Ersteller und für Schüler (nur eigene Abgaben), keine Schreibzugriffe, keine Löschfrist; nach Reaktivierung automatisch normal |
| App deaktiviert | – | Daten bleiben; Jobs laufen nicht (NC-Standard) |
| App entfernt (`occ app:remove`) | – | Tabellen und `IAppData` bleiben (NC-Standard); `occ nextnotes:purge --force` löscht alles |
| Gruppe gelöscht | `GroupDeletedEvent` (@since 18) | erst relevant mit Gruppen-Freigaben (ADR 0014 Option 3) |
Zusätzlich (Lead, 2026-11-02): `CleanupJob` prüft täglich jeden aktiven Team-Workspace über `ITeamManager::getTeam()`, aber nur, wenn Circles aktiv ist und fehlerfrei antwortet; meldet er zweimal hintereinander „Team fehlt“, wird der Workspace `orphaned` (normale 30-Tage-Frist mit Benachrichtigung). Ein Lauf mit Fehler/deaktiviertem Circles ändert den Zähler nicht; „Team vorhanden“ setzt ihn auf 0. Manuell: `occ nextnotes:workspace:orphan-check [--dry-run]` (gleiche Logik, `--dry-run` ändert nichts).

Zusätzlich `UserMigration\NextNotesMigrator` (`IMigrator`, @since 24) und `occ nextnotes:export-user`, `nextnotes:delete-user-data` (ADR 0008).

---

## 9. Austeilen und Einsammeln (M6)

### 9.1 Ablauf
1. Lehrkraft (Level ≥ 4) wählt eine Notiz (lesbar für sie), Team, Titel, Frist (`due_at` > now) → `POST /api/v1/assignments`: Aufgabe `draft`, eingefrorene Kopie als `role=template` (inkl. Anhänge, physisch kopiert).
2. `POST /api/v1/assignments/{id}/distribute`: Status `distributed`, für jedes Team-Mitglied mit Level = 1: Abgabe + eigene Kopie (Inhalt + Anhänge) in seinem Workspace `kind=assignments` (je Schüler und Team, bei Bedarf angelegt), Ordner je Aufgabe. Pro Schüler eine Transaktion.
3. Schüler bearbeitet die Kopie (normaler Editor), `POST /api/v1/submissions/{id}/submit` → `submitted`, danach schreibgeschützt.
4. Frist: offene Abgaben werden `missed` (§9.3), Schreibzugriff wird serverseitig bei jedem Speichern geprüft.
5. Lehrkraft sieht alle Abgaben mit Status, schreibt Feedback (Freitext, optional Punkte ≤ `max_points`), `return` oder `reopen` (optional neue individuelle Frist).
6. Schüler sieht Feedback in seiner Abgabe. Lehrkraft: `GET /api/v1/assignments/{id}/export` → ZIP (`ZipResponse`, @since 15): je Schüler Ordner `<Anzeigename>_<uid>/` mit Notiz (HTML/MD wie Upstream-Export), Anhängen, `feedback.txt`; dazu `status.csv` (uid, Name, Status, Abgabezeit, Punkte).

### 9.2 Zustandsautomat `submissions.status`
| von → nach | Auslöser | Bedingung |
|---|---|---|
| (neu) → `assigned` | Austeilen/Nachzügler | Aufgabe `distributed`, now ≤ `due_at`, effektives Level = 1, keine Abgabe vorhanden |
| `assigned` → `submitted` | Schüler `submit` | now ≤ effektive Frist |
| `assigned` → `missed` | Materialisierung (§9.3) | now > effektive Frist |
| `submitted` → `returned` | Lehrkraft `return` | Feedback gesetzt (darf leer sein) |
| `submitted` → `reopened` | Lehrkraft `reopen` | optional `due_override_at` > now |
| `missed` → `reopened` | Lehrkraft `reopen` | dto. |
| `missed` → `returned` | Lehrkraft `return` | Feedback zu nicht abgegebener Arbeit |
| `returned` → `reopened` | Lehrkraft `reopen` | dto. |
| `reopened` → `submitted` | Schüler `submit` | now ≤ effektive Frist |
| `reopened` → `missed` | Materialisierung | now > effektive Frist |
| `missed` → `assigned` | Lehrkraft verlängert `due_at` (PATCH) | nur Abgaben mit `submitted_at IS NULL` und ohne `due_override_at`; neue Frist > now |
Alle anderen Übergänge → 409 `invalid_transition`. `distribute` nach Ablauf von `due_at` → 409 `deadline_passed`. Nach einer Fristverlängerung erhalten fehlende Schüler ihre Kopie beim nächsten Zugriff oder Joblauf (`reconcile`, idempotent). Der Übergang nach `missed` ist ein **berechneter** Status (§9.3), keine manuelle Aktion; er gilt auch bei verwaisten/eingefrorenen Teams. Effektive Frist = `due_override_at` falls Status `reopened` und gesetzt, bei `reopened` ohne Override keine Frist; sonst `assignments.due_at`. Feedback ändern ohne Statuswechsel (`PUT …/feedback`) in `submitted`, `missed`, `returned`. Aufgabe `closed` (Lehrkraft) friert alle Übergänge ein. Jeder Übergang ist ein bedingtes `UPDATE … WHERE id = ? AND status = <alt>` (Wettlaufsicher); 0 Zeilen → 409.

### 9.3 Frist ohne Cron-Abhängigkeit
- `SubmissionStateService::materialize(submission, now)` setzt `assigned`/`reopened` mit abgelaufener effektiver Frist auf `missed`. Aufgerufen vor **jedem** Lesen oder Schreiben einer Abgabe oder ihrer Notiz (in `AccessService::requireNote` für `role=submission`, in allen Abgabe-Endpunkten, Listen materialisieren alle betroffenen Zeilen per bedingtem Massen-UPDATE).
- `studentCanEdit` = uid = `student_uid` ∧ Status ∈ {`assigned`, `reopened`} ∧ now ≤ effektive Frist ∧ Aufgabe `distributed` ∧ Teams verfügbar ∧ Team nicht verwaist ∧ effektives Level des Schülers = 1 (ausgetretene Schüler: nur Lesen). Dieselbe Bedingung gilt für `submit`. Gilt für alle schreibenden Notiz-Pfade: PATCH/Beacon, Tags, Icon/Farbe/Pins/Breite/Titelstil, Konvertieren, Tasks, Anhänge (Upload/Löschen/Verschieben), Excalidraw-Speichern, Snapshot-Restore, Verschieben, Löschen/Papierkorb, Duplizieren. Verstoß → 403 `submission_locked`.
- Zeit nur aus `ITimeFactory` (Server). `AssignmentDeadlineJob` ist nur für Benachrichtigungen und Aufräumen nötig.

### 9.4 Pflichtfälle
| Fall | Umsetzung | Nachweis (PHPUnit) |
|---|---|---|
| Neues Mitglied nach Austeilen bekommt Aufgabe, falls Frist offen | `reconcile(teamId[, uid])` bei `CircleMemberAddedEvent`, beim Öffnen der App/Aufgabenliste durch den Schüler und im Job; nur wenn now ≤ `due_at` | Mitglied nach Austeilen hinzufügen → genau 1 Abgabe; nach Frist hinzufügen → 0 |
| Ausgetretene Schüler: Abgabe bleibt für Lehrkraft | Austritt setzt nur `left_team_at`; Abgaben/Notizen werden nie wegen Austritt gelöscht; Lehrkraft-Liste zeigt sie markiert | Austritt → Lehrkraft-GET liefert Abgabe weiter |
| Schüler sehen niemals Abgaben anderer | Schüler-Endpunkte filtern `student_uid = uid`; `GET /assignments/{id}/submissions` nur Level ≥ 4; Notizen `role=submission` nur für Besitzer/Lehrkraft; keine Team-Workspace-Ablage; Suche filtert | S2 liest Abgabe/Notiz/Anhang von S1 → 404 auf allen Pfaden |
| Doppeltes Austeilen erzeugt keine Duplikate | UNIQUE (assignment_id, student_uid); `distribute` idempotent; Unique-Verletzung (`OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION`) = „schon vorhanden“ | 2× `distribute` parallel → je Schüler 1 Abgabe, 1 Notiz |
| Frist läuft auch bei verspätetem Cron | Materialisierung bei Zugriff (§9.3) | ohne Job-Lauf: Zeit nach Frist → Speichern 403, Status `missed` |

### 9.5 Endpunkte (alle `#[NoAdminRequired]`, CSRF aktiv)
`GET /api/v1/assignments` (Lehrkraft: Aufgaben ihrer Teams; Schüler: eigene Abgaben mit Aufgabe), `POST /api/v1/assignments`, `GET|PATCH|DELETE /api/v1/assignments/{id}` (PATCH: Titel, Frist nur in `draft`/`distributed` und nur verlängernd, Verlängerung setzt `missed` ohne Abgabezeit zurück auf `assigned`; DELETE löscht Abgaben + Kopien), `POST /api/v1/assignments/{id}/distribute`, `POST /api/v1/assignments/{id}/close`, `GET /api/v1/assignments/{id}/submissions`, `GET /api/v1/assignments/{id}/export` (`NoCSRFRequired`, nur GET-Download), `GET /api/v1/submissions/{id}`, `POST /api/v1/submissions/{id}/submit|return|reopen`, `PUT /api/v1/submissions/{id}/feedback`. UI: Ansicht `assignments.php` (neues Template) + `nextnotes-assignments.js` im Upstream-Stil; Banner „abgegeben/gesperrt“ im Editor über Adapter.

---

## 10. Migration gespeicherter URLs in Notizen
Kanonische Speicherform = Upstream-Form (bleibt kompatibel zu Upstream-Export/-Import):
| Muster im Inhalt | gespeichert | beim Ausliefern (`ContentUrlRewriter::toClient`) |
|---|---|---|
| `/api/v1/notes/<n>/attachments/<a>` | unverändert | `<apiBase>/notes/<n>/attachments/<a>` |
| `api_attachments.php?action=download&note_id=<n>&attachment_id=<a>` (alt) | beim Import/ersten Speichern → obere Form | dto. |
| `/audio_player.php?…` | unverändert | `<baseUrl>/audio_player.php?…` |
| `index.php?note=<n>[&workspace=…]` (relativ) | unverändert | unverändert (Seitenroute mit gleichem Namen, ADR 0004); Fallback ohne Spike: `<baseUrl>/?note=` |
| `diary.php?date=…` | unverändert | unverändert (dto.) |
`toServer` beim Speichern kehrt die Umschreibung um (`<apiBase>/…` → `/api/v1/…`). Gilt für `GET /notes/{id}`, serverseitig gerenderte Notizen, Exporte (Export liefert kanonische Form). Import (ADR 0009): Notiz- und Anhangs-IDs über ID-Map auf neue globale IDs umschreiben, Base64-Bilder wie Upstream `convert-base64-images` in Anhänge umwandeln.

---

## 11. Upstream-Patches und bekannte Upstream-Bugs

### 11.1 Upstream-Patches (direkte Änderungen in `src/`)
Regel (Thomas): gezielte direkte Änderungen in `src/` sind erlaubt; jede steht in dieser Liste. Keine Build-Zeit-Patches. Änderungen klein und lokal halten, damit `git merge` mit Upstream selten Konflikte erzeugt. `nextnotes/build/upstream-patches.txt` enthält dieselben Dateipfade; `check-upstream` vergleicht sie mit `git diff --name-only <Upstream-Basis> -- src/`.
| # | Datei | Änderung | Begründung | Upstream-PR-Kandidat |
|---|---|---|---|---|
| P1 | `public/js/events-drag-drop.js` (:181) | `new Function('event', dataOnclick)` → Aufruf über `data-action`-Registry | CSP ohne `unsafe-eval`; Bug B3 | ja |
| P2 | `public/js/tasklist-render.js`, `attachments.js`, `tasklist-subtasks.js`, `ui.js`, `utils-kanban.js`, `tasklist-order-drag.js`, `note-image-actions.js` | `onclick="…"` in erzeugtem HTML → `data-action` + Delegation | CSP, Inline-Handler blockiert (23 Stellen) | ja (strengere CSP auch für Upstream) |
| P3 | JS-Dateien mit `javascript:`-URLs | → `href="#"` + `preventDefault` | CSP | ja |
| P4 | `public/js/index-events.js` (:561, :1548) | Verweis auf nicht existierende `profile.php` entfernen | Bug B1 | ja |
| P5 (bedingt) | JS, das root-absolute `src` für Bilder/iframes erzeugt (`slash-command.js`, `attachments.js`, `markdown-parser.js`, `note-attachment-menu.js`, `events-utils.js`) | Präfix über `window.NEXTNOTES.url()` | nur falls der MutationObserver (§4.3.4) 404-Vorabladungen nicht verhindert (M3 messen) | ja (Upstream unter Unterpfad betreibbar) |
| P6 (bedingt) | `public/js/navigation.js` | Seiten-URLs über Basis | nur falls Spike `.php`-Routen scheitert (ADR 0004) | ja |
Templates (`nextnotes/templates/`) sind abgeleitete Kopien, keine Patches; Herkunft im Kopfkommentar. Jede neue Änderung in `src/` braucht einen Eintrag hier.

### 11.2 Bekannte Upstream-Bugs
| # | Fundstelle | Problem | Lösung im Port |
|---|---|---|---|
| B1 | `src/public/js/index-events.js:561`, `:1548` | Navigation auf `profile.php`, die Datei existiert nicht (Inventur B 2.2 #44) | Patch P4; Profil = NC-Einstellungen |
| B2 | `src/public/api/v1/index.php`, alle schreibenden api/v1-Routen | kein CSRF-Token, Schutz nur über `SameSite=Lax` (Inventur B 2.4) | NC-CSRF auf allen schreibenden Routen (§4.5), Token per Adapter |
| B3 | `src/public/js/events-drag-drop.js:181` | führt `data-onclick`-Attributtext per `new Function` aus → Codeausführung aus DOM-Attributen (XSS-Verstärker) | Patch P1 |
| B4 | `src/public/js/events-auto-save.js:685` | `sendBeacon` ohne CSRF-Token | Adapter hängt `requesttoken` an (§4.3.3) |
| B5 | `src/public/api/v1/index.php:36-46` | `Access-Control-Allow-Origin: *` auf einer Cookie-/Basic-authentifizierten API | entfällt; NC-Standard (same-origin) |
| B6 | `src/db_connect.php:88-98` | Requests ohne Sitzung öffnen die Datenbank von Benutzer 1; Schutz hängt allein an späteren Auth-Prüfungen | kein Fallback; UID nur aus `IUserSession` |
| B7 | `src/db_connect.php:165-880` | Schema-Prüfung (`PRAGMA table_info`, `ALTER TABLE`) bei jedem Verbindungsaufbau | Migrationen (`SimpleMigrationStep`) |
| B8 | `src/functions.php:568`, `NotesController.php:1145-1148` | Inhalt doppelt (Datei + Spalte `entry`), kann auseinanderlaufen | eine Quelle `nextnotes_notes.content` (ADR 0002) |
| B9 | `src/api/v1/controllers/AttachmentsController.php:313` vs. `CURRENT_TIMESTAMP` | Zeitstempel teils PHP-Lokalzeit (`date()`), teils SQLite-UTC | alle Zeiten Unix-UTC aus `ITimeFactory` |
| B10 | `entries.workspace`, `folders.workspace` (Name als Text, kein FK) | Umbenennen muss alle Zeilen ändern; Abbruch hinterlässt verwaiste Zeilen | `workspace_id` (§2.1) |
| B11 | `src/public/favorites.php` | keine Verlinkung im UI gefunden (Inventur B #9), vermutlich toter Code | in M4 prüfen; wenn tot, nicht portieren |
Weitere Funde während der Slices werden hier ergänzt.

## 12. Risiken
| # | Risiko | Gegenmaßnahme |
|---|---|---|
| R1 | Rollen-Level, Mitgliedsstatus und Team-Events nur über Circles-Interna (`CirclesManager`, `Membership::getLevel`, `Member::STATUS_*`, Events), nicht OCP | Kapselung in `TeamService`, `class_exists`; alles andere über `ITeamManager`; Integrationstest gegen Circles auf NC 35 |
| R2 | `getMembersOfTeam()` liefert nur UID → Name, ohne Level und Status | Filter auf Level 1 und Status `Member` über Circles im `TeamService`; in M5 gegen verschachtelte Mitgliedschaft testen |
| R3 | `.php`-Routen funktionieren nicht mit jeder Webserver-Konfiguration | M1-Spike Apache + nginx; Fallback P6 |
| R4 | `defer`-Laden: Upstream-„vor dem Paint“-Logik läuft später → Flackern, Reihenfolgefehler | CSS-Startzustand, Smoke-Test, ggf. `addInitScript` (@since 28) für Bootstrap |
| R5 | `strict-dynamic`-Nachladen/Excalidraw-Worker verhalten sich anders als angenommen | M3/M4 Playwright mit CSP-Prüfung; ADR 0012 |
| R6 | Upstream-Templates sind eng mit DB-Zugriffen verwoben (`index.php`, `notes_list.php`, `note_display.php`) → Portierungsaufwand höher | M3 zuerst Hauptansicht, Aufwand nach M3 neu schätzen |
| R7 | Globale IDs statt Upstream-IDs je Benutzer; Workspace-Schlüssel statt eindeutiger Namen | ID-Map beim Import, §4.4, Tests mit Namenskollision |
| R8 | Favoriten/Pins/Reihenfolge sind objektbezogen (Upstream-Semantik) und in Team-Workspaces für alle gleich | dokumentieren; benutzerbezogen erst nach v1 |
| R9 | Anhänge in `IAppData` zählen nicht zur NC-Quota | App-Quota (ADR 0002) |
| R10 | Suche per `LIKE` ohne Volltextindex skaliert schlecht | Limit/Paginierung; später Fulltext |
| R11 | Autosave des Upstream-Editors erzeugt Fehler bei gesperrten Abgaben | Adapter setzt Editor read-only + Banner, wenn Abgabe gesperrt |
| R12 | Hohe Release-Frequenz von Upstream | `check-upstream` (ADR 0007), Update je Release als eigener Slice |
| R13 | Request-Token-Quelle und InitialState-Format | gegen `stable35` geprüft (§1.3); M3-Smoke bestätigt es |

---

## 13. Done-Kriterien je Meilenstein
Testmatrix: Docker-Testsetup `dev/nextcloud/` mit **NC 35 (35.0.x)**. „3 DBs“ = SQLite, MariaDB, PostgreSQL.

**M1 – Installierbares Skelett**
1. `occ app:enable nextnotes` beendet mit 0 auf frischem NC 35 mit 3 DBs; `occ app:list` zeigt `nextnotes` aktiviert.
2. `appinfo/info.xml` validiert gegen `app-info.xsd` (`xmllint --schema`), `<nextcloud min-version="35" max-version="35"/>`, `<php min-version="8.3" max-version="8.5"/>`, Navigationseintrag vorhanden.
3. Nach Aktivierung existieren alle Tabellen aus §2.1 außer `nextnotes_assignments`/`nextnotes_submissions` mit den genannten Spalten und Indizes (Schema-Abfrage im Test).
4. Angemeldet liefert `GET /index.php/apps/nextnotes/` HTTP 200 mit leerer Seite im NC-Rahmen; abgemeldet Redirect auf Login; Browser-Konsole zeigt 0 CSP-Verletzungen.
5. Spike-Ergebnis: `GET /index.php/apps/nextnotes/dashboard.php` und `/apps/nextnotes/dashboard.php` (pretty URLs) liefern 200 auf Apache und nginx – oder Fallback-Entscheidung ist in ADR 0004 vermerkt.
6. `occ app:disable nextnotes` → 0; eine vorher eingefügte Testzeile ist danach noch vorhanden. `occ app:remove nextnotes` → 0. Install-Skript (install → enable → disable → remove) läuft mit Exit 0.

**M2 – Backend-Kern**
1. Jede Route aus §4.2 (ohne M6-Routen) ist registriert; ein Test vergleicht `routes.php` mit `src/public/api/v1/index.php` abzüglich der Inventurzeilen „streichen“ und „offen (ADR)“, zuzüglich der Kompatibilitätsrouten `/shared` und `/shared/with-me` (liefern `[]`) → keine Differenz.
2. PHPUnit: für jede Controller-Methode mit Objekt-ID greift Benutzer B auf ein Objekt von Benutzer A zu → 403 oder 404; jede Listen-Route liefert B keine Objekte von A.
3. Kern-API-Szenario für zwei Benutzer (Notiz anlegen/ändern/löschen/wiederherstellen, Ordner, Workspace, Tags, Anhang hoch/runter, Papierkorb, Snapshot anlegen/wiederherstellen, Suche) läuft grün; JSON-Schlüssel stimmen mit Upstream-Fixtures überein.
4. Gleicher Workspace-Name bei zwei Benutzern ohne Konflikt.
5. `grep -rE "datetime\(|COLLATE|INSERT OR|REPLACE INTO|PRAGMA|ON CONFLICT|\\\$_SESSION|X-User-ID" nextnotes/lib` → keine Treffer; Migrationen + Tests grün auf 3 DBs.
6. Sperre: Sitzung 2 erhält Konflikt, solange Sitzung 1 hält; nach TTL (gemockte Zeit) erhält Sitzung 2 die Sperre.
7. `ReminderJob` mit fälliger Erinnerung erzeugt genau eine NC-Benachrichtigung; Unified Search liefert nur eigene Notizen.
8. Nach `UserDeletedEvent` + Job enthält keine `nextnotes_*`-Tabelle mehr Zeilen des Benutzers außer den in §8 genannten.

**M3 – Frontend-Grundlage und Editor**
1. Playwright: Notiz anlegen, Text eingeben, neu laden → Inhalt da; Bild-Anhang einfügen → angezeigt; Markdown-Notiz mit Mermaid-Block → `svg` gerendert; KaTeX-Formel → `.katex` vorhanden.
2. Das Szenario läuft in NC-Hell und NC-Dunkel; Hintergrund der App entspricht `--color-main-background`.
3. Auf jeder besuchten Seite: 0 `securitypolicyviolation`-Ereignisse, 0 Antworten 404 auf `/api/v1/…` oder `/audio_player.php` ohne App-Präfix.
4. Tab schließen während ungespeicherter Änderung → Inhalt per Beacon gespeichert (Request mit gültigem `requesttoken`, HTTP 200).
5. `make -C nextnotes build` zweimal → identische Prüfsummen aller Ausgabedateien; `git diff --name-only <Upstream-Basis> -- src/` enthält nur Dateien aus §11.1.
6. Fremdzugriff Seiten- und Kompatibilitätsrouten: Benutzer B ruft für eine Notiz von A `index.php?note=<id>` (inkl. Fragment-Modus), `excalidraw_editor.php`, `revisions.php`, `attachments.php`, `kanban_content.php` (Ordner von A), `api_download_note.php`, `api_export_note.php`, `api_export_folder.php`, `api_note_info.php`, `api_save_excalidraw.php`, `workspaces.php` (Aktionen auf Workspace von A) und den Anhang-Download auf → jeweils 404 (bzw. 403 bei lesbar, aber nicht schreibbar), kein Inhalt von A in der Antwort.

**M4 – Feature-Parität**
1. Für jede Ansicht mit Status „übernehmen“ in Inventur B §1.1 öffnet ein Playwright-Smoke die Seite und führt die Hauptaktion aus (Kanban: Karte verschieben; Tasks: Aufgabe abhaken; Dashboard: Karte öffnen; Graph: Knoten sichtbar; Diary: Eintrag für heute anlegen; Papierkorb: wiederherstellen; Revisionen: Version wiederherstellen; Excalidraw: zeichnen + speichern + Vorschau sichtbar; Speed Dial, Tags, Favoriten, Notizverwaltung, Anhänge, Mentions, Workspaces: Liste lädt) – 0 CSP-Verletzungen.
2. Export (Notiz, Ordner-ZIP, alle Notizen, strukturiert) lädt herunter und ist mit Upstream-Import lesbar; falls [THOMAS] Import bejaht: Upstream-ZIP importiert, Links/Anhänge funktionieren.
3. Gestrichene Funktionen (Login, Konten, AI, öffentliche Links, PWA, Backup, Themes) sind in der UI nicht sichtbar und ihre Routen liefern 404 (Ausnahme: `/shared*` liefert `[]`).
4. Fremdzugriff für alle in M4 hinzugekommenen Seiten- und Kompatibilitätsrouten (`dashboard.php`, `diary.php`, `tasks.php`, `graph.php`, `speed_dial.php`, `mentions.php`, `notes_manager.php`, `list_tags.php`, `list_folders.php`, `api_export_entries.php`, `api_export_structured.php`, `api_export_attachments.php`, `storage-stats-user.php`, Kalender/Diary-Endpunkte): B sieht keine Daten von A (gefiltert bzw. 404).
5. Indirekte Lesepfade: A verlinkt Notiz X (`linked_note_id`, `index.php?note=` im Inhalt, Erwähnung); B ohne Recht auf X erhält X weder über `/notes/resolve`, Backlinks, Graph, `/changes`, Suche noch Unified Search.

**M5 – Teams**
1. A (Owner) legt Team T mit Mitglied B an, legt Workspace mit Besitzer T an → B sieht ihn in `GET /workspaces` und im Team-Arbeitsbereich (`ITeamManager::getSharedWith(T, B)` enthält die Ressource).
2. B aus T entfernen → nächster Request von B auf Notizen des Workspace → 404, ohne Job-Lauf.
3. Circles deaktivieren → private Workspaces funktionieren, keine HTTP 500; Team-Workspaces „eingefroren“: `created_by` liest, B liest nicht, Schreiben → 403/404, Zustand in der DB bleibt `active`; Circles aktivieren → ohne Eingriff wieder normal.
4. T löschen (`CircleDestroyedEvent`) → Workspace `orphaned`, nur `created_by` liest, Benachrichtigung an `created_by`; Umwandlung in privat innerhalb 30 Tagen gelingt; ohne Umwandlung nach 30 Tagen (gemockte Zeit) entfernt, Benachrichtigung 7 Tage vorher. Ein simulierter Circles-Fehler/„nicht gefunden“ beim Zugriff ändert `state` nicht.
5. Freigabe an Benutzer C → C liest/schreibt; Freigabe entfernen → 404.
6. Verschachtelte Mitgliedschaft: B ist über eine Gruppe in T → sieht den Workspace; eingeladenes, noch nicht beigetretenes Mitglied D → sieht ihn nicht.
7. `occ nextnotes:workspace:orphan-check --dry-run` listet einen Team-Workspace, dessen Team ohne Event gelöscht wurde (Circles währenddessen deaktiviert), ohne ihn zu ändern. `CleanupJob` setzt ihn erst nach dem zweiten fehlerfreien Lauf mit „Team fehlt“ auf `orphaned`; ein Lauf mit Circles-Fehler oder deaktiviertem Circles setzt den Zähler nicht hoch.

**M6 – Austeilen und Einsammeln**
1. PHPUnit: jeder erlaubte Übergang aus §9.2 gelingt, jeder nicht gelistete liefert 409.
2. PHPUnit: alle Pflichtfälle aus §9.4 grün; Speichern nach Frist ohne Job-Lauf → 403 und Status `missed`.
3. Playwright mit Lehrkraft L (Moderator) und Schülern S1, S2: Aufgabe aus Notiz mit Anhang erstellen, austeilen → S1, S2 haben je eine Kopie mit Anhang; S1 gibt ab (danach Editor read-only); S2 verpasst Frist (gemockte Zeit) → `missed`; L gibt S1 Feedback + Punkte und `return`, öffnet S2 erneut; S1 sieht Feedback; S2 sieht S1-Abgabe nicht (404); ZIP-Export enthält 2 Schülerordner und `status.csv`.
4. **Schüler B erreicht die Abgabe von Schüler A über keinen Pfad:** Aufgabenliste, `GET /submissions/{id}`, `GET /notes/{id}`, Anhang-Download, `/notes/search`, Unified Search, Graph, Backlinks, `/notes/resolve` (Titel und ID), `/changes`, Direkt-URL `index.php?note=<id>`, `api_download_note.php`, `api_export_note.php`, `revisions.php` → überall gefiltert bzw. 404.
5. Weitere Fälle: `distribute` nach Frist → 409; Fristverlängerung setzt `missed` ohne Abgabezeit auf `assigned`, Nachzügler erhalten Kopie; ausgetretener Schüler kann eigene Abgabe lesen, aber nicht bearbeiten/abgeben (403); Circles deaktiviert → Schüler liest eigene Abgabe, kann nicht abgeben.

**M7 – Paketierung**
1. `make -C nextnotes dist` erzeugt `nextnotes-<version>.tar.gz` mit Wurzel `nextnotes/`; zwei Läufe → gleiche Prüfsumme.
2. Paket auf frischem NC 35 entpacken, `occ app:enable nextnotes` → 0, M3-Smoke grün.
3. `README.md` (Installation, Herkunft „Basiert auf Poznote von Timothé Poznanski (MIT)“), `CHANGELOG.md`, Update-Hinweise vorhanden; `LICENCE` (MIT) enthalten; App-Store-Anforderungen (Lizenzangabe in `info.xml`, Screenshots, Kategorien, Signatur-Ablauf) als Checkliste geprüft, nichts eingereicht.
4. App-ID `nextnotes` im App Store erneut als frei geprüft (ADR 0001).

---

## 14. Verwendete OCP-API (geprüft in `.ref` v35.0.2)
`@since` jeweils der Klasse/Methode; **fett** = in 34/35 eingeführt (Zielversion 35, daher nutzbar).
- AppFramework: `Controller`, `Http\TemplateResponse` (6), `Http\JSONResponse`, `Http\FileDisplayResponse` (11), `Http\DataDownloadResponse` (8), `Http\ZipResponse` (15), `Http\ContentSecurityPolicy` (8.1), `EmptyContentSecurityPolicy` (9; `allowEvalWasm` 28, `addAllowedWorkerSrcDomain` 15, `addAllowedFrameDomain` 8.1, `useStrictDynamic` 24, `useStrictDynamicOnScripts` 28), `Http\Attribute\NoAdminRequired`/`NoCSRFRequired`/`UserRateLimit` (27), `Services\IInitialState` (20), `Utility\ITimeFactory` (8), `Db\QBMapper` (14), `Db\Entity` (7), `Bootstrap\IRegistrationContext` (`registerSearchProvider` 20, `registerEventListener` 20, `registerNotifierService` 22, `registerUserMigrator` 24, `registerTeamResourceProvider` 29).
- `Util::addScript` (4), `addStyle`, `addInitScript` (28); `IURLGenerator` (6); `IUserSession` (6); `IUserManager` (8); `IGroupManager` (8); `IDBConnection` (6; `insertIgnoreConflict` 16); `DB\QueryBuilder\IQueryBuilder` (8.2); `DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION`; `Migration\SimpleMigrationStep` (13); `Config\IUserConfig` (32); `IAppConfig` (7); `L10N\IFactory` (8.2); `IL10N`; `ITempManager` (8); `Security\ISecureRandom` (8).
- Dateien: `Files\IAppData` (11), `Files\SimpleFS\ISimpleFolder` (11).
- Jobs: `BackgroundJob\TimedJob`, `QueuedJob` (15), `IJobList` (7).
- `Notification\IManager` (17), `INotifier` (9/17), `UnknownNotificationException` (30). `Search\IProvider` (20). `Collaboration\Collaborators\ISearch` (13). `Settings\ISettings` (9.1), `IIconSection` (12).
- Events: `User\Events\UserDeletedEvent` (18), `Group\Events\GroupDeletedEvent` (18). `UserMigration\IMigrator` (24).
- Teams: `Teams\ITeamManager` (29; `getTeamsForUser` 33; **`getMembersOfTeam`, `hasTeamSupport` 34.0.0**; **`getTeam` 35.0.0**), `ITeamResourceProvider` (29), `TeamResource`, `Team` (29). Nicht verwendet: **`getTeamFolderProvider`, `ITeamFolderProvider`, `TeamFolder` (35.0.0)** – Notizen liegen nicht in Files (ADR 0002).
- Nicht OCP (Circles-App, gegen `github.com/nextcloud/circles` `stable35` geprüft, nicht in `.ref`): `OCA\Circles\CirclesManager`, `Model\Circle`, `Model\Member::LEVEL_*`/`STATUS_*`, `Model\Membership::getLevel`, `Events\CircleDestroyedEvent`, `CircleMemberAddedEvent`, `CircleMemberRemovedEvent`, `MembershipsCreatedEvent`, `MembershipsRemovedEvent`.
- Später (v1.x): `TaskProcessing\IManager` (30; `getAvailableTaskTypeIds` 32), `TaskTypes\AudioToText`/`TextToTextChat` (30); `AppFramework\AuthPublicShareController` (14), `Security\Bruteforce\IThrottler` (25).
