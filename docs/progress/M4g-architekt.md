# M4g – Architekt (Umsetzung)

Auftrag: M4g laut docs/slices/M4-plan.md (Dashboard, Graph, Speed Dial, Mentions + Benachrichtigung, Hintergrundbild B43) + Rest B52/reservierter Tag-Name + M4f-r1 Hinweise 1/2/4/5. Eigene Test-Instanz, am Ende abbauen.

Status: fertig

## Erledigt
- Plan/STATUS/SPEC/ADR 0014/M4f-r1 gelesen; Upstream-Seiten gesichtet
- DiaryService: Hinweise 1/2/5 (tooLarge keyFor, Vorlage toClient, Zähler modified = updated_at)
- Hintergrund: lib/Service/BackgroundFiles.php (IAppData, Löschjobs nach Commit), BackgroundService.php (Rechte, Upload/Status/Bild, Deckkraft je Workspace-ID), CompatController background/backgroundUpload/backgroundDelete, routes (+3), WsShareMapper::workspaceIdsSharedWith, WorkspaceService delete/unshare, UserDataService neutralize/purge, SettingsApiController Deckkraft-Mapping, SettingsService blockt bg_opacity_*
- Mentions: MentionService (notifyNew ≤20, railCount, page, collect ohne Schreiben für Seiten), NoteService (pendingMention nach Commit in create/update; speed-dial nur über Speed-Dial-Aktionen), Notifier subject mention, l10n de/de_DE
- TagService rename/delete: speed-dial reserviert
- PageController: dashboard/graph/speedDial/mentions, railMentions, Hintergrund in Index-Config; routes +3 Seiten

- View/DashboardViewService.php, View/SpeedDialViewService.php (php -l ok)
- Templates dashboard.php, graph.php, speed_dial.php, mentions.php; partials/icon_sidebar.php (home-Gruppe + Erwähnungen mit Badge)
- build/bundles.json (4 Seiten, background-settings.js in js.app), Adapter (GLOBALS Dashboard, Settings-Key dekodiert, Hintergrundstatus aus config), nextnotes-layout.css (Ebene, Graph, Speed-Dial-Bild)

- src-Patches P27 (clickable-tags.js, utils-menus.js), P28 (dashboard-page.js); upstream-patches.txt +dashboard-page.js
- IndexViewService: NEXTNOTES_TXT (IL10N), l10n de/de_DE (+3 Texte), Speed-Dial-Leertext eigener Text
- SPEC: §1.2 Nr. 3, §4.3 Nr. 18, §4.5 (Hintergrund, Mention 20), §6 mention, §8 Hintergrundbilder, §11.1 P27/P28, §11.2 B43/B62/B63
- make build rc=0 (158 Dateien), check-upstream grün rc=0, check-forbidden-sql 0
- Instanz nextnotes-m4g up (sqlite 8161, nginx 8164, pgsql 8163, mariadb 8162), App installiert rc=0
- Tests: php/Page/ViewsM4gTest.php (neu), Diary +testReadOnlyShareAndForeignTemplate, ControllerAttributeTest +5, ApiClient::uploadField
- PHPUnit sqlite ViewsM4g|Diary|ControllerAttribute: 14 Tests, 1 Testfehler (201 statt 200) korrigiert → grün
- E2E views-m4g hell nginx grün (76); Hintergrund-Ebene z-index 1 (sonst unsichtbar unter deckenden Flächen)

- PHPUnit pgsql/mariadb ViewsM4g|Diary|MentionsUsers je 15 Tests OK (rc=0)
- E2E nginx: views-m4g dunkel grün (62), editor 119, pages-m4e 295 grün; diary-tasks 1× rot (Kalender nach Reload, flaky), 2× Wiederholung grün (72)

- PHPUnit voll sqlite: 369 Tests, 5 rot (UserDeletionTest Konstruktor +BackgroundFiles, Folgefehler; TemplateOutputTest Allowlist +4 Seiten; ReminderJobTest zählt jetzt auch mention → Filter subject reminder + AlreadyProcessed für mention) → Tests angepasst, Nachlauf 30 Tests OK rc=0
- Abschluss: build rc=0, check-upstream grün rc=0, check-forbidden-sql 0; Instanz nextnotes-m4g abgebaut (down.sh all, nur eigene Volumes); Demo/gftest unberührt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte/Annahmen
- IAppData listet keine Ordner → Kontolöschung löscht Bilder in Workspaces aus Freigaben (eigene mit dem Workspace)
- Speed Dial: keine externen Favicons/Bilder (CSP img-src, Datenschutz) → B62
