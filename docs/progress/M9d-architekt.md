# M9d Architekt – Arbeitsstand

Status: fertig

## Auftrag
OneNote-Import mit Ressourcen (Bilder, Anhänge) + Quote; Plan `docs/slices/M9-plan.md` r3 §M9d, §4.4, §6; Review-Hinweise M9a (Abbruch beim Download, gzip) und M9b-r1 1–9; Konverter-Hilfe „zu groß“.

## Erledigt
- Plan, Reviews M9a/M9b/M9c gelesen; Bestand gelesen (HttpGate, GraphClient, ImportService, ImportRun, PageConverter, Attachment/Quota/EmbeddedImage, Tests-Fakes, msmock, ImportMockTest)
- Befund: Guzzle 7.15.2 im Image, CurlFactory: WRITEFUNCTION schreibt in `sink` (StreamInterface erlaubt, PSR-7 v2), Rückgabe < Länge → curl bricht ab; progress kann nicht abbrechen → Lösung: zählender Sink (dekodierte Bytes, da CURLOPT_ENCODING vor WRITEFUNCTION dekodiert)

- Schritt 1 fertig: LimitedSink.php (neu), HttpGate (sink immer, Datei-Ziel, timeout/connect_timeout, stream entfällt), HttpResult (bytes), GraphClient (validId Punkt/!-Segmente, get mit Datei/Timeout, remaining/freeAt), Mapper claim next_run_at
- Schritt 2 fertig: ResourceFetcher.php + ResourceBatch.php (neu), AttachmentService (IMAGE_TYPES, imageType, fileRules, createFromFile), NoteService::setImportedContent
- Schritt 3 fertig: OneNoteImportService (Konstruktor +ResourceFetcher +AttachmentService; tree ≤25 Anfragen/Team-Paging, harte 50; Lock vor Besitz in targetWorkspace/writePage; cursor base [total,done,skipped]; Titel 200, c/m als Unix (alte ISO-Cursor gelesen); pageStep+writePage mit Quote vorab/nachher, Seitenstart-Budget, failQuota; cleanup select ab created_at), ImportRun (base, report n, reported ohne resources), templates/onenote.php + l10n de/de_DE (resources)
- check-upstream rc 0, php -l ok
- Tests geschrieben (Lead-Auftrag, wie M9b): FakeHttp/FakeResponse (sink, Stücke), FakeGraph (Ressourcen), GraphClientTest (sink statt stream), ImportTestCase (Konstruktor, fetcher()), ImportJobTest (Ressourcen-404 holt jetzt), neu ResourceFetcherTest, PageBudgetTest, ImportHardeningTest; ImportMockTest +delete-user-data, Ressourcen, Gate-Bombe/endlos, langsam, 80-Messung
- make build rc 0; Instanz nextnotes-m9d sqlite (11601) up, install, app:enable, graceful, msmock up
- sqlite Filter OneNote ohne msmock: 103 Tests, 1 Fehler (PageBudget Erwartung: Abschluss erst im nächsten Lauf) → Test korrigiert, PageBudgetTest OK
- sqlite --group msmock: OK 13 Tests rc 0; Messung: 80×1 MiB-Seite 52,1 s (0,6-s-Abstand), Speicher-Spitze +8,0 MiB; Bombe/endlos Datei 1048576 B (Grenze 1 MiB), +0,1 MiB, 0,01 s; Mock-Seite 9 Ressourcen 13,8 s (inkl. Bomben-Erzeugung)
- Doku fertig: SPEC §1.4, §4.2 (OneNote-Routen), neu §4.9 (Ressourcen), §5 OneNoteImportJob, §6 onenote_*, §14 HTTP; Plan §6 sink, M9b-Testname, M9d-Testnachweis; ADR 0020 Nachtrag M9d
- mariadb/pgsql hoch (up, install, enable, graceful, msmock up) rc 0; mariadb Filter OneNote ohne msmock: OK 103 Tests rc 0; check-forbidden-sql 0 Treffer; CHANGELOG [Unreleased]
- pgsql Filter OneNote ohne msmock OK 103 rc 0; msmock mariadb OK 13 rc 0, pgsql OK 13 rc 0 (80er-Seite 52,1/52,2 s, +8,0 MiB)
- Nach den Läufen: redundante Zuweisung in tree entfernt (ohne Verhaltensänderung), App auf sqlite neu installiert für E2E
- make build rc 0, App neu installiert; E2E onenote-import hell sqlite grün (50 Prüfungen, Abschnitt E Bild/PDF), nextcloud.log sqlite/mariadb/pgsql 0 Treffer (Token/Secret/Bearer/eyJ/AADSTS); check-upstream rc 0; Instanz nextnotes-m9d abgebaut (0 Container, 0 Volumes)
- msmock index.php: res/res_n/tag, Ressourcen png,jpg,mb,pdf,big,bomb,slow,svg,txt,e404,e403; E2E onenote-import Abschnitt E
- Schritt 4 fertig: PageConverter::tooLargeHint public

