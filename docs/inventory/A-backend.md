# M0-Inventur Teil A – Backend

Stand: Branch `nextcloud-port`, Upstream-Code unter `src/`. OCP-Abgleich gegen `.ref/nextcloud-server/lib/public/` (v35.0.2).

## Legende Status

| Status | Bedeutung |
|---|---|
| übernehmen | Funktion und API-Vertrag bleiben; Backend wird als NC-Controller/`QBMapper` auf eigenen App-Tabellen nachgebaut (Eigentum über `owner_type`/`owner_id` am Workspace, siehe SPEC §2; Korrektur M0). |
| ersetzen → X | Funktion bleibt, eigener Mechanismus wird durch NC-Dienst X ersetzt. |
| streichen | Entfällt in v1 (laut Auftrag oder weil Nextcloud es selbst liefert). |

Alle unten genannten OCP-Klassen existieren in der Referenz und sind älter als NC 35, der Zielversion (geprüftes `@since`: `IUserSession` 6, `IDBConnection` 6, `QBMapper` 14, `SimpleMigrationStep` 13, `TimedJob`/`QueuedJob` 15, `IAppData` 11, `IRootFolder` 8, `Notification\IManager`/`INotifier` 17, `Config\IUserConfig` 32, `IAppConfig` 7, `IL10N` 6, `IUserManager` 8, `Collaboration\Collaborators\ISearch` 13, `Teams\ITeamManager` 29, `IGroupManager` 8, `Search\IProvider` 20, `PublicShareController` 14, `Attribute\NoAdminRequired`/`PublicPage`/`NoCSRFRequired` 27, `Attribute\ApiRoute`/`FrontpageRoute` 29, `IAppManager` 8, `User\Events\UserDeletedEvent` 18, `Security\Bruteforce\IThrottler` 25, `TaskProcessing\IManager` 30, `SpeechToText\ISpeechToTextManager` 27). Alle sind auf NC 35 verfügbar (Zielversion nur 35, Korrektur M0).

---

## 1. API-Endpunkte

### 1.1 REST-API v1 (`src/public/api/v1/index.php`)

Router: eigene Klasse `Router` in `src/public/api/v1/index.php` (Basis `/api/v1`, Pattern `{param}` → `[^/]+`, erste Übereinstimmung gewinnt). nginx leitet `location /api/v1` per `try_files … /api/v1/index.php` dorthin (`docker/nginx/default.conf:44`). Controller liegen in `src/api/v1/controllers/*.php`, bekommen ein PDO-Handle `$con` der **Benutzer-SQLite** (siehe 2.1). Auth-Weiche vor dem Routing: `index.php:51-102` (siehe 5).

Spalte „Datei“: Controller::Methode (Kurzname, `Controller` weggelassen). Zeile = Router-Zeile in `index.php`.

