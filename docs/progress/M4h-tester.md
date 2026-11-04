# M4h Tester
Auftrag: (1) CoreScenarioTest an versions-Schlüssel P27 anpassen (sqlite grün); (2) E2E-Regression Anti-Flicker opacity nginx/sqlite hell editor, diary-tasks, frame, tree-inplace, folder-actions, menu-501, stripped; dunkel editor; (3) Fokus/Klick während opacity 0 bzw. Phase < 1 s.
## Erledigt
- Instanz Scratchpad m4ht/nextcloud (Projekt nextnotes-m4ht, Ports 8181-8184), build 160 rc=0; up sqlite+nginx, App enabled
- run-phpunit.sh sqlite --filter CoreScenarioTest: OK 3 Tests, 351 Ass., rc=0
- allowed-diff.json: 2 Einträge POST/DELETE /folders/{id}/tags extra `versions` (Grund P27)
- E2E hell nginx (Hintergrund, Log scratchpad m4ht/e2e-light.log): editor 119, diary-tasks 72, frame 143, tree-inplace 148, folder-actions 100 grün
- menu-501 hell 93 grün; stripped hell ROT 17 Fehler (Links dashboard/graph/speed_dial/mentions/settings, Texte Graph/Einstellungen/Arbeitsbereiche bearbeiten): Spec veraltet seit M4g (zuletzt grün M4f), nicht Opacity
- stripped.mjs: FORBIDDEN_PAGES/TEXT an M4g/M4h (SPEC §4.3 Nr. 19) angepasst
- neu tests/nextnotes/e2e/anti-flicker.mjs (Phase je Seite <1 s; Tab/Klick während opacity 0); sqlite-Apache: Phasen index 399–944 ms (kalt höher), andere 207–290 ms; Tab fokussiert 12–13 Elemente in #app-content bei opacity 0, Klick auf #iconSidebarToggle erreicht Ziel
- nginx hell: stripped 126 grün (nach Anpassung), anti-flicker ROT 1 (index kalt Phase 1148 ms > 1 s; warm 838/673; andere Seiten 196–305 ms; Tab fokussiert 9–10 Elemente, Klick #iconSidebarToggle bei opacity 0 erreicht Ziel; 0 csp/pageerror); dunkel editor 119 grün
- down.sh all rc=0; 0 Container/Volumes/Netze nextnotes-m4ht; demo/gftest unberührt
## Status
fertig
## Offen
- Befund an Lead: opacity 0 lässt Fokus/Klick zu und Phase index kalt > 1 s (Upstream-Klasse visibility verhinderte das); Produktfix nicht durch Tester
