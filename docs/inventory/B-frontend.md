# M0-Inventur Teil B: Frontend und Seiten

Stand: 2026-11-02, Branch `nextcloud-port`, Upstream-Code unter `src/`. Nur Bestandsaufnahme, keine Entscheidung außer dem Status je Zeile.

**Status-Werte**
- `übernehmen`: Ansicht/Code bleibt; der Einstieg wird eine Route im PageController (`TemplateResponse`), `auth.php`/`page_bootstrap.php` entfallen.
- `ersetzen`: Funktion bleibt, Umsetzung wechselt (Ziel in der Spalte Hinweis).
- `streichen`: Für v1 gestrichen (Login, Benutzerverwaltung, OIDC, OAuth, TOTP, App-Passwörter, SaaS, SMTP, Webhooks, S3, Git Sync, Backup/Restore, PWA, MCP, Chrome-Extension).
- `offen (ADR)`: AI-Assistent, Transkription, öffentliche Links.

Webroot ist `src/public/` (siehe `docker/nginx/default.conf`). Dateien unter `src/` außerhalb von `public/` sind Includes, keine Einstiegspunkte. `src/public/api/v1/**` gehört zu Teil A (siehe Hinweis in 2.3).

---

## 1. Direkt aufrufbare PHP-Dateien (`src/public/**/*.php`, ohne `api/v1`)

Gefunden: **89 Dateien**. Davon rendern 50 eine HTML-Seite oder ein HTML-Fragment; der Rest sind Redirects, JSON-/Download-Endpunkte außerhalb von api/v1 und Asset-Bundler. Alle stehen hier, weil das Frontend sie direkt anspricht.

Art: **S** = HTML-Seite, **F** = HTML-Fragment (per AJAX), **R** = Redirect/Router, **E** = Endpunkt (JSON/Download), **A** = Asset-Bundler (CSS/JS).

### 1.1 Kernansichten

| # | Datei | Art | Zweck | Ansicht | Status | Hinweis |
|---|---|---|---|---|---|---|
| 1 | `index.php` | S/F | Hauptansicht: Ordnerbaum, Notizliste, Editor, Tabs, Suche (POST), inline Kanban; liefert mit Header `X-Poznote-Fragment: right_col` nur `#right_col` | Notizen | übernehmen | Fragment-Modus als eigene Route; 10 Inline-Skripte, eigener CSP-Header (`unsafe-inline`, `unsafe-eval`) |
| 2 | `kanban_content.php` | F | Kanban-HTML für die rechte Spalte (`?ajax=1&folder_id=`) | Kanban | übernehmen | Fragment-Route; 1 `onclick=` |
| 3 | `dashboard.php` | S | Notiz-Board nach Ordnern, Karten, Farben | Dashboard | übernehmen | 2 Inline-Skripte, 2 `onclick=` |
| 4 | `diary.php` | S | Tagesnotizen, Kalender | Diary | übernehmen | 2 Inline-Skripte, 1 `onclick=` |
| 5 | `graph.php` | S | Verknüpfungsgraph je Workspace | Graph | übernehmen | |
| 6 | `tasks.php` | S | Aufgaben aller Tasklisten | Tasks | übernehmen | 1 Inline-Skript |
| 7 | `notes_manager.php` | S | Alle Notizen, filterbar, Bulk-Aktionen | Notizen | übernehmen | |
| 8 | `create.php` | S | Erstellen-Hub (Notiz, Markdown, Tasklist, Excalidraw, Diary) | Notizen | übernehmen | 2 Inline-Skripte |
| 9 | `favorites.php` | S | Favoritenliste | Notizen | übernehmen | Kein Link im UI gefunden, evtl. tot; prüfen |
| 10 | `list_folders.php` | S | Ordnerliste mit Aktionen | Notizen | übernehmen | |
| 11 | `list_tags.php` | S | Tag-Übersicht | Notizen | übernehmen | 1 Inline-Skript |
| 12 | `attachments_list.php` | S | Alle Anhänge des Workspaces | Anhänge | übernehmen | |
| 13 | `attachments.php` | S | Anhänge einer Notiz | Anhänge | übernehmen | Git-Sync-Teil entfernen |
| 14 | `trash.php` | S | Papierkorb (Suchformular POST auf sich selbst) | Papierkorb | übernehmen | Form-POST braucht `requesttoken` |
| 15 | `revisions.php` | S | Frühere Versionen einer Notiz, Diff | Revisionen | übernehmen | setzt `window.POZNOTE_I18N` inline |
| 16 | `mentions.php` | S | Erwähnungen in geteilten Workspaces | Mentions | übernehmen | Mitgliederquelle hängt am Teilen-Modell (Teil A / ADR Teams) |
| 17 | `speed_dial.php` | S | Kacheln der mit `speed-dial` getaggten Notizen | Speed Dial | übernehmen | |
| 18 | `workspaces.php` | S/E | Workspace-Liste; zugleich POST-JSON-Endpunkt (löschen, umbenennen, Farbe, Reihenfolge, Teilen) | Workspaces | übernehmen | Seite bleibt; POST-Aktionen siehe 2.1 #12 |
| 19 | `excalidraw_editor.php` | S | Vollbild-Editor für Excalidraw | Excalidraw | übernehmen | `EXCALIDRAW_ASSET_PATH` muss auf App-Pfad zeigen, sonst unpkg-CDN-Fallback |
| 20 | `audio_player.php` | S | Minimaler Audio-Player für iframes im Editor | Anhänge | übernehmen | Wird root-absolut als `/audio_player.php` referenziert, auch in gespeicherten Notizen |
| 21 | `welcome.php` | S | Ersteinrichtung eines neuen Accounts | Onboarding | übernehmen | Sprache/Zeitzone kommen aus NC; Rest kürzen |
| 22 | `settings.php` | S | Einstellungen: persönlich + Admin-Kacheln, optionales Passwort-Gate | Settings | ersetzen | Persönliches bleibt als App-Seite; Admin-Teile in `OCP\Settings\ISettings` (Admin-Section); Passwort-Gate streichen |
| 23 | `storage-stats-user.php` | F | Speicherzahlen des eigenen Accounts (`?fragment=1`, in settings.php nachgeladen) | Settings | ersetzen | Zahlen aus App-DB, Quota aus NC |

