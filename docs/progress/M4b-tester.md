# M4b Tester
Auftrag: Abnahme M4b: build/check-upstream/check-forbidden-sql, PHPUnit sqlite voll, E2E hell (convert-import, menu-501, stripped, editor, folder-actions, tree-inplace), dunkel convert-import, gezielter Test Markdown+Anhang-Bild.
## Erledigt
- Instanz: Scratchpad m4bt/nextcloud, Projekt nextnotes-m4bt, sqlite Port 8097, down.sh-Präfix nextnotes-m4bt_
- make -C nextnotes build rc=0; check-forbidden-sql rc=0 (0 Treffer); timeout 400 make check-upstream rc=0 ((f) 7, (g) grün)
- up.sh sqlite rc=0, install-app rc=0, app:enable ok
- neu: tests/nextnotes/e2e/md-attachment-image.mjs (Markdown + Anhang-Bild, kein 404)
- run-phpunit.sh sqlite rc=0: OK 320 Tests, 9369 Assertions, 17:39 min
- md-attachment-image hell rc=0 (6); Gegenprobe Fix im Container zurückgenommen → FAIL 404 /api/v1/notes/<id>/attachments/<n> (Test trennt); Fix wiederhergestellt, Datei identisch mit Repo
- THEMES=light run-e2e.sh sqlite (7 Specs) rc=0: md-attachment-image 6, convert-import 32, menu-501 91, stripped 126, editor 119, folder-actions 100, tree-inplace 148
- THEMES=dark run-e2e.sh sqlite convert-import rc=0 (32)
- down.sh sqlite rc=0, Netz nextnotes-m4bt_default entfernt, keine m4bt-Volumes; demo/gftest unberührt
## Nächster Schritt
- keiner
## Status
fertig
## Offen
- PHPUnit 17:39 min (Budget überschritten, volle Suite ausdrücklich verlangt)
- Ohne Fix lädt das Bild trotzdem (Client korrigiert src später), aber erst 404 auf /api/v1/notes/<id>/attachments/<n>; Test prüft daher 4xx-Freiheit
