# M6bc Tester — Status: fertig
Auftrag: Gemeinsamer Kontrolllauf M6b+M6c (uncommitted) vor Commit: build/checks, E2E sqlite (hell 6 Specs, dunkel 2), PHPUnit sqlite gefiltert, bash -n/l10n/P33+P34. Instanz NN_PROJECT=nextnotes-m6bc NN_PORT_BASE=8490.
## Erledigt
- make build (161 Dateien) Exit 0; check-upstream grün Exit 0; check-forbidden-sql 0 Treffer Exit 0
- up.sh sqlite Exit 0 (Port 8491); install-app + app:enable 0.3.0 OK
- E2E hell sqlite (material-m6b, mention-tasks-m6c, teams-m5d, smoke-m5, editor, folder-actions): Exit 0, 525 PASS
- E2E dunkel: mention-tasks-m6c grün; material-m6b 1 FAIL "S: auch hier kein Sperr-Request" (Z. 184). 6 Wiederholungen (Kopie mit Detail im scratchpad): 5 grün, 1 rot mit POST notes/61/lock = eigene Kopie des Schülers (privater WS, folder_id null) → Test-Rennen (Lock der Kopie kommt nach locks.length=0), kein App-Fehler
- PHPUnit sqlite --filter '(Material|Team|Page|View|Task|Kanban)': Exit 0, 155 Tests, 4663 Assertions, 7:34 min
- bash -n update.sh Exit 0; l10n de.json + de_DE.json gültig; upstream-patches.txt +2 (mention-autocomplete, tasks-page), keine Doppelung; SPEC §11.1 P33+P34 je einmal, keine doppelte P-Nr.
- down.sh sqlite: Container/Volumes nextnotes-m6bc entfernt; Demo/gftest unberührt
## Nächster Schritt
- keiner (Testfix material-m6b Z. 184 durch Lead/Architekt freizugeben)
## Offen
- Empfehlung: Z. 184 nur Locks auf `/notes/${tl}/lock` zählen (oder vor Reset auf networkidle der Kopie warten), Detail `locks.join(' ')` mitgeben