### 1.2 Freigaben und öffentliche Ansichten

| # | Datei | Art | Zweck | Ansicht | Status | Hinweis |
|---|---|---|---|---|---|---|
| 24 | `shared.php` | S | Übersicht freigegebener Notizen/Ordner | Public | offen (ADR) | |
| 25 | `public_note.php` | S | Öffentliche Notiz per Token, Passwort-Gate | Public | offen (ADR) | Einzige Seite mit Nonce-CSP; braucht noch `unsafe-eval` |
| 26 | `public_folder.php` | S | Öffentlicher Ordner per Token | Public | offen (ADR) | 2 `onclick=` |
| 27 | `public_slug.php` | R | Router `/<slug>` → public_note.php | Public | offen (ADR) | nginx-Rewrite, in NC: `PublicShareController`-Route |
| 28 | `open_shared.php` | R | Signierter Mail-Link → Account wechseln → Notiz | Multi-Account | streichen | SMTP und Account-Wechsel |

### 1.3 Login, Accounts, Sitzung

| # | Datei | Art | Zweck | Ansicht | Status | Hinweis |
|---|---|---|---|---|---|---|
| 29 | `login.php` | S | Eigenes Login (Passwort, TOTP, OIDC-Button) | Login | streichen | NC-Login |
| 30 | `logout.php` | R | Abmelden | Login | streichen | NC-Header |
| 31 | `oidc.php` | E | OIDC-Bibliothek/Handler | Login | streichen | |
| 32 | `oidc_login.php` | R | OIDC-Start | Login | streichen | |
| 33 | `oidc_callback.php` | S | OIDC-Callback | Login | streichen | |
| 34 | `switch_account.php` | R | Aktiven Account wechseln (POST + CSRF) | Multi-Account | streichen | Benutzerverwaltung |
| 35 | `account_tree.php` | E | Baum eines anderen Accounts (JSON) | Multi-Account | streichen | |

### 1.4 Admin- und Integrationsseiten

| # | Datei | Art | Zweck | Ansicht | Status | Hinweis |
|---|---|---|---|---|---|---|
| 36 | `admin/users.php` | S | Benutzerverwaltung | Settings/Admin | streichen | 27 `onclick=` |
| 37 | `admin/oidc.php` | S | OIDC-Konfiguration | Settings/Admin | streichen | |
| 38 | `admin/smtp.php` | S | SMTP | Settings/Admin | streichen | |
| 39 | `admin/webhooks.php` | S | Ausgehende Webhooks | Settings/Admin | streichen | |
| 40 | `admin/storage-stats.php` | S | Instanzweite Speicherstatistik | Settings/Admin | streichen | 5 `onclick=` |
| 41 | `admin/activity-log.php` | S | Aktivitätsprotokoll | Settings/Admin | streichen | kein Aktivitätslog in v1 (ADR 0008; Korrektur M0, Abgleich mit Teil A T20) |
| 42 | `admin/disaster-recovery.php` | R | Redirect auf Settings-Dialog (Master-DB neu aufbauen) | Settings/Admin | streichen | |
| 43 | `admin/orphan-scanner.php` | R | Redirect auf Settings-Dialog verwaister Anhänge | Settings/Admin | streichen | Bereinigung später als BackgroundJob (Teil A) |
| 44 | `user-webhooks.php` | S | Webhooks je Account | Settings | streichen | |
| 45 | `git_sync.php` | S | Git-Sync-Status/Aktionen | Settings | streichen | |
| 46 | `backup_export.php` | S | Backup/Export (ZIP, strukturiert, S3) | Settings | streichen | Einzel-Export bleibt über #65/#66 |
| 47 | `restore_import.php` | S | Restore/Import (Chunk-Upload) | Settings | streichen | Notiz-Import per Drag&Drop bleibt über #69 |
| 48 | `local_backup_settings.php` | S | Lokales Backup | Settings/Admin | streichen | |
| 49 | `s3_backup_settings.php` | S | S3-Backup | Settings/Admin | streichen | |
| 50 | `s3_settings.php` | S | S3-Anhangsspeicher | Settings/Admin | streichen | |
| 51 | `ai_settings.php` | S | AI-Assistent (Admin) | Settings/Admin | offen (ADR) | |
| 52 | `ai_settings_user.php` | S | AI-Assistent (persönlich) | Settings | offen (ADR) | |
| 53 | `stt_settings.php` | S | Transkription (Admin) | Settings/Admin | offen (ADR) | |
| 54 | `stt_settings_user.php` | S | Transkription (persönlich) | Settings | offen (ADR) | |
| 55 | `oauth/authorize.php` | S | OAuth-Consent für MCP | OAuth | streichen | |
| 56 | `oauth/token.php` | E | OAuth-Token | OAuth | streichen | |
| 57 | `oauth/register.php` | E | Dynamic Client Registration | OAuth | streichen | |
| 58 | `oauth/introspect.php` | E | Token-Introspection | OAuth | streichen | |
| 59 | `oauth/revoke.php` | E | Token-Widerruf | OAuth | streichen | |
| 60 | `api-docs/index.php` | S | Swagger UI | Dev | streichen | Swagger braucht `new Function`; NC erzeugt OpenAPI selbst |

### 1.5 PWA und Offline

| # | Datei | Art | Zweck | Ansicht | Status | Hinweis |
|---|---|---|---|---|---|---|
| 61 | `offline.php` | S | Offline-Shell (vom Service Worker gecacht) | PWA | streichen | dazu `sw.js`, `pwa/pwa.js`, `manifest.webmanifest` |
| 62 | `offline_notes.php` | S | Offline gehaltene Notizen | PWA | streichen | |
| 63 | `share-target.php` | S | Web Share Target | PWA | streichen | |
| 64 | `pwa/manifest.php` | E | Manifest mit eigenem Logo | PWA | streichen | |

### 1.6 Endpunkte außerhalb von api/v1

