# M3c1 – Tester
Auftrag: Slice-Abschluss M3c1 (sqlite+nginx, E2E hell+dunkel, PHPUnit voll sqlite, attachments-search hell Apache). Allowlists TemplateOutput/ControllerAttribute, PageIsolationTest, e2e/pages.mjs, KNOWN_MISSING leer.
Status: fertig (Nachlauf grün)
## Erledigt
- git diff HEAD -- nextnotes/lib/Db: leer → keine mariadb/pgsql
- TemplateOutputTest: ALLOW + revisions.php/attachments.php, Flags-Test je Insel-Template (DataProvider); php -l ok
- ControllerAttributeTest: testPageAndCompatControllerAttributes (Allowlist NoCSRF, CSRF: search/noteInfo, NoCSRF nur GET); php -l ok
- tests/nextnotes/php/Page/PageIsolationTest.php geschrieben (4 Tests), php -l ok, noch nicht gelaufen
- Lead-Ergänzung: PageIsolationTest (a) dump vorher=nachher, (b) testDetectorCatchesLeaks, (c) workspace-only Fälle; pages.mjs (d) Basis-vs-Nutzlast Zählung revisions/attachments/kanban (Titel, Ordner, Workspace right_col, Dateiname)
- stripped.mjs KNOWN_MISSING = {}
- make build rc0, check-upstream grün rc0 (nach Fix); up sqlite + install + enable rc0
- PHPUnit sqlite gefiltert (PageIsolation, PageCsp, FilterReadable, ControllerAttribute, TemplateOutput): 17 Tests OK nach 3 Testkorrekturen (AppConfig-Lokalcache 3 s → sleep 4; Sprache über core/lang; TemplateOutput nur print_unescaped(json_encode) prüfen; de-Wort „Version“)
- PHPUnit voll sqlite: OK 248/248, rc0, 10:06 min
- sqlite/Apache hell attachments-search: grün 137, rc0
- down sqlite, up nginx + install rc0; pages.mjs Testkorrekturen: 503-Retry, Dateiname ohne " (Playwright-multipart), Dateiname serverseitig ohne <>
- BEFUND App: ⋮-Menü (toggle-mobile-toolbar-menu) wirft TypeError: .speed-dial-state-off fehlt in note_display.php (Upstream src/note_display.php:416 hat beide)
- nginx alle 8 Specs hell+dunkel: assets 82, attachments-search 137, budget 21, editor 118, frame 143, stripped 119 (KNOWN_MISSING leer, open-kanban-view sauber), xss 82 grün; pages ROT 4 (nur ⋮-Menü-Befund)
- pages.mjs +PDF auf Anhang-Seite (hell): BEFUND 2 Dateiauswahl-Vorschau <embed data:> → CSP object-src; BEFUND 3 PDF-iframe (Liste+Vorschau) lädt zuerst /api/v1/… ohne App-Präfix (Adapter korrigiert erst nach Einfügen)
- down.sh all rc0; nc35-gftest unberührt
## Nächster Schritt
- keiner (App-Fixes durch Architekt; danach Nachlauf: run-e2e nginx pages hell+dunkel, KEEP)
## Offen
- Befunde 1–3 sind App-Code (note_display.php speed-dial-state-off; attachments-page.js Zeile 172 embed; iframe-src vor Einfügen umschreiben)
- Alle Läufe = nach Lead-Fix (Nacharbeit war fertig, bevor Container liefen); kein Lauf vor Fix
## Nachlauf nach Fix B1–B3
- pages.mjs +⋮-Menü/Speed Dial hinzufügen, +PDF-Dateiauswahl kein embed, +Audio einfügen, +Vorschaukarten (attachment_previews_in_note=1); node --check ok
- up nginx + install + enable rc0
- pages hell nginx rc0 grün 186 (Speed Dial, Audio-src /apps/nextnotes/audio_player.php, Vorschaukarten 2 präfixiert)
- PHPUnit --filter TemplateOutputTest im nginx-fpm-Container (run-phpunit.sh lehnt nginx ab; gleiche Schritte manuell): OK 5/153 rc0
- run-e2e.sh nginx pages editor attachments-search hell+dunkel rc0: pages 186, editor 118, attachments-search 137 je Theme; 0 csp/pageerror/console/status/unprefixed
- Befunde 1–3 behoben (verifiziert); down.sh all rc0, nc35-gftest unberührt
