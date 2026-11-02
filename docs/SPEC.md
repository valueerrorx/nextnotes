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
│  │  │                                 ExportService, ContentUrlRewriter, TeamService (Circles-Adapter),
│  │  │                                 AssignmentService, SubmissionStateService, TeamStateService, TeamCleanupService,
│  │  │                                 ConversionService (HTML↔Markdown)
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
5. `src/i18n/*.json` → `nextnotes/i18n/`. PHP-Abhängigkeiten per Composer (`league/commonmark`, `league/html-to-markdown`, Namespace-Präfix per Build-Schritt gegen Konflikte mit anderen Apps); keine Upstream-PHP-Dateien im Paket. Ausgabe-Parität zur Upstream-Konvertierung über Fixture-Tests. Konvertierung (`/convert-*`, `/notes/{id}/convert`) kommt mit dem Export in M4; dort fällt die Wahl Port der Upstream-Parser (`src/markdown_parser.php`, `src/html_to_markdown.php`) vs. league. Bis dahin Rechteprüfung + 501 (Lead, M2-plan r2).
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

**UID-Regel** (Lead, 2026-11-02): Nextcloud kann eine gelöschte UID neu vergeben. Deshalb bleibt nach einer Kontolöschung keine UID stehen, die Rechte verleiht. Rechtetragende UID-Spalten: `workspaces.owner_id` (bei `owner_type=user`), `workspaces.created_by` (nur für verwaiste/eingefrorene Team-Workspaces, ADR 0003), `ws_shares.share_with`, `assignments.owner_uid`, `submissions.student_uid`; sie werden bei Kontolöschung gelöscht, NULL gesetzt oder übertragen (§8). Reine Anzeige-/Historienspalten (`created_by`, `updated_by`, `feedback_by` in Notizen, Anhängen, Snapshots, Aufgaben, Abgaben) verleihen nie Rechte und werden ebenfalls NULL gesetzt; wo der Name gebraucht wird, steht er in einer `*_name`-Spalte.

### 2.1 Tabellen

**`nextnotes_workspaces`** – Eigentum (ADR 0003)
| Spalte | Typ | Null | Default | Zweck |
|---|---|---|---|---|
| id | bigint | nein | AI | |
| owner_type | string(8) | nein | 'user' | `user` \| `team` \| `deleted` (Konto gelöscht, §8; kein Rechteweg) |
| owner_id | string(64) | nein | | UID oder Circle-singleId |
| kind | string(16) | nein | 'normal' | `normal` \| `assignments` (Schüler-Workspace je Team) |
| linked_team_id | string(64) | ja | | bei `assignments`: Team |
| name | string(255) | nein | | |
| color | string(32) | ja | | |
| tags | string(4000) | ja | | Upstream `workspaces.tags` |
| display_order | int | nein | 0 | Reihenfolge beim Besitzer |
| created_by | string(64) | ja | | Ersteller; rechtetragend nur für verwaiste/eingefrorene Team-Workspaces (§3), NULL bei Kontolöschung |
| created_at, updated_at | bigint | nein | | |
Indizes: `nn_ws_owner`(owner_type, owner_id); UNIQUE `nn_ws_asg`(owner_id, linked_team_id) (Schüler-Workspace eindeutig je Schüler und Team; bei `kind=normal` ist `linked_team_id` NULL und unbeschränkt). Kein gespeicherter Zustand am Workspace: Bei `owner_type=team` wird er aus `nextnotes_teams` abgeleitet (§3). Namen sind bei `kind=normal` je Besitzer eindeutig (Prüfung im `WorkspaceService`, wie Upstream); Schüler-Workspaces dürfen gleiche Anzeigenamen haben. Standard-Workspace (Thomas, 2026-11-02): beim ersten Zugriff ohne eigenen Workspace „Notizen“ (in Benutzersprache beim Anlegen), keine Willkommensnotiz.

**`nextnotes_teams`** – Team-Zustand, einzige Quelle für „verwaist“ und die 30-Tage-Frist (ADR 0003)
| Spalte | Typ | Null | Default | Zweck |
|---|---|---|---|---|
| team_id | string(64) | nein | | PK, Circle-singleId |
| state | string(16) | nein | 'active' | `active` \| `orphaned` (gespeichert); „eingefroren“ wird berechnet (§3) |
| orphaned_at | bigint | ja | | Beginn der 30-Tage-Frist |
| missing_count | smallint | nein | 0 | aufeinanderfolgende fehlerfreie Prüfungen mit „Team fehlt“ |
| team_name_snapshot | string(255) | ja | | letzter bekannter Team-Name (Anzeige nach Löschung) |
| deletion_notified_at | bigint | ja | | Vorab-Benachrichtigung 7 Tage vor Löschung gesendet |
| created_at, updated_at | bigint | nein | | |
Index `nn_team_state`(state, orphaned_at). Zeile wird angelegt (`insertIgnoreConflict`), sobald das Team in Next Notes zum ersten Mal genutzt wird: Team-Workspace anlegen oder Aufgabe anlegen. `team_name_snapshot` wird bei jedem erfolgreichen `getTeam()` aktualisiert. `CircleDestroyedEvent`, `orphan-check` und `CleanupJob` arbeiten nur auf dieser Tabelle.

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
| workspace_id | bigint | ja | | NULL nur bei `role=template` und bei Abgabe-Kopien gelöschter Schüler (ADR 0008 a) |
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
| created_by | string(64) | ja | | nur Anzeige/Historie, nie Rechte; NULL bei Kontolöschung |
| created_by_name | string(255) | ja | | Snapshot des Anzeigenamens des Erstellers |
| owner_uid | string(64) | ja | | verantwortliche Lehrkraft; = Ersteller beim Anlegen; zählt für Rechte nur bei verwaistem/eingefrorenem Team; Übergang per Systempfad (§9.7); NULL = gesperrt, nur nach Kontolöschung ohne Ziel (ADR 0008 c) |
| distributed_at, deadline_notified_at | bigint | ja | | |
| created_at, updated_at | bigint | nein | | |
Indizes: `nn_asg_team`(team_id, status); `nn_asg_due`(status, due_at); `nn_asg_owner`(owner_uid).

**`nextnotes_submissions`**
| Spalte | Typ | Null | Default | Zweck |
|---|---|---|---|---|
| id | bigint | nein | AI | |
| assignment_id | bigint | nein | | |
| student_uid | string(64) | ja | | NULL nach Löschung des Schülerkontos (ADR 0008 a) |
| student_name | string(255) | nein | | Snapshot Anzeigename (beim Austeilen gesetzt, bei Kontolöschung aktualisiert) |
| student_uid_former | string(64) | ja | | UID als Text nach Kontolöschung |
| student_deleted_at | bigint | ja | | Zeitpunkt der Kontolöschung |
| note_id | bigint | ja | | Kopie des Schülers |
| status | string(16) | nein | 'assigned' | §9.2 |
| due_override_at | bigint | ja | | Frist nach `reopen` |
| submitted_at, returned_at | bigint | ja | | |
| feedback | text | ja | | Freitext |
| points | int | ja | | optional |
| feedback_by | string(64) | ja | | nur Anzeige, NULL bei Kontolöschung |
| feedback_by_name | string(255) | ja | | Snapshot Anzeigename |
| feedback_at | bigint | ja | | |
| left_team_at | bigint | ja | | Schüler ausgetreten |
| created_at, updated_at | bigint | nein | | |
Indizes: UNIQUE `nn_sub_uniq`(assignment_id, student_uid) (mehrere NULL erlaubt, gilt in SQLite, MariaDB, PostgreSQL); `nn_sub_student`(student_uid, status); `nn_sub_due`(status, due_override_at).

