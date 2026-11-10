# UI-asgdesign – Architekt
Auftrag: Aufgaben-Ansicht optisch aufwerten (Karten für Aufgaben/Abgaben, Meta-Pillen, Fälligkeitsfarbe, mobil 390 px). UI-only, nur Theme-Variablen. Neue Spec assignments-design.mjs, Screenshots vorher/nachher.
Status: fertig
## Erledigt
- git reset --hard nextcloud-port (234d5771)
- Plan: Tabelle bleibt (Tests nutzen `.nn-asg-table tbody tr`), Zeilen als Karten (border-spacing); Liste `li` als Karte; Meta als `.nn-asg-pill` mit lucide; Fälligkeit passed/soon (48 h); Schüler-Avatar über NC-Kernroute `/avatar/{uid}/64[/dark]`, Verantwortlich = Initialen (kein uid im Modell)
- Testinstanz nextnotes-uiasg (11601): up, build, install, enable Exit 0
- Spec tests/nextnotes/e2e/assignments-design.mjs; Vorher-Lauf rot wie erwartet (16 Fehler je Theme, u. a. Abgaben-Tabelle 390 px 58 px Überlauf); Screenshots tests/nextnotes/e2e/out/uiasg-vorher/
- JS nextnotes-assignments.js, CSS nextnotes-assignments.css umgesetzt
- run-e2e sqlite (hell+dunkel): assignments-design 53, assignments-m6g 165, assignment-wsbind 45, assignment-tplpick 31, smoke-m6 48, mention-tasks-m6c 80, mobile-m6 89, layout-left 130 – alle grün, Exit 0; Screenshots tests/nextnotes/e2e/out/uiasg-nachher/
- SPEC §4.3 Nr. 35; make build + make check-upstream Exit 0
- down.sh all (nextnotes-uiasg) erledigt; node_modules-Symlinks entfernt
## Nächster Schritt
- Commit
## Offen
- keine
