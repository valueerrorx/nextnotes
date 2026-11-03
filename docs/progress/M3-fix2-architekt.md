# M3-fix2 Architekt
Auftrag: Voller Seiten-Reload bei Ordner-/Workspace-Aktionen der linken Spalte beseitigen (ein allgemeiner Mechanismus), E2E-Test, P18, ggf. B-Eintrag.
## Erledigt
- Start
## Offen
- Befund Ursache: inline-tree-edit.js saveNewFolder/saveFolderName/saveNoteName → location.reload(); ebenso Lösch/Archiv (utils-folders), Leeren/Umbenennen-Modal (utils-folder-tree), Verschieben (utils-move-folder, events-drag-drop), Unterordner-Modal (folder-hierarchy). Upstream hat bereits showAddedTreeRow()/refreshNotesListAfterFolderAction() (share.js) für In-place-Tausch von #left_col.
- B-Nummer: B41–B48 vom M4-Plan reserviert → B49. P18.
- Server: PageController Fragment `left_col` (gleicher build() wie Seite, 404 blank), Templates partials/left_col.php (aus index.php ausgelagert), fragment_left.php; php -l ok
- share.js: Header X-Poznote-Fragment: left_col
- JS-Patches: utils-folder-tree (showAddedTreeRow: item null, leavingFolderId, Promise), inline-tree-edit (3 Saves), utils-folders (Löschen, Archiv), utils-move-folder (2), events-drag-drop (2); utils-folder-tree Leeren
- Docs: SPEC P18, B49, §4.3 Nr. 10; upstream-patches.txt angehängt
- Nächster Schritt: E2E-Test tests/nextnotes/e2e/tree-inplace.mjs, dann Build/Tests
- E2E tests/nextnotes/e2e/tree-inplace.mjs geschrieben
- build rc=0; check-upstream rc=2 (Kopfkommentar fragment_left) → behoben, erneut läuft; eigene Instanz nextnotes-fix2 Port 8095 (Skripte in Scratchpad/nc), up rc=0
- E2E light rc=1: Erfolgs-Modal (Leeren) blockiert Klick → Erfolgs-Modals Leeren/Ordner-Verschieben entfernt (P18 ergänzt)
- Rebuild rc=0, install rc=0; check-upstream (Hintergrund) und E2E tree-inplace/editor/stripped hell laufen; erste check-upstream-Wiederholung hing in check-css.mjs (>7 min) → abgebrochen
- E2E hell tree-inplace 94 / editor 118 / stripped 126 grün, run-e2e rc=0
- check-upstream rc=0 (grün, Vordergrund; im Hintergrund hing check-css.mjs beim Beenden → Umgebungseffekt, nicht reproduzierbar im Vordergrund)
- eigene Instanz: down.sh sqlite (Projekt nextnotes-fix2) rc=0; gemeinsame sqlite und nc35-gftest nicht angefasst


## r2 (Lead): Reload-Zweig bei verlassener offener Notiz → Workspace-URL ohne ?note=; Erkennung über Ordner-ID der offenen Notiz statt sichtbarer Zeile
- Start r2
- r2: utils-folder-tree showAddedTreeRow: Verlassen über Brotkrumen #right_col .folder-path-segment (+ Baumzeile), dann redirectToWorkspace(); SPEC P18 angepasst
- r2: build rc=0, up/install/enable rc=0, check-upstream rc=0, run-e2e light tree-inplace (148) editor (118) rc=0, down (nextnotes-fix2) rc=0
## Status: fertig
