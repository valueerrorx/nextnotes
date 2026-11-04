# M4c Tester
Auftrag: Abnahme M4c+M4c-fix: build/check-upstream/check-forbidden-sql; PHPUnit sqlite gefiltert; PHPUnit pgsql (+mariadb) nur ExportTest; E2E hell export-note, menu-501, stripped, convert-import, editor; dunkel export-note.
## Erledigt
- Instanz-Kopie: Scratchpad m4ct/nextcloud, Projekt nextnotes-m4ct, Ports sqlite 8097, mariadb 8098, pgsql 8099; down.sh-Präfix nextnotes-m4ct_
- make build rc=0 (134 Dateien); check-forbidden-sql rc=0 (0 Treffer)
- up.sh sqlite rc=0, pgsql rc=0, install+enable ok; mariadb + check-upstream + PHPUnit sqlite laufen
- timeout 500 make check-upstream rc=0 (grün, 15 Prüfzeilen)
- up.sh mariadb rc=0; run-phpunit.sh pgsql --filter ExportTest rc=0 (9 Tests, 321 Ass.); mariadb dito rc=0 (9/321)
- down.sh pgsql rc=0, down.sh mariadb rc=0 (Volumes weg)
- run-phpunit.sh sqlite --filter (Auftrag) rc=0: OK 237 Tests, 8066 Ass., 15:12 min
- THEMES=light run-e2e.sh sqlite (5 Specs) rc=0: export-note 36, menu-501 91, stripped 126, convert-import 32, editor 119
- THEMES=dark run-e2e.sh sqlite export-note rc=0 (36)
- down.sh sqlite rc=0, Netz nextnotes-m4ct_default entfernt, keine m4ct-Volumes; demo/gftest unberührt
## Nächster Schritt
- keiner
## Status
fertig
## Offen
- PHPUnit sqlite gefiltert 15:12 min (Filter vom Lead vorgegeben; Gesamtlauf ca. 25 min, im Budget)
