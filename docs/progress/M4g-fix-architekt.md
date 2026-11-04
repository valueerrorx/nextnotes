# M4g-fix – Architekt
Auftrag: Review-Hinweise M4g-r1 (1 railCount-Cache, 2 Notifier-Datenschutz, 3 Testlücke, 4 SPEC §6, 6/P27 Lost Update) umsetzen; 5/7 nur dokumentieren.
Status: fertig
## Erledigt
- Instanz nextnotes-m4gfix (Scratchpad nc/, Ports 8171–8174): sqlite+pgsql up rc=0; App (Basis 2b4f6240) sqlite installiert
- Messung VORHER (sqlite, 500 Notizen à ~8,5 KB mit @alice, geteilt von bob): Seite /apps/nextnotes/ median 330 ms (min 323, max 345); railCount 57 ms / 49 ms (2. Aufruf), peak 28,9 MB
- P27: NoteMapper::versionParts (md5 in DB), NoteSerializer::versionOf, FolderService::changeTags liefert versions{id:{from,to}}; utils-menus.js refreshOpenNotesTags(versions) übernimmt nur bei Basis = from
- NoteMapper::searchSizes (id/type/Bytes ohne Inhalt) für railCount
- E2E neu: tests/nextnotes/e2e/tags-version.mjs (hell)
- MentionService: railCount über ICache (distributed, TTL 60 s, Schlüssel md5(uid)), countOpen mit searchSizes + Budget RENDER_BUDGET; notifyNew leert Zähler je Mitglied mit geänderter Anzahl; setHandled leert+zählt; page() setzt genauen Wert
- Notifier: by fehlt/gelöscht → „Someone mentioned you in {note}“ (l10n de/de_DE); UserDeletedListener: markProcessed(app, user) nach neutralize
- Tests ViewsM4gTest: Leer-Assertion ersetzt (purgeWorkspaces + === 0), neu testMentionRailCountCache, testMentionNotificationAfterAccountDeletion, testFolderTagVersions
- Befund: IJobList baut Jobs als DI-Singleton → UserDeletionTest::runDeletionJobs läuft bei >1 offenem Lösch-Job nur den letzten (Testhilfe); ViewsM4gTest trägt eigene Lösch-Jobs daher selbst aus (runDeletionJob)
- PHPUnit sqlite ViewsM4g|Mention|ReminderJob|UserDeletion|ControllerAttribute: 37 Tests OK, 799 Assertions, rc=0
- Messung NACHHER (gleiche Daten, Cache warm): Seite median 272 ms (min 271, max 275); railCount ungecacht 17,6 ms, gecacht 0,0 ms, peak 18,6 MB
- PHPUnit pgsql ViewsM4g|Mention: 18 Tests OK, 477 Assertions, rc=0
- E2E sqlite hell: views-m4g 76 grün, tags-version 15 grün (rc=0)
- Doku: SPEC §4.3 Nr. 18 (Zähler-Cache), §6 (mention bereinigt, Team → M5, neutraler Text, markProcessed), §11.1 P27, §12 R14/R15, §14 OCP; nextnotes/README.md Betrieb (Bilder-Quote, Cache)
- Abschluss: build rc=0 (158), check-upstream grün rc=0, check-forbidden-sql 0 rc=0; Instanz nextnotes-m4gfix down.sh all rc=0, 0 Container/Volumes; demo/gftest unberührt
## r1-Nacharbeit (docs/reviews/M4g-fix-r1.md)
- Auftrag: md5 (deprecated 35) raus, Version in PHP über contentByIds; Regel gegen ->md5( in check-forbidden-sql; SPEC Näherung über Budget
- versionParts entfernt; FolderService::changeTags: findListByIds + contentByIds je 100, md5 in PHP, NoteSerializer::versionOf
- dev/nextcloud/check-forbidden-sql.sh: Regel '->md5(' + Selbsttest (SELFTEST=1 OK)
- SPEC: §14 md5 entfernt, P27 Text, §4.3 Nr. 18 Näherung über Budget
- Tests: PHPUnit sqlite ViewsM4g+ControllerAttribute 12 OK (241); pgsql ViewsM4g 9 OK (236); mariadb ViewsM4g OK rc=0; E2E sqlite hell tags-version 15 grün; build 158 rc=0, check-upstream grün, check-forbidden-sql 0; Instanz down rc=0, 0 Container/Volumes
## Nächster Schritt
- keiner (Übergabe an Lead)
## Offen
- Testhilfe UserDeletionTest::runDeletionJobs (IJobList-Singleton) unverändert; nur ein offener Lösch-Job erlaubt
- Rail-Zähler: Papierkorb/Verschieben/Freigabe wirken bis 60 s verzögert (TTL)
