# M7-shots – Architekt (Status: fertig)
Auftrag: Screenshots neu mit deutschem Inhalt (Team „Klasse 4a“, Lehrkraft Thomas), frische Instanz nextnotes-shots (Port-Basis 11700), info.xml/README anpassen, ein Commit.
## Erledigt
- Worktree auf 440da598 (nextcloud-port), sauber.
- Gelesen: screenshots.mjs, info.xml (4 Screenshots), update.sh, nextnotes/README.md; check-dist/dist-exclude schließen screenshots/ bereits aus.
- node_modules-Symlinks (nextnotes/build, dev/nextcloud) aus Hauptklon gesetzt, vor Commit entfernt; make build rc 0.
- screenshots.mjs umgebaut: de/de_AT, nn-lehrer „Thomas“, nn-lena/-jonas/-mia (erfunden), Team+WS „Klasse 4a“, Material (Kennzeichen), Tags, Tasklist, Formeln, Tabelle, Excalidraw (Daten per API, Vorschau per Editor-Speichern), 2 Aufgaben (bald fällig; abgegeben/offen/zurückgegeben 9/10); erneuter Lauf nimmt nur auf (lookup); SHOT_ONLY.
- Korrekturen nach Sichtung: Notizliste 360 px (localStorage leftColWidth), Excalidraw zentriert, Speichern nach Änderung, Formelblock im Arbeitsblatt, \dfrac in Tabelle, notes-dark = Tasklist, team-workspace-light = Tafelbild.
- info.xml: 9 Screenshots; nextnotes/README.md Abschnitt „Screenshots“ (3 Bilder, relativ); dev/nextcloud/README.md Zeile screenshots.mjs.
- Endlauf auf frischer Instanz (down/up sqlite/install-app/app:enable): rc 0, 9 PNG 66–169 KiB, alle gesichtet.
- validate-info.sh rc 0 (9 Screenshots); make -C nextnotes check-upstream rc 0; down.sh all rc 0 (keine shots-Container/Volumes).
## Nächster Schritt
keiner (Commit, Bericht an Lead).
## Offen
- Dashboard-Bild eher leer (nur Ordnerkacheln); App-Verhalten, kein Fehler.
- README-Bilder relativ: im dist-Paket ohne screenshots/ nicht sichtbar (GitHub zeigt sie).
