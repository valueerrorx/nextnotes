# M4a-fix23 Tester
Auftrag: gemeinsamer Slice-Abschlusstest M4a + M3-fix2 + M3-fix3 auf sqlite (build, check-upstream, PHPUnit, E2E hell alle, dunkel neue/geänderte), danach aufräumen.

## Erledigt
- (Start) Vorgefunden: Container nextnotes-dev-nc-sqlite-1 läuft, Volume nextnotes-fix2_nc-sqlite liegt

- make -C nextnotes build rc=0
- check-upstream Lauf 1: check-css.mjs hing nach excalidraw_editor.css (node futex_wait, kein Chromium-Kind), manuell beendet; Lauf 2 mit timeout 400 läuft
- up.sh sqlite rc=0 (bestehender Container wiederverwendet), install-app.sh rc=0, app:enable rc=0 (already enabled)
- run-phpunit.sh sqlite rc=1: 307 Tests, 5 F (11:55 min). 4x 503 'Database busy' (Isolation x3, PageIsolation x1), 1x CoreScenario: PUT /notes/{id}/tags note.version zusätzlich (B52/M3-fix3, SPEC-konform)
- tests/nextnotes/fixtures/allowed-diff.json: Eintrag PUT /notes/{id}/tags extra note.version ergänzt
- Nachlauf --filter 'IsolationTest|CoreScenarioTest' rc=1: 128 Tests, 3 F, alle 503 'Database busy' (andere Routen als Lauf 1) → flaky; CoreScenario grün
- check-upstream: (a)-(e2) alle grün ausgegeben, check-css.mjs hängt im Hintergrund beim Beenden (bekannt aus M3-fix2-architekt) → rc 124/Abbruch, inhaltlich grün
- Befund: Brave (Chrome/156, 172.18.0.1) pollt /changes alle 5 s auf localhost:8081 (manuelle Nutzung, vermutlich Thomas) → SQLite-Konkurrenz; down.sh sqlite daher zurückgestellt, Lead entscheidet
- THEMES=light run-e2e.sh sqlite rc=0 (alle 14 Specs grün)
- Volume nextnotes-fix2_nc-sqlite + Netz nextnotes-fix2_default entfernt; fix3/nn-r8 nicht vorhanden
- THEMES=dark run-e2e.sh sqlite tag-filter tree-inplace admin workspace-create lock-return rc=0 (5/5 grün)
- down.sh sqlite NICHT ausgeführt: Brave-Sitzung (kein Test-Chromium) pollt weiter auf localhost:8081

## Status
fertig

## Offen
