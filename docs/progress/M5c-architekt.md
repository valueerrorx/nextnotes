# M5c – Architekt

Status: fertig

## Auftrag
CleanupJob (Schritt 1 Existenz über `TeamService::exists()`, Massenschutz, Rückweg, Hinweis Tag 23, Purge Tag 30), `TeamCleanupService`, `occ nextnotes:team:orphan-check [--dry-run|--force]`, verwaiste Anhangsdateien + Excalidraw-Vorschauen, README „Betrieb“. Plan: `docs/slices/M5-plan.md` §2.5/§2.6/M5c.

## Erledigt
- Plan gelesen; Code gelesen (TeamService::exists, TeamStateService, TeamMapper, WorkspaceDeletion::purge, TeamLifecycleService, Notifier, AttachmentService, ExcalidrawService::write, Tests Support/Jobs/TeamLifecycle)

## Entwurf
- `Service/TeamCleanupService` (run(budget), check(dryRun, force) für occ, purge(teamId, deadline)), `Service/OrphanFileCleanup` (Dateien ohne Zeile > 24 h; Excalidraw-Vorschauen mit Markierung), `BackgroundJob/CleanupJob`, `Command/TeamOrphanCheck`
- Schritt 1: alle Team-Zeilen (Cursor `cleanup_team_cursor`, rotierend), Ergebnisse sammeln, dann anwenden; Fehler → Schritt 1 komplett verworfen (Plan); Massenschutz verwirft nur Hochzählen/Verwaisen, Reset/Rückweg bleiben
- occ ohne --dry-run: fehlende Teams sofort verwaisen (Admin = Bestätigung), Massenschutz außer --force
- Excalidraw-Erkennung: neue Spalte `attachments.origin` ('excalidraw'), Migration 000400, App 0.2.1; Altbestand ohne Markierung bleibt
- Dateien: IAppData listet keine Unterordner → IRootFolder + getAppDataDirectoryName() (@since 30)

- Code: Migration Version000400 (origin + nn_att_origin), info.xml 0.2.1 + CleanupJob + <commands>, Attachment::origin, AttachmentService (ORIGIN_EXCALIDRAW, createFromString/copyAll), ExcalidrawService markiert, TeamMapper (findAllOrdered, findOrphanedSince, countMissing, resetMissing, reactivate, markDeletionNotified, deleteOrphaned), AttachmentMapper::findByOriginAfter, TeamLifecycleService (notifyCreators, deletionSoon), Service/TeamCleanupService, Service/OrphanFileCleanup, BackgroundJob/CleanupJob, Command/TeamOrphanCheck, Notifier (cleanup_suspicious, Datum deletionDate), l10n de/de_DE
- Abweichung: Löschung frühestens 7 Tage nach dem Hinweis (Cron aus) → Notifier-Datum = deletionDate()

- build rc=0 (160 Dateien), check-forbidden-sql 0, check-upstream grün
- Instanz NN_PROJECT=nextnotes-m5c NN_PORT_BASE=8230 sqlite up + App 0.2.1 enabled (install-app + app:enable); Job registriert, `orphan-check --dry-run` rc=0, Job force-execute ok
- tests: schema expected-m1 (origin, nn_att_origin), php/Teams/CleanupTestCase.php (Basis)

- tests php/Teams/CleanupJobTest.php (13) + OrphanCheckCommandTest.php (5): sqlite 18 OK (222 Ass.); Mutation Massenschutz (a) aus → testMassProtection rot, zurückgesetzt + neu installiert
- läuft: Regression sqlite Teams\\|Jobs\\|ExcalidrawSave|Duplicate|Attachment

- Doku: SPEC §2.1 (origin), §5 CleanupJob, §6 (cleanup_suspicious, Datum), §8 orphan-check, §13 M5 Krit. 4 (H3), §14 OCP; ADR 0003 Nachtrag M5c; README Betrieb; Plan M5c „Umsetzung“
- Tests: createMock → createStub (Notices)

- Regression sqlite Teams\\|Jobs\\|ExcalidrawSave|Duplicate|Attachment: 99 OK (2183 Ass.), rc=0 (Stand vor notifySoon-Guard)
- TeamCleanupService::notifySoon nur bei isAvailable() (eingefroren: keine Frist)
- validate-info OK; build rc=0 (160), check-forbidden-sql 0, check-upstream grün (nach README)
- mariadb + pgsql up (8232/8233), App 0.2.1; läuft: CleanupJobTest|OrphanCheckCommandTest|TeamLifecycleTest auf beiden

