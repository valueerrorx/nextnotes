# M2 – Backend-Kern: Plan

Stand: 2026-11-02, Architekt, r2 (nach Review `docs/reviews/M2-plan-r1.md` und Lead-Entscheidungen). Grundlage: SPEC §2, §3, §4 (4.1, 4.2, 4.4, 4.5), §5, §6, §7, §8, §10, §11.2, §13 M2, §14; ADR 0002, 0003 (nur Rechteweg „Team“ als Platzhalter, Teams = M5), 0005, 0008, 0010, 0011, 0014; Inventur A §1.1 (Routen #1–184), §2–§5.
Keine Änderung in `src/`, kein Eintrag in §11.1. Upstream-Testsuite `tests/integration/` (Isolation) dient als Vorlage, wird nicht geändert.

## 0. Slice-Teilung

| Slice | Inhalt | Routen (Inventur A §1.1) |
|---|---|---|
| **M2a** | Nacharbeit M1, Grundgerüst (Db, AccessService, Fehler-Middleware, Sanitizer/Validierung, Workspace-Schlüssel), PHPUnit-Setup, Notizen-Kern, Ordner, Workspaces, Tags, Papierkorb | #2, 3, 21–23, 32–41, 43–45, 69–83, 85–91, 93–95, 100–109 (53, ohne #42, #92) |
| **M2b** | Anhänge, Snapshots, Suche, Sperre, indirekte Lesepfade, Duplizieren, Unified Search, `LockCleanupJob`, `SnapshotRetentionJob` | #1, 4–7, 20, 24, 28–31, 46, 50–54, 84, 110–114 (23) |
| **M2c** | Tasks, Mentions, Erinnerungen + `ReminderJob` + Notifier, Settings, System, Users, Konvertierung (501), `/shared*` | #8–19, 47–49, 55–64, 115–117, 124, 126–130, 142, 160 (36) |
| **M2d** | Kontolöschung (`UserDeletedEvent` → synchroner Listener + `UserDataDeletionJob`), Abschlusslauf M2 auf 3 DBs | – |

Summe 112 Routen = alle Router-Zeilen #1–184 mit Status übernehmen/ersetzen (SPEC §13 M2.1 entsprechend präzisiert). Jede Slice ist für sich lauffähig (`run-m2.sh` grün) und wird einzeln committet.

**Zuordnung SPEC §13 M2 → Slice**
| Krit. | Inhalt | M2a | M2b | M2c | M2d |
|---|---|---|---|---|---|
| 1 | Routen-Diff gegen `index.php` | Test + eigene Routen (Rest als „ausstehend“) | eigene Routen | vollständig, Liste „ausstehend“ leer | Wiederholung |
| 2 | Fremdzugriff 403/404, Listen ohne Fremddaten | Suite + eigene Routen | eigene Routen | eigene Routen | Gesamtlauf |
| 3 | Kern-Szenario 2 Benutzer + Upstream-Fixtures | Notiz/Ordner/Workspace/Tags/Papierkorb, Fixtures | Anhang, Snapshot, Suche → vollständig | – | Gesamtlauf |
| 4 | gleicher Workspace-Name | ✔ | | | |
| 5 | grep + 3 DBs | je Slice | je Slice | je Slice | Abschluss |
| 6 | Sperre mit TTL (gemockte Zeit) | | ✔ | | |
| 7 | `ReminderJob` / Unified Search | | Unified Search | `ReminderJob` | |
| 8 | Kontolöschung ohne UID-Reste | | | | ✔ |

## 1. Gemeinsame Architektur (alle Slices)

- **Schichten**: Controller (dünn, nur Parameter + Antwort) → Service (Regeln, Transaktionen) → `QBMapper`. Controller lesen Objekte **nur** über `AccessService::requireWorkspace(key|id, perm)`, `requireNote(id, perm)`, `requireFolder(id, perm)`; Listen filtern in SQL über `visibleWorkspaceIds(uid)`. `perm` = `read` | `write` | `manage`. Ohne Leserecht → `NotFoundException` (404), lesbar ohne Schreibrecht → `ForbiddenException` (403). Systempfade ohne Sitzung (Jobs, Notifier) nutzen `AccessService::canRead(uid, noteId)` mit expliziter UID.
- **Rechtewege in M2**: Besitzer (`owner_type=user`, `owner_id=uid`) und `ws_shares` (`share_type=user`, `permission=write`). Team-Weg (`owner_type=team`) liefert in M2 immer „kein Zugriff“ (Methode `teamLevel()` gibt 0; M5 ersetzt sie). Jeder andere `owner_type` (z. B. `deleted`, M2d) → kein Zugriff. `role ≠ note` (Vorlage/Abgabe, M6) → 404 für alle (deny by default). `kind=assignments` und Ordner mit `assignment_id` → `manage`/Löschen/Verschieben 403 (Regel schon jetzt, Daten erst M6).
- **UID** nur aus `IUserSession::getUser()->getUID()`; kein `$_SESSION`, kein `X-User-ID`, kein Admin-Sonderweg.
- **Fehlerformat**: `lib/Middleware/ApiExceptionMiddleware.php` (`registerMiddleware`, nur App) bildet **nur** eigene `Service\Exception\*` (und `AppFramework\Db\DoesNotExistException` → 404) auf Upstream-JSON ab: `{"success":false,"error":"…"}` (`src/lib/api-response.php:35`), Status 400/403/404/409/413/423. **Alle anderen Ausnahmen werden unverändert weitergeworfen** – App-Middleware sieht Kern-Ausnahmen zuerst (`DIContainer.php:201-241`, `MiddlewareDispatcher.php:89-98`), so bleiben NC-Antworten für CSRF (412), fehlende Anmeldung (401) usw. erhalten. Erfolg: `{"success":true, …}` wie `apiSuccess`.
- **Zeit** nur aus `ITimeFactory::getTime()` (Konstruktorinjektion) → in Tests mockbar. Keine DB-Zeitfunktionen.
- **SQL**: nur `IQueryBuilder`/`QBMapper`; `LIKE` mit `escapeLikeParameter`; Groß/Klein über gespeicherte normalisierte Spalten (`search_text`) bzw. `$qb->func()->lower()`. Idempotente Einfügungen mit Unique-Index (Tags `nn_tag_uniq`, Mentions `nn_men_uniq`) über `IDBConnection::insertIgnoreConflict` (16; transaktionssicher auch auf PostgreSQL). Sperre: bedingtes UPDATE, sonst INSERT **außerhalb** offener Transaktionen, Unique-Verletzung (`DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION`) → 409.
- **Kaskaden und Dateien**: Workspace → Ordner → Notizen → Tags/Anhänge/Snapshots/Reminder/Locks/Mentions im Service in einer Transaktion (`TTransactional::atomic`). Zu löschende `IAppData`-Ordner sammelt ein request-weiter `Service/FileCleanupQueue`; abgearbeitet erst nach dem **äußersten** Commit (verschachtelte Aufrufe, z. B. `UserDataService` → `NoteService::purge`, hängen nur an); bei Rollback wird die Liste verworfen.
- **Workspace-Schlüssel (§4.4)**: `WorkspaceService::keyFor(uid, ws)` / `resolveKey(uid, key)`. Eigene: Name; geteilte: Name, bei Doppelung in der sichtbaren Menge `"<Name> (<Anzeigename Besitzer>)"`, bleibt es doppelt (gleicher Name und gleicher Anzeigename) `"<Name> (<Anzeigename Besitzer>, #<id>)"` – deterministisch, nie dauerhaft 409. `resolveKey` akzeptiert zusätzlich immer die Langform mit `#<id>`. Upstream-Namensregel `^[\p{L}0-9 _-]+$` (`WorkspacesController.php:119`) gilt → Klammern kommen in Namen nie vor. Unbekannter Schlüssel → 404. `?workspace=` aller Listen läuft durch `resolveKey`.
- **Standard-Workspace** (Thomas, O5): `WorkspaceService::ensureDefault(uid)` legt beim ersten API-Zugriff ohne eigenen Workspace „Notizen“ an (über `IL10N` in der Benutzersprache beim Anlegen, danach normaler Name), keine Willkommensnotiz. Upstream-„Archives“ (`functions.php:722`): Archiv-Workspace mit gleichem Besitzer wie die Quelle, bei Bedarf angelegt. **Wettlauf** (kein Unique-Index möglich, Schüler-Workspaces dürfen gleiche Namen haben): nach dem Insert erneut lesen (gleicher Besitzer, gleicher Name, `kind=normal`); bei mehr als einer Zeile gewinnt die kleinste ID, die eigene neue Zeile wird gelöscht.
- **Inhalt und Eingaben**: `HtmlSanitizer` (Port der Regeln aus `src/lib/html-sanitize.php`, `sanitizeHtml`/`sanitizeMarkdownContent`, Herkunft MIT im Kopf, kein `require` von Upstream-Dateien) beim Speichern wie `NotesController::sanitizeContentForType` (:3351). `InputValidator` prüft serverseitig wie Upstream: Farben (`color`, `icon_color`, `title_color`; Format/Palette wie `src/lib/note-colors.php`), `icon` (Whitelist wie Upstream-Iconliste), Namen von Ordnern/Workspaces/Tags (Länge, Regel wie Upstream, Tags wie `sanitizeTags`); Verstoß → 400. `search_text` = Titel + Klartext, klein, ohne Akzente (`SearchTextNormalizer`); `ContentUrlRewriter` (§10) in `GET /notes/{id}` (toClient) und beim Speichern (toServer).
- **Zuletzt geöffnet**: `POST /notes/{id}/opened` speichert Notiz-IDs in `IUserConfig`; beim Ausliefern wird die Liste über `AccessService` gefiltert (Notizen ohne Leserecht fallen weg).
- **Attribute (§4.5)**: alle API-Methoden `#[NoAdminRequired]`; CSRF aktiv auf allen Methoden außer der Allowlist `#[NoCSRFRequired]` (in M2 nur GET-Download Anhang); `#[UserRateLimit]` auf Upload. Routen in `appinfo/routes.php` in Upstream-Reihenfolge (`index.php`), Präfix `/api/v1`, Requirements `{id}`,`{noteId}`,`{attachmentId}` `\d+`; `{name}` `.+` (SPEC §4.1 angepasst); übrige `[^/]+`.
- **Migration**: M2 braucht keine Schemaänderung (`owner_type=deleted` passt in `string(8)`). Falls doch: neuer Schritt `Version000200Date…`, nie `Version000100…` ändern; Tester passt `expected-*.json` an.
- Jede neue Datei: SPDX-Kopf; Kommentare einzeilig.

## 2. Slices

### M2a – Grundgerüst, Notizen-Kern, Ordner, Workspaces, Tags, Papierkorb

**Nacharbeit M1**
- `nextnotes/img/app.svg`, `app-dark.svg`: SPDX-Kommentar als erstes Kind in `<svg>` (Datei beginnt mit `<svg`).
- `dev/nextcloud/compose.yaml`: `nginx:1.30.6-alpine` (lokal getestete Version), dazu `mariadb:11.4.13`, `postgres:17.11-alpine`; README nennt die getesteten Versionen.
- `dev/nextcloud/down.sh:14`: `svcs="$(dc … config --services)"` (Fehler bricht ab), dann `mapfile -t svcs <<<"$svcs"`.
- `dev/nextcloud/run-m1.sh:4` + `README.md:20`: „KEEP=1 lässt bei Fehlschlag die Umgebung stehen“.

**App**
- `appinfo/routes.php`: Routen M2a (Tabelle §0).
- `lib/AppInfo/Application.php`: `registerMiddleware(ApiExceptionMiddleware::class)`.
- `lib/Db/`: `Workspace`, `WsShare`, `Folder`, `Note`, `NoteTag` + je `…Mapper` (QBMapper).
- `lib/Service/`: `AccessService`, `WorkspaceService` (inkl. `share`/`unshare` nur als Service, UI-Weg M3/M4), `FolderService`, `NoteService`, `TagService`, `TrashService`, `HtmlSanitizer`, `InputValidator`, `SearchTextNormalizer`, `ContentUrlRewriter`, `FileCleanupQueue`, `NoteSerializer` (Upstream-JSON inkl. `folder`, `tags`, `reminder_at`; kein Feld `attachments`, Upstream liefert es in `GET /notes/{id}` nicht), `Exception/{NotFound,Forbidden,Conflict,BadRequest,Locked,TooLarge}Exception`.
- `lib/Middleware/ApiExceptionMiddleware.php`.
- `lib/Controller/`: `NotesApiController`, `FoldersApiController`, `WorkspacesApiController`, `TagsApiController`, `TrashApiController`; gemeinsame Basis `ApiController` (UID, JSON-Body lesen wie Upstream).
- Endgültiges Löschen über einen zentralen `NoteService::purge(ids)`, den M2b um Anhänge/Snapshots, M2c um Reminder erweitert.

**Testsetup** (Architekt): `dev/nextcloud/run-phpunit.sh`, `dev/nextcloud/run-m2.sh`, `dev/nextcloud/check-forbidden-sql.sh`, `dev/upstream/` (§3). Tester: `tests/nextnotes/` (§3).

**Done M2a**
1. Nacharbeit: `head -c4 nextnotes/img/app.svg` = `<svg`; `curl -u alice … /index.php/apps/theming/favicon/nextnotes` → 200 `image/*`; `grep -n "stable" dev/nextcloud/compose.yaml` leer; `down.sh sqlite` mit `docker`-Attrappe, die bei `config --services` Exit 1 liefert → Exit ≠ 0; `run-m1.sh sqlite` → 0 (Regression).
2. Routen-Diff (`RouteDiffTest`): Menge (Verb, Pfad) aus `routes.php` (Präfix `/api/v1` entfernt) = Router-Zeilen aus `src/public/api/v1/index.php` minus Inventur-A-Zeilen „streichen“ minus Liste „ausstehend“ (Inventurnummern M2b/M2c) → keine Differenz in beide Richtungen.
3. Isolation (§3.3) für alle M2a-Routen grün, inklusive aller Body-/Query-ID-Varianten (z. B. `POST /notes {workspace:<A>}`, `{folder_id:<A>}`; `POST /notes/{B}/folder {folder_id:<A>}`; `PATCH /notes/{B}` mit `folder_id`/`linked_note_id` von A; `POST /folders {parent_id:<A>}`; `/folders/{B}/move` nach A; `/folders/move-files`; `/notes/reorder`; `/folders/reorder`; `/folders/restore`): B → 403/404, kein A-Marker, A-Zustand vorher = nachher; Kontrollanfrage von A 2xx; Listen von B (auch mit `?workspace=<A-Schlüssel>`, `folder_id=<A>`) ohne A-Marker.
4. Szenario-Teil (`CoreScenarioTest`, A und B parallel): Notiz anlegen/ändern (ETag/`If-None-Match` 304 wie Upstream)/Papierkorb/wiederherstellen/endgültig löschen, Ordner anlegen/verschieben/umbenennen/löschen, Workspace anlegen/umbenennen/löschen, Tags setzen/umbenennen/löschen; erster Zugriff legt genau einen Workspace „Notizen“ an (auch bei zwei parallelen Erstzugriffen); JSON-Schlüssel = Upstream-Fixtures (§3.4).
5. Krit. 4: A und B legen je Workspace „Schule“ an → beide 200; jeder sieht genau einen; Umbenennen/Löschen bei A ändert B nicht. Schlüssel-Fallback: B hat Freigaben von zwei Benutzern mit gleichem Anzeigenamen und gleichem Workspace-Namen → beide über `… #<id>)` erreichbar, keine 409.
6. Sicherheitsantworten bleiben NC-Standard: ohne Anmeldung `GET /api/v1/notes` (Header `Accept: application/json`) → 401; Cookie-Sitzung (Formular-Login) `GET` und `POST /api/v1/notes` ohne `requesttoken` → 412; mit Header `requesttoken` → 200.
7. Attribute (`ControllerAttributeTest`, Reflection über alle öffentlichen Methoden in `lib/Controller/*ApiController`): jede hat `NoAdminRequired`; `NoCSRFRequired` genau auf der Allowlist (M2a: keine; M2b: Anhang-GET); kein `PublicPage`.
8. XSS/Validierung: `PATCH /notes/{id}` mit `<script>`, `onerror=`, `javascript:` → gespeichert/ausgeliefert ohne diese; Fälle aus Upstream `tests/html-sanitize.test.php` als Datensatz grün; ungültige Farbe/Icon/Ordner-, Workspace-, Tag-Name (z. B. `"><img onerror…`) → 400, nichts gespeichert.
9. Deny by default: per Mapper eingefügte Notiz `role=template` in A's Workspace → A erhält 404. `opened`-Liste nach Entzug einer Freigabe ohne die fremde Notiz.
10. `run-m2.sh` prüft vor den Tests `occ config:system:get mysql.utf8mb4` = `true` (MariaDB; NC legt Tabellen mit `utf8mb4_bin` an, `ConnectionFactory.php:233`); `check-forbidden-sql.sh` → 0 Treffer; `run-m2.sh` (sqlite, mariadb, pgsql) Exit 0.

### M2b – Anhänge, Snapshots, Suche, Sperre, Unified Search

**App**
- `routes.php`: Routen M2b. `info.xml`: `<background-jobs>` `LockCleanupJob`, `SnapshotRetentionJob`.
- `lib/Db/`: `Attachment`, `Snapshot`, `Lock` + Mapper.
- `lib/Service/`: `AttachmentService` (`IAppData` `attachments/<note_id>/<id>`, Quota aus `IAppConfig` mit Defaults 20 MB/Datei, 500 MB/Benutzer → 413), `SnapshotService` (Upstream-Grenzen aus `SnapshotsController.php:60`, `lib/snapshots.php:221`), `SearchService` (`/notes/search`, `/search/ids`, gemeinsam mit Unified Search), `LockService` (s. u.), `LinkService` (resolve, backlinks, graph – über `AccessService` gefiltert), `ChangesService` (`/changes`), Duplizieren von Notiz/Ordner inkl. physischer Anhangskopie; `NoteService::purge` löscht Anhänge (über `FileCleanupQueue`) und Snapshots.
- **`LockService` nach ADR 0005**: Sperre gilt, wenn `expires_at > now`. Erwerb = bedingtes `UPDATE … WHERE note_id=? AND (expires_at <= now OR holder_session = ?)`, sonst `INSERT`; Unique-Verletzung → **409** mit Upstream-`lock`-Payload. Übernahme nur durch dieselbe Sitzung (`X-Editor-Session-ID`); dasselbe Konto aus einer anderen Sitzung erhält 409; Upstream-Parameter `takeover` wird ignoriert. TTL 90 s; Heartbeat/Release nur durch die haltende Sitzung. Übernahme, Heartbeat und Release zusätzlich an `holder_uid = uid` gebunden (Review r2; eine fremde UID mit gleicher Sitzungs-ID erhält 409). Speichern (`PATCH /notes/{id}`, Beacon) mit gültiger Sperre einer anderen Sitzung → 423; ohne Sitzungs-ID (Skript, Einmal-Schreiber) blockiert nur die Sperre eines anderen Kontos; mit Sitzungs-ID ohne bestehende Sperre wird die Sperre beim Speichern erworben (wie Upstream `validateCurrentNoteEditLock`); Versionskonflikt (`updated_at`/ETag) → 409. Andere Schreibrouten (Tasks, Tags …) prüfen die Sperre nicht (wie ADR 0005: nur Speichern). Abweichung von Upstream als §11.2 B13 gelistet.
- `lib/Controller/`: `AttachmentsApiController` (GET `#[NoCSRFRequired]`, `FileDisplayResponse`; Auslieferung (wie Upstream, verschärft): inline nur PDF, `image/*` außer SVG, `audio/*`, `video/*` und passive Textformate (`txt json csv xml md markdown log ini cfg conf toml sql yml yaml`, ausgeliefert als `text/plain`/`application/json`/`text/csv` mit `charset=utf-8`); SVG (MIME oder Endung `svg`/`svgz`), HTML und alles andere immer `attachment`; `?download=1` → `application/octet-stream` + `attachment`. `X-Content-Type-Options: nosniff` auf jeder Antwort. CSP `default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox` auf allen Anhängen außer PDF; PDF behält die NC-Standard-CSP, weil Browser-PDF-Viewer unter `sandbox` nicht rendern. MIME-Typ kommt aus `IMimeTypeDetector::detectContent` (Server), nicht vom Client; Upload `#[UserRateLimit]`), `SnapshotsApiController`, `GraphApiController`, Ergänzungen `NotesApiController`.
- `lib/Search/NotesProvider.php` (`IProvider`; Link `…/index.php?note=<id>&workspace=<key>`), Registrierung `registerSearchProvider`.
- `lib/BackgroundJob/LockCleanupJob.php` (3600 s), `SnapshotRetentionJob.php` (86400 s). Der Job dünnt je Notiz mit der Benutzereinstellung `snapshots_keep_count` des Workspace-Besitzers aus (Team-Workspaces und Standard: 30); dieselbe Regel gilt beim Anlegen.

**Done M2b**
1. Routen-Diff mit verkürzter Liste „ausstehend“ grün; Isolation für alle M2a+M2b-Routen grün inkl. Body-Varianten (Anhang-`move` von B-Notiz in A-Notiz und umgekehrt, `/notes/resolve?title=<A-Titel>`, Backlinks/Graph/`/changes` ohne A-Marker).
2. Szenario vollständig (Krit. 3): + Anhang hoch/runter (Bytes identisch) und Header je Typ: PNG → `inline`, Typ `image/png`, CSP mit `sandbox`; PDF → `inline`, Typ `application/pdf`, keine `sandbox`-CSP; SVG → `attachment`, CSP mit `sandbox`; HTML → `attachment`, CSP mit `sandbox`; `.txt` → `inline`, `text/plain; charset=utf-8`, CSP mit `sandbox`; überall `nosniff`, CSP (wo gesetzt) mit `frame-ancestors 'self'`; `?download=1` → `attachment` + `application/octet-stream`, Snapshot anlegen/listen/wiederherstellen, Suche findet eigene Notiz per Wort mit Akzent/Großschreibung, nicht die von B; JSON-Schlüssel = Fixtures.
3. Krit. 6 (`LockServiceTest`, `ITimeFactory`-Mock): Sitzung 1 (A) hält Sperre; Sitzung 2 – einmal derselbe Benutzer A, einmal B mit Schreibrecht per `ws_shares` – erhält 409; Speichern aus Sitzung 2 → 423; Sitzung 1 erneuert/speichert ohne Konflikt; Zeit + 91 s → Sitzung 2 erhält die Sperre. HTTP-Gegenprobe ohne Zeitmock: 409 beim Erwerb, 423 beim Speichern.
4. Krit. 7 Teil Suche: `NotesProvider::search(B, "<A-Marker>")` → 0 Einträge; `(A, …)` → A's Notiz, nicht im Papierkorb; Paginierung über Cursor; OCS `GET /ocs/v2.php/search/providers/nextnotes/search?term=…` als B ohne A-Marker.
5. Endgültiges Löschen einer Notiz entfernt `IAppData`-Ordner `attachments/<id>` (nach Commit; bei erzwungenem Rollback bleibt die Datei) und Snapshot-Zeilen; beide Jobs laufen per `start()` fehlerfrei und löschen nur Abgelaufenes.
6. `ControllerAttributeTest` mit Allowlist Anhang-GET grün; `check-forbidden-sql.sh` 0 Treffer; `run-m2.sh` Exit 0.

### M2c – Tasks, Mentions, Erinnerungen, Settings, System, Users

**App**
- `routes.php`: Routen M2c → Liste „ausstehend“ leer. `info.xml`: `ReminderJob`.
- `lib/Db/`: `Reminder`, `MentionHandled` + Mapper.
- `lib/Service/`: `TaskService` (Tasks/Subtasks im Tasklist-Inhalt), `MentionService` (Erwähnungen nur in Workspaces mit `ws_shares`), `ReminderService`, `SettingsService` (Whitelist der Upstream-Schlüssel aus `SettingsController.php` ohne gestrichene Funktionen → `IUserConfig`). `WorkspaceService::unshare` löscht Erinnerungen und Mention-Status des Empfängers zu Notizen dieses Workspace.
- `lib/Controller/`: `TasksApiController`, `MentionsApiController` (`{ownerId}` wird angenommen und ignoriert; Zugriff nur über `requireNote`; `/mentions/members` = Besitzer + Freigabeempfänger, SPEC §4.2 angepasst), `RemindersApiController` (`/reminders*` Kompatibilität: `[]`, `0`, `success`), `SettingsApiController`, `SystemApiController` (Version aus `IAppManager::getAppVersion`; `/shared`, `/shared/with-me` → `[]`; whats-new → `show:false`, `releases:[]`, `/seen` ohne Wirkung (B12); i18n s. O4), `UsersApiController` (`/users/me` aus `IUser`; `/users/lookup/{username}` über `ISearch::filteredSearch`), Konvertierung #47–49: Rechteprüfung wie Upstream (`requireNote(id, write)` für `/notes/{id}/convert`), dann 501 `{"success":false,"error":"not_implemented"}` (SPEC §1.2.5: Konvertierung mit Export in M4).
- `lib/Notification/Notifier.php` (`INotifier`, Subjects `reminder`, `task_reminder`; unbekannt → `UnknownNotificationException`). `prepare` prüft `AccessService::canRead(empfänger, noteId)`; ohne Leserecht oder Notiz weg → `AlreadyProcessedException` (17; NC entfernt die Benachrichtigung). Registrierung `registerNotifierService`; `NoteService::purge` ruft `markProcessed` für Objekt `note/<id>`.
- `lib/BackgroundJob/ReminderJob.php` (300 s): fällig = `sent_at IS NULL AND trigger_at <= now`; Empfänger ohne Leserecht → Zeile löschen, nicht senden; sonst bedingtes `UPDATE … SET sent_at WHERE id=? AND sent_at IS NULL` vor `notify` (kein Doppelversand bei parallelen Läufen); Wiederholung → nächste Zeile.

**Done M2c**
1. Krit. 1: `RouteDiffTest` mit leerer Liste „ausstehend“ → keine Differenz; `/shared`, `/shared/with-me` → 200 `[]`.
2. Isolation für alle 112 Routen grün; Routen ohne Objekt-ID von A (`/settings/{key}`, `/users/lookup/{username}`, `/tags/{tag}`, `/mentions/members`) als SCOPED/EXCLUDED mit Begründung in der Tabelle; Settings von B ändern A's Wert nicht.
3. Krit. 7 Teil Reminder (`ReminderJobTest`, Zeitmock): fällige Erinnerung → nach Joblauf genau 1 Zeile in `oc_notifications` (app `nextnotes`, object `note`/id, Empfänger); zweiter Lauf → weiterhin 1; nicht fällige → 0; Erinnerung auf Notiz ohne Leserecht kann B nicht anlegen (404). `Notifier::prepare` liefert Betreff de/en und Link auf die Notiz. **Freigabe-Entzug**: B hat Erinnerung auf Notiz in A's geteiltem Workspace (eine schon gesendet, eine fällig) → `unshare` → fällige Zeile weg, Joblauf sendet nichts; `prepare` der gesendeten wirft `AlreadyProcessedException` (Anzeige leer); dasselbe, wenn die Freigabe per Mapper ohne `unshare` entfernt wird (Job prüft selbst).
4. Tasks: anlegen/ändern/löschen inkl. Subtasks auf eigener Tasklist; JSON-Schlüssel = Fixtures. `POST /convert-html` → 501; `/notes/{A}/convert` als B → 404.
5. `check-forbidden-sql.sh` 0 Treffer; `run-m2.sh` Exit 0.

### M2d – Kontolöschung, Abschluss

**App**
- `lib/Listener/UserDeletedListener.php` (`IEventListener<UserDeletedEvent>`, `getUid` @since 31), Registrierung `registerEventListener`. **Synchron** (in einer Transaktion, nur Einzel-Statements ohne Dateizugriff) – damit eine neu angelegte gleiche UID vor dem Joblauf nichts erbt (SPEC §2 UID-Regel, §5 „keine Logik hängt an Cron“):
  - eigene Workspaces `owner_type=user, owner_id=uid` → `owner_type=deleted` (kein Rechteweg, §1), ihre IDs merken;
  - `ws_shares` mit `share_with=uid` oder `created_by=uid` löschen; `reminders.user_id`, `locks.holder_uid`, `mentions_handled.user_id` = uid löschen;
  - `workspaces.created_by`, `notes.created_by/updated_by`, `attachments.created_by`, `snapshots.created_by` = uid → NULL;
  - `IJobList::add(UserDataDeletionJob::class, ['workspace_ids' => […]])`.
- `lib/BackgroundJob/UserDataDeletionJob.php` (`QueuedJob`) → `Service/UserDataService::purgeWorkspaces(ids)`: löscht nur Workspaces mit `owner_type=deleted` aus der Liste samt Inhalt (über `NoteService::purge`, `FileCleanupQueue`, `IAppData`); arbeitet nie über die UID → berührt Daten einer neu angelegten gleichen UID nicht; idempotent.
- Abgaben/Aufgaben/Team-Teile (§8): Erweiterungspunkte im Listener/Service für M5/M6, in M2 leer.

**Done M2d**
1. Krit. 8 (`UserDeletionTest`): Benutzer C mit eigenem Workspace (Notizen, Ordner, Tags, Anhang, Snapshot, Reminder, Sperre, Mention-Status), Freigabe von C an B und von A an C, Notiz von C in A's geteiltem Workspace; Löschung über `IUserManager` + Joblauf → geprüft werden genau die UID-Spalten: `workspaces.owner_id` (bei `owner_type=user`), `workspaces.created_by`, `ws_shares.share_with`, `ws_shares.created_by`, `notes.created_by`, `notes.updated_by`, `attachments.created_by`, `snapshots.created_by`, `reminders.user_id`, `locks.holder_uid`, `mentions_handled.user_id` → kein Wert = C; keine Zeile `owner_type=deleted` mehr; `IAppData` ohne C's Anhangsdateien. Nicht geprüft: Inhalts-/Namensspalten (`content`, `search_text`, `heading`, Namen) – Nutzerinhalt, keine Rechtewirkung. A's Workspace und die von C verfasste Notiz bleiben mit `created_by` NULL. Job zweimal → kein Fehler.
2. UID-Wiederverwendung **vor** Joblauf: C löschen, sofort C neu anlegen (Job nicht gelaufen) → `GET /workspaces` liefert nur den neuen Standard-Workspace, `GET /notes` keine alten Notizen, A's (früher an C freigegebener) Workspace → 404, alte Erinnerungen erzeugen keine Benachrichtigung; danach Joblauf → neuer C behält seinen Workspace und seine Daten unverändert.
3. Gesamtlauf: `run-m2.sh` (sqlite, mariadb, pgsql) mit allen Tests Exit 0; `check-forbidden-sql.sh` 0 Treffer; `run-m1.sh` Exit 0 (Regression).

## 3. Testsetup

### 3.1 PHPUnit gegen NC 35 im Image
- Image `nextcloud:35.0.2-apache` enthält kein `tests/`, kein PHPUnit, kein Composer (geprüft). Daher: **PHPUnit 12.5 per Composer nur dev** in `tests/nextnotes/composer.json` (+ `composer.lock` eingecheckt, `vendor/` per `tests/nextnotes/.gitignore` ignoriert), Installation auf dem Host (`composer install -d tests/nextnotes`, PHP 8.5; einmaliger Netzzugriff freigegeben, Lead). Keine Composer-Abhängigkeit in `nextnotes/`. NC-3rdparty enthält keine PHPUnit-Abhängigkeiten (kein `nikic`, `sebastian`, `myclabs`, `phar-io`) → keine Autoload-Konflikte.
- `tests/nextnotes/bootstrap.php`: `define('PHPUNIT_RUN', 1)`; `require '/var/www/html/lib/base.php'`; `Server::get(IAppManager::class)->loadApp('nextnotes')`; `vendor/autoload.php`; PSR-4 `OCA\NextNotes\Tests\` → `tests/nextnotes/php/`. `PHPUNIT_RUN` schaltet NC auf `ArrayCache` (`lib/private/Server.php:562`) – gewollt.
- Ausführung **im Container** als `www-data` gegen die echte Instanz-DB (damit 3 DBs): `dev/nextcloud/run-phpunit.sh <env> [phpunit-args]` kopiert per `tar` `tests/nextnotes/`, `src/public/api/v1/index.php`, `docs/inventory/A-backend.md` nach `/tmp/nn-tests` (Variable `NN_REF_DIR`), aktiviert `notifications`, wartet bis die Web-Instanz die App kennt (APCu-Verzug wie M1a), ruft `php /tmp/nn-tests/vendor/bin/phpunit -c /tmp/nn-tests/phpunit.xml`, gibt dessen Exit-Code zurück.
- HTTP-Tests im selben Container gegen `http://localhost` (Apache, Trusted Domain `localhost`), daher nur Apache-Umgebungen sqlite/mariadb/pgsql; nginx bleibt M1-Regression.
- `dev/nextcloud/run-m2.sh [env…]` (Standard: sqlite mariadb pgsql): `validate-info.sh` → `check-forbidden-sql.sh` → je Umgebung `down` → `up` → `install-app` → `app:enable nextnotes` → bei mariadb Prüfung `mysql.utf8mb4` = true → `run-phpunit.sh` → `down`; Exit 0 nur wenn alles grün; `KEEP=1` lässt bei Fehlschlag stehen.
- `dev/nextcloud/check-forbidden-sql.sh`: SPEC-Muster `grep -rnE 'datetime\(|COLLATE|INSERT OR|REPLACE INTO|PRAGMA|ON CONFLICT|\$_SESSION|X-User-ID' nextnotes/lib` plus Roh-SQL `->(prepare|executeQuery|executeStatement)\(\s*['"]` → Exit 1 bei Treffer; Selbsttest mit Probedatei in `mktemp`.

### 3.2 Benutzer, Zeit, Jobs
- Tests legen eigene Benutzer `nn-<rolle>-<zufall>` per `IUserManager::createUser` an und löschen sie im `tearDown`; HTTP mit Basic-Auth über App-Token (`occ user:auth-tokens:add`, `core/register_command.php:247`; Fallback Passwort) und Header `OCS-APIRequest: true` (NC: `Request::passesCSRFCheck` Z. 436, ohne Cookie kein Strict-Cookie-Zwang Z. 458–467). CSRF selbst prüft `CsrfTest` mit echter Cookie-Sitzung (Done M2a 6).
- Zeit: Services mit `ITimeFactory`-Mock direkt instanziieren (Rest aus dem App-Container), kein Zeitmock über HTTP.
- Jobs: Instanz aus dem App-Container, `start(Server::get(IJobList::class))`.

### 3.3 Isolation (Krit. 2, systematisch)
Port der Upstream-Suite `tests/integration/isolation.test.php` + `fixtures.php` (README dort) nach PHPUnit `tests/nextnotes/php/Access/IsolationTest.php`:
- Routenquelle = `nextnotes/appinfo/routes.php` (nur `/api/v1`). `routeTable()` klassifiziert jede Route als `OWNED`, `SCOPED` (keine Objekt-ID, darf nur B's Daten liefern) oder `EXCLUDED` (mit Begründung). Route ohne Eintrag → rot.
- **Parameterkarte je Route** deklariert alle ID-Stellen: Pfad (`{id}` je Präfix Notiz/Ordner, `{noteId}`, `{attachmentId}`, `{taskId}`, `{subtaskId}`, `{name}`), Query (`workspace`, `folder_id`, `note_id` …) und Body-Felder (`workspace`, `folder_id`, `parent_id`, `target_folder_id`, `linked_note_id`, `note_ids[]`, `folder_ids[]`, `target_note_id` …) mit Objekttyp, dazu eine `body`-Vorlage, die ohne Rechteprüfung gelingen würde. Schreibroute ohne Deklaration (auch „keine Body-IDs“ muss explizit `[]` sein) → rot.
- **Generator**: je Route eine Variante „alle IDs von A“ und **je ID-Stelle eine Variante, in der nur diese A gehört und alle übrigen B** (Quelle B → Ziel A und Quelle A → Ziel B). Erwartung je Variante: B → Status ∈ {403, 404} **und** kein A-Marker im Body; Kontrollanfrage (gleiche Variante mit A's Objekten durch A bzw. B's durch B) → 2xx (sonst „inconclusive“ = rot); Dump von A's Daten vorher = nachher. SCOPED-Routen zusätzlich mit `?workspace=<A-Schlüssel>`; Detektor-Selbsttest (künstliches Leck/Erfolg muss erkannt werden).
- Upstream-Teile `X-User-ID`, Share-Tokens, Backups entfallen (gestrichen).

### 3.4 Upstream-Fixtures (Krit. 3)
- `dev/upstream/compose.yaml`, `up.sh`, `down.sh` (Architekt): Upstream-Poznote aus dem Repo-`Dockerfile` (Stand `UPSTREAM.md`), nur Tests, Port 8090. Einmaliger Netzzugriff für den Build freigegeben (Lead).
- Tester: `tests/nextnotes/fixtures/capture.php` legt per Upstream-Admin-API zwei Konten an, spielt das Kern-Szenario ab und speichert je Route den Schlüsselbaum (Werte → Typname) unter `tests/nextnotes/fixtures/upstream/*.json` – **eingecheckt**, spätere Läufe offline. Vergleich: Objektschlüssel rekursiv gleich; bewusste Abweichungen in `fixtures/allowed-diff.json` mit Grund (z. B. `offline`, ADR 0015).

## 4. OCP-Klassen (Nachweis `.ref/nextcloud-server/lib/public/`, 35.0.2)
- Neu in M2: `AppFramework/Middleware.php` (6), `IRegistrationContext::registerMiddleware` (20), `registerSearchProvider` (20), `registerEventListener` (20), `registerNotifierService` (22); `AppFramework/Db/QBMapper` (14), `Entity` (7; nur `slugify` deprecated, nicht genutzt), `TTransactional` (24), `DoesNotExistException` (7); `DB/Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION` (21); `IDBConnection::insertIgnoreConflict` (16), `escapeLikeParameter`; `AppFramework/Utility/ITimeFactory::getTime` (8); `Files/IAppData` (11, per App-DI `DIContainer.php:119`), `SimpleFS/ISimpleFolder`/`ISimpleFile` (11); `Http/FileDisplayResponse` (11, akzeptiert `ISimpleFile`), `JSONResponse` (6), `Attribute/UserRateLimit` (27); `Config/IUserConfig` (32), `IAppConfig` (7); `App/IAppManager::getAppVersion` (8); `Collaboration/Collaborators/ISearch::filteredSearch` (**35.0.0**); `Search/IProvider`, `ISearchQuery`, `SearchResult`, `SearchResultEntry` (20); `Notification/IManager::createNotification` (17), `IApp::notify`/`markProcessed` (9), `INotifier` (17), `UnknownNotificationException` (30), `AlreadyProcessedException` (17); `BackgroundJob/TimedJob` (15; `setInterval`, `setTimeSensitivity`), `QueuedJob` (15), `IJobList::add` (7); `EventDispatcher/IEventListener` (17); `User/Events/UserDeletedEvent` (18), `getUid` (**31**); `IL10N` (6); `IUserManager::createUser` (nur Tests).
- Gemieden (deprecated): `ISearch::search` (35 → `filteredSearch`), `IUserManager::search` (27), `IDBConnection::lastInsertId`/`insertIfNotExist`/`executeUpdate`, `IJobList::getJobs` (26), `IAppManager::isInstalled` (32), `Notification\IManager::registerNotifierService` (22).

## 5. Entscheidungen und SPEC-/ADR-Änderungen (r2)
Lead-Entscheidungen zu r1 und die dafür geänderten Stellen (vom Architekten direkt eingearbeitet):
| # | Entscheidung | Geändert |
|---|---|---|
| O1 | Krit. 1 = Router-Zeilen `api/v1/index.php` (übernehmen/ersetzen); Kompat-/Seiten-/Kalender-Routen in M3/M4 | SPEC §13 M2.1 |
| O2 | Konvertierung #47–49 in M2: Rechteprüfung + 501; Umsetzung mit Export in M4 (dort Wahl Upstream-Parser-Port vs. league) | SPEC §1.2 Punkt 5 |
| O3 | whats-new ohne GitHub-Abruf, `show:false`; GET ohne Seiteneffekt | SPEC §11.2 B12 |
| O5 | Standard-Workspace „Notizen“, keine Willkommensnotiz (Thomas) | SPEC §2.1 `nextnotes_workspaces` |
| O8 | `/mentions/members` aus Workspace-Mitgliedschaft; `ISearch::filteredSearch` nur für `/users/lookup` | SPEC §4.2, §14 |
| O9 | Sperre nach ADR 0005 (409, Übernahme nur dieselbe Sitzung, Speichern gegen fremde Sitzung 423); Upstream „gleiches Konto aus jeder Sitzung“ = Bug | SPEC §11.2 B13; ADR 0005 unverändert; §13 M2.6 unverändert |
| §4.1 | `{name}` `.+` | SPEC §4.1 |
| Hinweis | Workspace-Schlüssel deterministisch mit `#<id>`, unbekannt → 404 | SPEC §4.4 |
| §14 | `filteredSearch` (35), `UserDeletedEvent::getUid` (31), `insertIgnoreConflict` (16), `AlreadyProcessedException` (17) | SPEC §14 |
| B2 | Kontolöschung: rechtetragende UIDs synchron im Listener, Inhalt im Job | SPEC §5 (`UserDataDeletionJob`), §8 („Benutzer gelöscht“) |
| B4 | Erinnerungen: Leserecht in Job und `prepare`, Löschen bei Freigabe-Entzug | SPEC §5 (`ReminderJob`), §6 |
| Krit. 8 | geprüft werden die benannten UID-Spalten, nicht Inhaltsspalten | SPEC §13 M2.8 |
| O14 | einmaliger Netzzugriff für Upstream-Build und `composer install`; Fixtures eingecheckt | – |

Weiter offen (kein Blocker, Vorschlag steht):
- **O4 `/system/i18n`**: Wörterbücher entstehen erst im M3-Build. M2 liest `nextnotes/i18n/<lang>.json` (Sprachabbildung §1.4), sonst `en`, sonst `{}`; Test prüft nur das Format.
- **O6 Archiv**: `/notes/{id}/archive`, `/folders/{id}/archive` verschieben in „Archives“ des gleichen Besitzers wie die Quelle; Team-Workspaces in M5 neu bewerten.
- **O10** Benachrichtigung `mention` (§6) → M4; `CleanupJob` inkl. verwaister `IAppData`-Dateien → M5; `occ nextnotes:delete-user-data`/`export-user`/`purge` → M7.
- **O12 Quota**: Grenzen aus `IAppConfig` (Defaults ADR 0002), Admin-Formular (`ISettings`) M4; in M2 nur Durchsetzung (413). Team-Workspaces zählen beim Ersteller ab M5.
- **Notiz M6 (Review M2a H2)**: `NoteMapper::moveFolderNotesToWorkspace`/`moveWorkspaceNotes` filtern nicht nach `role`; Ordner löschen/verschieben prüft `assignment_id` nur am Wurzelordner, nicht im Teilbaum → in M6 beides nachziehen.
- **Notiz M2c (Review M2a H3)**: `/settings/recently_opened_notes` liest ausschließlich über `NoteService::openedNoteIds()` (gefiltert per `AccessService`, Done M2a.9).
- **O13** `PATCH/DELETE /tags/{tag}` wirken auf Notizen in Workspaces mit Schreibrecht des Aufrufers (in geteilten Workspaces auch auf Notizen anderer, wie Upstream im geteilten Scope).

## 6. Risiken
- **R1 Umfang**: 112 Routen, Upstream-Controller ~19,8 k Zeilen. Gegenmaßnahme: vier Slices, Isolation erzwingt Abdeckung jeder Route und jeder ID-Stelle, Fixtures erzwingen Formatparität.
- **R2 Netz**: `composer install` und Upstream-Image-Build brauchen einmal Netz (freigegeben); danach offline (Lockfile, eingecheckte Fixtures).
- **R3 APCu-Verzug** nach `occ app:enable` (M1a) → Warteschleife in `run-phpunit.sh`.
- **R4 Testdauer**: Body-Varianten vervielfachen die Isolationsanfragen; App-Token statt Passwort-Hashing, Varianten je Route deduplizieren.
- **R5 `UserRateLimit`** kann Tests mit vielen Uploads drosseln → Grenzen großzügig, eigener Benutzer je Fall.
- **R6 Globaler Zustand** im In-Process-Teil (base.php, ein Prozess) → Tests räumen eigene Benutzer/Daten auf; keine Reihenfolgeabhängigkeit; `FileCleanupQueue` je Test geleert.
- **R7 PostgreSQL**: Unique-Verletzung in offener Transaktion bricht sie ab → `insertIgnoreConflict` bzw. Sperr-Insert außerhalb von Transaktionen.
- **R8 Sanitizer** nutzt `DOMDocument`; libxml-Version kann Ausgabe minimal ändern → Vergleich auf Sicherheitsmerkmale, nicht Byte-Gleichheit.
- **R9 `LIKE`-Suche** ohne Index (SPEC R10) → Limit/Paginierung wie Upstream.
- **R10 Team-Weg** in M2 nur Platzhalter; M5 muss `AccessService` erweitern, ohne Isolationstests aufzuweichen.
- **R11 Sperre strenger als Upstream**: zweiter Tab desselben Benutzers wird gesperrt (409, Banner) statt still übernommen; Frontend-Verhalten in M3 prüfen (`takeover`-Knopf bleibt wirkungslos).
- **R12 Listener synchron** in der Kontolöschung: mehrere Einzel-UPDATEs über nicht indizierte `created_by`-Spalten; bei großen Instanzen spürbar, aber einmalig je Löschung.
