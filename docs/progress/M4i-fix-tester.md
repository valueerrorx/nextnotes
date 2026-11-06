# M4i-fix Tester
Status: fertig
Auftrag: volle Matrix Meilenstein-Ende M4 mit `dev/nextcloud/run-m4.sh` (sqlite, mariadb, pgsql, nginx), Standard (Phase 1 parallel, E2E seriell). Kein Produktcode, nur tests/.

## Erledigt
- 15:07 `NN_LOG_DIR=dev/nextcloud/.cache/run-m4-M4i-fix dev/nextcloud/run-m4.sh` → Exit 1, 119 min, „rot in sqlite (grün: 3/4)“
- Vorab (Build 2× reproduzierbar 160 Dateien, check-upstream, info.xml, SQL 0 Treffer): grün, ~25 s
- Phase 1 parallel 15:08–15:34 alle grün: sqlite 22 min (PHPUnit 390, 1 Skip DiaryTest SQLite), mariadb 23 min (390 OK, utf8mb4, probe-grenzen), pgsql 26 min (390 OK, probe-grenzen), nginx (Lebenszyklus, Spike, CSP, Schema)
- Phase 2 sqlite 15:34–16:21: 30/31 Specs × 2 grün, ROT dark:folder-actions („Neuaufbau: + eines alten Ordners öffnet Menü“); Wiederholung `THEMES=dark run-e2e.sh sqlite folder-actions` erneut ROT
- Phase 2 nginx 16:21–17:07: 31 Specs × light/dark grün
- Diagnose: folder-actions ist themeunabhängig flaky (~40 % mit HEAD-Spec, auch hell). Zwei Test-Rennen: (1) Fokus der in Abschnitt 5 angelegten Notiz kommt asynchron und stiehlt den Fokus im Tastatur-Abschnitt; (2) Außenklick direkt nach Öffnen des Erstellen-Menüs, bevor `openCreateMenu` den Schließer per setTimeout(0) registriert → Menü bleibt offen, Folgefehler in Neuaufbau
- Fix in `tests/nextnotes/e2e/folder-actions.mjs` (Fokus-Ruhe abwarten, einen Timer-Tick nach Öffnen warten, 3 neue Lokalisierungs-Checks → 103): sqlite hell 6/6, dunkel 4/4 grün (2 weitere dunkel durch Abbau der Instanz abgebrochen, kein Spec-Fehler)
- Aufgeräumt: keine nextnotes-dev-Container/Volumes/Netze; Demo und nc35-gftest unberührt

## Offene Punkte
- Kein voller Matrix-Neulauf nach dem Testfix (nur folder-actions einzeln)
- Produkt (Info): Außenklick innerhalb weniger ms nach Öffnen schließt das Erstellen-Menü nicht (setTimeout(0)-Registrierung); Escape schließt das „+“-Menü nicht