| # | Methode | Pfad | Datei::Methode (Zeile) | Zweck | Status |
|---|---|---|---|---|---|
| 1 | GET | /notes/resolve | Notes::resolveReference (285) | Notizreferenz (Titel/ID) auflösen | übernehmen |
| 2 | POST | /notes/reorder | Folders::reorderNote (290) | Reihenfolge einer Notiz im Ordner | übernehmen |
| 3 | POST | /notes/favorites/clear | Notes::clearFavorites (295) | Alle Favoriten entfernen | übernehmen |
| 4 | GET | /notes/search | Notes::search (300) | Volltextsuche (nutzt SQLite-UDFs `remove_accents`, `search_clean_entry`) | übernehmen |
| 5 | GET | /notes/search/ids | Notes::searchIds (305) | Suche, nur IDs | übernehmen |
| 6 | GET | /notes/{id}/backlinks | Backlinks::index (310) | Rückverweise auf Notiz | übernehmen |
| 7 | GET | /graph | Graph::index (315) | Notiz-Graph | übernehmen |
| 8 | GET | /mentions | Mentions::index (320) | @-Erwähnungen in geteilten Workspaces | übernehmen |
| 9 | GET | /mentions/members | Mentions::members (325) | Erwähnbare Mitglieder | ersetzen → `IUserManager` / `Collaborators\ISearch` |
| 10 | POST | /mentions/{ownerId}/{noteId}/handled | Mentions::setHandled (329) | Erwähnung erledigt | übernehmen |
| 11 | DELETE | /mentions/{ownerId}/{noteId}/handled | Mentions::setHandled (333) | Erwähnung wieder offen | übernehmen |
| 12 | GET | /tasks | Tasks::index (338) | Aufgaben über alle Notizen | übernehmen |
| 13 | GET | /notes/{id}/tasks | Tasks::listForNote (343) | Aufgaben einer Tasklist-Notiz | übernehmen |
| 14 | POST | /notes/{id}/tasks | Tasks::createForNote (347) | Aufgabe anlegen | übernehmen |
| 15 | PATCH | /notes/{id}/tasks/{taskId} | Tasks::updateForNote (351) | Aufgabe ändern | übernehmen |
| 16 | DELETE | /notes/{id}/tasks/{taskId} | Tasks::deleteForNote (355) | Aufgabe löschen | übernehmen |
| 17 | POST | /notes/{id}/tasks/{taskId}/subtasks | Tasks::createSubtask (360) | Unteraufgabe anlegen | übernehmen |
| 18 | PATCH | /notes/{id}/tasks/{taskId}/subtasks/{subtaskId} | Tasks::updateSubtask (364) | Unteraufgabe ändern | übernehmen |
| 19 | DELETE | /notes/{id}/tasks/{taskId}/subtasks/{subtaskId} | Tasks::deleteSubtask (368) | Unteraufgabe löschen | übernehmen |
| 20 | GET | /notes/with-attachments | Notes::listWithAttachments (373) | Notizen mit Anhängen | übernehmen |
| 21 | GET | /notes/templates | Notes::listTemplates (378) | Vorlagen | übernehmen |
| 22 | GET | /notes | Notes::index (383) | Notizliste | übernehmen |
| 23 | GET | /notes/{id} | Notes::show (388) | Notiz lesen (ETag) | übernehmen |
| 24 | GET | /changes | Notes::changes (393) | Änderungs-Polling | übernehmen |
| 25 | GET | /offline/manifest | Offline::manifest (398) | Offline-Manifest (Service Worker) | streichen |
| 26 | GET | /offline/list | Offline::listing (402) | Offline-Liste | streichen |
| 27 | GET | /offline/notes | Offline::notes (406) | Offline-Inhalte | streichen |
| 28 | POST | /notes/{id}/lock | Notes::acquireLock (411) | Bearbeitungssperre holen | übernehmen |
| 29 | GET | /notes/{id}/lock | Notes::lockStatus (416) | Sperrstatus | übernehmen |
| 30 | POST | /notes/{id}/lock/heartbeat | Notes::heartbeatLock (421) | Sperre verlängern | übernehmen |
| 31 | POST | /notes/{id}/lock/release | Notes::releaseLock (426) | Sperre freigeben | übernehmen |
| 32 | POST | /notes | Notes::create (431) | Notiz anlegen | übernehmen |
| 33 | PATCH | /notes/{id} | Notes::update (436) | Notiz speichern (stößt Git-Sync an – entfällt) | übernehmen |
| 34 | DELETE | /notes/{id} | Notes::delete (443) | Notiz in Papierkorb/löschen | übernehmen |
| 35 | POST | /notes/{id}/restore | Notes::restore (448) | Aus Papierkorb holen | übernehmen |
| 36 | POST | /notes/{id}/beacon | Notes::beaconSave (453) | Speichern per `sendBeacon` beim Verlassen | übernehmen |
| 37 | PUT | /notes/{id}/tags | Notes::updateTags (458) | Tags setzen | übernehmen |
| 38 | PUT | /notes/{id}/icon | Notes::updateIcon (463) | Icon | übernehmen |
| 39 | PUT | /notes/{id}/color | Notes::updateColor (468) | Farbe | übernehmen |
| 40 | POST | /notes/{id}/favorite | Notes::toggleFavorite (473) | Favorit umschalten | übernehmen |
| 41 | POST | /notes/{id}/opened | Notes::markOpened (478) | Zuletzt geöffnet | übernehmen |
| 42 | PUT | /notes/{id}/offline | Notes::updateOffline (483) | Offline-Markierung (PWA) | streichen |
| 43 | PUT | /notes/{id}/pinned | Notes::updatePinned (488) | Anheften | übernehmen |
| 44 | PUT | /notes/{id}/content-width | Notes::updateContentWidth (493) | Inhaltsbreite | übernehmen |
| 45 | PUT | /notes/{id}/title-style | Notes::updateTitleStyle (498) | Titelstil | übernehmen |
| 46 | POST | /notes/{id}/duplicate | Notes::duplicate (503) | Duplizieren | übernehmen |
| 47 | POST | /notes/{id}/convert | Notes::convert (508) | HTML↔Markdown konvertieren | übernehmen |
| 48 | POST | /convert-html | Notes::convertHtml (513) | Markdown→HTML (stateless) | übernehmen |
| 49 | POST | /convert-markdown | Notes::convertMarkdown (518) | HTML→Markdown (stateless) | übernehmen |
| 50 | POST | /notes/{id}/snapshot | Snapshots::create (527) | Snapshot anlegen | ersetzen → Snapshot-Dateien in `IAppData` (ADR Speicherort) |
| 51 | GET | /notes/{id}/snapshots | Snapshots::listSnapshots (532) | Snapshots auflisten | ersetzen → `IAppData` |
| 52 | GET | /notes/{id}/snapshot | Snapshots::show (537) | Snapshot lesen | ersetzen → `IAppData` |
| 53 | DELETE | /notes/{id}/snapshot | Snapshots::destroy (542) | Snapshot löschen | ersetzen → `IAppData` |
| 54 | POST | /notes/{id}/snapshot/restore | Snapshots::restore (547) | Snapshot wiederherstellen | ersetzen → `IAppData` |
| 55 | GET | /notes/{id}/reminder | Reminders::getReminder (556) | Erinnerung lesen | übernehmen |
| 56 | POST | /notes/{id}/reminder | Reminders::setReminder (561) | Erinnerung setzen | übernehmen |
| 57 | POST | /notes/{id}/task-reminder | Reminders::setTaskReminder (566) | Aufgaben-Erinnerung | übernehmen |
| 58 | DELETE | /notes/{id}/task-reminder | Reminders::removeTaskReminder (570) | Aufgaben-Erinnerung löschen | übernehmen |
| 59 | DELETE | /notes/{id}/reminder | Reminders::removeReminder (574) | Erinnerung löschen | übernehmen |
| 60 | GET | /reminders | Reminders::index (579) | In-App-Benachrichtigungen | ersetzen → `Notification\IManager` (Glocke) |
| 61 | GET | /reminders/count | Reminders::count (584) | Zähler | ersetzen → `Notification\IManager` |
| 62 | POST | /reminders/dismiss-all | Reminders::dismissAll (589) | Alle verwerfen | ersetzen → `Notification\IManager` |
| 63 | POST | /reminders/{id}/read | Reminders::markRead (594) | Gelesen | ersetzen → `Notification\IManager` |
| 64 | POST | /reminders/{id}/dismiss | Reminders::dismiss (599) | Verwerfen | ersetzen → `Notification\IManager` |
| 65 | GET | /notes/{id}/share | Share::show (604) | Öffentlicher Notiz-Link lesen | streichen (ADR 0011) |
| 66 | POST | /notes/{id}/share | Share::store (609) | Öffentlichen Link anlegen | streichen (ADR 0011) |
| 67 | PATCH | /notes/{id}/share | Share::update (614) | Link ändern (Passwort, Ablauf, Modus) | streichen (ADR 0011) |
| 68 | DELETE | /notes/{id}/share | Share::destroy (619) | Link löschen | streichen (ADR 0011) |
| 69 | POST | /notes/{id}/folder | Folders::moveNoteToFolder (624) | In Ordner verschieben | übernehmen |
| 70 | POST | /notes/{id}/remove-folder | Folders::removeNoteFromFolder (629) | Aus Ordner lösen | übernehmen |
| 71 | POST | /notes/{id}/archive | Folders::archiveNote (634) | Archivieren | übernehmen |
| 72 | POST | /notes/{id}/kanban-completed | Folders::setNoteKanbanCompleted (639) | Kanban erledigt | übernehmen |
| 73 | GET | /folders/counts | Folders::counts (648) | Zähler je Ordner | übernehmen |
| 74 | GET | /folders/suggested | Folders::suggested (653) | Ordnervorschläge | übernehmen |
| 75 | POST | /folders/move-files | Folders::moveFiles (658) | Mehrere Notizen verschieben | übernehmen |
| 76 | POST | /folders/reorder | Folders::reorder (663) | Ordner sortieren | übernehmen |
| 77 | POST | /folders/restore | Folders::restoreDeleted (668) | Gelöschten Ordner wiederherstellen | übernehmen |
| 78 | GET | /folders | Folders::index (673) | Ordnerbaum | übernehmen |
| 79 | GET | /folders/{id} | Folders::show (678) | Ordner lesen | übernehmen |
| 80 | POST | /folders | Folders::create (683) | Ordner anlegen | übernehmen |
| 81 | PATCH | /folders/{id} | Folders::update (688) | Ordner ändern | übernehmen |
| 82 | DELETE | /folders/{id} | Folders::delete (693) | Ordner löschen | übernehmen |
| 83 | POST | /folders/{id}/move | Folders::move (698) | Ordner verschieben | übernehmen |
| 84 | POST | /folders/{id}/duplicate | Folders::duplicate (703) | Ordner duplizieren | übernehmen |
| 85 | POST | /folders/{id}/archive | Folders::archiveFolder (708) | Ordner archivieren | übernehmen |
| 86 | POST | /folders/{id}/empty | Folders::empty (713) | Ordner leeren | übernehmen |
| 87 | POST | /folders/{id}/tags | Folders::addTags (718) | Tags auf Inhalt | übernehmen |
| 88 | DELETE | /folders/{id}/tags | Folders::removeTags (721) | Tags entfernen | übernehmen |
| 89 | PUT | /folders/{id}/icon | Folders::updateIcon (726) | Icon | übernehmen |
| 90 | PUT | /folders/{id}/color | Folders::updateColor (731) | Farbe | übernehmen |
| 91 | PUT | /folders/{id}/pinned | Folders::updatePinned (736) | Anheften | übernehmen |
| 92 | PUT | /folders/{id}/offline | Folders::updateOffline (741) | Offline-Markierung (PWA) | streichen |
| 93 | PUT | /folders/{id}/favorite | Folders::updateFavorite (746) | Favorit | übernehmen |
| 94 | GET | /folders/{id}/notes | Folders::noteCount (751) | Notizanzahl | übernehmen |
| 95 | GET | /folders/{id}/path | Folders::path (756) | Pfad (rekursive CTE) | übernehmen |
| 96 | GET | /folders/{id}/share | FolderShare::show (761) | Öffentlicher Ordner-Link | streichen (ADR 0011) |
| 97 | POST | /folders/{id}/share | FolderShare::store (766) | Link anlegen | streichen (ADR 0011) |
| 98 | PATCH | /folders/{id}/share | FolderShare::update (771) | Link ändern | streichen (ADR 0011) |
| 99 | DELETE | /folders/{id}/share | FolderShare::destroy (776) | Link löschen | streichen (ADR 0011) |
| 100 | GET | /trash | Trash::index (785) | Papierkorb | übernehmen |
| 101 | DELETE | /trash | Trash::empty (790) | Papierkorb leeren | übernehmen |
| 102 | DELETE | /trash/{id} | Trash::destroy (795) | Endgültig löschen | übernehmen |
| 103 | GET | /workspaces | Workspaces::index (804) | Workspaces | übernehmen |
| 104 | POST | /workspaces | Workspaces::store (809) | Workspace anlegen | übernehmen |
| 105 | PATCH | /workspaces/{name} | Workspaces::update (814) | Umbenennen/ändern | übernehmen |
| 106 | DELETE | /workspaces/{name} | Workspaces::destroy (819) | Löschen | übernehmen |
| 107 | GET | /tags | Tags::index (828) | Tags | übernehmen |
| 108 | PATCH | /tags/{tag} | Tags::rename (833) | Tag umbenennen | übernehmen |
| 109 | DELETE | /tags/{tag} | Tags::delete (838) | Tag löschen | übernehmen |
| 110 | GET | /notes/{noteId}/attachments | Attachments::index (847) | Anhänge auflisten | ersetzen → Dateien in `IAppData` bzw. `IRootFolder` (ADR) |
| 111 | POST | /notes/{noteId}/attachments | Attachments::store (852) | Upload | ersetzen → `IAppData`/`IRootFolder` |
| 112 | GET | /notes/{noteId}/attachments/{attachmentId} | Attachments::show (857) | Download (auch für öffentliche Links ohne Login) | ersetzen → `IAppData`/`IRootFolder` |
| 113 | DELETE | /notes/{noteId}/attachments/{attachmentId} | Attachments::destroy (862) | Anhang löschen | ersetzen → `IAppData`/`IRootFolder` |
| 114 | POST | /notes/{noteId}/attachments/{attachmentId}/move | Attachments::move (867) | Anhang in andere Notiz | ersetzen → `IAppData`/`IRootFolder` |
| 115 | GET | /settings | Settings::index (876) | Benutzereinstellungen | ersetzen → `Config\IUserConfig` |
| 116 | GET | /settings/{key} | Settings::show (881) | Einstellung lesen | ersetzen → `Config\IUserConfig` |
| 117 | PUT | /settings/{key} | Settings::update (886) | Einstellung schreiben | ersetzen → `Config\IUserConfig` |
| 118 | GET | /backups | Backup::index (895) | Backups listen | streichen |
| 119 | POST | /backups | Backup::create (900) | Backup erzeugen | streichen |
| 120 | POST | /backups/upload | Backup::upload (905) | Backup hochladen | streichen |
| 121 | GET | /backups/{filename} | Backup::download (910) | Backup laden | streichen |
| 122 | DELETE | /backups/{filename} | Backup::destroy (915) | Backup löschen | streichen |
| 123 | POST | /backups/{filename}/restore | Backup::restore (920) | Restore | streichen |
| 124 | GET | /system/version | System::version (929) | App-Version | ersetzen → `IAppManager::getAppVersion` |
| 125 | GET | /system/updates | System::checkUpdates (934) | Update-Check GitHub | streichen |
| 126 | GET | /system/whats-new | System::whatsNew (939) | Neuigkeiten-Dialog | übernehmen |
| 127 | POST | /system/whats-new/seen | System::whatsNewSeen (943) | Neuigkeiten gesehen | übernehmen |
| 128 | GET | /system/i18n | System::i18n (948) | Übersetzungs-Strings | ersetzen → `IL10N` / `l10n/` der App |
| 129 | GET | /shared/with-me | System::listSharedWithMe (953) | Links, die auf mich beschränkt sind | ersetzen → Kompatibilitätsroute liefert `[]` (ADR 0011) |
| 130 | GET | /shared | System::listShared (958) | Eigene öffentliche Links | ersetzen → Kompatibilitätsroute liefert `[]` (ADR 0011) |
| 131 | GET | /git-sync/status | GitSync::status (967) | Git-Sync | streichen |
| 132 | GET | /github-sync/status | GitSync::status (972) | Alias | streichen |
| 133 | POST | /git-sync/test | GitSync::test (977) | Git-Sync | streichen |
| 134 | POST | /github-sync/test | GitSync::test (982) | Alias | streichen |
| 135 | POST | /git-sync/push | GitSync::push (987) | Git-Sync | streichen |
| 136 | POST | /github-sync/push | GitSync::push (992) | Alias | streichen |
| 137 | POST | /git-sync/pull | GitSync::pull (997) | Git-Sync | streichen |
| 138 | POST | /github-sync/pull | GitSync::pull (1002) | Alias | streichen |
| 139 | GET | /git-sync/progress | GitSync::progress (1007) | Git-Sync | streichen |
| 140 | PUT | /git-sync/config | GitSync::saveConfig (1012) | Git-Sync | streichen |
| 141 | GET | /users/profiles | Users::profiles (1024) | Profilauswahl Login | streichen |
| 142 | GET | /users/me | Users::me (1029) | Aktueller Benutzer | ersetzen → `IUserSession::getUser()` |
| 143 | PATCH | /users/me | Users::updateMe (1034) | Profil ändern | streichen |
| 144 | POST | /users/me/password | Users::changePassword (1039) | Passwort | streichen |
| 145 | GET | /users/me/password-status | Users::passwordStatus (1044) | Passwortstatus | streichen |
| 146 | DELETE | /users/me | Users::deleteMe (1049) | Konto löschen | streichen |
| 147 | GET | /users/me/app-passwords | Users::listAppPasswords (1055) | App-Passwörter | streichen |
| 148 | POST | /users/me/app-passwords | Users::createAppPassword (1059) | App-Passwörter | streichen |
| 149 | DELETE | /users/me/app-passwords/{id} | Users::revokeAppPassword (1063) | App-Passwörter | streichen |
| 150 | GET | /users/me/connected-apps | Users::listConnectedApps (1069) | OAuth-Grants (MCP) | streichen |
| 151 | DELETE | /users/me/connected-apps/{id} | Users::revokeConnectedApp (1073) | OAuth-Grants | streichen |
| 152 | GET | /users/me/push | Users::pushStatus (1079) | Web-Push (PWA) | streichen |
| 153 | POST | /users/me/push/subscriptions | Users::savePushSubscription (1083) | Web-Push | streichen |
| 154 | DELETE | /users/me/push/subscriptions | Users::deletePushSubscription (1087) | Web-Push | streichen |
| 155 | GET | /users/me/two-factor | Users::twoFactorStatus (1092) | TOTP | streichen |
| 156 | POST | /users/me/two-factor/setup | Users::twoFactorSetup (1096) | TOTP | streichen |
| 157 | POST | /users/me/two-factor/enable | Users::twoFactorEnable (1100) | TOTP | streichen |
| 158 | POST | /users/me/two-factor/disable | Users::twoFactorDisable (1104) | TOTP | streichen |
| 159 | POST | /users/me/two-factor/recovery-codes | Users::twoFactorRegenerateRecoveryCodes (1108) | TOTP | streichen |
| 160 | GET | /users/lookup/{username} | Users::lookup (1113) | Benutzer suchen (Workspace-Freigabe) | ersetzen → `IUserManager` / `Collaborators\ISearch` |
| 161 | GET | /admin/stats | Users::stats (1118) | Admin-Statistik | streichen |
| 162 | GET | /admin/statistics/token | InstanceStatistics (1123) | Statistik-Token | streichen |
| 163 | POST | /admin/statistics/token | InstanceStatistics (1128) | Statistik-Token | streichen |
| 164 | DELETE | /admin/statistics/token | InstanceStatistics (1136) | Statistik-Token | streichen |
| 165 | PUT | /admin/statistics/public | InstanceStatistics (1144) | Statistik öffentlich | streichen |
| 166 | GET | /admin/statistics | InstanceStatistics (1160) | Instanzstatistik | streichen |
| 167 | GET | /admin/users | Users::list (1166) | Benutzerverwaltung | streichen |
| 168 | GET | /admin/users/{id} | Users::get (1171) | Benutzerverwaltung | streichen |
| 169 | POST | /admin/users | Users::create (1176) | Benutzerverwaltung | streichen |
| 170 | PATCH | /admin/users/{id} | Users::update (1182) | Benutzerverwaltung | streichen |
| 171 | POST | /admin/repair | Users::repair (1188) | Master-DB reparieren | streichen |
| 172 | GET | /admin/orphan-attachments | Users::orphanAttachments (1193) | Verwaiste Anhänge finden | streichen |
| 173 | DELETE | /admin/orphan-attachments | Users::orphanAttachments (1197) | Verwaiste Anhänge löschen | streichen |
| 174 | DELETE | /admin/users/{id} | Users::delete (1202) | Benutzer löschen | streichen |
| 175 | POST | /admin/users/{id}/reset-password | Users::adminResetPassword (1207) | Passwort-Reset | streichen |
| 176 | POST | /admin/users/{id}/two-factor/reset | Users::adminResetTwoFactor (1212) | TOTP-Reset | streichen |
| 177 | GET | /admin/users/{id}/password-status | Users::adminPasswordStatus (1217) | Passwortstatus | streichen |
| 178 | PATCH | /public/tasks/{id} | Public::updateTask (1225) | Aufgabe über öffentlichen Link | streichen (ADR 0011) |
| 179 | POST | /public/tasks | Public::addTask (1229) | dto. | streichen (ADR 0011) |
| 180 | DELETE | /public/tasks/{id} | Public::deleteTask (1233) | dto. | streichen (ADR 0011) |
| 181 | PATCH | /public/notes/content | Public::updateNoteContent (1237) | Notiz über öffentlichen Link bearbeiten | streichen (ADR 0011) |
| 182 | POST | /public/notes/lock | Public::acquireEditLock (1242) | Sperre (öffentlich) | streichen (ADR 0011) |
| 183 | POST | /public/notes/lock/heartbeat | Public::heartbeatEditLock (1246) | dto. | streichen (ADR 0011) |
| 184 | POST | /public/notes/lock/release | Public::releaseEditLock (1250) | dto. | streichen (ADR 0011) |
| 185 | GET | /public/statistics | InstanceStatistics::serveRequest (index.php:62, vor Router) | Instanzstatistik per Token | streichen |

