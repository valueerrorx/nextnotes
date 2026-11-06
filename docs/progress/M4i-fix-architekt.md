# M4i-fix – Architekt

Auftrag: M4-Nacharbeit: Sperrreihenfolge Workspace->Notiz (B64), Guard resolvePath, Tests (PATCH-Rennen, Import/Export Lesefreigabe), SPEC §6 UID-Restlücke, Startzeit Hauptansicht, recommendations aus, Matrix-Laufzeit.

## Erledigt
- Start, Quellen gelesen (M4i-r1, M4i-tester).
- (1) NoteService::update: mayCreateFolder → FolderService::lockWorkspace(ws) vor updateLocked (Notiz-Sperre); lockForCreate → public lockWorkspace.
- (2) Transaction::active(); resolvePath(create) ohne offene Tx → eigenes tx->run.
- (3) Tests: DiaryTest testConcurrentDateRenamesShareOneFolder, testDateRenameLocksWorkspaceBeforeNote (mariadb/pgsql, Sperrreihenfolge per zweiter Verbindung); ApiClient::start; PageIsolationTest testM4ReadShareImportExport.

- (4) SPEC B64 + §6 Restlücke nachgeführt; smoke-m4 Erwähnungen .mentions-toolbar + Plan-Verweis views-m4g; M4-plan Krit.1/4 ergänzt.
- (6) run-e2e.sh: recommendations + firstrunwizard aus; Dauer je Spec.
- env.sh/compose.yaml/down.sh: NN_PROJECT, NN_PORT_BASE (Demo/gftest tabu).
- Repro läuft: scratchpad/fix/repro.sh (Projekt nextnotes-m4ifix, Port 8274, nginx, THEMES=light alle Specs) → repro.log.
- (7) run-m4.sh neu: Phase 1 parallel (Lifecycle+Schema, frisch, utf8mb4, PHPUnit, probe-grenzen), Phase 2 E2E seriell; run-m2/m3 gelöscht; README + M4-plan.
- PHPUnit (Projekt nextnotes-m4ifix): mariadb+pgsql Filter Diary|PageIsolation|WorkspaceDelete|NoteVersion|CoreScenario 32 Tests, 1 Fehler (numerischer workspace= für Leser → 404, Testfall entfernt); danach Diary+ReadShare 9/9 OK beide. Mutation (lockWorkspace aus): mariadb Deadlock 1213, pgsql lock timeout → Test rot (belegt Reihenfolge). Achtung opcache.revalidate_freq=60.
- Anti-Flicker-Befund: Phase = domInteractive→DCL (defer-Skripte), nicht Server (TTFB ~40 ms). RecalcStyle ~456 ms, Layout ~289 ms, Script ~311 ms bei 1,4k Knoten; core.css 1,16 MB (1061 Lucide-Data-URIs).
- PHPUnit sqlite Filter (Diary|PageIsolation|CoreScenario|NoteVersion|WorkspaceDelete): 32 OK, 1 skipped (Sperrtest nur mariadb/pgsql).
- Ursache Startzeit: ADR-0017-Wächter `:where(:not(#header,#header *,…4×))` an `*`-Regeln → 5 volle Stilberechnungen à ~110 ms (4,6k Elemente), dunkel zusätzlich `html[data-theme=dark] *`. A/B per Route: ULT 634→~170 ms, DCL 961→~300 ms. Fix: css-bump.mjs NOT_NC_CHROME = `:where(:not(:is(N) *):not(N))` (gleichwertig), BUMP_AFTER_PSEUDO-Regex, frame.mjs-Regex, ADR 0017 Konsequenz. build + check-upstream grün.
- Messung dunkel nach vollem Light-Pass (31 Specs grün, 1525 s): alter Build Phase index 1130/1108 ms (Befund nachgestellt). Nur Wächter-Umbau: keine Besserung (Route-A/B war Artefakt). Selektor-Statistik: 14 `::highlight(pz-syntax-*)`-Regeln als `*`-Regeln je Element. Fix: `::highlight(name)` ohne Zusatz → 865/908/839/837 ms (warm 735→410–444). Versuch `*`-Subjekt nur Scoped-Kopie: kaum Gewinn, verworfen. ADR 0017 nachgeführt.
- run-e2e nginx (Daten nach vollem Lauf) anti-flicker, smoke-m4, diary-tasks, frame hell+dunkel: 4×2 grün (EXIT 0).
- Vorher/nachher gleiche Daten (noch mehr Notizen): alter Build hell 1248/940/820, dunkel 1254/973/801 (beide rot); neuer Build hell 911/631/480, dunkel 912/642/505 (grün).
- run-m4.sh: Fehlerpfade per Stub geprüft (set -e im Subshell nur außerhalb ||-Liste; E2E_PARALLEL); Lifecycle sqlite mit NN_PROJECT läuft (lc.log).

- Lifecycle sqlite mit NN_PROJECT=nextnotes-m4ifix: OK, Schema 11 Tabellen (LC EXIT 0).
- Abbau: Projekt nextnotes-m4ifix down all, keine Container/Volumes/Netze übrig. build 160 Dateien, check-upstream grün, check-forbidden-sql 0, php -l/node --check OK.

## Runde 2 (Review M4i-fix-r1, Hinweise 1–5)
- SPEC B64 Restfall allgemein; mayCreateFolder nur bei DiaryService::isDateTitle (parseTitle); restoreDeleted + mirrorPath sperren Workspace vor dem Anlegen; DiaryTest Umbenennen: 503 nur SQLite.
- env.sh: NN_PROJECT nur ^nextnotes-[a-z0-9-]+$ ohne nextnotes-demo; NN_PORT_BASE Zahl; nn_check_ports: 8090 + Host-Ports fremder (auch gestoppter) Container (gftest 18035) → Abbruch, belegte Ports (ss) ohne eigenen Container → Abbruch. Selbsttest 10 Fälle wie erwartet.
- PHPUnit Filter Diary|PageIsolation|CoreScenario|WorkspaceDelete|IsolationTest|ListPages: sqlite 154 OK (1 skipped, Sperrtest), mariadb 154 OK. build 160, check-upstream grün, check-forbidden-sql 0, bash -n alle Skripte OK. Projekt nextnotes-m4ifix abgebaut.

## Status
fertig

## Offen/Annahmen
- Keine neuen P/B-Nummern (kein src/-Patch, kein Upstream-Bug; CSS-Ursache lag im Port-Build, ADR 0017 nachgeführt).
- run-m4.sh neu nicht als Volllauf getestet (nur Stub-Orchestrierung + Lifecycle real); erster Volllauf beim nächsten Meilenstein.
- `workspace=<id>` (numerisch) für Freigabe-Empfänger → 404 (nur Name/Schlüssel); Testfall entsprechend.
- dev/upstream nutzt Port 8090 (= Demo-Port) – nicht angefasst, Hinweis an Lead.
