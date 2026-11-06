# M4i – Architekt (Umsetzung)
Auftrag: M4i Abschluss M4 laut docs/slices/M4-plan.md (Krit. 1,3,4,5, Grenzen-Gesamtlauf, check-upstream Rest O8, Budget) + Tagebuch-Datumsordner-Rennen, flaky diary-tasks, R14 nur Doku. Basis 1e7ce091. Nächste Nummern P30, B64.
Status: fertig
## Erledigt
- Plan/STATUS gelesen
- B64 Tagebuch-Rennen: FolderService::lockForCreate (WorkspaceMapper::lockRows, NC READ COMMITTED) in resolvePath(create) + create(); Test DiaryTest::testConcurrentCreatesShareOneFolder (parallel POST /notes, /folders); php -l ok
- Test-Instanz: Scratchpad nc/ (Projekt nextnotes-m4i, Ports 8191–8194), sqlite+nginx up, App aktiv rc=0; make build rc=0 (160 Dateien)
- PHPUnit sqlite: DiaryTest::testConcurrentCreatesShareOneFolder OK; IndirectReadTest::testM4ViewsAndExportsWithoutShareAndAfterRevoke (neu, Kontrolle mit Freigabe, Entzug, nie geteilt) OK 79 Assertions
- Krit. 3: tests/nextnotes/php/Routes/StrippedRoutesTest.php (Inventur A §1.1/§1.2, B §1.2–1.6 geparst; 72 API-Routen 404/405, Dateien GET+POST 404, login/logout 303, /shared* leer); run-phpunit.sh kopiert B-frontend.md; sqlite 3 Tests/360 OK (66 s)
- Krit. 4: PageIsolationTest +testEveryPageRouteHasForeignCase (COVERED-Map aller Nicht-API-Routen), +testM4PagesForeignShowOwnOnly, +testM4CompatRoutesForeign; sqlite 3 Tests OK
- Flaky diary-tasks: Ursache belegt (Probe CDP CPU-Drossel 4×/8×: networkidle feuert vor DOMContentLoaded → Kalender-Anfrage fehlt beim Zählen; ungedrosselt 5/5 ok, diary-tasks 5× grün); Fix diary-tasks.mjs wartet auf notes-by-date-Antwort
- smoke-m4.mjs geschrieben; Lauf 1 sqlite hell: 66 ok, Revisionen-Knopf im Aktionsmenü → angepasst
- smoke-m4 sqlite hell 164 / dunkel 164 grün
- Zusatz Lead (Thomas): mention-Benachrichtigung speichert byName (MentionService), Notifier nutzt aktuellen Namen / byName / sonst Jemand; ViewsM4gTest::testMentionNotificationAfterAccountDeletion angepasst; SPEC §6, §4.3 Nr. 18; PHPUnit sqlite testMention 5/138 OK
## Nächster Schritt
- Fortsetzung 2026-11-06: diff geprüft, SPEC §6/§4.3 Nr. 18 vorhanden
- check-upstream O8: (b) direkte Asset-Tags der Seiten gegen cssPages/exclude/omit (bundles.json settings.omit +tokens.css, public_folder.css), (i) FORCED_HIDDEN/KNOWN_MISSING leer; make check-upstream rc=0
## Nächster Schritt (neu)
- erledigt: budget.mjs (index.php,dashboard.php, node --check ok), run-m4.sh (Kopie run-m3 + probe-grenzen mariadb/pgsql), scripts/probe-grenzen.sh (bash -n ok)
- FolderService::lockForCreate: lockRows<1 → 404 (gelöschter Workspace), php -l ok
- SPEC: B64 (§11.2), §4.3 Nr. 17 Satz B64; R14 schon in nextnotes/README.md „Betrieb“ (nur geprüft)
- Testinstanz neu: scratchpad nc/ (Projekt nextnotes-m4i, 8191–8194, down.sh Volumes nextnotes-m4i_), sqlite+nginx+pgsql
- probe-grenzen pgsql: 1. Lauf rc=1 (read ohne Zeilenende) → behoben; 2. Lauf rc=0: 200 MiB HTTP 200 11,5 s, sha256 gleich, Temp 0 nach Export und Abbruch; sha-Vergleich robust (grep -x)
- dev/nextcloud/README.md: Zeile run-m4.sh
- PHPUnit sqlite (Diary|testMention|StrippedRoutes|PageIsolation|IndirectRead|FolderTest) rc=0: 31 Tests, 1883 Assertions
- E2E nginx budget hell 36 / dunkel 36 grün (Hauptansicht + Dashboard)
- E2E nginx stripped 1. Lauf rot: Menünotiz vom Löschdialog im Papierkorb (attachments/revisions 404), Fehlalarm „wiederherstellen“ (trash/revisions), „Mein Konto“ (Hinweis-Karte M4h) → eigene Seitennotiz, Ausnahmen nur gegen sichtbaren Text
- probe-grenzen pgsql 3. Lauf rc=0 (15,5 s, Altbestand aus Fehlläufen)
- E2E nginx stripped 2. Lauf hell 246 / dunkel 246 grün
- check-forbidden-sql 0 Treffer; Instanz nextnotes-m4i abgebaut (down.sh all rc=0, keine Volumes), Demo unberührt
## Offene Punkte/Annahmen
- tests/ wird vom Architekten ergänzt (Auftrag Lead), wie M4g/M4h
