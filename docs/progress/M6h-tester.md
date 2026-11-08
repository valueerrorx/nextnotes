# M6h Tester (Meilenstein-Abschluss M6)
Auftrag: E2E-Specs auf aktuelle UI (rail/speeddial) umstellen; AssignmentIsolationTest, MaterialIsolationTest+Aufgaben, smoke-m6, Budget; run-m6.sh; volle Matrix einmal. NN_PROJECT=nextnotes-m6h, Port-Basis 8700.
## Erledigt
- (Start, HEAD 3ac81180)
## Nächster Schritt
- Bestandsaufnahme Specs/Tests
## Offen/Annahmen
- sqlite-Instanz nextnotes-m6h:8701 hoch (App 0.3.3 aktiv), Build rc=0
- Probe-Lauf E2E hell sqlite gestartet (Log scratchpad e2e-probe-light.log) zur Ermittlung veralteter Specs
- Neu: tests/nextnotes/php/Assignments/AssignmentIsolationTest.php (Sweep alle Routen + gezielte Suche/Export/Backlinks); erster Lauf scheiterte nur an sqlite-Lock (parallel E2E) → nach Probe-Lauf erneut
- E2E angepasst: assets (Hintergrund an Inhaltsfläche, App-Start=Dashboard), anti-flicker (index.php statt ''), pages-m4e §6 (+-Menü), diary-tasks §5 (+-Menü), smoke-m4/workspace-chip (create.php raus), layout-left/mobile-m6/stripped (create.php→assignments.php), assignments-m6g (S2-Check präzise, Verdacht ID-Kollision)
- Befund offen: anti-flicker „B index Rückfall“: #app-tab-bar (dynamisch eingefügt) noch opacity 0 bei seen+2,8 s → nachprüfen (Flake vs. App)
- AssignmentIsolationTest erweitert: resolve per Titel, Ex-Mitglied + eingefroren (Sweep); MaterialIsolationTest +testAssignmentPathsKeepMaterial (noch nicht gelaufen)
- mariadb-Instanz :8702 für PHPUnit-Entwicklung. AssignmentIsolationTest 4/4, MaterialIsolationTest::testAssignmentPathsKeepMaterial, neu AssignmentBudgetTest grün auf mariadb (Budget: Austeilen 50×30 28,9 s; index L 67,6→64,3 ms; GET /assignments 36,7 ms; assignments.php 43,6 ms; 30 parallele Uploads 5,1 s, 0 Fehler)
- Testfixes: Sweep strikt nur bei eindeutigem {id}-Präfix; resolve-Gegenprobe per ID (Beobachtung: eigene Abgabe per Titel → 404)
- Neu: tests/nextnotes/e2e/smoke-m6.mjs (Material, Q1–Q5) – mariadb hell grün 48
- Neu: dev/nextcloud/run-m6.sh (Kopie run-m5 + M6-Kommentar); geplant Volllauf mit E2E_PARALLEL=1 (Zeitbudget, ~170 min sonst)
- Weitere Testfixes: export-zip (Zurück-Link → Dashboard), frame (img/media ohne requesttoken-Pflicht, Hintergrund #right_pane)
- Läuft: Fix-Nachlauf mariadb hell (12 Specs), Probe sqlite hell (alle)
- anti-flicker B index: reproduziert (sqlite unter Last): #app-tab-bar wird ~1 s nach Inhalt eingefügt, CSS-Rückfall-Animation startet je Element neu → sichtbar erst ~3,4 s nach Navigationsstart. mariadb (ohne Last) grün. Klassifikation: Flake unter Last + kleine App-Schwäche (an Lead melden, nicht fixen)
- pages-m4e: Leisten-Check iconSidebarCreateBtn → Dashboard/Home (UI-rail) angepasst
- layout-left: Listen-Container (.diary-month, .tasks-note-group, .nm-bulk-bar u. a.) links am Kopf, Daten-Setup ergänzt (noch ungetestet)
- pages.mjs/tag-filter.mjs: Speed-Dial-Menüpunkte entfernt → Prüfung „kein Speed-Dial-Eintrag“ bzw. interner Tag per API (Altdaten)
- frame.mjs: Warten auf headersArray() begrenzt (Hänger 600 s auf mariadb nach Fremdkontext)
- stripped: „austeilen“ fiel unter /teilen/ (Testfehler) → (?<!aus)teilen; assignments.php Export erlaubt
- layout-left 390 attachments_list.php: Liste 26 px vs Kopf 16 px – Ursache src/public/css/shared/responsive.css:110 (.shared-content padding 10px ≤ 800 px) → App-Abweichung (UI, klein), an Lead
- Probe sqlite hell fertig: rot nur in angepassten Specs (anti-flicker Last, assets, assignments-m6g, export-zip, frame, pages, stripped) – alle als Testfehler bzw. s. o. klassifiziert
- Nachläufe mariadb hell: stripped, pages-m4e, frame, pages grün; layout-left rot nur App-Abweichung 390 attachments_list
- Instanzen abgebaut; Volllauf run-m6.sh gestartet (E2E_PARALLEL=1), Log dev/nextcloud/.cache/run-m6-M6h
- run-m6 Versuch 1: rot nach 1 min in allen Umgebungen: lifecycle.sh erwartete 11 Tabellen (M6d hat 13) → Testskript-Fehler, auf 13 korrigiert; check-schema 4× OK; Volllauf neu gestartet
- Volllauf 2 (run-m6-M6h-2) läuft: Lebenszyklus/Schema/utf8mb4/circles-off grün, PHPUnit 549 Tests je DB in Arbeit
- Phase 1 grün: PHPUnit sqlite 549 (1 Skip), mariadb 549, pgsql 549 OK; Lebenszyklus/CSP/Schema/utf8mb4/probe-grenzen/circles-off grün (41–48 min). Phase 2 E2E läuft
- Volllauf 2 fertig rc=1, 109 min: Phase 1 alle grün; E2E sqlite/nginx je 43 Specs×2: rot layout-left (App-Abw. 390), assignments-m6g dunkel (Testreihenfolge: B besitzt Team aus teams-m5d → fix), mobile-m6 dunkel (gleichnamige Notiz aus Hell-Lauf → fix), diary-tasks hell sqlite + ui-nav-vt dunkel nginx (Verdacht Flake, parallel) → Nachlauf
- Nachlauf (nur betroffene Specs): sqlite hell diary-tasks/teams-m5d/assignments-m6g/mobile-m6, dunkel assignments-m6g/mobile-m6 grün; nginx ui-nav-vt hell+dunkel grün → diary-tasks und ui-nav-vt = Flake unter E2E_PARALLEL
- Instanzen + Volumes nextnotes-m6h abgebaut
## Status: fertig

## Nachtest layout-left (CSS-Fix .shared-content padding-inline 0 mobil)
- Auftrag: sqlite (nextnotes-m6h, Port 8701), nur layout-left hell+dunkel, danach abbauen
- Gestartet: up.sh sqlite + install-app.sh
- up.sh sqlite + install-app.sh rc=0, app:enable nextnotes 0.3.3
- NN_PROJECT=nextnotes-m6h NN_PORT_BASE=8700 dev/nextcloud/run-e2e.sh sqlite layout-left → rc=0; hell grün 130, dunkel grün 130 (390 attachments_list: .shared-filter-bar/.attachments-list-container links am Kopf PASS)
- down.sh all rc=0; keine Container/Volumes/Netze nextnotes-m6h übrig
## Status Nachtest: fertig
