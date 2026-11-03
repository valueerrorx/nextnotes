# M3-fix2 Tester
Auftrag: fehlende Tests nach docs/reviews/M3-fix2-diff-r1.md: PHPUnit left_col-Fragment (Rechte), E2E tree-inplace (Archivieren, Modal-Verschieben, Alle verschieben, Löschen mit offener Notiz). Nur sqlite.
## Erledigt
- Start, Datei angelegt
- EditorPageTest::testLeftColFragmentOwnAndForeign ergänzt
- build/up/install/enable sqlite: rc 0
- run-phpunit sqlite (6er-Filter): rc 1, nur AdminSettingsTest::testAdminOnlyAndNormalizedSave FAIL (stored() leer); EditorPageTest inkl. neuem Test PASS; isoliert AdminSettingsTest rc 0, PageCsp+AdminSettings rc 0
- THEMES=dark run-e2e sqlite tree-inplace admin: rc 1, tree-inplace gleicher Fehler Schritt 11, admin grün
- run-phpunit 6er-Filter zweiter Lauf: rc 0 (79 Tests) → AdminSettingsTest flaky
- down.sh sqlite: rc 0
## Nächster Schritt
- keiner (Lead: App-Fix Reload-Zweig, danach Nachlauf tree-inplace light)
## Offen
- AdminSettingsTest::testAdminOnlyAndNormalizedSave 1/2 rot im Kombi-Lauf (stored() leer nach 200), Ursache unklar (Config-Cache?)
## Status: fertig
- E2E tree-inplace Schritte 8–11 ergänzt (Archivieren, Modal-Verschieben, Alle verschieben, Löschen offen)
- THEMES=light run-e2e sqlite (6 Specs): tree-inplace ROT (Schritt 11), admin/workspace-create/excalidraw/editor grün
- App-Befund: Ordner mit offener Notiz löschen → reload auf ?note=<im Papierkorb> → 404, ohne #left_col (curl-Repro: vorher 200, nachher 404)