| # | Datei | Art | Zweck | Status | Hinweis |
|---|---|---|---|---|---|
| 65 | `api_export_note.php` | E | Export einer Notiz (HTML/MD, Druckansicht) | ersetzen | Controller-Route, `DataDownloadResponse`; enthält 2 Inline-Skripte in der Druckansicht |
| 66 | `api_download_note.php` | E | Download einer Notizdatei | ersetzen | Controller-Route |
| 67 | `api_export_folder.php` | E | Ordner als ZIP | ersetzen | Controller-Route |
| 68 | `api_note_info.php` | E | Infos für Notiz-Info-Dialog | ersetzen | api/v1-Controller |
| 69 | `api_import_dropped_notes.php` | E | Import per Drag&Drop in die Sidebar | ersetzen | api/v1-Controller |
| 70 | `api_save_excalidraw.php` | E | Excalidraw speichern (große POSTs) | ersetzen | api/v1-Controller |
| 71 | `api_attachments.php` | E | Alt-URLs für Anhänge (nur noch in gespeicherten Inhalten) | ersetzen | Kompatibilitäts-Route auf Anhang-Controller oder Inhalts-Migration |
| 72 | `api_upload_background.php` | E | Hintergrundbild je Workspace | ersetzen | Controller + `IAppData` (@since 11.0.0) |
| 73 | `api_upload_css.php` | E | Eigene CSS-Themes (Admin-Upload) | streichen | entschieden (Thomas, ADR 0016) |
| 74 | `api_upload_font.php` | E | Eigene Schriften | streichen | entschieden (Thomas, ADR 0016) |
| 75 | `api_branding.php` | E | Instanzlogo | streichen | NC Theming liefert Logo (ADR 0016) |
| 76 | `api_ai_chat.php` | E | Proxy zu OpenAI-kompatiblem Server | offen (ADR) | |
| 77 | `api_transcribe.php` | E | Proxy zu STT-Server | offen (ADR) | |
| 78 | `api_backup_job.php` | E | Hintergrund-Export | streichen | |
| 79 | `api_export_entries.php` | E | Alle Notizen als ZIP | übernehmen | Benutzer-Export, Abgleich mit Teil A #196–198 (ADR 0008) |
| 80 | `api_export_structured.php` | E | Strukturierter Export | übernehmen | Benutzer-Export, Abgleich mit Teil A #196–198 (ADR 0008) |
| 81 | `api_export_attachments.php` | E | Alle Anhänge als ZIP | übernehmen | Benutzer-Export, Abgleich mit Teil A #196–198 (ADR 0008) |
| 82 | `api_restore_upload.php` | E | Chunk-Upload Restore/Import | streichen | |
| 83 | `api_local_backup.php` | E | Lokales Backup (Admin) | streichen | |
| 84 | `api_s3_backup.php` | E | S3-Backup (Admin) | streichen | |
| 85 | `api_s3_storage.php` | E | S3-Anhangsspeicher (Admin) | streichen | |
| 86 | `api_health.php` | E | Health (Offline-Erkennung, Docker) | streichen | NC `status.php` |
| 87 | `index_js.php` | A | Konkateniert ~60 JS-Dateien zu `?group=head|app` | ersetzen | Build-Zeit-Bundle in `js/`, geladen per `OCP\Util::addScript` (@since 4.0.0) |
| 88 | `index_css.php` | A | Konkateniert index-CSS (`?group=core`) | ersetzen | Build-Zeit-Bundle, `OCP\Util::addStyle` |
| 89 | `dark_mode_css.php` | A | Konkateniert `css/dark-mode/*.css` | ersetzen | Build-Zeit-Bundle |

---

## 2. Direkte Frontend-Aufrufe an PHP-Dateien außerhalb von api/v1

Quelle: `src/public/js/*.js` (226 Dateien, ohne `*-dist/`, `mermaid/`, `katex/`, `highlight/`, `qrcode-generator/`). Kommentare ausgenommen.

### 2.1 Datenaufrufe (fetch / XHR / Formular-POST / Download)

