# M2a – Tester

Status: fertig

Auftrag: PHPUnit-Tests M2a (Routen-Diff, Isolation, Kern-Szenario+Fixtures, Workspace-Name/Schlüssel, 401/412, Attribute, XSS/Validierung, forbidden-sql), run-m2.sh auf sqlite/mariadb/pgsql, Gegenprobe, down.sh all.

## Erledigt
- Plan gelesen (docs/slices/M2-plan.md r2)
- Dev-Instanz sqlite läuft (up/install/enable, Exit 0)
- tests/nextnotes/php/Support/{ApiClient,Response,ApiTestCase}.php (App-Token per IProvider, Dump/Purge per QB)
- Routes/RouteDiffTest.php, Access/ControllerAttributeTest.php (Lauf sqlite: Attribut grün; RouteDiff #185 vor Router → gefiltert)

- Security/CsrfTest (Login braucht Origin-Header), Security/HtmlSanitizerTest (25 Upstream-Fälle), Security/XssValidationTest, Scenario/WorkspaceNameTest, Access/DenyByDefaultTest: sqlite grün (run-phpunit.sh sqlite --filter …, Exit 0)
- Scenario/CoreScenarioTest (Szenario wie dev/upstream/capture.php, ETag 304, Fixtures-Schlüsselvergleich, 1/4 parallele Erstzugriffe → 1 „Notizen“) + fixtures/allowed-diff.json (leer, keine Abweichung nötig): sqlite grün
- Access/IsolationTest (routeTable 53 Routen, Slots/Varianten, Kontrollen A+B, Querverweis-Prüfung, Detektor-Selbsttest); Lauf 1 sqlite: 2 rot (kanban visible_order A-IDs → 200 ohne Wirkung; PATCH linked_note_id A → 200, wird ignoriert wie Upstream) → als hints markiert, an Lead melden; POST /notes linked_note_id-Fall ergänzt
- IsolationTest sqlite Lauf 2: OK (55 Tests, 1533 Assertions, Exit 0)
- Gegenprobe: NoteService.php:354 requireNote → notes->find (linked_note_id), install-app sqlite + restart → `run-phpunit.sh sqlite --filter 'POST /notes\b'` Exit 1 (201 statt 403/404 + Querverweis erkannt); Datei aus Kopie zurück, sha256 OK, git status nextnotes/ unverändert
- `SELFTEST=1 check-forbidden-sql.sh` Exit 0 (0 Treffer); Nacharbeit-Stichprobe: head -c4 svg = `<svg`, grep stable leer, down.sh mit docker-Attrappe Exit 1
- run-m2.sh Lauf 1 (VOR Nachbesserung, gemischter Stand): sqlite OK (98 Tests, Exit 0), dann abgebrochen wegen Lead-Nachbesserung; down.sh all
- Lead-Zusatz: Tests B18–B20 (Workspace löschen, Tag l'été, java&#9;script, Beacon Papierkorb) ergänzen, dann Endlauf 3 DBs auf neuem Stand
- Scenario/WorkspaceDeleteTest (B18, 3 Tests), XssValidation +B19 HTTP/+l'été/+Beacon-Papierkorb, HtmlSanitizer +6 B19-Fälle: sqlite (frisch, NACH Nachbesserung) Exit 0
- Endlauf (NACH Nachbesserung, Code-sha256 unverändert während Lauf): `run-m2.sh sqlite mariadb pgsql` Exit 0; je DB 110 Tests/2173 Assertions Exit 0; mariadb utf8mb4=true; danach `down.sh all` Exit 0, keine Container/Volumes übrig

## Nächster Schritt
- keiner (Abnahme durch Lead)

## Offene Punkte
- Abweichung Plan-Wortlaut: visible_order (kanban) und PATCH linked_note_id liefern 200 (ignoriert), nicht 403/404 – kein Leck, A unverändert, kein Querverweis