### 2.2 Nicht übernommen
`settings` → `IUserConfig`; `global_settings` → `IAppConfig`; `users` → NC-Benutzer; `shared_notes`/`shared_folders`/`shared_links` (ADR 0011); `ai_conversations` (ADR 0010); `activity_log` (ADR 0008); alle Auth-/OAuth-/TOTP-/Push-/Webhook-Tabellen (gestrichen). Spalte `offline` entfällt (ADR 0015); `entries.folder` (Name), `entries.attachments` (JSON), `reminder_at`/`reminder_recurrence` werden in der API aus den neuen Tabellen erzeugt.
Vereinfachungen M2b: Snapshots versionieren keine Tags (`tags: null`, Wiederherstellen ändert Tags nicht); Anhänge werden beim Löschen nicht für Snapshots aufbewahrt (`retained_for_snapshots: false`); Snapshot-`render=1` (Markdown-HTML) folgt mit M4; Anhang-IDs im JSON als String wie Upstream. Speicherquote (`attachment_quota_mb`, Standard 500) zählt gegen den Besitzer des Workspace, auch für Uploads von Freigabe-Empfängern (Thomas, 2026-11-02); Prüfung und Einfügen gemeinsam unter Sperre aller Workspace-Zeilen des Besitzers (Update ohne Wertänderung), damit parallele Uploads die Grenze nicht überschreiten.

---

## 3. Rechte-Modell

**Prüfpunkt:** `Service\AccessService`. **Regel: Jeder Lesepfad auf Notizen, Anhänge und Abgaben läuft über `AccessService`**, auch indirekte: Ziele von `linked_note_id`, `/notes/resolve`, Backlinks, Graph, Suche (`/notes/search*`, Unified Search), Mentions, Tasks-Übersicht, Kalender/Diary, Änderungs-Polling (`/changes`), alle Seitenrouten (`index.php?note=`, `excalidraw_editor.php`, `revisions.php`, `audio_player.php`, `kanban_content.php`, `attachments.php` …) und alle Kompatibilitätsrouten (`api_download_note.php`, `api_export_*`, `api_note_info.php`, `api_save_excalidraw.php`, `workspaces.php`, `storage-stats-user.php`). Daten ohne Leserecht werden in Listen, Graph, Backlinks und Suchergebnissen still weggefiltert und bei Direktzugriff mit 404 beantwortet. Jeder Controller lädt Objekte ausschließlich über `requireWorkspace(id|key, perm)`, `requireNote(id, perm)`, `requireFolder(id, perm)`, `requireAssignment(uid, id, action)` (Matrix §9.7), `requireSubmission(id, perm)`; Listen filtern in SQL über `visibleWorkspaceIds(uid)` bzw. `visibleAssignmentIds(uid)` (§9.6). Kein Lesen per Mapper am Service vorbei. Keine Leseberechtigung → 404 (keine ID-Aufzählung); lesbar, aber nicht schreibbar → 403. Admins haben keinen impliziten Zugriff. UID kommt nur aus `IUserSession::getUser()->getUID()`. Rechte auf Aufgaben und Abgaben nie über `created_by` (§2 UID-Regel).

**Team-Zustand** (`TeamStateService::stateOf(teamId)`): `frozen`, wenn `TeamService::isAvailable()` false (berechnet, nichts gespeichert); sonst `nextnotes_teams.state` (`active` \| `orphaned`; fehlt die Zeile: `active`). Team-Workspaces und Aufgaben erben diesen Zustand.

| Objekt | lesen | schreiben | verwalten (umbenennen, löschen, teilen) |
|---|---|---|---|
| Workspace `owner_type=user` | Besitzer; `ws_shares` user=uid | dto. (`permission=write`) | Besitzer |
| Workspace `owner_type=team`, Team `active` | Team-Level ≥ 1 | Level ≥ 1 | Level ≥ 4 |
| Workspace `owner_type=team`, Team `orphaned` (§8) | `created_by` | – | `created_by`: bis Fristende in privat umwandeln (`owner_type=user`, `owner_id=created_by`; damit vom Team gelöst, die Aufgaben bleiben am Team und an dessen Frist) oder exportieren |
| Workspace `owner_type=team`, Team **eingefroren** | `created_by` | – | – (keine Löschfrist; bei Reaktivierung automatisch normal) |
| Workspace `owner_type=deleted` (Konto gelöscht, wartet auf `UserDataDeletionJob`) | niemand | niemand | niemand |
| Workspace `kind=assignments` (systemverwaltet) | Besitzer (Schüler) | nur über Notiz-Regeln | **niemand**: Löschen, Papierkorb, Umbenennen, Verschieben, Teilen → 403 für alle Benutzer; entfernt nur durch Aufgabe löschen oder Team-Cleanup |
| Ordner mit `assignment_id` (systemverwaltet) | wie Workspace | Notizen darin nur über Notiz-Regeln | **niemand**: Löschen, Papierkorb, Umbenennen, Verschieben → 403; entfernt nur durch Aufgabe löschen oder Team-Cleanup |
| Notiz `role=note` | wie Workspace | wie Workspace | – |
| Notiz `role=template` | Lehrkraft des Teams (Level ≥ 4) | dto., solange Aufgabe `draft` | – |
| Notiz `role=submission` | Schüler (`student_uid`); sonst wie die Aufgabe (§9.7) | nur Schüler und nur wenn `SubmissionStateService::studentCanEdit` (§9.3); Löschen/Papierkorb/Verschieben für niemanden (403; nur mit der Aufgabe, §8, §9.5); „Papierkorb leeren“ überspringt Abgabe-Notizen und Inhalte systemverwalteter Ordner | – |
| Notiz `role=submission` eines gelöschten Schülerkontos (`student_uid` NULL, `workspace_id` NULL) | wie die Aufgabe (§9.7) | niemand (gehört der Aufgabe, nur lesbar) | – |
| Ordner, Tags, Anhänge, Snapshots, Aufgaben (Tasks), Erinnerungen, Sperren | abgeleitet von Notiz/Workspace | dto. | |
| Aufgabe (assignment), Team `active` | aktuelle Lehrkräfte des Teams (Level ≥ 4); Schüler nur über eigene Abgabe | aktuelle Lehrkräfte | aktuelle Lehrkräfte: Aufgabe inkl. aller Abgaben löschen (mit Bestätigung, §9.5); einzelne Abgaben nicht löschbar. `owner_uid` ohne Level ≥ 4 hat keine Rechte |
| Aufgabe, Team `orphaned` | `owner_uid` (Ansicht „Meine Aufgaben“, §9.6) | keine manuellen Aktionen (Austeilen, Ändern, Feedback, Rückgabe, Wiederöffnen) | `owner_uid`: Export, Löschen; sonst Löschung durch Team-Cleanup nach Fristende (§8) |
| Aufgabe, Team **eingefroren** | `owner_uid` | keine manuellen Aktionen | `owner_uid`: nur Export |
| Aufgabe mit `owner_uid` NULL (gesperrt, ADR 0008 c) | bei aktivem Team die aktuellen Lehrkräfte; sonst niemand | wie Zeilen oben | Übertragung nur per `occ nextnotes:assignment:transfer` (§9.7) |
| Abgabe eines verwaisten/eingefrorenen Teams oder ausgetretenen Schülers | Schüler (nur eigene, Feedback sichtbar, ohne Löschfrist), sonst wie die Aufgabe | Schüler: nichts (kein Bearbeiten, kein Abgeben); Fristablauf → `missed` wird weiter berechnet | – |
| Abgabe eines gelöschten Schülerkontos | wie die Aufgabe (Name aus `student_name`) | Lehrkraft: Feedback/Punkte, `return`; `reopen` → 409 `student_deleted` | – |
| Abgabe (submission) | Schüler (eigene), wie die Aufgabe | Statuswechsel laut §9.2 und §9.7 | – |

**Rollen** (ADR 0003): Team-Level aus `TeamService::getLevel(teamId, uid)` = **effektives** Level (Circles `Membership::getLevel()` über `CirclesManager::getLink` bzw. `Circle::getInitiator()` nach `startSession`), d. h. inklusive Mitgliedschaft über Gruppen und verschachtelte Teams; nur Status `Member` zählt, `Invited`/`Requesting`/`Blocked` = 0. Owner 9, Admin 8, Moderator 4 = **Lehrkraft**; Member 1 = **Schüler**; 0 = kein Mitglied. Ergebnis wird pro Request gecacht, nie gespeichert → Mitgliederänderungen wirken sofort. Mitgliederlisten fürs Austeilen: `Circle::getInheritedMembers()`, gefiltert auf lokale Benutzer mit Status `Member` und effektivem Level 1.

