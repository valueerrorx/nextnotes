# M9b Architekt – Fortschritt

Status: in Arbeit

## Auftrag
OneNote-Import: tree/start, OneNoteImportService (Job-Schritte, Lease, Struktur, Seiten, Fortsetzen), OneNoteImportJob, Ansicht, Notifier. Plan `docs/slices/M9-plan.md` r3 §M9b, Q1/Q2 entschieden (Positivliste, Team nichts vorausgewählt; Überspringen).

## Erledigt
- Plan, ADR 0020 gelesen

- M9a-Code gelesen: ConnectService, GraphClient, HttpGate, RateBudget, Mapper, Controller, Template/JS, msmock index.php, OneNoteTestCase/FakeHttp
- Kern-Services geprüft: Workspace/Folder/Note/Attachment nehmen uid explizit (keine IUserSession); aber Notiz-Datum aus Graph, eindeutige Namen → minimale Ergänzungen nötig
- M9c-Signatur gelesen (nicht angefasst): PageConverter::convert(html, IL10N): ConvertResult(html, resources, skippedResources, ink, tooLarge), applyResources

## Entwurf
- `lib/OneNote/PageConverterInterface.php` + `ConvertedPage` + `BasicPageConverter` (Übergang), Alias in Application
- `lib/OneNote/OneNoteImportService.php` (tree, start, runDue, cleanup), `OneNoteImportJob` (TimedJob 300 s)
- Mapper: claim, findDue, findStale; ItemMapper: find/insert/replace
- Workspace-Namen: nur Buchstaben/Ziffern/Leer/_/- (InputValidator) → „Name OneNote“, „Name OneNote 2“ statt Klammern (Abweichung Plan §5)
- Quote-Prüfung erst M9d (keine Ressourcen in M9b)

- Code: PageConverterInterface, ConvertedPage, BasicPageConverter, ImportRun, RestructureException, OneNoteImportService (tree/start/runDue/cleanup), BackgroundJob/OneNoteImportJob; Mapper findDue/claim/findStale, ItemMapper findBySource/add/replaceTarget
- Kern-Services minimal ergänzt: WorkspaceService::createImported, FolderService::createImported, NoteService::createImported (uid explizit, ohne Sitzung)
- M9a angepasst: ConnectService toReauth/fail public + eta_seconds in view; GraphClient::reserve an Lease gebunden (nach cancel keine Anfrage); Notifications DONE; Messages graph_unavailable/stalled/graph_10008
- info.xml Job + 0.9.2 (Job-Registrierung beim Upgrade), CHANGELOG, Application-Alias

- Lead-Korrektur: Übergangskonverter (PageConverterInterface, ConvertedPage, BasicPageConverter, Alias) entfernt; Service nutzt Convert\PageConverter direkt (convert/applyResources/title/timestamp); Application.php unverändert
- Controller tree/start + Routen (RouteDiffTest PORT_ONLY ergänzt); Template onenote.php (Auswahl, Fortschritt, Bericht), js-src/nextnotes-onenote.js, css-src/nextnotes-onenote.css; Karte in templates/settings.php (PageController oneNoteUrl); l10n de/de_DE (+37, 1 veralteter Schlüssel entfernt)

- make build rc 0, make check-upstream rc 0, php -l aller Dateien ok
- Tests geschrieben: tests/nextnotes/php/OneNote/{FakeGraph,ImportTestCase,ImportJobTest,IdempotenceTest,ConcurrencyTest,AccountStateTest}.php (im Prozess, FakeHttp)

- Instanz nextnotes-m9b sqlite hoch (11401), App 0.9.2, OneNoteImportJob registriert, graceful
- sqlite PHPUnit --filter OneNote: 82/83 grün; Fix: l10n-Platzhalter ohne Argumente in onenote.php (ArgumentCountError) → behoben
- sqlite Filter OneNote|RouteDiff|Settings|Page: 2 Fehler: ConvertLimitsTest::testHugeTextNode (M9c, nicht meins), PageIsolationTest COVERED fehlte onenote.php/callback (seit M9a) → testOneNoteForeign ergänzt
- Mock erweitert (dev/nextcloud/scripts/msmock/index.php: nb1/s1/g1/s2, pages, op=error); ImportMockTest (msmock: occ, parallel, cancel<2s, Löschung, SIGKILL/12h); E2E onenote-import.mjs
- msmock sqlite up

- sqlite --group msmock: OK 8 Tests (3 M9a + 5 ImportMockTest) rc 0
- E2E sqlite onenote-import + onenote-connect hell/dunkel grün (39/42); Fix Fortschritt als Tabelle, Einrückung Abschnitte; erneut grün
- check-forbidden-sql 0; check-csp.mjs onenote.php/settings.php 0 Verletzungen (Root-Hinweis bei onenote.php erwartet, eigene Seite)
- ADR 0020 Nachtrag M9b
- mariadb/pgsql hoch (11402/11403), App 0.9.2; msmock auf mariadb

- sqlite Filter (279 Tests): 1 Fehler testOneNoteForeign (Funktion nicht eingerichtet) → Test richtet ein und stellt zurück; PageIsolationTest sqlite OK 17
- Hinweis: ConvertLimitsTest (M9c) war im ersten Lauf rot, im zweiten grün

- mariadb Filter 279: 1 Fehler testOneNoteForeign (vor Fix) → PageIsolationTest mariadb OK 17; msmock mariadb OK 8
- pgsql Filter 279: dito → PageIsolationTest pgsql OK 17; msmock pgsql OK 8
- nextcloud.log sqlite/mariadb/pgsql: 0 Treffer für Token, Code, Secret, eyJ, AADSTS
- make build 0, check-upstream 0; msmock down, nextnotes-m9b sqlite/mariadb/pgsql down (0)

## Status
fertig

## Offene Punkte
- tooLarge-Text im Service dupliziert (Wunsch an Convert: öffentliche Hilfe)
- Ressourcen und Quote erst M9d; SPEC §5-Jobtabelle M9e
- Workspace-Kollisionsname ohne Klammern (Validator)
