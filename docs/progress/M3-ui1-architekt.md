# M3-ui1 Architekt
Auftrag: Ordnerzeile links bekommt zwei Symbol-Knöpfe (Kanban, „+“ Hier erstellen) vor dem Drei-Punkte-Menü; Hover/Fokus/aktiv/Touch sichtbar; „+“ nur mit Schreibrecht. E2E folder-actions.mjs (hell+dunkel), tree-inplace hell, build/check-upstream/check-css.
## Erledigt
- Analyse: Ordnerzeile in nextnotes/templates/partials/notes_list.php ($renderFolder), Menü in folders_display.php; Upstream-Delegation notes-list-events.js liest data-folder-id/-name vom Element (create-note-in-folder → openCreateMenu, open-kanban-view → openKanbanView) → kein src/-Patch nötig
## Nächster Schritt (erledigt)
- Template + CSS
## Offen/Annahmen
- „+“-Bedingung: $view['canWrite'] (Workspace-Schreibrecht, wie Kopf-„+“); Menüpunkt selbst hat keine Bedingung
- tests/: E2E auf ausdrückliche Anweisung Lead (wie M3-fix4)
- Umsetzung: folders_display.php ($renderFolderQuick, button.nn-folder-quick, data-action open-kanban-view / create-note-in-folder), notes_list.php (Aufruf in .folder-actions, Klasse nn-folder-active), IndexViewService ('active'), css-src/nextnotes-layout.css (sichtbar Hover/focus-within/aktiv/Menü offen/hover:none; sonst clip, fokussierbar), js-src/nextnotes-late.js (keydown Enter/Space capture stopPropagation gegen tree-keyboard-nav); php -l ok; make build rc=0
- Instanz: Scratchpad ncui1, Projekt nextnotes-ui1, Port 8098, App aktiv
- E2E folder-actions hell rc=0, 100 Prüfungen (Touch über hasTouch-Kontext; CDP hover-Feature wirkt nicht)
- Lange Namen: Zähler lag unter den Knöpfen → .folder-toggle padding-right 74px fest (nur Zeilen mit Schnellknöpfen); Build rc=0, App neu kopiert
- E2E folder-actions hell+dunkel rc=0 (je 100), tree-inplace hell rc=0 (148); Gegenprobe ohne keydown-Schutz → 3 FAIL (Tastatur), Schutz wiederhergestellt
- check-upstream (inkl. check-css e2) rc=0 grün; SPEC §4.3 Punkt 15 ergänzt (kein src/-Patch, keine P-Nummer)
- Instanz nextnotes-ui1 abgebaut (down.sh rc=0, Netz entfernt, kein ui1-Volume übrig); demo/gftest unberührt
## Status: fertig
