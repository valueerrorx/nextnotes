# M4i Tester – volle Matrix M4-Ende

Auftrag: run-m4.sh (sqlite, mariadb, pgsql, nginx), Regression run-m3/m2/m1, make build, check-upstream, check-forbidden-sql. Basis 1e7ce091 + uncommitted Diff.

## Erledigt
- Start: keine Vorarbeit. Skripte teilen Compose-Projekt `nextnotes-dev` (Ports 8081–8084), `make clean build`, `down.sh all` im Trap -> nur sequentiell sicher.
- run-m4.sh gestartet; Build reproduzierbar (160 Dateien), check-upstream grün, info.xml OK, check-forbidden-sql 0 Treffer.

- run-m4.sh: EXIT 1 (2h43m). mariadb/pgsql grün (PHPUnit 387 OK, probe-grenzen grün). sqlite PHPUnit 387 OK; E2E rot: dark editor (Beacon 503), dark excalidraw. nginx E2E rot: excalidraw dark, assets/lock-return/frame/editor 404 core/preview (recommendations), anti-flicker dark 1200ms.
- Befund 1: excalidraw dark rot wegen settings-m4h.mjs (setzt slash_menu_trigger=alt-slash, kein Reset). Testfix tests/nextnotes/e2e/settings-m4h.mjs: Reset 5 Einstellungen am Ende. Nachlauf nginx settings-m4h+excalidraw hell+dunkel grün.
- run-m2.sh im Hintergrund gestartet (m2.log).

- Befund 2: nginx 404 /core/preview?fileId=142 = Skeleton-Datei Resume.odt (kein Office-Preview-Provider), angefragt von NC-App recommendations auf der Dateien-Seite; auf frischer Instanz (admin, anti-flicker, assets hell) nicht reproduziert -> Umgebungsrauschen, nicht App. Vorschlag Lead: recommendations in run-e2e.sh deaktivieren.
- Nachlauf nginx anti-flicker/assets/editor/frame/lock-return hell+dunkel gestartet (nginx-rerun.log).

- Nachlauf nginx (frische Instanz) anti-flicker/assets/editor/frame/lock-return hell+dunkel: alle grün -> anti-flicker 1200 ms = Flake (Timing).
- run-m2 sqlite grün. Nachlauf sqlite editor dark (Beacon 503): grün -> Flake.

- run-m2.sh: EXIT 0 (sqlite, mariadb, pgsql je 387 OK, utf8mb4 true).
- run-m3.sh = run-m4.sh ohne probe-grenzen (diff geprüft) -> nur sqlite nginx zur Bestätigung der vollen E2E-Reihenfolge mit Testfix; mariadb/pgsql durch run-m4+run-m2 abgedeckt.
- run-m1.sh: EXIT 0 (Lifecycle + Schema 11 Tabellen auf allen 4).
- run-m3.sh sqlite nginx gestartet (m3.log).

- run-m3.sh sqlite nginx: EXIT 1. Build/check-upstream/SQL grün, PHPUnit sqlite 387 OK, excalidraw/settings/assets/editor jetzt grün in voller Reihenfolge; einziger Fehler anti-flicker dark "A index" (1128/1216 ms).
- Befund 3: anti-flicker dark index deterministisch grenzwertig (dark-Pass nach ~30 hellen Specs: 990/1200/1128/1216 ms; hell 647–909 ms; isoliert grün). Datenabhängig, kein Flake. Lead entscheidet (Spec isolieren oder Index beschleunigen).
- Aufgeräumt: keine nextnotes-dev-Container/Volumes.

## Status
fertig

## Offen/Annahmen
- nextnotes-demo, nc35-gftest, nc35-redis nicht angefasst.
- Vorschlag: recommendations-App in run-e2e.sh deaktivieren (dev/, nicht meine Zuständigkeit).
