# M5d Tester — Status: fertig
Auftrag: make build + Checks; PHPUnit voll sqlite, Teams auf mariadb/pgsql; E2E hell teams-m5d, smoke-m4, workspace-create; manuell Teilen Team-WS 403, users/search Enumeration. Instanz NN_PROJECT=nextnotes-m5dt NN_PORT_BASE=8390.
## Erledigt
- make build (161 Dateien) rc0; make check-upstream grün rc0; check-forbidden-sql 0 Treffer rc0
- up.sh sqlite/mariadb/pgsql (8391-8393), install-app + app:enable rc0
- run-phpunit.sh mariadb /tmp/nn-tests/php/Teams: OK 63 Tests, 1343 Assertions, rc0 (3:59)
- run-phpunit.sh pgsql Teams: OK 63 Tests, 1343 Assertions, rc0 (4:22)
- manuell (scratchpad manual.sh, mariadb 8392): Team-WS teilen alice(Ersteller) 403 "Team workspaces cannot be shared", unshare 403, bob(Mitglied) 403; privat teilen 200; Nicht-Besitzer 404. users/search: q<2 leer, >100 400, ohne Login 401, Enumeration aus/nur Gruppe/nur Gruppenmitglieder/shareapi aus = identisch zu core sharees-API (nach Config-Cache-Ablauf ~5-10 s); neue Freigabe ohne gem. Gruppe 400, Update bestehender 200; nie E-Mail im Ergebnis. PASS
- run-phpunit.sh sqlite (voll): Tests 457, Assertions 13510, Skipped 1, rc0 (23:08)
- run-e2e.sh sqlite teams-m5d smoke-m4 workspace-create (THEMES=light): rc0, 62/164/22 Prüfungen grün, 0 FAIL (37/34/9 s)
- down.sh mariadb/pgsql/sqlite: Container+Volumes entfernt; Demo, nc35-gftest unberührt
## Nächster Schritt
- keiner
## Offen
- keine Testanpassungen nötig; Hinweis: Core-Config-Änderungen per occ wirken im Web erst nach ~5-10 s (APCu-Cache), bei manuellen Prüfungen warten
