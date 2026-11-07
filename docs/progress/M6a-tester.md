# M6a Tester — Status: fertig
Auftrag: build/check-upstream/check-forbidden-sql; PHPUnit sqlite voll, Material+Teams auf mariadb/pgsql; curl-Proben Material-Ordner. Instanz NN_PROJECT=nextnotes-m6at NN_PORT_BASE=8390.
## Erledigt
- make build (161 Dateien) Exit 0; check-upstream grün Exit 0; check-forbidden-sql 0 Treffer Exit 0
- up.sh sqlite/mariadb/pgsql Exit 0; install-app + app:enable je env OK (0.3.0)
- run-phpunit.sh sqlite (voll, allein auf der Instanz): Exit 0, 471 Tests, 14160 Assertions, 1 Skip (bekannt, Diary SQLite-Sperre), 25:24 min
- run-phpunit.sh mariadb|pgsql php/Material php/Teams: je Exit 0, OK 76 Tests, 1939 Assertions (5:43 / 6:33 min)
- curl-Probe probe-m6a.sh (scratchpad, nicht im Repo): mariadb + pgsql je 46 PASS, 0 FAIL, Exit 0 (erster Lauf 2 FAIL = Skriptfehler PUT statt PATCH beim Umbenennen, behoben)
- down.sh sqlite/mariadb/pgsql: Container + Volumes nextnotes-m6at entfernt; Demo unberührt
## Nächster Schritt
- keiner
## Offen
- keine Testanpassungen nötig; keine Spec-Abweichung gefunden