### 1.2 Sonstige Endpunkte: Einzelskripte unter `src/public/` (nginx `location ~ \.php$`)

Diese Skripte werden direkt aufgerufen (JSON- oder HTML-Antwort). Seiten (HTML) sind hier mitgezählt, damit nichts fehlt; deren UI-Teil gehört zu Teil B.

| # | Methode | Pfad | Datei | Zweck | Status |
|---|---|---|---|---|---|
| 186 | GET | /api/v1/calendar/diary-entry.php | public/api/v1/calendar/diary-entry.php | Tagebucheintrag zu Datum | übernehmen |
| 187 | GET | /api/v1/calendar/notes-by-date.php | public/api/v1/calendar/notes-by-date.php | Notizen je Datum (Kalender) | übernehmen |
| 188 | GET | /api/v1/calendar/notes-on-date.php | public/api/v1/calendar/notes-on-date.php | Notizen an Datum | übernehmen |
| 189 | GET, POST | /api/v1/diary/diaries.php | public/api/v1/diary/diaries.php | Tagebücher listen/anlegen | übernehmen |
| 190 | GET | /api/v1/diary/entries.php | public/api/v1/diary/entries.php | Gerenderte Tagebucheinträge | übernehmen |
| 191 | GET/POST | /api_attachments.php | public/api_attachments.php | Legacy-Anhang-API (`action=list/upload/download/delete`) | ersetzen → `IAppData`/`IRootFolder` |
| 192 | POST | /api_save_excalidraw.php | public/api_save_excalidraw.php | Excalidraw speichern (JSON in Notiz-HTML, SVG-Vorschau als Anhang) | übernehmen |
| 193 | GET | /api_download_note.php | public/api_download_note.php | Notiz als Datei (note/markdown/excalidraw/tasklist) | übernehmen |
| 194 | GET | /api_export_note.php | public/api_export_note.php | Export einer Notiz (HTML/MD/PDF-Vorstufe) | übernehmen |
| 195 | GET | /api_export_folder.php | public/api_export_folder.php | Ordner als ZIP | übernehmen |
| 196 | GET | /api_export_entries.php | public/api_export_entries.php | Alle Notizen als ZIP | übernehmen |
| 197 | GET | /api_export_structured.php | public/api_export_structured.php | Strukturierter Export | übernehmen |
| 198 | GET | /api_export_attachments.php | public/api_export_attachments.php | Anhänge als ZIP | übernehmen |
| 199 | POST | /api_import_dropped_notes.php | public/api_import_dropped_notes.php | Import per Drag&Drop | übernehmen |
| 200 | GET | /api_note_info.php | public/api_note_info.php | Notiz-Infodialog | übernehmen |
| 201 | POST | /api_backup_job.php | public/api_backup_job.php | Komplett-Export als Job | streichen |
| 202 | POST | /api_restore_upload.php | public/api_restore_upload.php | Chunked Restore/Import-Upload | streichen |
| 203 | POST | /api_local_backup.php | public/api_local_backup.php | Lokales Backup (Admin) | streichen |
| 204 | POST | /api_s3_backup.php | public/api_s3_backup.php | S3-Backup (Admin) | streichen |
| 205 | GET/POST | /api_s3_storage.php | public/api_s3_storage.php | S3-Anhangsspeicher (Admin) | streichen |
| 206 | POST | /api_ai_chat.php | public/api_ai_chat.php | AI-Chat-Proxy (OpenAI-kompatibel) | streichen (ADR 0010) |
| 207 | POST | /api_transcribe.php | public/api_transcribe.php | Transkriptions-Proxy | streichen (ADR 0010) |
| 208 | GET/POST/DELETE | /api_upload_background.php | public/api_upload_background.php | Hintergrundbild je Workspace | ersetzen → `IAppData` |
| 209 | GET/POST/DELETE | /api_upload_css.php | public/api_upload_css.php | Eigenes CSS (instanzweit) | streichen |
| 210 | GET/POST/DELETE | /api_upload_font.php | public/api_upload_font.php | Eigene Schriften (instanzweit) | streichen |
| 211 | GET | /api_branding.php | public/api_branding.php | Instanzlogo | streichen |
| 212 | GET | /api_health.php (/api/health, /api/info) | public/api_health.php | Healthcheck | streichen |
| 213 | GET | /account_tree.php | public/account_tree.php | Notizbaum eines anderen Kontos (Account-Access) | streichen |
| 214 | GET/POST | /workspaces.php | public/workspaces.php | Workspace-Seite + Aktionen delete/rename/reorder/set_color/set_tags/move_notes | übernehmen |
| 215 | POST | /workspaces.php (share_workspace/unshare_workspace) | public/workspaces.php | Workspace mit Benutzern teilen | ersetzen → Freigabe an NC-Benutzer/Teams (`ITeamManager`/`IGroupManager`, ADR) |
| 216 | GET/POST | /settings.php | public/settings.php | Einstellungsseite (+JSON-Aktionen) | übernehmen |
| 217 | GET | /index.php | public/index.php | Hauptseite (+JSON-Teilantworten) | ersetzen → `PageController` mit `TemplateResponse` |
| 218 | GET | /index_js.php, /index_css.php, /dark_mode_css.php | public/index_js.php, index_css.php, dark_mode_css.php | Gebündelte Assets | ersetzen → `Util::addScript`/`addStyle`, Build |
| 219 | GET | /excalidraw_editor.php | public/excalidraw_editor.php | Excalidraw-Editorseite | übernehmen |
| 220 | GET | /revisions.php | public/revisions.php | Revisionsansicht (Snapshots) | übernehmen |
| 221 | GET | /trash.php | public/trash.php | Papierkorbseite | übernehmen |
| 222 | GET | /tasks.php | public/tasks.php | Aufgabenseite | übernehmen |
| 223 | GET | /dashboard.php | public/dashboard.php | Notizboard | übernehmen |
| 224 | GET | /diary.php | public/diary.php | Tagebuch | übernehmen |
| 225 | GET | /graph.php | public/graph.php | Graphseite | übernehmen |
| 226 | GET | /kanban_content.php | public/kanban_content.php | Kanban-HTML-Fragment | übernehmen |
| 227 | GET | /list_folders.php | public/list_folders.php | Ordnerliste (Fragment) | übernehmen |
| 228 | GET | /list_tags.php | public/list_tags.php | Tagliste | übernehmen |
| 229 | GET | /favorites.php | public/favorites.php | Favoritenseite | übernehmen |
| 230 | GET | /mentions.php | public/mentions.php | Erwähnungsseite | übernehmen |
| 231 | GET | /notes_manager.php | public/notes_manager.php | Notizverwaltung (Bulk) | übernehmen |
| 232 | GET | /speed_dial.php | public/speed_dial.php | Speed Dial | übernehmen |
| 233 | GET | /create.php | public/create.php | Anlegen-Hub | übernehmen |
| 234 | GET | /attachments.php, /attachments_list.php | public/attachments.php, attachments_list.php | Anhangsübersicht | übernehmen |
| 235 | GET | /audio_player.php | public/audio_player.php | Audio-Einbettung | übernehmen |
| 236 | GET | /welcome.php | public/welcome.php | Erststart-Guide | übernehmen |
| 237 | GET | /storage-stats-user.php | public/storage-stats-user.php | Eigene Speicherstatistik | ersetzen → api/v1-JSON, Summe aus `nextnotes_attachments` (Korrektur M0, ADR 0002) |
| 238 | GET | /shared.php | public/shared.php | Übersicht öffentliche Links | streichen (ADR 0011) |
| 239 | GET | /open_shared.php | public/open_shared.php | Landing aus Workspace-Share-Mail | streichen |
| 240 | GET | /public_note.php (/workspace/{t}) | public/public_note.php | Öffentliche Notiz | streichen (ADR 0011) |
| 241 | GET | /public_folder.php (/folder/{t}) | public/public_folder.php | Öffentlicher Ordner | streichen (ADR 0011) |
| 242 | GET | /public_slug.php (/{slug}) | public/public_slug.php | Hübsche öffentliche URL | streichen (ADR 0011) |
| 243 | GET | /offline.php, /offline_notes.php | public/offline.php, offline_notes.php | Offline-Seiten (PWA) | streichen |
| 244 | GET | /pwa/manifest.php, /sw.js, /share-target.php | public/pwa/*, sw.js, share-target.php | PWA, Service Worker, Web Share Target | streichen |
| 245 | GET/POST | /login.php, /logout.php | public/login.php, logout.php | Eigener Login | streichen |
| 246 | GET | /oidc.php, /oidc_login.php, /oidc_callback.php | public/oidc*.php | OIDC | streichen |
| 247 | GET/POST | /oauth/{authorize,token,register,revoke,introspect}.php | public/oauth/*.php | OAuth-Server für MCP | streichen |
| 248 | GET | /switch_account.php | public/switch_account.php | Konto wechseln (Account-Access) | streichen |
| 249 | GET/POST | /git_sync.php | public/git_sync.php | Git-Sync-Seite | streichen |
| 250 | GET/POST | /backup_export.php, /restore_import.php | public/backup_export.php, restore_import.php | Backup/Restore-Seiten | streichen |
| 251 | GET/POST | /local_backup_settings.php, /s3_backup_settings.php, /s3_settings.php | public/… | Backup-/S3-Einstellungen | streichen |
| 252 | GET/POST | /user-webhooks.php | public/user-webhooks.php | Benutzer-Webhooks | streichen |
| 253 | GET/POST | /ai_settings.php, /ai_settings_user.php | public/ai_settings*.php | AI-Einstellungen | streichen (ADR 0010) |
| 254 | GET/POST | /stt_settings.php, /stt_settings_user.php | public/stt_settings*.php | Transkriptions-Einstellungen | streichen (ADR 0010) |
| 255 | GET/POST | /admin/users.php | public/admin/users.php | Benutzerverwaltung (create/delete/toggle_status/update_profile/update_account_access) | streichen |
| 256 | GET/POST | /admin/oidc.php, /admin/smtp.php, /admin/webhooks.php | public/admin/… | OIDC/SMTP/Webhook-Admin | streichen |
| 257 | GET/POST | /admin/activity-log.php, /admin/storage-stats.php, /admin/orphan-scanner.php, /admin/disaster-recovery.php | public/admin/… | Admin-Werkzeuge | streichen |
| 258 | GET | /api-docs/ | public/api-docs/index.php, openapi.yaml | Swagger UI | streichen |

Hinweis: In der UI-Hauptseite gerenderte PHP-Includes in `src/` (`notes_list.php`, `note_display.php`, `modals.php`, `search_handler.php`, `favorites_handler.php` …) sind keine eigenen Endpunkte; sie werden in Teil B (Frontend) inventarisiert.

---

## 2. Datenbank

### 2.1 Aufteilung und Mandantentrennung

- **Eine SQLite-DB pro Benutzer**: `data/users/{id}/database/poznote.db` (`users/UserDataManager.php:37`). Auswahl in `db_connect.php:30-99` über `$_SESSION['user_id']`; bei öffentlichen Links über `master.db.shared_links` → Besitzer-ID (`db_connect.php:70-82`). Fallback ohne Session: DB von Benutzer 1 (`db_connect.php:88-98`).
- **Eine zentrale `master.db`**: `data/master.db` (`users/db_master.php`), Benutzer, globale Einstellungen, Freigaben, Sperren, Auth-Artefakte.
- **Workspaces**: keine eigene DB, sondern Spalte `workspace TEXT` (Name, kein FK) in `entries`/`folders` und Tabelle `workspaces` in der Benutzer-DB. Fremde Workspaces werden über `master.workspace_shares` geöffnet; der Grantee arbeitet dann in der DB des Besitzers mit `$_SESSION['shared_workspace_scope']` (`auth.php:789-935`).
- **Account-Access**: `master.user_account_access` erlaubt einem Login, die komplette DB eines anderen Profils zu öffnen (`$_SESSION['login_user_id']` ≠ `$_SESSION['user_id']`).
- Schema-Migration: kein Migrations-Framework; `CREATE TABLE IF NOT EXISTS` + `PRAGMA table_info` + `ALTER TABLE ADD COLUMN` bei jedem Verbindungsaufbau, versioniert über `settings`-Schlüssel (`db_connect.php:165-880`).

**Folge für NC:** Alle Benutzer-Tabellen werden zu zentralen App-Tabellen `oc_nextnotes_*` (Korrektur M0, ADR 0001) mit Eigentum über `owner_type`/`owner_id` am Workspace (NC-UID bzw. Team-ID statt Integer; Notizen/Ordner hängen über `workspace_id` daran, SPEC §2). IDs sind heute **pro Benutzer** lokal (`entries.id` beginnt bei jedem Benutzer bei 1); Referenzen der Form `(owner_user_id, note_id)` in `master.*`, `/mentions/{ownerId}/{noteId}` und Notiz-Links im HTML (`/api/v1/notes/{id}/attachments/…`) müssen bei globalen IDs umgestellt werden. Migrationen per `SimpleMigrationStep`.

### 2.2 Tabellen der Benutzer-DB (`data/users/{id}/database/poznote.db`)

| # | Tabelle | Wichtige Spalten | Zweck | Beziehung | Fundstelle | Status |
|---|---|---|---|---|---|---|
| T1 | entries | id, heading, entry (Inhalt-Kopie für Suche), type (note/markdown/tasklist/excalidraw…), tags (Text, kommagetrennt), folder (Name, legacy), folder_id, workspace, trash, trashed_at, favorite, pinned, attachments (JSON-Array), reminder_at, reminder_recurrence, linked_note_id, created/updated, created_by_user_id/updated_by_user_id, Sortierspalten (display/dashboard/speed_dial/kanban_order), kanban_completed, icon/color/title_*, content_width, offline, client_state_hash/version | Notizen | folder_id→folders.id (SET NULL), linked_note_id→entries.id | db_connect.php:246, 364-874 | übernehmen (+`workspace_id`, Eigentum über Workspace; `attachments`-JSON → `nextnotes_attachments`; `offline` streichen) |
| T2 | folders | id, name, workspace, parent_id, display_order, pinned, favorite, icon, icon_color, color, kanban_enabled, sort_setting, is_diary, offline | Ordnerbaum je Workspace | parent_id→folders.id (CASCADE); eindeutig (workspace,parent_id,name) | db_connect.php:278, 401-434, 553-562 | übernehmen (+`workspace_id`) |
| T3 | workspaces | id, name (UNIQUE), display_order, tags, color | Workspaces | per Name von entries/folders referenziert | db_connect.php:291, 676-705; public/workspaces.php:25 | übernehmen (+`owner_type`/`owner_id`; Name je Besitzer eindeutig, SPEC §2) |
| T4 | settings | key, value | Benutzer-Einstellungen + Schema-Version | – | db_connect.php:305 | ersetzen → `Config\IUserConfig` (Schemaversion entfällt durch Migrationen) |
| T5 | shared_notes | note_id, token, expires, access_mode, password(_encrypted), theme, indexable, allowed_users (JSON), disabled | Öffentliche Notiz-Links | note_id→entries.id (CASCADE) | db_connect.php:311, 445-463 | streichen (ADR 0011) |
| T6 | shared_folders | folder_id, token, expires, theme, indexable, password(_encrypted), access_mode, allowed_users, disabled | Öffentliche Ordner-Links | folder_id→folders.id (CASCADE) | db_connect.php:324, 482-492 | streichen (ADR 0011) |
| T7 | notifications | note_id, task_id, type, message, is_read, dismissed, trigger_at, recurrence, email_*, webhook_*, push_* | Erinnerungen + Zustellstatus | note_id→entries.id (CASCADE) | db_connect.php:340, 541-665; ReminderEmailService.php:203-246 | übernehmen (nur Fälligkeit; `email_*`/`webhook_*`/`push_*` streichen, Anzeige via `Notification\IManager`) |
| T8 | ai_conversations | user_id, id, workspace, title, messages (JSON), updated | AI-Chat-Verläufe | – | lib/ai-conversations.php:78 | streichen (ADR 0010) |
| T9 | shared_workspaces (legacy) | workspace_name, token, allowed_users | Alt, wird nach master.workspace_shares migriert | – | db_connect.php:207-240 | streichen |

### 2.3 Tabellen der `master.db` (`data/master.db`)

| # | Tabelle | Wichtige Spalten | Zweck | Beziehung | Fundstelle | Status |
|---|---|---|---|---|---|---|
| T10 | users | id, username, email, password_hash, oidc_subject, is_admin, active, language, ai_chat_enabled, stt_enabled, quota_*, password_login_disabled, last_login | Benutzerprofile | Ziel aller user_id-FKs | users/db_master.php:75-187 | ersetzen → NC-Benutzer (`IUserManager`, UID) |
| T11 | global_settings | key, value | Instanz-Konfiguration (SMTP, OIDC, S3, AI, …) | – | users/db_master.php:209 | ersetzen → `IAppConfig` |
| T12 | shared_links | token, user_id, target_type, target_id | Token→Besitzer-Routing öffentlicher Links | user_id→users | users/db_master.php:217 | streichen (ADR 0011) |
| T13 | user_account_access | accessor_user_id, target_user_id | Login darf fremdes Profil öffnen | beide→users (CASCADE) | users/db_master.php:228 | streichen |
| T14 | workspace_shares | owner_user_id, workspace_name, grantee_user_id | Workspace mit Benutzern teilen | →users (CASCADE) | users/db_master.php:256 | ersetzen → App-Tabelle mit NC-UID/Team-ID (`ITeamManager`/`IGroupManager`, ADR) |
| T15 | note_edit_locks | target_user_id, note_id, holder_login_user_id, holder_session_id, holder_kind, expires_at | Bearbeitungssperren | →users | users/db_master.php:288 | übernehmen (Schlüssel auf globale Notiz-ID) |
| T16 | push_subscriptions | user_id, endpoint, p256dh, auth | Web-Push | user_id→users | users/db_master.php:320 | streichen |
| T17 | webhooks | url, secret, events, user_id | Ausgehende Webhooks | user_id→users | users/db_master.php:338 | streichen |
| T18 | mention_handled | owner_user_id, note_id, user_id, handled_count | Erledigte Erwähnungen | →users (CASCADE) | lib/mentions.php:125 | übernehmen (+NC-UID, globale Notiz-ID) |
| T19 | workspace_activity_emails | user_id, reported_until, attempts | Wöchentliche Aktivitätsmail | – | WorkspaceActivityEmailService.php:225 | streichen |
| T20 | activity_log | user_id, username, action, details, source | Admin-Aktivitätslog | – | ActivityLog.php:122 | streichen (ADR 0008; Korrektur M0) |
| T21 | login_attempts | key, attempts, first_attempt_at | Login-Rate-Limit | – | auth.php:1189 | streichen |
| T22 | user_app_passwords | user_id, label, token_hash, expires_at | App-Passwörter | user_id→users | users/app_passwords.php:28 | streichen |
| T23 | oauth_clients | client_id, client_secret_hash, redirect_uris | OAuth (MCP) | – | users/oauth.php:36 | streichen |
| T24 | oauth_codes | code_hash, client_id, user_id, code_challenge | OAuth | →oauth_clients, users | users/oauth.php:47 | streichen |
| T25 | oauth_grants | client_id, user_id, refresh_token_hash | OAuth | →oauth_clients, users | users/oauth.php:63 | streichen |
| T26 | oauth_access_tokens | token_hash, grant_id | OAuth | →oauth_grants | users/oauth.php:82 | streichen |
| T27 | user_totp | user_id, secret, last_step | TOTP | →users | users/totp.php:36 | streichen |
| T28 | user_totp_recovery_codes | user_id, code_hash, used_at | TOTP | →users | users/totp.php:45 | streichen |

Hinweis: `folder_path`/`folder_tree` in Queries sind rekursive CTE-Namen, keine Tabellen.

### 2.4 SQLite-spezifisches SQL (muss DB-neutral über `IQueryBuilder` neu geschrieben werden)

| Konstrukt | Treffer | Wichtigste Fundstellen | Ersatz |
|---|---|---|---|
| `PRAGMA journal_mode/busy_timeout/foreign_keys/synchronous` | 4 | db_connect.php:111-114 | entfällt (NC verwaltet Verbindung) |
| `PRAGMA table_info(...)` (Schema-Selbstheilung) | 72 in 24 Dateien | db_connect.php (26), users/db_master.php (8), backup_sql_restore.php, import_helpers.php, UserDataManager.php, users/oauth.php, ReminderEmailService.php, public/account_tree.php | Migrationen (`SimpleMigrationStep`) |
| `sqlite_master` | 6 | db_connect.php:175,223; users/db_master.php; backup_zip.php | Migrationen / `ISchemaWrapper` |
| `INSERT OR IGNORE` / `INSERT OR REPLACE` / `REPLACE INTO` | 70 in 22 Dateien | db_connect.php (24), GitSync.php (8), users/db_master.php, ai_config.php, UserDataManager.php, public/workspaces.php, SettingsController.php, public/admin/* | Select-then-insert/update bzw. `IDBConnection::insertIgnoreConflict()` / Unique-Exception abfangen |
| `ON CONFLICT … DO` (Upsert) | 5 | WorkspaceActivityEmailService.php, users/totp.php, users/db_master.php, lib/mentions.php, auth.php | wie oben |
| `datetime("now")` / `datetime(...)` | 48 in 18 Dateien | FoldersController.php (11), RemindersController.php (5), NotesController.php (5), lib/ai-tools.php (4), import_helpers.php (4), public/index.php, calendar/notes-on-date.php, api_save_excalidraw.php:201 | Zeit in PHP (`ITimeFactory`) als Parameter |
| `COLLATE NOCASE` | 43 in 17 Dateien | users/db_master.php (19), NotesController.php (4), lib/ai-tools.php, public/index.php, lib/workspaces.php, db_connect.php:562,570 | `$qb->func()->lower()` / `iLike()` |
| Benutzerdefinierte SQL-Funktionen (`createFunction`: `remove_accents`, `search_clean_entry`) | 24 in 8 Dateien | db_connect.php:117,127; NotesController.php (8), search_handler.php, lib/note-titles.php, lib/ai-tools.php, public/trash.php, TrashController.php | Suchspalte mit normalisiertem Text beim Speichern, oder `Search\IProvider` |
| `json_extract` u. a. JSON1 | 2 | InstanceStatistics.php | entfällt (gestrichen) |
| `WITH RECURSIVE` (CTE) | 6 | ShareController, FolderShareController, PublicController, AttachmentsController, note_display.php, public/public_note.php | in PHP auflösen (Ordnerbaum laden) – CTE nicht über QB |
| `INTEGER PRIMARY KEY AUTOINCREMENT`, `DATETIME DEFAULT CURRENT_TIMESTAMP`, partielle Indizes (`WHERE …`), `ALTER TABLE … DROP COLUMN` | alle Schemata | db_connect.php:246-874, users/*.php | Migrationen mit `ISchemaWrapper` |
| `lastInsertId()` | 21 in 13 Dateien | FoldersController.php (6), lib/ai-tools.php, import_helpers.php, functions.php | `$qb->getLastInsertId()` / `QBMapper::insert` |
| Direkte `PDO`/`Pdo\Sqlite` | überall (`$con`) | db_connect.php:109 | `IDBConnection` |

---

## 3. Hintergrundjobs, Worker, Cron

Es gibt **keinen Cron**. Langläufer werden per supervisord gestartet (`docker/supervisor/supervisord.conf`), einmalige Wartung beim Containerstart (`docker/init.sh`), Jobs per `nohup php` (`background_jobs.php:241-244`), einiges „gelegentlich“ im Request.

| # | Job | Auslöser | Datei | Zweck | Status |
|---|---|---|---|---|---|
| J1 | reminder-email-worker (Schleife 60 s) – Teil Erinnerungs-Mail | supervisord | workers/reminder-email-worker.php → ReminderEmailService | Fällige Erinnerungen per SMTP | streichen |
| J2 | dto. – Teil Webhook | supervisord | ReminderWebhookService.php | Erinnerung an Webhook | streichen |
| J3 | dto. – Teil Web-Push | supervisord | ReminderPushService.php | Erinnerung per Web-Push | streichen |
| J4 | dto. – Teil Workspace-Aktivitätsmail | supervisord | WorkspaceActivityEmailService.php | Aktivitätsmail an Mitglieder | streichen |
| J5 | Fälligkeitsprüfung Erinnerungen (neu, ersetzt J1-J3 fachlich) | – | – | Fällige `notifications` als NC-Benachrichtigung zustellen | ersetzen → `BackgroundJob\TimedJob` + `Notification\IManager`/`INotifier` |
| J6 | s3-backup-worker (Schleife 300 s) | supervisord | workers/s3-backup-worker.php → S3BackupService, LocalBackupService | Automatische S3-/lokale Backups | streichen |
| J7 | job-runner `complete_export` | `nohup` aus api_backup_job.php | workers/job-runner.php, background_jobs.php | Komplett-Export-ZIP | streichen |
| J8 | job-runner `chunked_restore` | `nohup` aus api_restore_upload.php | dto. | Restore | streichen |
| J9 | job-runner `notes_import` | `nohup` aus api_restore_upload.php | dto. + import_helpers.php | ZIP-Import einzelner Notizen | streichen (ADR 0009, kein Import in v1; Korrektur M0-Nachtrag) |
| J10 | job-runner `s3_backup` / `local_backup` | `nohup` aus Einstellungen | dto. | Manuelles Backup | streichen |
| J11 | poznoteJobCleanup | im Request | background_jobs.php:227 | Jobverzeichnisse >24 h löschen | streichen |
| J12 | maintenance/convert-base64-images.php | init.sh beim Start | maintenance/ | Base64-Bilder in Anhänge umwandeln | streichen (Altdaten-Migration, nur für Import relevant) |
| J13 | maintenance/repair-attachment-urls.php | init.sh beim Start | maintenance/ | Anhangs-URLs reparieren | streichen |
| J14 | maintenance/cleanup-orphan-snapshots.php | init.sh beim Start | maintenance/ | Verwaiste Snapshots löschen | ersetzen → `BackgroundJob\TimedJob` |
| J15 | poznoteExpireAllSnapshotsOccasionally | gelegentlich bei Aufruf von public/index.php:80 | lib/snapshots.php:221 | Snapshot-Aufbewahrung durchsetzen | ersetzen → `BackgroundJob\TimedJob` |
| J16 | purgeOldSnapshots | im Request | SnapshotsController.php:60 | Snapshots je Notiz begrenzen | übernehmen |
| J17 | pruneExpiredNoteEditLocks | bei jedem Sperr-Zugriff | users/db_master.php:867 | Abgelaufene Sperren löschen | übernehmen (+ optional `TimedJob`) |
| J18 | pruneActivityLogOccasionally | beim Loggen | ActivityLog.php:511 | Log kürzen | streichen |
| J19 | pruneOAuthTables | bei OAuth-Zugriff | users/oauth.php:125 | OAuth-Aufräumen | streichen |
| J20 | GitSync nach Speichern | PATCH /notes/{id} (`$triggerSync`) | GitSync.php | Push nach Git | streichen |

---

## 4. Dateispeicher

Basis `src/data/` (Docker: `/var/www/html/data`). Pfadauflösung über `$_SESSION['user_id']` in `lib/paths.php:65-87` und `users/UserDataManager.php`.

| # | Daten | Ort heute | Fundstelle | Status |
|---|---|---|---|---|
| F1 | Notizinhalt | `data/users/{id}/entries/{noteId}.html` bzw. `.md` (führend) + Kopie in `entries.entry` | functions.php:568, NotesController.php:1145-1148 | ersetzen → nur DB-Spalte (Text/Longtext) oder `IAppData` (ADR; Datei+DB doppelt vermeiden) |
| F2 | Anhänge | `data/users/{id}/attachments/{datei}`; Metadaten als JSON in `entries.attachments`; optional S3 | storage/AttachmentStorage.php, lib/attachments.php | ersetzen → `IAppData` (app-intern) oder `IRootFolder` (Nutzerdateien) – ADR |
| F3 | S3-Anhangsspeicher | Bucket, Präfix je Benutzer | storage/S3Client.php, AttachmentStorage.php:172 | streichen |
| F4 | Snapshots/Revisionen | `data/users/{id}/snapshots/{noteId}/{key}.html|md` + `.meta.json` | lib/snapshots.php:58, SnapshotsController.php:432 | ersetzen → `IAppData` oder Tabelle (ADR) |
| F5 | Excalidraw | Szene als escaptes JSON in einem versteckten `<div class="excalidraw-data">` im Notiz-HTML (F1); SVG-Vorschau als Anhang (F2) | lib/excalidraw-note-html.php, lib/excalidraw-preview.php, public/api_save_excalidraw.php | übernehmen (folgt F1/F2) |
| F6 | Exporte (ZIP/Einzeldatei) | `sys_get_temp_dir()` (Staging), Stream an Client | lib/export-staging.php:96 | übernehmen (NC-Temp über `ITempManager`) |
| F7 | Hintergrund-Jobs | `sys_get_temp_dir()/poznote_jobs/{userId}/{jobId}/` | background_jobs.php:35 | streichen |
| F8 | Backups | `data/users/{id}/backups/`, lokales Backupverzeichnis (global_settings), S3 | UserDataManager.php:61, LocalBackupService.php:65, S3BackupService.php | streichen |
| F9 | Hintergrundbilder | `data/users/{id}/backgrounds/{workspace}/` (nginx liefert direkt aus) | public/api_upload_background.php:37 | ersetzen → `IAppData` + Controller-Download |
| F10 | Eigenes CSS / Schriften / Logo | `data/css/`, `data/fonts/`, `data/branding/` | api_upload_css.php:27, font_catalog.php:21, lib/branding.php:27 | streichen (NC-Theming) |
| F11 | Datenbanken | `data/master.db`, `data/users/{id}/database/poznote.db` | db_connect.php, users/db_master.php | ersetzen → NC-Datenbank (`IDBConnection`) |
| F12 | PHP-Sessions | `data/sessions/` | lib/session.php:67 | ersetzen → NC-Session (`ISession`, nur falls nötig) |
| F13 | MCP-Service-Token | `data/.mcp_token` | auth.php:1807, docker/init.sh | streichen |

---

## 5. Auth/Session-Mechanik

| # | Mechanismus | Wo | Was es tut | Status |
|---|---|---|---|---|
| A1 | PHP-Session `POZNOTE_SESSION_{port}` | lib/session.php, auth.php | Login-Zustand; Schlüssel `user_id` (aktives Datenprofil, 102 Treffer), `login_user_id`/`login_user` (wer eingeloggt ist), `user`, `authenticated`, `auth_method`, `shared_workspace_scope`, diverse CSRF-/TOTP-/OAuth-/Git-Schlüssel. Insgesamt 326 `$_SESSION`-Zugriffe in 54 Dateien. | ersetzen → `IUserSession::getUser()->getUID()` |
| A2 | Formular-Login + Remember-me-Cookie | auth.php:1322 `authenticate()`, :81, :1394; public/login.php | Benutzername/Passwort gegen `master.users.password_hash` | streichen |
| A3 | Login-Rate-Limit | auth.php:1182-1304 (`login_attempts`) | Brute-Force-Schutz | streichen (NC-Login; für öffentliche Link-Passwörter ggf. `IThrottler`) |
| A4 | Auto-Login / Login deaktiviert | lib/auto-login.php, auth.php:655-689 | Ohne Credentials als Standardkonto | streichen |
| A5 | OIDC (Browser-Login + JWT-Bearer für API) | public/oidc*.php, auth.php:2092 | Externer IdP | streichen |
| A6 | TOTP + Recovery-Codes, Header `X-Poznote-OTP` | users/totp.php, lib/totp.php, auth.php:1415-1692, :2344 | 2FA | streichen |
| A7 | HTTP Basic Auth für API | auth.php:1875 `getApiBasicCredentials`, :2195 | API-Login mit Benutzer/Passwort | streichen (NC bietet Basic/App-Token selbst) |
| A8 | App-Passwörter | users/app_passwords.php, lib/app-passwords.php | Token pro Benutzer | streichen |
| A9 | MCP-Service-Token (Bearer) | auth.php:1807-1924 | MCP-Server handelt als Admin | streichen |
| A10 | OAuth-Server (Bearer für MCP) | users/oauth.php, lib/oauth-server.php, public/oauth/* | Token für Chat-Apps | streichen |
| A11 | Header `X-User-ID` | auth.php:2395 `requireApiAuth`, :2460, :2621; CORS `index.php:42` | Wählt Datenprofil; Admin-Credentials dürfen jedes Profil | streichen (Profil = eingeloggter NC-Benutzer) |
| A12 | Auth-Weiche je Pfad (`requireApiAuth`/`…User`/`…Admin`, öffentliche Pfade ohne Auth) | public/api/v1/index.php:51-102 | Bestimmt Prüfungsart vor Routing | ersetzen → Controller-Attribute `NoAdminRequired`/`PublicPage`/`NoCSRFRequired`, Admin über `AuthorizedAdminSetting` bzw. `IGroupManager::isAdmin` |
| A13 | Admin-Flag `users.is_admin` | auth.php:2578 `isCurrentUserAdmin` | Admin-Rechte | ersetzen → `IGroupManager::isAdmin()` |
| A14 | Account-Access / Kontowechsel | auth.php:323-507, public/switch_account.php, `user_account_access` | Login arbeitet in fremdem Profil | streichen |
| A15 | Shared-Workspace-Scope | auth.php:732-1036 | Grantee darf nur geteilten Workspace des Besitzers | ersetzen → Rechteprüfung je Request gegen Workspace-Freigabe (NC-UID/Team) |
| A16 | `X-Editor-Session-ID` | public/js/notes.js:196 u. a.; Notes::acquireLock | Kennung des Editor-Tabs für Sperren | übernehmen |
| A17 | Eigene CSRF-Tokens (`$_SESSION['csrf_token']` u. a.) | icon_sidebar.php, public/*settings*, admin/* | CSRF-Schutz Formulare | ersetzen → NC-CSRF (`requesttoken`-Header) |
| A18 | Öffentliche Links: Token + Passwort in Session | db_connect.php:20-82, public_note.php, share_passwords.php | Zugriff ohne Login | streichen (ADR 0011) |
| A19 | CORS `Access-Control-Allow-Origin: *` | public/api/v1/index.php:36-46 | Fremd-Origin-Clients | streichen |

---

## Zählung

| Bereich | übernehmen | ersetzen | streichen | Summe |
|---|---|---|---|---|
| 1.1 REST v1 | 87 | 25 | 73 | 185 |
| 1.2 Einzelskripte | 34 | 6 | 33 | 73 |
| 2 Tabellen | 6 | 4 | 18 | 28 |
| 3 Jobs | 2 | 3 | 15 | 20 |
| 4 Dateispeicher | 2 | 6 | 5 | 13 |
| 5 Auth | 1 | 5 | 13 | 19 |
| **Gesamt** | **132** | **49** | **157** | **338** |

Gezählt per `awk` über die letzte Spalte aller nummerierten Zeilen (#, T, J, F, A); Tabelle 2.4 (SQL-Konstrukte) ist nicht mitgezählt.

**Korrekturen M0 (Architekt, 2026-11-02):** #237 streichen → ersetzen (Abgleich mit Teil B #23, App-Speicher zählt nicht auf NC-Quota, ADR 0002); T20 ersetzen → streichen (ADR 0008); Legende, §2.1, T1–T3: Besitzer über `owner_type`/`owner_id` statt `user_id`; Z. 14 auf NC 35; Tabellenpräfix `oc_poznote_*` → `oc_nextnotes_*` (ADR 0001). M0-Nachtrag: J9 ersetzen → streichen (ADR 0009, kein Import); alle ADR-offenen Zeilen umgestellt: #129, #130 → ersetzen (Kompatibilitätsroute `[]`, ADR 0011), übrige → streichen (ADR 0010/0011).
