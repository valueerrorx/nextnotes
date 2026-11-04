# M4f – Architekt

Auftrag: Slice M4f umsetzen (Tagebuch, Kalender, Aufgaben) nach docs/slices/M4-plan.md §M4f + Lead-Auflagen/Nachträge (384 KiB Render-Grenze, 1 MiB Budget wie TrashViewService::RENDER_BUDGET).
Status: in Arbeit

## Erledigt
- Plan, STATUS, Upstream (diary.php, tasks.php, api/v1/calendar|diary/*, lib/diary.php, lib/datetime.php Namen, calendar.js, diary-page.js, NotesController-Automatik) gesichtet
- Entwurf (siehe unten)

## Entwurf
- DiaryDates (Port lib/diary.php Formate + datetime.php Namen, Pcre), DiaryService (Wurzeln read-only inkl. Namens-Fallback, Teilbaum, Eintrag je Datum, Tagebücher anlegen, Hooks Vorlage/Datumsordner, entries-Render mit Budget), DiaryApiController (5 Routen .php), DiaryViewService + TasksPage im PageController
- NoteService::create/update: optionaler DiaryService-Parameter aus NotesApiController (kein DI-Zyklus NoteService↔ConversionService)
- Kalender: P25 calendar.js eine Anfrage mode=both, nur bei sichtbarem Kalender
- entries.php: {entries:{id:html}, truncated:[ids]}; P26 diary-page.js lädt truncated nach
- InputValidator::date (YYYY-MM-DD + checkdate → 400)
- B61: GET diary.php/diary-entry/diaries schreibt is_diary (lazy) → read-only Fallback, Markierung beim ersten Eintrag/POST

## Nächster Schritt
- PHP: DiaryDates, DiaryService, Mapper-Erweiterungen

## Offene Punkte/Annahmen
- PHP fertig (ungetestet): lib/Service/DiaryDates.php, DiaryService.php, View/DiaryViewService.php, Controller/DiaryApiController.php; NoteMapper (calendarStamps, updated_from/to), InputValidator::date, NoteService create/update ?DiaryService, NotesApiController, routes (+2 Seiten, +6 API)
- Templates: diary.php, tasks.php, partials/board_view_menu.php, partials/task_due_modal.php (aus modals.php ausgelagert), create.php (+Tagebuch-Karte), modals.php (+Tagebuch im Erstellen-Menü), icon_sidebar (+Aufgaben, Tagebuch), notes_list (+Mini-Kalender)
- PageController diary/tasks, CspFactory diary (+ADR 0012 Nachtrag), bootstrap (outline=diary, DIARY_DATA), late.js (diary-Karte), bundles.json (calendar.js in app, pages.diary/tasks)
- src: P25 calendar.js, P26 diary-page.js; upstream-patches.txt +2; upstream-ported.txt +7; check-upstream (g) + Diary*
- make build rc=0 (150 Dateien), check-upstream grün rc=0
- Eigene Instanz: Scratchpad nc/ (Projekt nextnotes-m4f, Ports 8151–8154, down.sh Volumes nextnotes-m4f_*), up sqlite/nginx/pgsql/mariadb gestartet
- Instanzen up (8151–8154), App installiert; Tests: RouteDiff (+Einzeldateien), Allowlists, ListPages (+diary/tasks), stripped/pages-m4e, neu php/Diary/DiaryTest.php; lookup: fremde Tagebuch-ID → 404
- E2E diary-tasks hell nginx grün (72 Prüfungen)
- SPEC: §1.2, §4.2, §4.3 Nr. 17, §4.5 Tagebuch, §11.1 P25/P26, §11.2 B61; Suchtext je Karte ≤ 1000 Zeichen (Seite 1,7 MB bei 1031 Einträgen); CSS nn-diary-main
- PHPUnit voll sqlite: 2 Fehler (IsolationTest routeTable, CoreScenario Fixtures für A #186–190) → routeTable +6 (118), 6 Fixtures aus Upstream-Quelltext + allowed-diff truncated, Szenario-Schritte; Nachlauf läuft
- PHPUnit mariadb 57 OK, pgsql 61 OK (Diary|Page|Routes|ControllerAttribute)
- E2E: diary-tasks dunkel grün (65), editor 119, menu-501 92, stripped 126, budget 21 grün; pages-m4e Kartenzahl 4→5 angepasst, grün (295)
- sqlite Isolation+CoreScenario 135 OK; voller sqlite-Lauf davor 356 Tests, nur diese 2 Fehler
- Abschluss: build rc=0, check-upstream grün, check-forbidden-sql 0; Instanz nextnotes-m4f abgebaut (down.sh all, nur eigene Volumes); Demo/gftest unberührt
Status: fertig