- mariadb + pgsql CleanupJobTest|OrphanCheckCommandTest|TeamLifecycleTest: je 30 OK (437 Ass.)
- sqlite neu installiert (Endstand): M5c-Tests 18 OK (222 Ass.), keine Notices
- Schema-Prüfung (check-schema.php) sqlite/mysql/pgsql OK
- Instanz nextnotes-m5c abgebaut (down.sh all rc=0), Demo unberührt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte/Annahmen
- Ein dauerhaft mehrdeutiges Team (exists() wirft, z. B. föderiert) blockiert Schritt 1 für alle Teams (Plan: Fehler → Lauf abbrechen); Events wirken weiter
- Excalidraw-Vorschauen vor 0.2.1 haben keine Markierung und bleiben liegen
- Vorschau, die per Kopieren in eine andere Notiz eingefügt wurde, gilt dort nicht als Verweis (wie ExcalidrawService::write)
- Sperre gegen gleichzeitiges Umwandeln im Purge nicht per Test belegt (Mutation unbemerkt)

# Nacharbeit r1 (docs/reviews/M5c-r1.md, FREIGABE; Lead-Entscheidungen H1–H5 + Tests)
Status: in Arbeit
- Plan: H1 Fehler je Team überspringen (`error`), Abbruch nur ohne Circles/alle Fehler, Zähler je Team in App-Config `cleanup_team_errors`, Admin-Meldung `cleanup_team_error` einmal ab 3 Läufen; H2 `TeamMapper::lockRow` vor Workspace-Zeilen (Purge, makePrivate); H3 Budget-Aufteilung (Schritt 1 ≤ Budget − 2×Mindest, Purge/Dateien je ≥ Budget/5); H4 globaler Verweis-Check (Notizen inkl. Papierkorb + Snapshots, LIKE `/attachments/<id>`) unter Sperre; H5 Sortierung in PHP mit strcmp
- r1 Code: TeamMapper::lockRow; makePrivate sperrt Team-Zeile zuerst; TeamCleanupService (Ergebnis `error`, Abbruch nur ohne Circles/alle Fehler, trackErrors + `cleanup_team_error`, Budget-Reserve 1/5, usort strcmp, Purge-Tx Team-Zeile → Zustand → Workspace); Command listet `error`; Notifier `cleanup_team_error` + l10n; NoteMapper/SnapshotMapper::anyContentLike, OrphanFileCleanup::usedElsewhere unter Sperre
- r1 Doku: SPEC §5/§6/§8, ADR 0003, Plan §2.5 + Umsetzung, README
- Nächster Schritt: Tests ergänzen (Vorschau Papierkorb/Duplikat/HTML-Kopie/24 h, H1 mehrdeutig + fehlend, Fehlermeldung nach 3 Läufen), Budget-Test anpassen, Instanz, Läufe
- r1 Tests: CleanupJobTest +testAmbiguousTeamSkippedOthersCounted, +testAllLookupsFailDiscardStepOne (ersetzt testLookupErrorDiscardsStepOne), +testPreviewReferencedElsewhereStays (HTML-Kopie, Papierkorb, Duplikat), +testPreviewKeptWhileNoteRecentlyChanged; Budget-Test auf Reserve angepasst; OrphanCheckCommandTest +testErrorTeamListedAndSkipped; CleanupTestCase räumt cleanup_team_errors + alle cleanup-Meldungen
- r1: build rc=0 (160), check-forbidden-sql 0, check-upstream grün; Instanz nextnotes-m5c neu; sqlite CleanupJob|OrphanCheck 22 OK (267 Ass.); Mutation usedElsewhere aus → testPreviewReferencedElsewhereStays rot, zurückgesetzt + neu installiert
- läuft: sqlite CleanupJob|OrphanCheck|TeamLifecycle|Excalidraw|Duplicate; mariadb + pgsql CleanupJob|OrphanCheck
- r1 Ergebnis: sqlite CleanupJob|OrphanCheck|TeamLifecycle|Excalidraw|Duplicate 51 OK (1117 Ass.); mariadb + pgsql CleanupJob|OrphanCheck je 22 OK (267 Ass.); Instanz abgebaut (down.sh all rc=0), Demo unberührt
Status r1: fertig
