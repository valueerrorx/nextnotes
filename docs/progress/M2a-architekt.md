# M2a – Architekt (Umsetzung)

Status: fertig

## Auftrag
M2a nach `docs/slices/M2-plan.md` r2 umsetzen (§0, §1, §2 M2a, §3 soweit M2a, §4, §5) + Review-r2-Hinweise (SPEC `owner_type=deleted`, `ensureDefault`/Namensprüfung nur `owner_type=user`, LockService-Hinweis für M2b). Done-Prüfungen selbst einmal ausführen.

## Erledigt
- Plan, Review r2, SPEC, Upstream-Controller (Notes/Folders/Workspaces/Tags/Trash), html-sanitize, note-colors, note-sort, search-text gelesen; OCP geprüft (QBMapper/Entity ColumnType, IDBConnection::insertIgnoreConflict, Middleware, IRegistrationContext::registerMiddleware, IUserConfig, IDateTimeZone, NotModifiedMiddleware → 304 per setETag)
- `lib/Service/Exception/*` (ApiException + 6), `lib/Db/*` (5 Entities + Mapper; `WorkspaceMapper::findOwned` filtert `owner_type=user`)
- `lib/Service/`: Transaction, FileCleanupQueue, HtmlSanitizer (Upstream `tests/html-sanitize.test.php` per Shim: 15/15 grün), SearchTextNormalizer, InputValidator, ContentUrlRewriter, AccessService, WorkspaceService, SettingsService, NoteSerializer, TagService, FolderService, NoteService, TrashService (alle `php -l` ok)
- `lib/Middleware/ApiExceptionMiddleware.php`, `Application::register` → registerMiddleware
- `lib/Controller/`: ApiController (Basis), Notes/Folders/Workspaces/Tags/TrashApiController (`#[NoAdminRequired]`, kein NoCSRFRequired)
- `appinfo/routes.php`: 53 API-Routen in Upstream-Reihenfolge, Requirements `{id}` `\d+`, `{name}` `.+`, `{tag}` `[^/]+`
- `l10n/de.json`, `de_DE.json` („Notizen“)
- M1-Nacharbeit: SVG-Kommentar in `<svg>`, compose-Pins (lokale Images per `docker tag` auf exakt gleiche Versionen 1.30.6/11.4.13/17.11 geprüft), down.sh, run-m1.sh-Trap + README
- `dev/nextcloud/check-forbidden-sql.sh` (SELFTEST=1 → OK, 0 Treffer, Exit 0), `run-phpunit.sh`, `run-m2.sh`
- `tests/nextnotes/composer.json` + `composer.lock` (PHPUnit 12.5.43, platform php 8.3), `.gitignore`, `bootstrap.php`, `phpunit.xml` (Abweichung: Bootstrap/phpunit.xml vom Architekten, damit run-phpunit lauffähig; Testklassen bleiben beim Tester)
- Smoke sqlite (curl, scratchpad/api.sh): Kern-Szenario Notiz/Ordner/Workspace/Tags/Papierkorb ok, ETag 304 ok, 409 version_conflict ok, XSS entfernt, Farbe mit Markup 400, B → A 404
- SPEC: §2.1 `owner_type` + `deleted`, §3 Zeile `owner_type=deleted` (kein Rechteweg), §11.2 B14–B17; Plan M2b: LockService an `holder_uid = uid` gebunden
- Eigene Done-Prüfungen sqlite (scratchpad-Skripte, nicht im Repo): Routen-Diff (Python über index.php/Inventur/routes.php: 53 = 53, keine Differenz); Attribute (Reflection im Container: 53 Methoden, 0 Verstöße); CSRF (401 ohne Login, 412 GET/POST ohne Token, 200/201 mit Header); Isolation-Stichprobe 36 Body-/Pfadvarianten B→A 403/404 + 7 Listen ohne A-Marker (Fund: `/folders/restore` mit fremder parent_id/Notiz-ID → jetzt 404, behoben); Schlüssel-Fallback „Mathe (Lehrer, #7/#9)“ erreichbar; role=template → 404; opened nach unshare leer; 4 parallele Erstzugriffe → 1 Workspace; XSS/Validierung 400; geteilter Workspace: Verlassen/Archiv/Löschen 403, Bearbeiten 200

## Done-Prüfungen (eigener Lauf, Skripte im scratchpad)
- D1: `head -c4 img/app*.svg` = `<svg`; Favicon 200 `image/x-icon`; `grep stable compose.yaml` leer; `down.sh sqlite` mit docker-Attrappe → Exit 1; `run-m1.sh sqlite` → Exit 0
- D2: Routen-Diff 53 = 53, keine Differenz (Python-Gegenstück zum RouteDiffTest)
- D3: Isolation-Stichprobe sqlite 36 Varianten + 7 Listen grün (Fund `/folders/restore` behoben)
- D4: Kern-Szenario per `dev/upstream/capture.php nextnotes` auf sqlite/mariadb/pgsql: 54 Routen 2xx, Schlüssel = Fixtures; ETag 304; 4 parallele Erstzugriffe → 1 Workspace
- D5: gleichnamige Workspaces A/B; Schlüssel `Mathe (Lehrer, #7)`/`#9)` erreichbar
- D6: 401 / 412 (GET+POST ohne Token) / 200+201 mit Header
- D7: Reflection 53 Methoden, 0 Verstöße
- D8: XSS entfernt; Ordner-/Workspace-/Tag-Name, Icon, Farbe mit Markup → 400
- D9: role=template → 404; opened nach unshare leer
- D10: `check-forbidden-sql.sh` 0 Treffer (Selbsttest OK); mariadb `mysql.utf8mb4` = true; Scratch-PHPUnit (3 Tests, 14 Assertions) per `run-phpunit.sh` auf sqlite/mariadb/pgsql Exit 0; `run-m2.sh sqlite` läuft bis PHPUnit und endet mit „No tests executed“ (Exit 1), bis der Tester Testklassen liefert
- `down.sh all` + `dev/upstream/down.sh` ausgeführt, keine Container/Volumes übrig

## Nächster Schritt
keiner (fertig); Abnahme durch tester/speccritic

## Abweichungen vom Plan (Begründung)
- Workspace löschen verschiebt Notizen+Ordner in anderen eigenen Workspace (Upstream, UI-Text sagt das) statt Kaskade
- Ordner löschen wie Upstream: Zeilen weg, Notizen in Papierkorb, Wiederherstellen über `restore_snapshot` → `/folders/restore`; `folders.trashed_at` ungenutzt
- `GET /notes/{id}` ohne Feld `attachments` (Upstream liefert es nicht; Fixture-Parität)
- Fehler-JSON: Workspaces/Tags zusätzlich Schlüssel `message` (Upstream nutzt dort `message` statt `error`)
- Icon/Farben/Tags mit Markup → 400 statt stillem Entfernen
- Inhalte verlassen einen Workspace (Notiz/Ordner verschieben, move-files, Archiv) nur mit `manage` auf der Quelle
- Tag-Umbenennen/Löschen nur in Workspaces mit Schreibrecht (O13)
- Unbenutzte Mapper-Methoden nach dem DB-Lauf entfernt (nur `php -l`)
- Bereits in M2b/M4 verschoben: Tagebuch-Automatik, Base64-Bild-Umwandlung, Sperren, Snapshots

## Upstream-Bugs (in SPEC §11.2 als B14–B17 eingetragen)
- Wiederherstellen einer Notiz, deren Ordner gelöscht ist → Notiz unsichtbar; hier: Wurzel
- `POST /notes` mit `folder_id` aus anderem Workspace → hier 400
- `icon_color` ungeprüft gespeichert → hier validiert

## Offene Punkte / Annahmen
- `POST /notes/{id}/beacon` per `sendBeacon` kann keinen Header setzen → CSRF 412, Frontend muss `requesttoken` ins FormData legen (M3)
- `{id}` `\d+` → Upstream-Fallback „nicht-numerische ID = reference“ bei `GET /notes/{id}` entfällt; `?reference=` bleibt
- Datumsfilter/created_date in Zeitzone des Benutzers (IDateTimeZone), Ausgabe UTC wie Upstream

## Nachbesserung r1 (`docs/reviews/M2a-diff-r1.md`), ohne Docker
Status: fertig
- Blocker 1: `WorkspaceService::delete` Ziel = eigener `kind=normal` ohne Freigabe, nicht „Archives“, bevorzugt Standardname, sonst kleinste ID, sonst neuer Standard-Workspace (Name frei: „Notes 2“ …); `WsShareMapper::sharedWorkspaceIds` neu; SPEC B18
- Abw. 3: Plan-Text `attachments` angepasst
- Abw. 5: SPEC B20; `'` in Tags/Workspace-Tags erlaubt (Frontend escapt `data-tag`, geprüft list_tags.php/diary-page.js)
- Abw. 9: `NoteService::show(uid, int id, ws)` ohne Referenz-Zweig; `findIdByTitle` bleibt für `/notes/resolve` (M2b)
- H1: `HtmlSanitizer::cleanAttributes` prüft href/src zusätzlich ohne Whitespace/Steuerzeichen; SPEC B19; Host: Upstream-Sanitize-Shim 15/15, eigene Fälle `java&#9;script:` u. a. entfernt
- H4: Beacon nur nicht gelöschte Notizen (Papierkorb → 404)
- H2/H3 als Notizen in `docs/slices/M2-plan.md` §5
- `php -l` alle lib-Dateien ok, `check-forbidden-sql.sh` 0 Treffer; HTTP-/DB-Prüfung durch tester

## Kleinkorrektur r2 N1 (ohne Docker)
Status: fertig
- `WorkspaceService::delete`: Kandidaten, Freigabestand und Zielwahl jetzt im `tx->run`-Block; Prüfung „letzter Workspace“ (400) bleibt davor; `php -l` ok
