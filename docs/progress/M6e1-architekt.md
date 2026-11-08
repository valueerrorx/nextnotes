# M6e1 – Architekt (Austeilen, Abgabe-Zustand, Sperren, Quote)

Auftrag: M6e1 nach Plan §2.3a/§2.4/Done M6e1, r2-Hinweise 1/3, M6d-r1; vorgezogen r1-H1 (Kontolöschung schont Abgaben), r1-H3 (Vorlagen-Neuprüfung alle Schreibwege), r1-H6 (Dashboard listMenu), Quelle nur eigene/Team-Notizen, Feedback erst nach Rückgabe. Instanz `nextnotes-m6e1` Port 8620. Kein Commit.

## Erledigt
- Plan, Reviews r2/M6d-r1, M6d-Progress gelesen

- Instanz nextnotes-m6e1 sqlite: up.sh im Hintergrund gestartet (Log scratchpad/up.log)
- Code gelesen: AssignmentService, AccessService, QuotaService, NoteService-Schreibwege, UserDataService, Tests-Basis

- Instanz sqlite bereit (8621)
- SubmissionMapper (updateIfStatus, materialize, closeOpen, studentsOf, findLeft, clearLeft, detachStudent, clearFeedbackBy), WorkspaceMapper::ensureAssignmentWorkspace
- SubmissionStateService neu (Automat, materialize, studentCanEdit, Sperr-Kommentar)
- AccessService: submissionNoteLevel mit materialize+studentCanEdit, submission_locked, lockForWrite/recheckLocked
- Schreibwege mit Neuprüfung unter Notizsperre: NoteService (update, beacon, restoreSnapshot, setTags, writeFields für Icon/Farbe/Pin/Breite/Titelstil/Favorit/Kanban), TaskService::mutate, Excalidraw::write, Conversion, Attachment store/destroy (move: requireWorkspace write auf kind=assignments → 403 schon)

- DistributionService neu (start draft→distributed mit Team→Aufgabe→Vorlage-Sperre + Quote-Vorprüfung 413, reconcile a/b, createFor je Schüler), DuplicateService::copyAsSubmission
- AssignmentService: distribute, close, reconcileOwn in index, materialize in Listen, Quelle-Regel (403 source_not_allowed), Verlängerung → reconcile
- SubmissionService neu (submit, giveBack=return, reopen, feedback), Routen 6 neu, Controller
- H1: UserDataService::neutralize löst Kopien+Abgaben in derselben Tx (Notizen vor Abgaben), feedback_by/assignments.created_by NULL; purgeWorkspaces + WorkspaceDeletion::purge nur role=note, Workspace mit Systemnotizen bleibt (Warnung); Listener übergibt Anzeigename
- H6: DashboardViewService listMenu; l10n „Assignments – %s“
- Tests: AccessService-Konstruktor in AssignmentAccessTest/TeamLifecycleTest angepasst

- make build rc=0, install-app sqlite, app 0.3.1 enabled
- Tests geschrieben: Assignments/{DistributeTest, SubmissionStateTest, SubmissionLockTest, AssignmentAccountDeletionTest}, AssignmentQuotaTest erweitert, AssignmentTestCase (distributedFixture, submissionsOf, raceOnNote), ApiClient::parallelUploadsOf

- Entwicklungsläufe sqlite: DistributeTest 5/5 OK; State/Lock/AccountDeletion/Quota 9 Tests, 2 Testfehler (201 statt 200, content_width) behoben, Lock 3/3 OK
- Routen-Tests: IsolationTest (+6 excluded, 135), RouteDiffTest/CoreScenarioTest PORT_ONLY +6
- Doku: SPEC §8 (Umsetzung M6e1, Sperr-Reihenfolge), §9.1, §9.2, §9.3, §9.5; Plan §11

- check-upstream rc=0, check-forbidden-sql 0 Treffer rc=0
- Optimierung: reconcileOwn nur für Level 1, reconcile ohne studentsOf bei leerer Schülerliste; erster Abschlusslauf dafür abgebrochen, neu gestartet (Log scratchpad/final-sqlite.log)
- mariadb/pgsql up + install im Hintergrund (Log scratchpad/up-maria.log, up-pg.log)

- mariadb up + App 0.3.1 rc=0; Gegenproben-Skript vorbereitet (scratchpad/gp/apply.py: beacon-Neuprüfung, Purge-Filter, Detach, Quelle-Regel, studentCanEdit bei submit)

- mariadb/pgsql Assignments: je 23 Tests, Fehler nur AssignmentAccessTest (Quelle aus anderem Team → jetzt 403, Test angepasst; mariadb zusätzlich Nadel "3," traf due_at → '"id":N,'); alle M6e1-Tests grün auf beiden
- Nach Abschluss sqlite: AssignmentAccessTest auf sqlite/mariadb/pgsql nachlaufen lassen

- Abschlusslauf sqlite (249 Tests, Assignments|Isolation|FilterReadable|RouteDiff|CoreScenario|Csrf|TeamQuota|UserDeletion|NoteVersion|Attachment|Conversion|Tasks|ExcalidrawSave|WorkspaceDelete|TeamLifecycle|MaterialIsolation|ViewsM4g): 2 Fehler UserDeletionTest (Konstruktor, Test angepasst), 1 AssignmentAccessTest (Quelle-Regel, Test angepasst); Nachlauf sqlite AccessTest+UserDeletion 8/8 OK, mariadb/pgsql AccessTest 5/5 OK
- mariadb/pgsql Assignments 23 Tests: übrige grün
- Gegenproben (sqlite): Detach, Purge-Filter, Quelle-Regel, studentCanEdit bei submit → 4 rot; Rennen (beacon ohne Neuprüfung) auf mariadb → 2 rot; alles zurückgesetzt
- Instanz nextnotes-m6e1 abgebaut (down.sh all); Demo unberührt

## Nächster Schritt
- keiner. Status: fertig
