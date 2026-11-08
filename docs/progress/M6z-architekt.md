# M6z Architekt (Aufräumen nach M6)
Auftrag: Speed-Dial-Reste im Backend/nextnotes entfernen (Upload-Scope 400, reorder-Scope weg, tote Keys/Routen/JS), Anti-Flicker #app-tab-bar, Tests auf 400. Instanz nextnotes-m6z, Port-Basis 8800.
## Erledigt
- Bestandsaufnahme grep (nextnotes/, tests/, src/)
## Offen/Annahmen
- tests/ trotz Rollenregel geändert (ausdrücklicher Lead-Auftrag Punkt 3), im Bericht gemeldet
- Code Teil 1: NoteService::reorder ohne speed_dial; BackgroundService nur Scope background (unbekannt → 400 nach Workspace-Prüfung, auch GET/DELETE), Deckkraft-Map ohne speed_dial; SettingsService 'speed_dial_' gesperrt, speed_dial_label_colors aus JSON_OBJECTS; FolderListViewService/IndexViewService/NoteMapper::countByFolder ohne Speed-Dial-Zählung; Templates folder_rows/folders_display ohne data-speed-dial, ui_customization_sections ohne Seite speed-dial; nextnotes-teams.js Aktionen; l10n-String; README
- Bewusst behalten: Route page#speedDial (303), TagService::SPEED_DIAL + Reservierung, NEXTNOTES_TXT.speedDialReserved, Note::speedDialOrder/Spalte, BackgroundFiles sd_bg_opacity_ (Aufräumen Altdaten), bundles.json-Eintrag
- Anti-Flicker: nextnotes-bootstrap.js setzt html.nextnotes-released nach 2 s ohne ready; nextnotes-layout.css Kinder-Regel :not(.nextnotes-ready, .nextnotes-released), Fade-in nur ohne released
- SPEC: §Frontend-Liste 22 (Anti-Flicker) und 28 (M6z Backend), Hintergrundbild-Abschnitt; kein src/-Patch
- tests/ (auf ausdrücklichen Lead-Auftrag, Abweichung von Rollenregel): MaterialIsolationTest speed_dial reorder → 400; ViewsM4gTest Upload speed_dial → 400 + Upload background 200
- php -l ok; make build rc=0; make check-upstream rc=0
- Instanz nextnotes-m6z sqlite :8801 hoch, App aktiv; PHPUnit (16 betroffene Klassen) läuft, Log scratchpad/phpunit.log
- PHPUnit sqlite 16 Klassen: 239 Tests OK, 1 bekannter Skip, rc=0; nächster Schritt E2E anti-flicker + smoke-m6 hell
- E2E sqlite hell: anti-flicker grün 57, smoke-m6 grün 48 (rc=0); Gegenprobe AF_STRIP=css rot wie erwartet (rc=1)
- down.sh all rc=0, keine Container/Volumes/Netze nextnotes-m6z übrig; Demo/gftest unberührt
## Status: fertig