| # | Ziel | JS-Datei(en) | Mechanik | Zweck | Status | Hinweis |
|---|---|---|---|---|---|---|
| 1 | `api_save_excalidraw.php` | `excalidraw-editor.js` | fetch POST | Zeichnung speichern | ersetzen | api/v1-Route |
| 2 | `api_export_note.php` | `utils-export-create.js` | fetch / Navigation | Notiz exportieren | ersetzen | Controller-Route |
| 3 | `api_export_folder.php` | `utils-folders.js` | Navigation (Download) | Ordner-ZIP | ersetzen | Controller-Route |
| 4 | `api_note_info.php` | `note-info-modal.js` | fetch GET | Notiz-Info | ersetzen | api/v1 |
| 5 | `api_import_dropped_notes.php` | `sidebar-file-import.js` | fetch POST (FormData) | Drag&Drop-Import | ersetzen | api/v1 |
| 6 | `api_upload_background.php` | `background-settings.js`, `workspace-background.js` | fetch GET/POST/DELETE | Hintergrundbild | ersetzen | api/v1 + IAppData |
| 7 | `api_upload_css.php` | `settings-page.js`, `theme-list-modal.js` | fetch GET/POST/DELETE | Eigene Themes | streichen | siehe 1.6 #73 |
| 8 | `api_upload_font.php` | `settings-page.js` | fetch GET/POST/DELETE | Eigene Schriften | streichen | siehe 1.6 #74 |
| 9 | `api_branding.php` | `custom-logo.js` | fetch | Instanzlogo | streichen | NC Theming |
| 10 | `storage-stats-user.php?fragment=1` | `settings-page.js` (URL aus `data-src` in settings.php) | fetch HTML | Speicherzahlen | ersetzen | api/v1 JSON |
| 11 | `kanban_content.php?ajax=1` | `utils-kanban.js` | fetch HTML-Fragment | Kanban einblenden | übernehmen | Fragment-Route |
| 12 | `workspaces.php` (POST JSON) | `workspaces-actions.js` (5×), `workspaces-page.js`, `workspaces-share.js` | fetch POST | Workspace löschen/umbenennen/Farbe/Reihenfolge/Teilen | ersetzen | api/v1 workspaces (Teilen → ADR Teams) |
| 13 | `index.php` (Fragment) | `note-loader.js` | XHR GET mit `X-Poznote-Fragment: right_col` | Notiz laden ohne Reload | übernehmen | Fragment-Route im PageController |
| 14 | `index.php` (POST Suche) | `unified-search.js` (`fetch(form.action)`), Formular in `notes_list.php` | fetch POST | Suche | übernehmen | POST braucht `requesttoken` |
| 15 | `audio_player.php` | `attachments.js`, `events-utils.js`, `slash-command.js`, `note-attachment-menu.js`, `markdown-parser.js` | iframe-`src`, root-absolut `/audio_player.php` | Audio im Editor | übernehmen | Absolute Pfade brechen unter `/apps/nextnotes/`; steht auch in gespeicherten Notizen |
| 16 | `api_ai_chat.php` | `ai-chat.js`, `ai-settings-form.js`, `live-refresh.js` (Pfad-Ausnahme) | fetch POST | AI-Chat, Verbindungstest | offen (ADR) | |
| 17 | `api_transcribe.php` | `speech-to-text.js`, `stt-settings-form.js` | fetch POST | Transkription, Test | offen (ADR) | |
| 18 | `account_tree.php` | `workspaces-core.js`, `other-accounts.js` (`data-endpoint` aus `notes_list.php`) | fetch GET | Andere Accounts | streichen | |
| 19 | `switch_account.php` | `profile.js` | Formular-POST mit `csrf_token` | Account wechseln | streichen | |
| 20 | `api_backup_job.php` | `backup-export.js` | fetch GET/POST mit `csrf_token` | Hintergrund-Export | streichen | |
| 21 | `api_export_entries.php` | `backup-export.js`, `utils-note-create.js` | Navigation | ZIP aller Notizen | ersetzen | Aufruf aus Export-Abschnitt der persönlichen Einstellungen (ADR 0008) |
| 22 | `api_export_structured.php` | `backup-export.js` | Navigation | Strukturierter Export | ersetzen | Aufruf aus Export-Abschnitt der persönlichen Einstellungen (ADR 0008) |
| 23 | `api_export_attachments.php` | `backup-export.js` | Navigation | Anhänge-ZIP | ersetzen | Aufruf aus Export-Abschnitt der persönlichen Einstellungen (ADR 0008) |
| 24 | `api_restore_upload.php` | `restore-import.js` | fetch + XHR (Chunks) mit `csrf_token` | Restore/Import | streichen | |
| 25 | `api_health.php` | `offline-sync.js`, `offline-app.js` | fetch | Online-Erkennung | streichen | PWA |
| 26 | `offline.php` | `offline-sync.js`, `offline-app.js` | fetch + Cache | Offline-Shell | streichen | PWA |
| 27 | `login.php` | `login-page.js` (fetch), `session-guard.js` (Umleitung bei 401), `offline-store.js`, `offline-app.js`, `delete-account.js` | fetch / Navigation | Login, Sitzungsablauf | ersetzen | `session-guard.js`: bei 401 NC-Login (`/login?redirect_url=`) bzw. Reload |
| 28 | `logout.php` | `profile.js`, `offline-sync.js`, `offline-app.js`, `icon-sidebar-toggle.js` | Navigation / fetch | Abmelden | streichen | NC-Header |
| 29 | `oidc_login.php` | `login-page.js` | Navigation | OIDC | streichen | |

### 2.2 Navigation zu Seiten (location / href / Link-Erkennung)