## Plan (Umsetzung)
1. LimitedSink + HttpGate (sink immer, Datei-Ziel) + GraphClient (download, remaining, validId)
2. ResourceFetcher (Temp-Dateien, Grenzen, Typen) + AttachmentService createFromFile/checkFile + NoteService setImportedContent
3. ImportService: Quote vorab/nachher, Seitenstart-Budget, Lock-vor-Besitz, base-Zähler, Titel 200, tree-Budget, cleanup created_at; Mapper claim next_run_at
4. PageConverter::tooLargeHint public
5. Tests (Lead-Auftrag): FakeHttp-Sink, ResourceFetcherTest, PageBudgetTest, msmock Ressourcen + ImportMockTest (delete-user-data, gzip, Ressourcen), E2E
6. SPEC/Plan-Doku, Läufe sqlite/mariadb/pgsql + msmock, build, check-upstream

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen / Annahmen
- tests/ geändert, weil der Lead-Auftrag Tests und Mock ausdrücklich verlangt (wie M9b)
- validId: „leere Segmente“ als leere !-Segmente gedeutet
- Seiten mit fehlenden Ressourcen: Bericht `resources` mit n, zählt nicht als „nicht importiert“
- Dunkel-E2E nicht gelaufen (keine CSS-Änderung)

## fix (M9d-fix, Review r1)
Auftrag: Blocker Temp-Reste (cleanup löscht oc_tmp_*.nn-onenote > 1 h), Hinweise 1–5 (Timeout/Lease, mb_strcut, base-Bericht, Titel-Doku, listAll-Hinweis), Tests sqlite + msmock.
Status: fertig
### Erledigt
- Blocker: ResourceFetcher::cleanStale (readdir, oc_tmp_*.nn-onenote, lstat nur reguläre Dateien, mtime > 1 h), Aufruf in OneNoteImportService::cleanup (läuft vor enabled-Prüfung)
- H1: RESOURCE_SECONDS 240 je Ressource (Lease 300 s, Verlängerung nach jeder Anfrage), Zeitüberschreitung → page_limit statt Backoff; Gewählt statt Lease-Verlängerung im Download: keine DB-Schreibvorgänge aus dem curl-Callback
- H2: PageWalker::fileName byte-genau mb_strcut 255, Endung ≤16 Bytes bleibt
- H3: ImportRun::mark/rewind, base = 5 Werte (alt 3 gelesen), mark vor not_found-Einträgen in structureStep
- H5: ImportRun::truncated, listAll setzt bei Abbruch, tree `incomplete`, Template-Hinweis + JS + l10n de/de_DE
- php -l ok
- Doku: Plan §5 (Titel 200, object-Name), §6 (240 s, Temp-Reste); SPEC §4.9 Punkt 1/3 + neu 7, §5 Job-Zeile; CHANGELOG
- Tests geschrieben: ResourceFetcherTest (+CJK-Name, 240 s, Temp-Aufräumen auch bei Funktion aus), ImportHardeningTest (+Bericht nach Neuaufbau, mark/rewind, tree incomplete), FakeGraph teamsEndless, ImportMockTest (+SIGKILL Temp-Rest)
- make build rc 0, check-upstream rc 0; Instanz nextnotes-m9d sqlite (11601) up + install + msmock up rc 0
- sqlite Filter OneNote ohne msmock: OK 109 Tests rc 0
- msmock sqlite: OK 14 Tests rc 0 (inkl. SIGKILL-Temp-Rest; 80er-Seite 52,5 s, +7,9 MiB)
- Instanz nextnotes-m9d abgebaut (msmock down, down.sh all; 0 Container, 0 Volumes); Demo/nc35-* unberührt
- mariadb/pgsql entfallen: kein SQL geändert
### Nächster Schritt
- keiner (Bericht an Lead)
