# M4d Tester
Auftrag: Abnahme M4d: build/check-upstream/check-forbidden-sql; PHPUnit sqlite voll; ExportZipTest+ExportTest mariadb; E2E hell export-zip, export-note, menu-501, stripped, folder-actions, dunkel export-zip; Upgrade 0.1.1 -> Working Tree (ExportTempCleanupJob registriert).
## Erledigt
- Instanz-Kopien: Scratchpad m4dt/nextcloud (Projekt nextnotes-m4dt, Ports 8101-8104), m4du/nextcloud (Projekt nextnotes-m4du, Upgrade, Ports 8111-8114); env.sh mit NN_REPO_ROOT-Override, down.sh-Präfix je Projekt
- make build rc=0 (134 Dateien); check-forbidden-sql rc=0 (0 Treffer)
- m4dt up sqlite rc=0, up mariadb rc=0, App 0.1.2 enabled
- run-phpunit.sh mariadb --filter ExportZipTest|ExportTest rc=0: OK 17 Tests, 628 Ass.
- PHPUnit sqlite voll läuft im Hintergrund (Log m4dt/phpunit-sqlite.log)
- make check-upstream rc=0 (grün, 15 Prüfzeilen)
- Upgrade (m4du, sqlite 8111): git worktree HEAD (0.1.1) gebaut rc=0, install+enable 0.1.1 rc=0 (4 NextNotes-Jobs); Working-Tree-Build kopiert, occ upgrade rc=0 "Updated <nextnotes> to 0.1.2"; background-job:list zeigt ExportTempCleanupJob; background-job:execute --force-execute rc=0; App-Seite alice 200
- db:schema:check nach Upgrade: 2 fehlende Core-Indizes (oc_job_classes_registry, oc_federated_invites), identisch auf frischer sqlite-Instanz, mariadb frisch sauber -> Core/SQLite, nicht App
- m4du down rc=0, Netz entfernt, worktree entfernt
- THEMES=light run-e2e.sh mariadb (5 Specs) rc=0: export-zip 32, export-note 36, menu-501 92, stripped 126, folder-actions 100
- THEMES=dark run-e2e.sh mariadb export-zip rc=0 (11 Prüfungen; Spec prüft dunkel absichtlich nur Ordner-Menü)
- down.sh mariadb rc=0
- run-phpunit.sh sqlite (voll) rc=0: OK 338 Tests, 10017 Ass., 18:38 min
- down.sh sqlite rc=0, Netz nextnotes-m4dt_default entfernt; keine m4d-Container/Volumes; demo/gftest unberührt
## Nächster Schritt
- keiner
## Status
fertig
## Offen
- Parallel lief M4d-fix (Architekt, 04:51-04:59 geändert: ZipExportService, ExportArchive, ApiExceptionMiddleware, templates/export_error.php, ExportZipTest, ApiClient, export-zip.mjs). Meine App-Builds/Kopien stammen von ca. 04:40-04:47 -> Ergebnisse gelten für Stand vor dem Fix; dunkel export-zip lief mit neuer Spec gegen alte App. Nachlauf für Fix nötig.
- Fremde Instanz nextnotes-m4dfix (7 Container) läuft, nicht von mir, nicht angefasst.
