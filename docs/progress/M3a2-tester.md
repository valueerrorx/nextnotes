# M3a2 – Tester

Auftrag: M3a2 (Hauptansicht-Rahmen, Gestrichenes, Navigation, XSS, ?note/?workspace, B28, Budget, check-upstream, Build, Regression) auf frischen Instanzen testen. Stand = Arbeitsbaum gegen 91435688.

Status: fertig

## Erledigt
- Plan/Progress Architekt gelesen; Harness/budget.mjs gesichtet
- frame.mjs um Scrollbalken-CSSOM-Prüfung ergänzt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen/Annahmen
- Lead-Ergänzung: XSS/print_unescaped/Fremdzugriff dauerhaft unter tests/ (run-e2e.sh nimmt alle Specs im Verzeichnis automatisch; run-m3.sh selbst ist dev/, nicht meins); P8-Probe; Smoke „+“/Kanban/Modal; Läufe vor Build-Fix = „vor Fix“, finaler Lauf nach Lead-Meldung
- tests/nextnotes/e2e/lib/api.mjs, frame.mjs geschrieben; sqlite+nginx up (rc0)
- stripped.mjs geschrieben; vor Fix (sqlite hell): frame 134/135 (nur CSS-Regelverlust 12 Scrollbalken-Selektoren rot), stripped: Kanban 404+CSP, move-entire-folder TypeError, show-move-folder-dialog Konsolenfehler, „+“ ohne Menü → als KNOWN_MISSING (WARN) geführt; delete-note löscht ohne Rückfrage (Info)
- Lead: Build-Fix da (css-bump.mjs, check-css.mjs, check-upstream e2) → finaler Lauf nach Fix
- xss.mjs geschrieben, vor Fix sqlite hell grün (82)
- PHPUnit neu: tests/nextnotes/php/Page/{TemplateOutputTest,PageRoutesTest}.php; sqlite --filter rc0 (6 Tests, 137 Assertions)
- NACH FIX: make clean build ×2 (2. unshare -rn) rc0, 123 Dateien identisch; check-upstream rc0 inkl. (e2); Gegenproben: Inter → rc2, Zusatz hinter Pseudo-Element → rc2; wiederhergestellt rc0
- NACH FIX sqlite (wiederverwendete Instanz) frame hell grün 142; dunkel ROT 6: Regel `html[data-theme=dark] *` (transition …0s!important) trifft header#header selbst – NOT_NC_CHROME schließt nur Nachfahren aus (`#header *`), nicht #header/.v-popper__popper/.modal-mask/.toastify selbst. Scrollbalken-Regeln im CSSOM vorhanden (codeBlock, right_col, kanban, dark). stripped hell/dunkel grün (WARN bekannt), xss hell/dunkel grün
- gestartet: run-m3.sh (alle 4) → run-m1.sh → run-m2.sh, Logs scratchpad final-*.log
- 1. Finallauf abgebrochen: Specs nahmen „Notizen“ fest an, frische Instanz hat „Notes“ (Standard-Workspace entsteht beim ersten Zugriff durch run-phpunit-curl vor `lang de`) → api.defaultWorkspace(); sqlite PHPUnit dabei OK (231 Tests, 7404 Assertions)
- 2. Finallauf gestartet (run-m3 alle 4 → run-m1 → run-m2 → down all), rc in scratchpad final-rc.txt
- 2. Finallauf sqlite: PHPUnit OK 231/7404, assets+budget grün; frame hell ROT: user_status-404 (NC-Kern, Kontaktmenü bob) und B27 im Client (Server-HTML korrekt: Ahnen offen, Zwilling zu; Client: Unter zu durch localStorage aus Schritt 3, Zwilling offen?) → frame.mjs: user_status erlaubt, B27 in frischem Kontext + Server-HTML-Prüfung. Danach erneuter run-m3 sqlite nginx nötig
- 2. Finallauf: mariadb, pgsql PHPUnit OK 231/7404; nginx frame hell B27 rot = Testartefakt (≤ 3 Notizen → Upstream öffnet alle Ordner) → Füllnotizen in frame.mjs; danach gezielter frame-Lauf sqlite+nginx frisch nötig
- run-m3.sh sqlite mariadb pgsql nginx rc1 (rot sqlite/nginx nur frame; mariadb/pgsql grün); Build reproduzierbar 123, check-upstream grün; PHPUnit 231/7404 ×3; budget: API 1, JS gz 997,8 KB, CSS gz 139,0 KB, TTFB ≈25 ms; Screenshots docs/screenshots/M3a2-{light,dark}.png (nginx) kopiert
- run-m1.sh (alle 4) rc0; run-m2.sh (sqlite mariadb pgsql) rc0, je 231/7404; down all rc0
- Kontrolllauf frischer sqlite: run-e2e frame (finale Spec) gestartet → final-frame.log
- Kontrolllauf frischer sqlite frame: hell grün 143, dunkel ROT 6 (nur NC-Kopf-Befund); down all rc0, keine Container/Volumes nextnotes-dev übrig

## Offen/Annahmen
- KNOWN_MISSING in stripped.mjs (Kanban M3c inkl. CSP script-src-attr, move-Modals M3b, „+“-Menü M3b) – Lead entscheidet
- delete-note im Notizmenü löscht ohne Rückfrage in den Papierkorb; Papierkorb-Seite erst M4
- Standard-Workspace heißt je nach Sprache beim ersten Zugriff „Notes“/„Notizen“ (run-phpunit-curl vor lang de)
- run-m3.sh selbst nicht geändert (dev/); neue Specs werden von run-e2e.sh automatisch aufgenommen, PHPUnit Page/* über phpunit.xml
- Nachlauf (Lead): NOT_NC_CHROME schließt Elemente selbst aus; KNOWN_MISSING-Kommentare „muss bis M3b/M3c verschwinden“; frame.mjs Entbump-Regex für neue NOT_NC_CHROME-Form; Build×2/check-upstream/run-m3 alle 4 gestartet
- Nachlauf: Build×2 identisch (123, 2. offline) rc0; check-upstream rc0; run-m3.sh sqlite mariadb pgsql nginx rc0 (20 Spec-Läufe grün, 0 rot; Regel-Scan 24× PASS/0 FAIL; PHPUnit 231/7404 ×3; CSS gz 139,2 KB, API 1, TTFB ≈25 ms); Screenshots aus nginx neu kopiert; down all rc0