| # | Ziel | JS-Datei(en) | Zweck | Status | Hinweis |
|---|---|---|---|---|---|
| 30 | `index.php?note=…&workspace=…` | 52 Dateien, zentral `navigation.js` (`buildUrl`), `globals.js`, `notes.js`, `tabs.js`, `note-reference.js`, `markdown-parser.js`, `kanban.js` … | Notiz öffnen, interne Links | übernehmen | Interne Notizlinks `index.php?note=N` stehen in gespeicherten Inhalten und werden per Regex erkannt (`note-reference.js`, `markdown-parser.js`, `events-rte-fields.js`) |
| 31 | `dashboard.php` | `navigation.js`, `list_folders.js`, `tasks-page.js`, `favorites-page.js`, `shared-page.js`, `ai-chat.js`, `dashboard-page.js` | Dashboard | übernehmen | |
| 32 | `diary.php?date=` | `diary-page.js`, `slash-command.js`, `events-rte-fields.js` | Diary | übernehmen | Diary-Links in Inhalten |
| 33 | `tasks.php` | `index-events.js`, `backlinks.js` | Tasks | übernehmen | |
| 34 | `list_tags.php` | `index-events.js`, `clickable-tags.js` | Tags | übernehmen | |
| 35 | `trash.php` | `trash.js`, `navigation.js` | Papierkorb | übernehmen | |
| 36 | `create.php` | `utils-note-create.js`, `utils-folders.js`, `folder-hierarchy.js` (Pfadprüfung) | Erstellen | übernehmen | |
| 37 | `revisions.php` | `snapshots.js` | Revisionen | übernehmen | |
| 38 | `attachments.php` | `attachments.js` | Anhänge | übernehmen | |
| 39 | `excalidraw_editor.php` | `excalidraw.js` | Excalidraw | übernehmen | |
| 40 | `workspaces.php?new=1` | `workspaces-create.js` | Workspace anlegen | übernehmen | |
| 41 | `settings.php` (`#section=…`, `?open=…`) | `theme-manager.js`, `index-events.js`, `workspaces-core.js`, `dashboard-page.js`, `settings-page.js` | Einstellungen | übernehmen | Nur persönliche Teile; Admin-Ziele fallen weg (siehe 1.1 #22) |
| 42 | `shared.php` | `share.js` | Freigaben | offen (ADR) | |
| 43 | `admin/webhooks.php` | `connected-apps.js` (Link) | Instanz-URL | streichen | |
| 44 | `profile.php` | `index-events.js` | Profil | streichen | Datei existiert nicht, toter Code |

### 2.3 Serverseitig gerenderte Formulare und Links auf *.php

| # | Ziel | Fundstelle | Zweck | Status |
|---|---|---|---|---|
| 45 | `index.php` (POST) | `notes_list.php:105` | Suchformular | übernehmen |
| 46 | `trash.php` (POST) | `trash.php:180` | Suche im Papierkorb | übernehmen |
| 47 | Selbst-POST (Passwort-Gate) | `settings.php:71` | Settings-Passwort | streichen |
| 48 | `data-href` auf Admin-/Integrationsseiten | `settings.php` (15 Kacheln: admin/users, oidc, smtp, webhooks, activity-log, storage-stats, ai_settings, stt_settings, s3_settings, s3_backup_settings, local_backup_settings, git_sync, user-webhooks, ai_settings_user, stt_settings_user) | Navigation | Status je Ziel wie Abschnitt 1 |
| 49 | `authorize.php` (POST) | `oauth/authorize.php:269` | OAuth-Consent | streichen |
| 50 | Selbst-POST-Formulare | `login.php` (6), `admin/*` (11), `git_sync.php` (3), `ai_*`/`stt_*` (6), `s3_*`/`local_backup_*`/`backup_export`/`restore_import` (9), `user-webhooks.php` (3), `public_note.php`/`public_folder.php` (je 1) | Seiten-Formulare | Status je Seite wie Abschnitt 1 |

Hinweis api/v1: Unter `src/public/api/v1/` liegen neben dem Router `index.php` fünf echte PHP-Dateien (`calendar/notes-by-date.php`, `calendar/notes-on-date.php`, `calendar/diary-entry.php`, `diary/entries.php`, `diary/diaries.php`), aufgerufen von `calendar.js`, `diary-page.js`, `slash-command.js`, `utils-export-create.js`. Sie umgehen den Router und gehören in Teil A.

Außerdem lädt das Frontend Dateien direkt aus `data/` (nginx-Locations): `data/users/<id>/backgrounds/`, `data/css/*.css`, `data/fonts/*`, `data/branding/logo-*.png`. In NC: über Controller aus `IAppData` ausliefern (Status ersetzen, mit #6–#9 zusammen).

### 2.4 Basis-URL, Auth und CSRF im Frontend

**Basis-URL**
- Kein zentraler Basis-Pfad. Seiten werden relativ angesprochen (`'index.php?…'`, `'dashboard.php'`), api/v1 gemischt: **257 root-absolute** (`'/api/v1/…'`, 84 Dateien) und **78 relative** (`'api/v1/…'`). Zusätzlich root-absolut `/audio_player.php`, `/login.php` (`session-guard.js`). PHP-Templates enthalten 35 weitere root-absolute `/api/v1/`-Pfade.
- Gespeicherte Notizinhalte enthalten URLs: Anhänge als `/api/v1/notes/<id>/attachments/<aid>` (früher `api_attachments.php?action=download…`), interne Links `index.php?note=N`, Audio-iframes `/audio_player.php?…`.
- Unter NC liegt die App unter `/index.php/apps/nextnotes/` bzw. `/apps/nextnotes/`. Root-absolute Pfade brechen. Ansatzpunkte: `session-guard.js` umhüllt bereits `window.fetch` und `XMLHttpRequest.open/send` für alle same-origin-Aufrufe; dort ließe sich ein Präfix (`OC.generateUrl`) ergänzen. Navigation, iframe-`src`, `sendBeacon` und `window.open` laufen nicht durch den Wrapper.

**Auth**
- Ausschließlich Session-Cookie (`credentials: 'same-origin'`, Cookie `SameSite=Lax`). Keine Tokens im Browser.
- `session-guard.js` setzt auf jeden same-origin-Aufruf `X-Poznote-Account` (aktiver Account der Seite); Server antwortet 409 `account_switched` bei Abweichung. Bei 401 Umleitung auf `login.php`. Multi-Account entfällt, der Header damit auch.
- `X-Requested-With: XMLHttpRequest` wird von vielen Aufrufen gesetzt; `lib/request-kind.php` unterscheidet damit Skript-Aufrufe von Navigationen (kein Basic-Challenge im Browser). In NC entfällt das.
- `X-Editor-Session-ID` ist in den CORS-Headern des api/v1-Routers erlaubt (Teil A).

**CSRF**
- api/v1: kein CSRF-Token; Schutz nur über SameSite=Lax. In NC sind Controller-Aufrufe ohne `#[NoCSRFRequired]` an den `requesttoken` gebunden.
- Eigene Session-Tokens nur an wenigen Stellen: `switch_account.php`, `api_backup_job.php`, `api_restore_upload.php` (alle streichen), sowie Formular-Tokens in `admin/oidc.php`, `admin/webhooks.php`, `user-webhooks.php`, `login.php` (alle streichen).
- Für NC muss jeder Schreibaufruf den Header `requesttoken` (`OC.requestToken`) tragen. Der `session-guard.js`-Wrapper ist der Einhängepunkt für fetch/XHR. Nicht abgedeckt: `navigator.sendBeacon('/api/v1/notes/<id>/beacon')` in `events-auto-save.js` (kann keine Header setzen; Token im FormData-Body nötig, ob NC das akzeptiert ist zu prüfen) und die Formular-POSTs 2.3 #45/#46.

---

## 3. Serverseitig eingebettete Daten und Inline-Code (CSP)

| Kategorie | Anzahl (grob) | Hauptfundstellen | Status | Ersatz in NC |
|---|---|---|---|---|
| Ausführbare Inline-`<script>` (ohne `src`, ohne JSON-Typ) | ~64 in 36 Dateien | `index.php` (10), `icon_sidebar.php` (5), `config.php` (3, Theme-Liste/Fonts/Custom-CSS), `settings_shell.php` (2), `lib/ui-customization.php` (2), `dashboard.php`, `diary.php`, `create.php`, `settings.php`, `backup_export.php`, `git_sync.php`, `admin/users.php`, `admin/storage-stats.php` (je 2), `public_note.php` (3, mit Nonce) | ersetzen | Daten über `IInitialState::provideInitialState` (@since 20.0.0); Logik in externe JS-Dateien |
| davon reine Datenübergabe `window.X = <?php json_encode ?>` | ~50 Zuweisungen | `ALLOWED_IFRAME_DOMAINS`, `DEFAULT_NOTE_TITLES`, `TAG_COLORS`, `NOTE_COLOR_PALETTE`, `calendarTranslations`, `PoznoteMentionMembers`, `PoznoteProfileI18n`, `PoznoteIconSidebarColorsConfig`, `__poznoteThemeList`, `__poznoteCustomFonts`, `__POZNOTE_TOOLBAR_ICON_COLORS__`, `__POZNOTE_HIDDEN_UI_ELEMENTS__`, `DASHBOARD_*`, `DIARY_DATA`, `EXCALIDRAW_ASSET_PATH`, `POZNOTE_I18N` (revisions) | ersetzen | `IInitialState`, ein kleiner Loader setzt die bisherigen `window.*`-Namen, damit Upstream-JS unverändert bleibt |
| davon Logik vor dem ersten Paint | ~12 | `index.php:392/428/636` (Spaltenbreite, letzte Notiz, Create-Loader), `icon_sidebar.php:480/549/616` (Rail-Zustand, Theme-Icons), `dashboard.php:475`, `diary.php:193`, `settings.php:393` | ersetzen | Externes Skript früh laden; Flackern prüfen |
| JSON-Dateninseln `<script type="application/json" id=…>` | 23 in 12 Dateien | `poznote-config` (index, attachments), `page-config-data` (3×), `current-note-data`, `markdown-init-data`, `tasklist-init-data`, `excalidraw-config`, `revisions-config`, `welcome-config`, `workspace-*` | übernehmen | CSP-unkritisch (nicht ausführbar); alternativ `IInitialState` |
| Inline-Handler `on…=` in PHP | 46 in 10 Dateien | `admin/users.php` (27, entfällt), `admin/storage-stats.php` (5, entfällt), `modals.php` (3), `dashboard.php` (2), `diary.php` (1), `kanban_content.php` (1), `public_folder.php` (2), `git_sync.php` (2, entfällt), `admin/activity-log.php` (2) | ersetzen | Auf `data-action`-Delegation umstellen (Muster existiert bereits, >300 `data-action=` im Markup); in v1-Seiten bleiben ~8 |
| Inline-Handler in JS-erzeugtem HTML | 23 in 7 Dateien | `tasklist-render.js` (10), `attachments.js` (5), `tasklist-subtasks.js` (3), `ui.js` (2), `utils-kanban.js`, `tasklist-order-drag.js`, `note-image-actions.js` (je 1) | ersetzen | Listener statt `onclick`-String |
| `new Function` auf `onclick`/`data-onclick` | 1 | `events-drag-drop.js:181` | ersetzen | Ohne Ersatz kein Betrieb ohne `unsafe-eval` |
| `javascript:`-URLs | 23 | meist `javascript:void(0)`; `public_note.php:759` als `form action` | ersetzen | `href="#"` + `preventDefault` |
| Inline-`style=` | ~240 in PHP | verteilt | übernehmen | NC erlaubt `style-src 'unsafe-inline'` per Default |
| Eigene CSP-Header | 6 Seiten | `index.php` (`unsafe-inline` + `unsafe-eval`), `login.php`, `offline.php`, `share-target.php`, `oauth/authorize.php`, `public_note.php` (Nonce + `unsafe-eval`); `lib/attachments.php` sandboxed Anhänge | ersetzen | NC-CSP per `ContentSecurityPolicy` an der Response; Iframe-Domains per `addAllowedFrameDomain` (@since 8.1.0) |

---

## 4. CSP-Risiken und Vendor-Bibliotheken

### 4.1 Rahmen in Nextcloud 35 (geprüft in `.ref/…/lib/public/AppFramework/Http/`)
- `ContentSecurityPolicy`: `script-src 'self'` + Nonce, `strict-dynamic` auf `script-src-elem` per Default (`strictDynamicAllowedOnScripts = true`), **kein** `unsafe-inline` für Skripte, `style-src 'unsafe-inline'` erlaubt, `img-src 'self' data: blob:`, `font-src 'self' data:`, `worker-src` leer, `frame-src` leer.
- `EmptyContentSecurityPolicy` hat in v35 **keine** Methode für `unsafe-eval` mehr; nur `allowEvalWasm()` (@since 28.0.0) für `wasm-unsafe-eval`, `addAllowedWorkerSrcDomain()` (@since 15.0.0), `useStrictDynamic()` (@since 24.0.0), `useStrictDynamicOnScripts()` (@since 28.0.0).
- Folge: Alles, was heute `unsafe-eval` oder Inline-Skripte braucht, muss weg oder per ADR begründet werden.

### 4.2 Eigener Code
| Fund | Fundstelle | Status |
|---|---|---|
| `new Function('event', dataOnclick)` | `events-drag-drop.js:181` | ersetzen |
| `onclick`-Strings in innerHTML | siehe Abschnitt 3 | ersetzen |
| Dynamisches Nachladen per `<script src>` | `lazy-libs.js` (Mermaid, KaTeX), `settings-page.js`/`tasklist-order-drag.js` (Sortable), `two-factor.js` (qrcode) | übernehmen; mit `strict-dynamic` erlaubt, wenn das ladende Skript eine Nonce trägt (zu testen) |
| `eval(`, `setTimeout('string')` | keine Funde | – |

### 4.3 Vendor-Bibliotheken (Quelle `vendor.json`)

| Bibliothek | Version | Datei(en) | Geladen von | CSP-Befund | Status |
|---|---|---|---|---|---|
| mermaid | 11.12.2 | `js/mermaid/mermaid.min.js` (cdnjs IIFE) | `lazy-libs.js`, `public_note.php` | 2× `Function("return this")()` nur als Fallback für das Global-Objekt (nicht erreicht, wenn `self`/`globalThis` existiert); SVG mit eingebettetem `<style>` (NC erlaubt Inline-Styles) | übernehmen |
| katex | 0.16.9 | `js/katex/katex.min.js`, `.css`, 20 woff2 | `lazy-libs.js`, `public_note.php`, `css_assets.php` | keine eval-Funde; Inline-Styles im Output | übernehmen |
| highlight.js | 11.9.0 | `js/highlight/highlight.min.js`, `powershell.min.js` | `index.php`, `public_note.php` | keine eval-Funde | übernehmen |
| sortablejs | 1.15.0 | `js/Sortable.min.js` | on demand | keine | übernehmen |
| CodeMirror 6 | Lockfile | `js/codemirror-dist/markdown-codemirror.iife.js` (Vite-Build) | `index.php`, Bridge `markdown-editor.js` | kein eval; injiziert `<style>` (style-mod) → braucht `style-src 'unsafe-inline'` (gegeben) oder Nonce | übernehmen |
| @excalidraw/excalidraw | 0.17.6 (+ React 18.3.1, lodash) | `js/excalidraw-dist/excalidraw-bundle.iife.js`, `excalidraw-assets/` (vendor-Chunk, Fonts, Locales) | `excalidraw_editor.php` | Bundle: `Function("return this")` (Fallback) und `Function('r','regeneratorRuntime = r')` (nur ohne `globalThis`); vendor-Chunk (pica): `new Worker` aus `data:`- und `blob:`-URLs, `WebAssembly.compile` → braucht `worker-src blob: data:` und `wasm-unsafe-eval`, sonst JS-Fallback (zu testen); ohne `EXCALIDRAW_ASSET_PATH` Fallback auf `https://unpkg.com/@excalidraw/excalidraw@0.17.6/dist/` | übernehmen; CSP-Ausnahmen per ADR |
| lucide-static | 0.575.0 | `css/lucide.css` (generiert, CSS-Masken) | alle Seiten | keine | übernehmen |
| devicon | 2.17.0 | `css/devicon.css` (generiert) | index-Bundle | keine | übernehmen |
| Inter | 4.001 | `webfonts/Inter/static/*.ttf` | `css/fonts.css` | keine | streichen (NC-Schrift `var(--font-face)`, ADR 0016) |
| swagger-ui-dist | 5.10.0 | `api-docs/swagger-ui/*` | `api-docs/index.php` | `new Function` im Bundle | streichen |
| qrcode-generator | 1.4.4 | `js/qrcode-generator/qrcode.js` | `two-factor.js` | keine | streichen (TOTP) |

### 4.4 Build und Auslieferung der Assets
- Vendored Dateien liegen fertig im Repo (`src/public/js/…`, `src/public/css/…`); `vendor.json` hält Version, Quelle, sha256, Ladeort.
- Selbst gebaut werden nur zwei Bundles mit Vite: `build/excalidraw/` → `excalidraw-dist/` (`tools/rebuild-excalidraw.sh`) und `build/markdown-editor/` → `codemirror-dist/` (`tools/rebuild-markdown-editor.sh`). Ausgabe wird committed.
- Lucide/Devicon-CSS: `tools/generate-lucide-icons.py`, `tools/generate-devicon-icons.py`.
- Laufzeit-Konkatenation: `index_js.php` (Gruppen `head`/`app`, ~60 Dateien), `index_css.php`, `dark_mode_css.php`; übrige Seiten listen CSS über `css_assets.php` (`poznoteRenderStylesheets`). Cache-Busting über `?v=`.
- Alle eigenen JS-Dateien sind klassische Skripte mit Globals (`window.*`), keine Module, kein Bundler.
- Für NC: Bundles zur Build-Zeit erzeugen und per `Util::addScript`/`addStyle` einbinden. Geklärt in M0 (`lib/private/Template/functions.php::emit_script_tag`, `JSResourceLocator::appendScriptIfExist`): `.js` wird als klassisches Skript mit `defer` und Nonce ausgegeben, nur `.mjs` als `type="module"`. Bundles daher als `.js` ausliefern; Globals bleiben nutzbar.

---

## 5. Design-Tokens und Themes

**Mechanik**
- Eine Datei `src/public/css/tokens.css` (771 Zeilen), von jeder Seite geladen. 132 Tokens mit Präfix `--pz-*`; Hellwerte auf `:root`, Dunkelwerte auf `html[data-theme='dark']`. Alte Namen `--dm-*` speichern die Dunkelwerte weiter (Kompatibilität für eigene CSS).
- Themes: `light`, `dark`, `black`, `lavender`, `sepia`, `terminal` (`theme_catalog.php`, `theme-manager.js`, `theme-init.js`, `tokens.css`), Varianten über Klassen `html.theme-<id>`. Dazu Admin-Upload eigener Themes (`data/css/`).
- Auswahl je Benutzer in `localStorage`; `system` folgt `prefers-color-scheme` (`theme-init.js`, lädt vor dem ersten Paint).
- Dunkel-Ebene zusätzlich in `css/dark-mode/*.css` (10 Dateien). `tools/css-check.php` erzwingt, dass Stylesheets nur `--pz-*` lesen.

**Token-Gruppen und Mapping-Vorschlag** (NC-Namen sind gegen `apps/theming` zu verifizieren; die App liegt nicht in `.ref`)

| Poznote-Token | Rolle | Kandidat in NC | Status |
|---|---|---|---|
| `--pz-bg`, `--pz-chrome-bg` | Seitenhintergrund | `--color-main-background` | ersetzen |
| `--pz-surface`, `--pz-surface-hover`, `--pz-surface-sunken`, `--pz-surface-strong(-hover)` | Flächen | `--color-background-dark`, `--color-background-hover`, `--color-background-darker` | ersetzen |
| `--pz-sidebar-bg`, `--pz-sidebar-surface` | Seitenleiste | `--color-main-background` / Navigation von `NcAppNavigation` | ersetzen |
| `--pz-text`, `--pz-text-strong` | Text | `--color-main-text` | ersetzen |
| `--pz-text-secondary`, `--pz-text-muted`, `--pz-text-subtle` | Sekundärtext | `--color-text-maxcontrast` (eine Stufe) | ersetzen |
| `--pz-border-light`, `--pz-border`, `--pz-border-strong` | Rahmen | `--color-border`, `--color-border-dark`, `--color-border-maxcontrast` | ersetzen |
| `--pz-accent`, `--pz-accent-hover`, `--pz-accent-soft`, `--pz-accent-strong`, `--pz-accent-text(-hover)`, `--pz-active-bg` | Akzent | `--color-primary-element`, `--color-primary-element-hover`, `--color-primary-element-light`, `--color-primary-element-text` | ersetzen |
| `--pz-accent-rgb`, `--pz-shadow-rgb`, `--pz-highlight-rgb`, `--pz-accent-text-rgb` | RGB-Kanäle für `rgba()` | kein direktes Gegenstück; `color-mix()` | ersetzen |
| `--pz-link` | Links | `--color-primary-element` / `--color-main-text` | ersetzen |
| `--pz-danger*`, `--pz-success*`, `--pz-warning*` (je fill/hover/soft/strong/text) | Status | `--color-error`, `--color-success`, `--color-warning` (+ `-hover`, `-text`) | ersetzen |
| `--pz-neutral(-hover)`, `--pz-disabled`, `--pz-icon(-hover)` | Neutral | `--color-text-maxcontrast`, `--color-background-darker` | ersetzen |
| `--pz-tooltip-*`, `--pz-tab*`, `--pz-tabbar-bg`, `--pz-scrollbar*`, `--pz-selection`, `--pz-mark*`, `--pz-code-bg` | Komponenten | ableiten aus obigen | ersetzen |
| `--pz-radius-*` (sm…pill, circle) | Radien | `--border-radius`, `--border-radius-large`, `--border-radius-pill` (bzw. `--border-radius-element`) | ersetzen |
| `--pz-weight-*` | Schriftgewichte | keine NC-Variable | übernehmen |
| `--pz-color-*` (15 Farben + `-soft`) | Notiz-/Tag-Farben (Benutzerinhalt) | keine | übernehmen |
| `--pz-syntax-*` (11) | Syntax-Highlighting | keine | übernehmen |
| `--pz-excalidraw-filter`, `--pz-excalidraw-scheme` | Excalidraw im Dunkelmodus | keine | übernehmen |
| `--pz-theme-swatch-*` | Vorschau der Themes im Menü | entfällt mit Theme-Menü | streichen |
| `--left-col-width` | Spaltenbreite (JS setzt) | keine | übernehmen |
| Themes `black`, `lavender`, `sepia`, `terminal`, eigene CSS-Themes | Zusatz-Themes | NC Theming (Hell/Dunkel/Kontrast) | streichen (entschieden, ADR 0016) |
| Theme-Umschalter + `localStorage` + `prefers-color-scheme` | Hell/Dunkel | NC-Benutzereinstellung; `html[data-theme]` aus NC-Body-Attribut `data-themes` ableiten | ersetzen |

Vorgehen-Vorschlag: `tokens.css` behalten und nur die `:root`/`dark`-Werte auf NC-Variablen zeigen lassen (`--pz-text: var(--color-main-text)`), dann bleiben alle übrigen Stylesheets unverändert.

---

## 6. i18n-Mechanik (`src/i18n`)

| Aspekt | Ist | Status | NC-Ersatz/Hinweis |
|---|---|---|---|
| Wörterbücher | 10 JSON-Dateien (`en`, `de`, `fr`, `es`, `pt`, `ca`, `ru`, `zh-cn`, `ja`, `ko`), verschachtelte Punkt-Schlüssel, `en` ~4 480 Blätter, 118 Top-Level-Gruppen | übernehmen | NC-Format (`l10n/*.json`, Quelltext als Schlüssel, Transifex) passt nicht; eigene Dateien behalten |
| Platzhalter | `{{name}}` | übernehmen | |
| PHP-API | `lib/i18n.php`: `t($key, $vars, $default, $lang)`, `t_h()` (HTML-escaped), Fallback Sprache → en → Default → Schlüssel; ~4 170 Aufrufe in PHP | übernehmen | |
| Sprachwahl | `getUserLanguage()` aus Account-Einstellung `language` (Default `en`), Abgleich mit Browser beim Bootstrap | ersetzen | `OCP\L10N\IFactory::findLanguage()` bzw. `IL10N::getLanguageCode()` (@since 6.0.0); Codes abbilden (`zh_CN`→`zh-cn`, `pt_BR`/`pt_PT`→`pt`, `de_DE`→`de`) |
| JS-API | `globals.js`: `window.t(key, vars, fallback)`, lädt `api/v1/system/i18n` (fetch), füllt `window.POZNOTE_I18N`, Event `poznote:i18n:loaded`; DOM-Übersetzung über `data-i18n`, `data-i18n-placeholder` (36 Stellen in PHP); ~780 JS-Aufrufe | übernehmen | Endpoint in Teil A; alternativ Wörterbuch per `IInitialState` mitgeben (spart einen Request) |
| Seiteneigene Übersetzungs-Globals | `calendarTranslations`, `DASHBOARD_*_TXT`, `NOTIFICATIONS_TXT`, `NOTE_COLOR_TXT`, `PoznoteProfileI18n` inline | ersetzen | über `IInitialState` (siehe Abschnitt 3) |
| Offline-Variante | `offline-boot.js` definiert eigenes `window.t` | streichen | PWA |
| Willkommensnotiz | `welcome_note.content` je Sprache, `welcome_note.html` als Fallback | übernehmen | |
| Doku-Übersetzungen | `tools/docs-i18n.py` (nur `docs/`) | streichen | nicht App-relevant |

---

## Zählung je Status

| Abschnitt | übernehmen | ersetzen | streichen | offen (ADR) | Summe |
|---|---|---|---|---|---|
| 1 Seiten und PHP-Einstiegspunkte | 24 | 13 | 42 | 10 | 89 |
| 2.1 Datenaufrufe aus JS | 4 | 12 | 11 | 2 | 29 |
| 2.2 Navigation aus JS | 12 | 0 | 2 | 1 | 15 |
| 2.3 Server-Formulare/Links (#45–#47, #49) | 2 | 0 | 2 | 0 | 4 |
| 3 Eingebettete Daten/Inline-Code | 2 | 8 | 0 | 0 | 10 |
| 4.2/4.3 Eigener Code und Vendor | 9 | 2 | 3 | 0 | 14 |
| 5 Tokens/Themes | 5 | 14 | 2 | 0 | 21 |
| 6 i18n | 5 | 2 | 2 | 0 | 9 |
| **Gesamt** | **63** | **51** | **64** | **13** | **191** |

(2.3 #48 und #50 verweisen auf den Status der Zielseiten in Abschnitt 1 und sind nicht gesondert gezählt. 4.2 zählt 3 Zeilen ohne die Zeile „keine Funde“.)

**Korrekturen M0 (Architekt, 2026-11-02):** #73, #74, #75 und 2.1 #7–#9 ersetzen → streichen, Inter → streichen (Entscheidung Thomas, ADR 0016); #79–#81 streichen → übernehmen und 2.1 #21–#23 streichen → ersetzen (Abgleich mit Teil A #196–198, Datenexport ADR 0008); #41 ersetzen → streichen (ADR 0008); 4.4 Skript-Ladeart geklärt; App-Pfad `/apps/poznote/` → `/apps/nextnotes/` (ADR 0001).