**Ohne Teams-App:** `TeamService::isAvailable()` false → alle Teams gelten als **eingefroren** (Zeilen oben); private Workspaces unverändert. `TeamService` nutzt OCP, wo es reicht: `ITeamManager::hasTeamSupport()` (@since 34) für die Verfügbarkeit, `getTeam()` (@since 35) für Existenz/Name, `getTeamsForUser()` (@since 33) und `getMembersOfTeam()` (@since 34) für Mitgliederlisten. Circles-API nur für das, was OCP nicht liefert: effektives Rollen-Level und Mitgliedsstatus (`Membership::getLevel`, `Member::STATUS_*`) sowie Events. Ein Circles-Fehler oder „Team nicht gefunden“ beim Zugriff ändert nie den gespeicherten Zustand (nur 404 für diesen Request).

---

## 4. API

### 4.1 Basis-URL und Routen
- Registrierung in `appinfo/routes.php` (gleiche Reihenfolge wie `src/public/api/v1/index.php`, damit `check-upstream` diffen kann). Keine OCS-Routen (OCS-Hülle würde das JSON-Format ändern).
- Pfade: `/apps/nextnotes/api/v1/<Upstream-Pfad>`; Basis serverseitig per `IURLGenerator::linkToRoute('nextnotes.page.index')` (entspricht `generateUrl('/apps/nextnotes')` aus `@nextcloud/router` im Browser; `generateUrl` ist eine JS-Funktion, `IURLGenerator` hat sie nicht) → InitialState `nextnotes/config` `{ baseUrl, apiBase, uid, displayName, lang, teamsAvailable, excalidrawAssetPath, allowedIframeDomains, hiddenUiElements }`.
- Parameter `{id}`, `{noteId}`, `{taskId}` mit Requirement `\d+` bzw. Upstream-Format; `{tag}` `[^/]+`; `{name}` `.+` (Workspace-Schlüssel kann Anzeigenamen mit `/` enthalten, nur PATCH/DELETE `/workspaces/{name}` ohne Folgesegment).
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
| `/users/lookup/{username}` | gleich, über `Collaborators\ISearch::filteredSearch` | dto. | |
| `/mentions/members` | gleich, aus Workspace-Mitgliedschaft (Besitzer + Freigabeempfänger, Team ab M5; ADR 0014) | `MentionsApiController` | |
| `/shared`, `/shared/with-me` | gleich, liefert `[]` (Inventur A #129, #130 „ersetzen“) | `SystemApiController` | ADR 0011 |
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
Upstream adressiert Workspaces per Name (`/workspaces/{name}`, `?workspace=`). Schlüssel = Name bei eigenen Workspaces; bei Team- oder geteilten Workspaces `"<Name> (<Team- bzw. Besitzername>)"`, falls der Name in der sichtbaren Menge sonst doppelt wäre. Bleibt der Schlüssel doppelt (gleicher Name und Besitzername): `"<Name> (<Besitzername>, #<id>)"`; diese Langform wird immer akzeptiert. `WorkspaceService::resolveKey(uid, key)` bildet zurück; unbekannt → 404 (M2-plan r2). Rename-Bodies werden ohne Zusatz gespeichert.

### 4.5 CSRF und Attribute
- Alle schreibenden Routen (POST/PUT/PATCH/DELETE): CSRF-Prüfung aktiv (kein `NoCSRFRequired`). NC prüft CSRF bei jeder Methode ohne `NoCSRFRequired` (`SecurityMiddleware::isInvalidCSRFRequired`), daher auch GET-API-Aufrufe über den Adapter mit Token.
- `#[NoCSRFRequired]` nur für: Seiten (PageController), GET-Downloads/Medien (Anhänge, Exporte, Hintergrundbild, Audio-Player). Regel: kein GET mit Seiteneffekt.
- Alle Benutzerrouten `#[NoAdminRequired]`. Admin-Einstellungen nur über `ISettings`-Formular (Admin-geprüft).
- `#[UserRateLimit]` (@since 27) auf Drag&Drop-Notizanlage, Export-ZIP und Upload.

### 4.6 CSP
Jede `TemplateResponse` erhält ihre Policy aus `Http\CspFactory` (ADR 0006, 0012). Templates enthalten keine ausführbaren Inline-Skripte und keine `on…=`-Handler; Daten über InitialState oder JSON-Inseln (`type="application/json"`).

---

## 5. Hintergrundjobs

| Job | Typ | Intervall | Aufgabe |
|---|---|---|---|
| `ReminderJob` | `TimedJob` (@since 15) | 300 s | fällige `nextnotes_reminders` (`sent_at IS NULL AND trigger_at <= now`) → Leserecht des Empfängers über `AccessService` (sonst Zeile löschen) → `Notification\IManager::notify`, `sent_at` setzen; Wiederholung → nächste Zeile |
| `AssignmentDeadlineJob` | `TimedJob` | 300 s | `SubmissionStateService::sweep(now)`: fällige Abgaben → `missed`; Nachzügler-Abgleich; einmalige Benachrichtigung an Lehrkräfte (`deadline_notified_at`) |
| `LockCleanupJob` | `TimedJob` | 3600 s | abgelaufene Sperren löschen (nur Aufräumen, Logik braucht ihn nicht) |
| `SnapshotRetentionJob` | `TimedJob` | 86400 s | Upstream-Aufbewahrung je Notiz, verwaiste Snapshots |
| `CleanupJob` | `TimedJob` | 86400 s | arbeitet nur auf `nextnotes_teams` (§8): Existenzprüfung aller `active`-Teams (nur bei aktivem, fehlerfreiem Circles; zweimal „Team fehlt“ → `orphaned`), auch für Teams ohne Workspace; Vorab-Benachrichtigung 7 Tage vor Fristende; nach 30 Tagen `TeamCleanupService::purge(teamId)`: alle Aufgaben, Vorlagen, Abgaben, Abgabe-Kopien (mit Anhängen, Snapshots), Aufgabenordner, Schüler-Workspaces `kind=assignments` mit diesem `linked_team_id`, Team-Workspaces (`owner_type=team`, `owner_id=teamId`), dann die Team-Zeile. Prüft außerdem in aktiven Teams, ob `owner_uid` noch Level ≥ 4 hat (sonst Übergang nur unter den Bedingungen aus §9.7). Verwaiste Anhangsdateien in `IAppData` (keine Notiz zeigt mehr darauf) |
| `UserDataDeletionJob` | `QueuedJob` (@since 15) | einmalig | Kontolöschung nach `UserDeletedEvent` (§8): löscht den Inhalt der vom Listener entkoppelten Workspaces (Argument: Workspace-IDs, nie die UID); Abgaben/Aufgaben-Teile ab M6 |
Keine Logik darf von pünktlichem Cron abhängen (NC-Cron kann auf AJAX stehen).

## 6. Benachrichtigungen
`Notification\Notifier` (`INotifier`, @since 9/17; registriert per `registerNotifierService`, @since 22; unbekannte → `UnknownNotificationException`, @since 30). App `nextnotes`, Objekttypen `note`, `assignment`, `submission`. Subjects: `reminder`, `task_reminder` (Notiz-Link), `assignment_assigned` (Schüler), `assignment_deadline_passed` (Lehrkräfte), `submission_returned`, `submission_reopened` (Schüler), `team_orphaned`, `team_deletion_soon` (an `created_by` der Team-Workspaces und `owner_uid` der Aufgaben; nennen Aufgaben mit Abgaben und verlinken den ZIP-Export), `assignment_transferred` (neue verantwortliche Lehrkraft), `mention` (Erwähnung in geteiltem/Team-Workspace). Beim Löschen eines Objekts `IManager::markProcessed` (aus `Notification\IApp`). `prepare` prüft das Leserecht des Empfängers über `AccessService`; ohne Recht `AlreadyProcessedException` (17). Entzug einer Freigabe löscht Erinnerungen und Mention-Status des Empfängers in diesem Workspace. Kein E-Mail-/Push-Versand eigener Art (NC-Notifications-App übernimmt).

## 7. Unified Search
`Search\NotesProvider` (`OCP\Search\IProvider`, @since 20; registriert per `registerSearchProvider`, @since 20). Suche in `heading`/`search_text` der sichtbaren Workspaces (gleiches SQL wie `/notes/search`, `LIKE` auf normalisierten Text über `IQueryBuilder`, kein DB-spezifisches SQL), ohne Papierkorb, mit Paginierung (`ISearchQuery::getCursor/getLimit`). Ergebnis-Link `…/index.php?note=<id>&workspace=<key>`. Filter über `AccessService` (§3): Schüler finden nur eigene Abgaben; Abgaben anderer erscheinen für niemanden außer Lehrkräften, und für diese nur in der Aufgabenansicht, nicht in der globalen Suche.

## 8. Lebenszyklus
| Ereignis | Mechanismus | Wirkung |
|---|---|---|
| Benutzer gelöscht | `OCP\User\Events\UserDeletedEvent` (@since 18) → synchroner Listener + `UserDataDeletionJob` | **Synchron im Listener** (eine neu vergebene UID darf vor dem Joblauf nichts erben, §2, §5): eigene Workspaces `owner_type=user` → `owner_type=deleted` (kein Rechteweg), `ws_shares` an/von UID, Reminder, Locks, Mentions der UID löschen, Anzeige-UID-Spalten NULL, Rechte-UIDs der Aufgaben/Abgaben (ab M6) neutralisieren; **im Job** nur das Löschen der Inhalte (Notizen, Dateien). **Abgaben bleiben** (ADR 0008 a): je Abgabe `student_name` aktualisieren, `student_uid_former` = UID, `student_uid` = NULL, `student_deleted_at`; Kopie-Notiz aus dem Schüler-Workspace lösen (`workspace_id`/`folder_id`/`created_by`/`updated_by` = NULL), Anhänge und Snapshots bleiben; danach erst die `assignments`-Workspaces (nur Ordner) löschen. **Aufgaben als Lehrkraft** (ADR 0008 c): Systempfad `AssignmentService::transferOwnership` (§9.7) → verbleibende Lehrkraft, sonst `owner_uid` NULL (gesperrt). **UID-Spalten** (§2): `assignments.created_by` → NULL (Name bleibt in `created_by_name`), `submissions.feedback_by` → NULL (`feedback_by_name`), `workspaces.created_by`, `notes.created_by`/`updated_by`, `attachments.created_by`, `snapshots.created_by` → NULL. **Übrige Daten** (ADR 0008 f): private Workspaces inkl. Notizen, Ordner, Tags, Anhänge/IAppData, Snapshots, Reminder, Locks, Mentions und `ws_shares` an/vom Benutzer werden gelöscht. Danach enthält keine Tabelle mehr die UID in einer Spalte |
| Team gelöscht | Circles `CircleDestroyedEvent` (§1.3); Rückfallweg für verpasste Events: `CleanupJob`/`occ nextnotes:team:orphan-check` mit zweifacher fehlerfreier Bestätigung (s. u.); nie durch „nicht gefunden“ beim Zugriff | `nextnotes_teams.state` → `orphaned`, `orphaned_at` = now (ADR 0003); gilt für das Team mit allen Workspaces und Aufgaben, auch wenn es keinen Workspace hat. Benachrichtigung beim Verwaisen und 7 Tage vor Fristende. `created_by` eines Team-Workspace kann ihn bis Fristende in privat umwandeln (vom Team gelöst, bleibt erhalten). Aufgaben bleiben am Team-Datensatz und an dessen Frist. In der Frist: Lesen und ZIP-Export für `owner_uid` über „Meine Aufgaben“ (§9.6). Nach 30 Tagen löscht `CleanupJob` alle Aufgaben, Abgaben, Abgabe-Kopien, Anhänge, Snapshots und die verbliebenen Team-Workspaces des Teams (ADR 0008 b). Gesperrt sind nur **manuelle Aktionen** (Austeilen, Abgeben, Rückgabe, Wiederöffnen, Feedback, Friständerung); der berechnete Fristablauf → `missed` (§9.3) läuft weiter |
| Mitglied tritt bei (auch indirekt) | Circles `CircleMemberAddedEvent`/`MembershipsCreatedEvent` (optional) + lazy + Job | Nachträgliches Austeilen (§9.4), idempotent |
| Mitglied tritt aus (auch indirekt über Gruppe/verschachteltes Team) | Circles `CircleMemberRemovedEvent`/`MembershipsRemovedEvent` (optional) + Prüfung des effektiven Levels bei jedem Zugriff | sofort: kein Zugriff auf Team-Workspaces, eigene Abgabe nur noch lesbar (kein Bearbeiten/Abgeben, keine Löschfrist); `left_team_at` setzen; Abgabe bleibt vollständig für Lehrkräfte (ADR 0008 e). Ist das ausgetretene Mitglied `owner_uid` einer Aufgabe: Übergang nur unter den Bedingungen aus §9.7, sonst bleibt `owner_uid` |
| Rolle herabgestuft (Level < 4) | Circles `CircleMemberEditedEvent` (optional) + tägliche Prüfung im `CleanupJob` | Rechte folgen sofort der aktuellen Rolle (§3); ist das Mitglied `owner_uid`: Übergang nur unter den Bedingungen aus §9.7, sonst bleibt `owner_uid` |
| Teams-App deaktiviert/fehlt | `TeamService::isAvailable()` | „eingefroren“ (§3): Team-Workspaces lesbar für `created_by`, Aufgaben und Abgaben für `owner_uid` (read/export, §9.7), Schüler nur eigene Abgaben; keine Schreibzugriffe, keine Löschfrist, nichts wird gelöscht; nach Reaktivierung automatisch normal |
| Lehrkraft löscht Aufgabe | `DELETE /api/v1/assignments/{id}` (§9.5) | einziger manueller Löschweg für Abgaben: Aufgabe, Vorlage, alle Abgaben, Kopie-Notizen, Anhänge, Snapshots; `markProcessed` für zugehörige Benachrichtigungen |
| App deaktiviert | – | Daten bleiben; Jobs laufen nicht (NC-Standard) |
| App entfernt (`occ app:remove`) | – | Tabellen und `IAppData` bleiben (NC-Standard); `occ nextnotes:purge --force` löscht alles (bewusste Admin-Aktion bei Deinstallation) |
| Gruppe gelöscht | `GroupDeletedEvent` (@since 18) | erst relevant mit Gruppen-Freigaben (ADR 0014 Option 3) |
Zusätzlich (Lead, 2026-11-02): `CleanupJob` prüft täglich jede Zeile in `nextnotes_teams` mit `state=active` über `ITeamManager::getTeam()`, aber nur, wenn Circles aktiv ist und fehlerfrei antwortet; meldet er zweimal hintereinander „Team fehlt“ (`missing_count`), wird das Team `orphaned` (normale 30-Tage-Frist mit Benachrichtigung). Ein Lauf mit Fehler/deaktiviertem Circles ändert den Zähler nicht; „Team vorhanden“ setzt ihn auf 0. Manuell: `occ nextnotes:team:orphan-check [--dry-run]` (gleiche Logik, `--dry-run` ändert nichts).

Zusätzlich `UserMigration\NextNotesMigrator` (`IMigrator`, @since 24, nur Export) und `occ nextnotes:export-user`, `nextnotes:delete-user-data` (gleiche Regeln wie Kontolöschung, Abgaben bleiben), `nextnotes:assignment:transfer <assignment-id|--all-unowned> <uid> [--force]` (§9.7, ADR 0008).

Keine automatische Löschung von Abgaben außer über die Team-Orphan-Frist (`nextnotes_teams`); kein Löschweg durch Austritt, Schülerkonto- oder Lehrkraftkonto-Löschung. Die Aufbewahrungsfrist legt die Schule fest (Datenschutzhinweis ADR 0008).

---

## 9. Austeilen und Einsammeln (M6)

### 9.1 Ablauf
1. Lehrkraft (Level ≥ 4) wählt eine Notiz (lesbar für sie), Team, Titel, Frist (`due_at` > now) → `POST /api/v1/assignments`: Aufgabe `draft`, eingefrorene Kopie als `role=template` (inkl. Anhänge, physisch kopiert).
2. `POST /api/v1/assignments/{id}/distribute`: Status `distributed`, für jedes Team-Mitglied mit Level = 1: Abgabe (mit `student_name` = aktueller Anzeigename) + eigene Kopie (Inhalt + Anhänge) in seinem Workspace `kind=assignments` (je Schüler und Team, bei Bedarf angelegt), Ordner je Aufgabe. Pro Schüler eine Transaktion.
3. Schüler bearbeitet die Kopie (normaler Editor), `POST /api/v1/submissions/{id}/submit` → `submitted`, danach schreibgeschützt.
4. Frist: offene Abgaben werden `missed` (§9.3), Schreibzugriff wird serverseitig bei jedem Speichern geprüft.
5. Lehrkraft sieht alle Abgaben mit Status, schreibt Feedback (Freitext, optional Punkte ≤ `max_points`), `return` oder `reopen` (optional neue individuelle Frist).
6. Schüler sieht Feedback in seiner Abgabe. Lehrkraft: `GET /api/v1/assignments/{id}/export` → ZIP (`ZipResponse`, @since 15): je Schüler Ordner `<student_name>_<uid bzw. student_uid_former>/` mit Notiz (HTML/MD wie Upstream-Export), Anhängen, `feedback.txt`; dazu `status.csv` (uid, Name, Status, Abgabezeit, Punkte).

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
- `studentCanEdit` = uid = `student_uid` ∧ Status ∈ {`assigned`, `reopened`} ∧ now ≤ effektive Frist ∧ Aufgabe `distributed` ∧ `TeamStateService::stateOf(team_id)` = `active` ∧ effektives Level des Schülers = 1 (ausgetretene Schüler: nur Lesen). Dieselbe Bedingung gilt für `submit`. Gilt für alle schreibenden Notiz-Pfade: PATCH/Beacon, Tags, Icon/Farbe/Pins/Breite/Titelstil, Konvertieren, Tasks, Anhänge (Upload/Löschen/Verschieben), Excalidraw-Speichern, Snapshot-Restore, Duplizieren. Verstoß → 403 `submission_locked`. Verschieben, Löschen und Papierkorb einer Abgabe-Notiz sind auch bei `studentCanEdit` verboten (403); die Notiz verschwindet nur mit der Aufgabe (§8).
- Zeit nur aus `ITimeFactory` (Server). `AssignmentDeadlineJob` ist nur für Benachrichtigungen und Aufräumen nötig.

### 9.4 Pflichtfälle
| Fall | Umsetzung | Nachweis (PHPUnit) |
|---|---|---|
| Neues Mitglied nach Austeilen bekommt Aufgabe, falls Frist offen | `reconcile(teamId[, uid])` bei `CircleMemberAddedEvent`, beim Öffnen der App/Aufgabenliste durch den Schüler und im Job; nur wenn now ≤ `due_at` | Mitglied nach Austeilen hinzufügen → genau 1 Abgabe; nach Frist hinzufügen → 0 |
| Ausgetretene Schüler: Abgabe bleibt für Lehrkraft | Austritt setzt nur `left_team_at`; Abgaben/Notizen werden nie wegen Austritt gelöscht; Lehrkraft-Liste zeigt sie markiert | Austritt → Lehrkraft-GET liefert Abgabe weiter |
| Gelöschtes Schülerkonto: Abgabe bleibt mit Name | `UserDataDeletionJob` löst die Bindung (§8), Lehrkraft-Liste zeigt `student_name` mit Hinweis „Konto gelöscht“ | Konto löschen + Job → Lehrkraft-GET liefert Abgabe, Notizinhalt, Anhänge und Name |
| Schüler sehen niemals Abgaben anderer | Schüler-Endpunkte filtern `student_uid = uid`; `GET /assignments/{id}/submissions` nur Level ≥ 4; Notizen `role=submission` nur für Besitzer/Lehrkraft; keine Team-Workspace-Ablage; Suche filtert | S2 liest Abgabe/Notiz/Anhang von S1 → 404 auf allen Pfaden |
| Doppeltes Austeilen erzeugt keine Duplikate | UNIQUE (assignment_id, student_uid); `distribute` idempotent; Unique-Verletzung (`OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION`) = „schon vorhanden“ | 2× `distribute` parallel → je Schüler 1 Abgabe, 1 Notiz |
| Frist läuft auch bei verspätetem Cron | Materialisierung bei Zugriff (§9.3) | ohne Job-Lauf: Zeit nach Frist → Speichern 403, Status `missed` |

### 9.5 Endpunkte (alle `#[NoAdminRequired]`, CSRF aktiv)
`GET /api/v1/assignments` (gefiltert über `visibleAssignmentIds(uid)`, §9.6), `POST /api/v1/assignments`, `GET|PATCH|DELETE /api/v1/assignments/{id}` (PATCH: Titel, Frist nur in `draft`/`distributed` und nur verlängernd, Verlängerung setzt `missed` ohne Abgabezeit zurück auf `assigned`; DELETE laut §9.7, verlangt `confirm: true` im Body, sonst 400 `confirmation_required`; löscht Aufgabe inkl. aller Abgaben, Kopien und Anhänge; Abgaben einzeln nicht löschbar), `POST /api/v1/assignments/{id}/distribute`, `POST /api/v1/assignments/{id}/close`, `GET /api/v1/assignments/{id}/submissions`, `GET /api/v1/assignments/{id}/export` (`NoCSRFRequired`, nur GET-Download), `GET /api/v1/submissions/{id}`, `POST /api/v1/submissions/{id}/submit|return|reopen`, `PUT /api/v1/submissions/{id}/feedback`. UI: Ansicht `assignments.php` (neues Template) + `nextnotes-assignments.js` im Upstream-Stil; Banner „abgegeben/gesperrt“ im Editor über Adapter; Löschen-Dialog nennt Zahl der Abgaben und bietet den ZIP-Export an.

### 9.6 Ansicht „Meine Aufgaben“
`assignments.php` und `GET /api/v1/assignments` sind unabhängig von Workspaces und filtern in SQL über `AccessService::visibleAssignmentIds(uid)` = Vereinigung aus (a) Aufgaben in Teams mit Zustand `active`, in denen uid aktuell Level ≥ 4 hat (Team-IDs aus `TeamService::getTeamsForUser`, Level je Team geprüft); (b) Aufgaben mit `owner_uid = uid` in Teams mit Zustand `orphaned` oder eingefroren; (c) Aufgaben mit einer Abgabe `student_uid = uid` (Schüler, nur die eigene Abgabe). Aufgaben verwaister Teams sind markiert und zeigen die Restfrist aus `nextnotes_teams.orphaned_at`, den Team-Namen aus `team_name_snapshot`. Abgaben öffnen sich dort im Lesemodus über `requireSubmission`/`requireNote`, auch wenn die Kopie-Notiz keinen Workspace mehr hat. Schüler sehen dort ihre eigenen Abgaben, auch nach Austritt.

### 9.7 Aufgaben-Rechte und Übergang (`AccessService`, `AssignmentService`)
`requireAssignment(uid, assignmentId, action)`; Team-Zustand aus `TeamStateService` (§3). „L“ = aktuelle Lehrkraft (effektives Level ≥ 4), „O“ = `owner_uid`, „S“ = Schüler mit eigener Abgabe (nur `read` auf Aufgabe und eigene Abgabe). Alles andere: ohne Leserecht 404, sonst 403.
| Aktion | aktiv | eingefroren | verwaist |
|---|---|---|---|
| read | L, S | O, S | O, S |
| feedback, return, reopen (auch edit, distribute, close) | L | – | – |
| export (ZIP) | L | O | O |
| delete (Aufgabe inkl. aller Abgaben, `confirm`) | L | – | O |
`owner_uid` zählt nur bei eingefrorenem oder verwaistem Team; im aktiven Team gelten allein die aktuellen Rollen.

**Übergang an Co-Lehrkraft** – benannter Systempfad `AssignmentService::transferOwnership(assignmentId, reason)` ohne Sitzung (kein `IUserSession`, keine `AccessService`-Prüfung des Aufrufers). Ziel = aktuelle Lehrkraft des Teams (höchstes Level, dann kleinste UID). `owner_uid` wird nur geändert, wenn im selben Lauf **alle drei** Bedingungen erfüllt sind: (1) `ITeamManager::getTeam()` bestätigt das Team fehlerfrei als vorhanden, (2) Circles antwortet fehlerfrei (Level-Abfragen ohne Fehler), (3) es gibt eine Ziel-Lehrkraft mit Level ≥ 4 (über `TeamService::getLevel` geprüft). Sonst bleibt `owner_uid` **unverändert** – bei Austritt, Herabstufung, verpasstem Lösch-Event und unabhängig von der Reihenfolge der Events beim Auflösen eines Teams (Austritts-Events vor `CircleDestroyedEvent`); im aktiven Team gibt `owner_uid` ohnehin keine Rechte, im verwaisten bleibt so der Export in der Frist möglich. **Einzige Ausnahme Kontolöschung** (`UserDeletedEvent`): Ist kein Ziel bestätigt, wird `owner_uid` = NULL (gesperrt), weil die gelöschte UID nicht stehen bleiben darf (§2 UID-Regel); NULL entsteht auf keinem anderen Weg. Benachrichtigung `assignment_transferred`. Auslöser: Kontolöschung des `owner_uid` (§8), Austritt oder Herabstufung des `owner_uid` (Events + tägliche Prüfung im `CleanupJob`). Bedingtes `UPDATE … WHERE owner_uid = <alt>`.

**`occ nextnotes:assignment:transfer <assignment-id|--all-unowned> <uid> [--force]`**: Ziel muss existierender NC-Benutzer sein. Team `active`: Ziel muss dort aktuell Lehrkraft (Level ≥ 4) sein, sonst Fehler (auch mit `--force`). Team verwaist, eingefroren oder unbekannt: beliebiger existierender Benutzer, nur mit `--force`. Jeder Übertrag wird über `Psr\Log\LoggerInterface` (NC-Logger; `OCP\ILogger` ist veraltet) mit Aufgabe, altem/neuem Besitzer und Team-Zustand protokolliert.

---

## 10. Migration gespeicherter URLs in Notizen
Kanonische Speicherform = Upstream-Form (Exporte bleiben in Upstream-Poznote lesbar):
| Muster im Inhalt | gespeichert | beim Ausliefern (`ContentUrlRewriter::toClient`) |
|---|---|---|
| `/api/v1/notes/<n>/attachments/<a>` | unverändert | `<apiBase>/notes/<n>/attachments/<a>` |
| `api_attachments.php?action=download&note_id=<n>&attachment_id=<a>` (alt) | beim ersten Speichern → obere Form | dto. |
| `/audio_player.php?…` | unverändert | `<baseUrl>/audio_player.php?…` |
| `index.php?note=<n>[&workspace=…]` (relativ) | unverändert | unverändert (Seitenroute mit gleichem Namen, ADR 0004); Fallback ohne Spike: `<baseUrl>/?note=` |
| `diary.php?date=…` | unverändert | unverändert (dto.) |
`toServer` beim Speichern kehrt die Umschreibung um (`<apiBase>/…` → `/api/v1/…`). Gilt für `GET /notes/{id}`, serverseitig gerenderte Notizen, Exporte (Export liefert kanonische Form). Kein Import aus Poznote in v1 (ADR 0009, gestrichen).

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
| B12 | `src/api/v1/controllers/SystemController.php:99-139` | `GET /system/whats-new` schreibt die gesehene Version (GET mit Seiteneffekt) und lädt Poznote-Releases von GitHub | liefert `show:false`, `releases:[]`; `/seen` ohne Wirkung |
| B13 | `src/api/v1/controllers/NotesController.php:276-288` | Sperre: dasselbe Konto übernimmt sie aus jeder Sitzung, `takeover` verdrängt fremde Halter → zwei Tabs überschreiben sich | ADR 0005: Übernahme nur durch dieselbe Sitzung, Konflikt 409, Speichern gegen fremde Sitzung 423. Speichern ohne Sitzungs-ID: nur die Sperre eines anderen Kontos blockiert (423). Speichern mit Sitzungs-ID ohne bestehende Sperre: die Sperre wird dabei erworben |
| B14 | `src/api/v1/controllers/NotesController.php` `restore` | Wiederherstellen einer Notiz, deren Ordner inzwischen gelöscht ist, lässt `folder_id` auf den alten Ordner zeigen → Notiz in keiner Liste sichtbar | `folder_id` → NULL (Wurzel) |
| B15 | `src/api/v1/controllers/NotesController.php` `create` | `folder_id` wird nicht gegen den Ziel-Workspace geprüft → Notiz in Workspace A mit Ordner aus Workspace B | Ordner muss im Ziel-Workspace liegen, sonst 400 |
| B16 | `src/api/v1/controllers/FoldersController.php` `updateIcon`, `NotesController.php` `updateIcon` | `icon_color` ungeprüft gespeichert (Frontend setzt ihn in `style`) | `InputValidator::iconColor` (Hex oder Farbname), sonst 400 |
| B17 | `src/api/v1/controllers/NotesController.php` `update`, `FoldersController.php` `move`/`moveFiles`/`archive*` | Inhalte eines geteilten Workspace konnten vom Gast in eigene Workspaces verschoben werden (Upstream: nur im Freigabe-Scope gesperrt) | Verlassen eines Workspace nur mit `manage` auf der Quelle, sonst 403 |
| B18 | `src/api/v1/controllers/WorkspacesController.php` `destroy` | Löschen verschiebt Notizen und Ordner in einen beliebigen anderen Workspace; im Port könnte das ein geteilter oder „Archives“ sein → Inhalte würden einem anderen Personenkreis sichtbar | Ziel nur eigener `kind=normal` ohne `ws_shares`, nicht „Archives“; bevorzugt der Standard-Workspace (Name in Benutzersprache), sonst kleinste ID; gibt es keinen, wird ein neuer Standard-Workspace angelegt (Thomas, 2026-11-02). Letzter eigener Workspace → 400 wie Upstream |
| B19 | `src/lib/html-sanitize.php` (Attributprüfung) | Schema mit Whitespace/Steuerzeichen (`java&#9;script:`) wird in `href`/`src` nicht erkannt | nach Entity-Decoding Whitespace/Steuerzeichen entfernen, dann Schema prüfen |
| B20 | `NotesController`/`FoldersController`/`WorkspacesController` (Icon, Farben, Namen, Tags) | ungültige Werte werden still bereinigt oder ungeprüft gespeichert | `InputValidator`: Verstoß → 400, nichts gespeichert; Markup-Zeichen (`<`, `>`, `"`, Backtick) und Steuerzeichen in Tags/Namen verboten, Apostroph `'` in Tags erlaubt (Ausgabe wird escapt) |
| B21 | `src/api/v1/controllers/AttachmentsController.php` `move`, `NotesController.php` `duplicate` | Anhänge/Notizkopien konnten aus geteilten Workspaces in eigene kopiert werden (wie B17) | Ziel braucht `write`, Quelle in anderem Workspace `manage`, sonst 403 |
| B22 | `src/api/v1/controllers/AttachmentsController.php` Download | SVG wird inline ausgeliefert (nur durch CSP geschützt) | inline nur PDF, `image/*` außer SVG, Audio, Video, passive Textformate (als Text); SVG, HTML und Rest immer `attachment`; `nosniff` überall; CSP `default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox` außer PDF (Browser-PDF-Viewer rendern nicht unter `sandbox`, PDF behält NC-Standard-CSP); Plan M2 §2 M2b |
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
| R7 | Globale IDs statt Upstream-IDs je Benutzer; Workspace-Schlüssel statt eindeutiger Namen | §4.4, Tests mit Namenskollision; kein Import (ADR 0009) |
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
1. Jede Router-Zeile aus `src/public/api/v1/index.php` mit Status übernehmen/ersetzen (Inventur A #1–184) ist registriert (Kompatibilitäts-, Seiten- und Kalender/Diary-Routen aus §4.2: M3/M4); ein Test vergleicht `routes.php` mit `src/public/api/v1/index.php` abzüglich der Inventurzeilen „streichen“ (die Kompatibilitätsrouten `/shared` und `/shared/with-me` sind „ersetzen“ und liefern `[]`) → keine Differenz.
2. PHPUnit: für jede Controller-Methode mit Objekt-ID greift Benutzer B auf ein Objekt von Benutzer A zu → 403 oder 404; jede Listen-Route liefert B keine Objekte von A.
3. Kern-API-Szenario für zwei Benutzer (Notiz anlegen/ändern/löschen/wiederherstellen, Ordner, Workspace, Tags, Anhang hoch/runter, Papierkorb, Snapshot anlegen/wiederherstellen, Suche) läuft grün; JSON-Schlüssel stimmen mit Upstream-Fixtures überein.
4. Gleicher Workspace-Name bei zwei Benutzern ohne Konflikt.
5. `grep -rE "datetime\(|COLLATE|INSERT OR|REPLACE INTO|PRAGMA|ON CONFLICT|\\\$_SESSION|X-User-ID" nextnotes/lib` → keine Treffer; Migrationen + Tests grün auf 3 DBs.
6. Sperre: Sitzung 2 erhält Konflikt, solange Sitzung 1 hält; nach TTL (gemockte Zeit) erhält Sitzung 2 die Sperre.
7. `ReminderJob` mit fälliger Erinnerung erzeugt genau eine NC-Benachrichtigung; Unified Search liefert nur eigene Notizen.
8. Nach `UserDeletedEvent` + Job enthält keine `nextnotes_*`-Tabelle mehr Zeilen des Benutzers außer `submissions.student_uid_former` (Text, ohne Rechtewirkung); keine Spalte mit Rechtewirkung (§2 UID-Regel) enthält die UID. Geprüft werden die UID-Spalten (`owner_id` bei `owner_type=user`, `created_by`, `updated_by`, `share_with`, `user_id`, `holder_uid` …), nicht Inhalts-/Namensspalten. Eine vor dem Joblauf neu angelegte gleiche UID sieht keine alten Daten.

**M3 – Frontend-Grundlage und Editor**
1. Playwright: Notiz anlegen, Text eingeben, neu laden → Inhalt da; Bild-Anhang einfügen → angezeigt; Markdown-Notiz mit Mermaid-Block → `svg` gerendert; KaTeX-Formel → `.katex` vorhanden.
2. Das Szenario läuft in NC-Hell und NC-Dunkel; Hintergrund der App entspricht `--color-main-background`.
3. Auf jeder besuchten Seite: 0 `securitypolicyviolation`-Ereignisse, 0 Antworten 404 auf `/api/v1/…` oder `/audio_player.php` ohne App-Präfix.
4. Tab schließen während ungespeicherter Änderung → Inhalt per Beacon gespeichert (Request mit gültigem `requesttoken`, HTTP 200).
5. `make -C nextnotes build` zweimal → identische Prüfsummen aller Ausgabedateien; `git diff --name-only <Upstream-Basis> -- src/` enthält nur Dateien aus §11.1.
6. Fremdzugriff Seiten- und Kompatibilitätsrouten: Benutzer B ruft für eine Notiz von A `index.php?note=<id>` (inkl. Fragment-Modus), `excalidraw_editor.php`, `revisions.php`, `attachments.php`, `kanban_content.php` (Ordner von A), `api_download_note.php`, `api_export_note.php`, `api_export_folder.php`, `api_note_info.php`, `api_save_excalidraw.php`, `workspaces.php` (Aktionen auf Workspace von A) und den Anhang-Download auf → jeweils 404 (bzw. 403 bei lesbar, aber nicht schreibbar), kein Inhalt von A in der Antwort.

**M4 – Feature-Parität**
1. Für jede Ansicht mit Status „übernehmen“ in Inventur B §1.1 öffnet ein Playwright-Smoke die Seite und führt die Hauptaktion aus (Kanban: Karte verschieben; Tasks: Aufgabe abhaken; Dashboard: Karte öffnen; Graph: Knoten sichtbar; Diary: Eintrag für heute anlegen; Papierkorb: wiederherstellen; Revisionen: Version wiederherstellen; Excalidraw: zeichnen + speichern + Vorschau sichtbar; Speed Dial, Tags, Favoriten, Notizverwaltung, Anhänge, Mentions, Workspaces: Liste lädt) – 0 CSP-Verletzungen.
2. Export (Notiz, Ordner-ZIP, alle Notizen, strukturiert) lädt herunter und ist mit Upstream-Import lesbar. (Import nach Next Notes gestrichen, ADR 0009.)
3. Gestrichene Funktionen (Login, Konten, AI, öffentliche Links, PWA, Backup, Themes) sind in der UI nicht sichtbar und ihre Routen liefern 404 (Ausnahme: `/shared*` liefert `[]`).
4. Fremdzugriff für alle in M4 hinzugekommenen Seiten- und Kompatibilitätsrouten (`dashboard.php`, `diary.php`, `tasks.php`, `graph.php`, `speed_dial.php`, `mentions.php`, `notes_manager.php`, `list_tags.php`, `list_folders.php`, `api_export_entries.php`, `api_export_structured.php`, `api_export_attachments.php`, `storage-stats-user.php`, Kalender/Diary-Endpunkte): B sieht keine Daten von A (gefiltert bzw. 404).
5. Indirekte Lesepfade: A verlinkt Notiz X (`linked_note_id`, `index.php?note=` im Inhalt, Erwähnung); B ohne Recht auf X erhält X weder über `/notes/resolve`, Backlinks, Graph, `/changes`, Suche noch Unified Search.

**M5 – Teams**
1. A (Owner) legt Team T mit Mitglied B an, legt Workspace mit Besitzer T an → B sieht ihn in `GET /workspaces` und im Team-Arbeitsbereich (`ITeamManager::getSharedWith(T, B)` enthält die Ressource).
2. B aus T entfernen → nächster Request von B auf Notizen des Workspace → 404, ohne Job-Lauf.
3. Circles deaktivieren → private Workspaces funktionieren, keine HTTP 500; Team-Workspaces „eingefroren“: `created_by` liest, B liest nicht, Schreiben → 403/404, `nextnotes_teams.state` bleibt `active`; Circles aktivieren → ohne Eingriff wieder normal.
4. T löschen (`CircleDestroyedEvent`) → `nextnotes_teams.state` = `orphaned`, Workspace gilt als verwaist, nur `created_by` liest, Benachrichtigung an `created_by`; Umwandlung in privat innerhalb 30 Tagen gelingt; ohne Umwandlung nach 30 Tagen (gemockte Zeit) entfernt, Benachrichtigung 7 Tage vorher (mit Hinweis auf Export der Abgaben). Ein simulierter Circles-Fehler/„nicht gefunden“ beim Zugriff ändert den Team-Zustand nicht.
5. Freigabe an Benutzer C → C liest/schreibt; Freigabe entfernen → 404.
6. Verschachtelte Mitgliedschaft: B ist über eine Gruppe in T → sieht den Workspace; eingeladenes, noch nicht beigetretenes Mitglied D → sieht ihn nicht.
7. `occ nextnotes:team:orphan-check --dry-run` listet ein Team aus `nextnotes_teams`, das ohne Event gelöscht wurde (Circles währenddessen deaktiviert), ohne es zu ändern. `CleanupJob` setzt es erst nach dem zweiten fehlerfreien Lauf mit „Team fehlt“ auf `orphaned`; ein Lauf mit Circles-Fehler oder deaktiviertem Circles setzt den Zähler nicht hoch.

**M6 – Austeilen und Einsammeln**
1. PHPUnit: jeder erlaubte Übergang aus §9.2 gelingt, jeder nicht gelistete liefert 409.
2. PHPUnit: alle Pflichtfälle aus §9.4 grün; Speichern nach Frist ohne Job-Lauf → 403 und Status `missed`.
3. Playwright mit Lehrkraft L (Moderator) und Schülern S1, S2: Aufgabe aus Notiz mit Anhang erstellen, austeilen → S1, S2 haben je eine Kopie mit Anhang; S1 gibt ab (danach Editor read-only); S2 verpasst Frist (gemockte Zeit) → `missed`; L gibt S1 Feedback + Punkte und `return`, öffnet S2 erneut; S1 sieht Feedback; S2 sieht S1-Abgabe nicht (404); ZIP-Export enthält 2 Schülerordner und `status.csv`.
4. **Schüler B erreicht die Abgabe von Schüler A über keinen Pfad:** Aufgabenliste, `GET /submissions/{id}`, `GET /notes/{id}`, Anhang-Download, `/notes/search`, Unified Search, Graph, Backlinks, `/notes/resolve` (Titel und ID), `/changes`, Direkt-URL `index.php?note=<id>`, `api_download_note.php`, `api_export_note.php`, `revisions.php` → überall gefiltert bzw. 404.
5. Weitere Fälle: `distribute` nach Frist → 409; Fristverlängerung setzt `missed` ohne Abgabezeit auf `assigned`, Nachzügler erhalten Kopie; ausgetretener Schüler kann eigene Abgabe lesen, aber nicht bearbeiten/abgeben (403); Circles deaktiviert → Schüler liest eigene Abgabe, kann nicht abgeben.
6. Schülerkonto löschen → Abgabe für Lehrkraft vollständig sichtbar inkl. Anhänge, mit Name (`student_name`); Schüler-Workspace entfernt; Kopie-Notiz nur lesbar; ZIP-Export enthält sie.
7. Schüler aus Team entfernen → Abgabe für Lehrkraft erhalten (Inhalt, Anhänge, Feedback); Schüler liest sie weiter.
8. Team löschen → Abgaben bis Fristende für `owner_uid` über „Meine Aufgaben“ lesbar und als ZIP exportierbar; nach 30 Tagen (gemockte Zeit) löscht `CleanupJob` Aufgaben, Abgaben, Kopie-Notizen, Anhangsdateien und Team-Workspaces. Ein vorher in privat umgewandelter Workspace bleibt, die Aufgaben werden trotzdem gelöscht. Team ohne Workspace löschen → Aufgaben nach Frist gelöscht.
9. Lehrkraft löschen → Übergang an Co-Lehrkraft (`owner_uid`, Benachrichtigung); ohne Co-Lehrkraft `owner_uid` NULL, Daten bleiben, `occ nextnotes:assignment:transfer` überträgt (aktives Team: Nicht-Lehrkraft als Ziel → Fehler; verwaistes Team: nur mit `--force`, Log-Eintrag). Gelöschte Lehrkraft-UID neu anlegen → kein Zugriff auf Aufgaben und Abgaben (404), `created_by` ist NULL, Name aus `created_by_name`. Austritt oder Herabstufung des `owner_uid` mit Co-Lehrkraft → Übergang; herabgestufte Lehrkraft liest im aktiven Team nichts mehr (404). Herabstufung ohne Co-Lehrkraft → `owner_uid` bleibt. Team löschen, dabei zuerst Austritts-Events (bzw. verpasster Lösch-Event, Team in `nextnotes_teams` noch `active`) → `owner_uid` bleibt, nach `orphaned` ist Export durch `owner_uid` in der Frist möglich. Circles-Fehler oder `getTeam()` ohne Bestätigung im Lauf → `owner_uid` unverändert. Matrix §9.7: jede Zelle als PHPUnit-Fall.
10. Lehrkraft löscht Aufgabe ohne `confirm` → 400; mit `confirm` → Aufgabe, alle Abgaben und Anhänge weg. Systemverwaltete Container: Schüler und Lehrkraft erhalten 403 für Löschen, Papierkorb, Verschieben der Abgabe-Notiz, für Löschen, Papierkorb, Umbenennen, Verschieben des Aufgabenordners (`assignment_id`) und des Workspace `kind=assignments`; „Papierkorb leeren“ lässt Abgabe-Notizen unberührt.

**M7 – Paketierung**
1. `make -C nextnotes dist` erzeugt `nextnotes-<version>.tar.gz` mit Wurzel `nextnotes/`; zwei Läufe → gleiche Prüfsumme.
2. Paket auf frischem NC 35 entpacken, `occ app:enable nextnotes` → 0, M3-Smoke grün.
3. `README.md` (Installation, Herkunft „Basiert auf Poznote von Timothé Poznanski (MIT)“), `CHANGELOG.md`, Update-Hinweise vorhanden; `LICENCE` (MIT) enthalten; App-Store-Anforderungen (Lizenzangabe in `info.xml`, Screenshots, Kategorien, Signatur-Ablauf) als Checkliste geprüft, nichts eingereicht.
4. App-ID `nextnotes` im App Store erneut als frei geprüft (ADR 0001).

---

## 14. Verwendete OCP-API (geprüft in `.ref` v35.0.2)
`@since` jeweils der Klasse/Methode; **fett** = in 34/35 eingeführt (Zielversion 35, daher nutzbar).
- AppFramework: `Controller`, `Http\TemplateResponse` (6), `Http\JSONResponse`, `Http\FileDisplayResponse` (11), `Http\DataDownloadResponse` (8), `Http\ZipResponse` (15), `Http\ContentSecurityPolicy` (8.1), `EmptyContentSecurityPolicy` (9; `allowEvalWasm` 28, `addAllowedWorkerSrcDomain` 15, `addAllowedFrameDomain` 8.1, `useStrictDynamic` 24, `useStrictDynamicOnScripts` 28), `Http\Attribute\NoAdminRequired`/`NoCSRFRequired`/`UserRateLimit` (27), `Services\IInitialState` (20), `Utility\ITimeFactory` (8), `Db\QBMapper` (14), `Db\Entity` (7), `Bootstrap\IRegistrationContext` (`registerSearchProvider` 20, `registerEventListener` 20, `registerNotifierService` 22, `registerUserMigrator` 24, `registerTeamResourceProvider` 29).
- `Util::addScript` (4), `addStyle`, `addInitScript` (28); `IURLGenerator` (6); `IUserSession` (6); `IUserManager` (8); `IGroupManager` (8); `IDBConnection` (6; `insertIgnoreConflict` 16, `escapeLikeParameter`); `AppFramework\Middleware` (6), `registerMiddleware` (20), `Db\TTransactional` (24); `DB\QueryBuilder\IQueryBuilder` (8.2); `DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION`; `Migration\SimpleMigrationStep` (13), `Migration\IOutput` (9.1); `DB\ISchemaWrapper` (13; `createTable` liefert **`DB\Schema\ITable`/`IColumn` 35.0.0**), `DB\Types` (21; `JSON` deprecated, nicht genutzt); `AppFramework\App` (6), `Bootstrap\IBootstrap`/`IBootContext` (20); `Server::get` (25, nur Testskript `dev/nextcloud/scripts/probe-row.php`); `Config\IUserConfig` (32); `IAppConfig` (7); `L10N\IFactory` (8.2); `IL10N`; `ITempManager` (8); `Security\ISecureRandom` (8).
- Dateien: `Files\IAppData` (11), `Files\SimpleFS\ISimpleFolder` (11; `getOrCreateFolder` **35.0.0**), `Files\IMimeTypeDetector` (8.2; `detectContent` 18), `IRequest::getUploadedFile` (6).
- Jobs: `BackgroundJob\TimedJob`, `QueuedJob` (15), `IJobList` (7).
- `Notification\IManager` (17), `INotifier` (9/17), `UnknownNotificationException` (30). `Search\IProvider` (20), `ISearchQuery`, `SearchResult::paginated`, `SearchResultEntry` (20). `Collaboration\Collaborators\ISearch::filteredSearch` (**35.0.0**; `search` deprecated 35). `Settings\ISettings` (9.1), `IIconSection` (12).
- `Notification\AlreadyProcessedException` (17). Events: `User\Events\UserDeletedEvent` (18; `getUid` 31), `Group\Events\GroupDeletedEvent` (18). `UserMigration\IMigrator` (24).
- Teams: `Teams\ITeamManager` (29; `getTeamsForUser` 33; **`getMembersOfTeam`, `hasTeamSupport` 34.0.0**; **`getTeam` 35.0.0**), `ITeamResourceProvider` (29), `TeamResource`, `Team` (29). Nicht verwendet: **`getTeamFolderProvider`, `ITeamFolderProvider`, `TeamFolder` (35.0.0)** – Notizen liegen nicht in Files (ADR 0002).
- Nicht OCP (Circles-App, gegen `github.com/nextcloud/circles` `stable35` geprüft, nicht in `.ref`): `OCA\Circles\CirclesManager`, `Model\Circle`, `Model\Member::LEVEL_*`/`STATUS_*`, `Model\Membership::getLevel`, `Events\CircleDestroyedEvent`, `CircleMemberAddedEvent`, `CircleMemberRemovedEvent`, `MembershipsCreatedEvent`, `MembershipsRemovedEvent`.
- Später (v1.x): `TaskProcessing\IManager` (30; `getAvailableTaskTypeIds` 32), `TaskTypes\AudioToText`/`TextToTextChat` (30); `AppFramework\AuthPublicShareController` (14), `Security\Bruteforce\IThrottler` (25).
