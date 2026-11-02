# M1b – Arbeitsstand Tester

Status: fertig

## Auftrag
SPEC §13 M1 Krit. 1–6 auf sqlite/mariadb/pgsql (Apache) und nginx/fpm prüfen: run-m1.sh, unabhängige Gegenproben, Fehlerfall → Exit≠0, Aufräumen.

## Erledigt
- `tests/nextnotes/csp/csp-gegenprobe.mjs` (neu): CSP-Header, 0 Verletzungen, 3 Provokationen müssen erkannt werden.
- `dev/nextcloud/run-m1.sh` (alle 4): EXIT=0; Schema OK sqlite/mysql/pgsql/sqlite(nginx); Spike 4×200 Apache+nginx; CSP 0; Seite 200/303; danach 0 Container/Volumes/Netze.
- Krit. 2 eigen: `xmllint --schema .cache/info.xsd` exit 0; NC 35–35, PHP 8.3–8.5, 1 navigation (Route nextnotes.page.index).

- Eigenprüfung (up.sh+install-app.sh, dann direkt `docker compose exec … occ`): je Umgebung enable exit 0, dbtype sqlite3/mysql/pgsql/sqlite3, app:list enabled 0.1.0, `check-schema.php --db=…` exit 0 (4×).
- Eigener curl: 8081–8084 dashboard.php über /index.php/apps und /apps 200 + #nextnotes-root; Server Apache bzw. nginx; abgemeldet 303 → /login.
- `csp-gegenprobe.mjs` 4 Umgebungen × 2 Pfade: exit 0 (0 Verletzungen; Bild/setTimeout-String/Inline-HTML erkannt + blockiert). Hinweis: DOM-eingefügtes Script wegen 'strict-dynamic' erlaubt, CDP-eval CSP-frei → nicht als Provokation geeignet.
- Befund (kein M1-Kriterium): 404 `/apps/theming/favicon/nextnotes` auf allen Umgebungen → Konsolenfehler. Ursache: `nextnotes/img/app.svg` beginnt mit SPDX-Kommentar → mime text/plain, IconBuilder rendert nicht (files/dashboard: 200).

- Negativ check-schema.php direkt: kaputte Erwartung exit 1; Zusatz-/verbotene Tabelle (assignments) exit 1; fehlende Tabelle/Spalte exit 1.
- Negativ Pipeline: `run-m1.sh sqlite` mit kaputter expected-m1.json → exit 1 (FAIL nextnotes_notes.id); `CHROMIUM=/nonexistent run-m1.sh nginx` → exit 1 (CSP-Schritt). Beide räumen auf (0 Container/Volumes). expected-m1.json wiederhergestellt (cmp ok).
- Nicht ohne dev-Änderung provozierbar: vorab angelegte oc_nextnotes_*-Tabelle (lifecycle macht immer down/up); ersatzweise über check-schema abgedeckt.
- `down.sh all` exit 0; 0 Container, 0 Volumes, 0 Netze nextnotes-dev.

## Nächster Schritt
keiner (Bericht an Lead).

## Offene Punkte
- Favicon-404 (app.svg mit führendem Kommentar) → Architekt.
- run-m1.sh prüft Spike/CSP auf Apache nur in sqlite; mariadb/pgsql von mir manuell geprüft (bestanden).
