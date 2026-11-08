# UI-namen – Architekt
Status: fertig
Auftrag: de „Aufgaben (Team)“ → „Aufgaben“ (assignments.php); Upstream-Tasks-Seite de „Aufgaben“ → „To-dos“. Englisch bleibt.

## Erledigt
- Befund: kein i18n-Override im Build (build.mjs kopiert src/i18n roh; SPEC §11.1 „keine Build-Zeit-Patches“) → direkter Patch src/i18n/de.json (P37). Keine de-formal-Variante in src/i18n.
- Befund: icon_names.tasks (Icon-Wähler), dashboard.note_modal.empty_tasks (leere Aufgabenliste), tasklist.* (Editor) betreffen nicht die Seite → unverändert.
- nextnotes/l10n/de.json, de_DE.json: "Assignments" → „Aufgaben“.
- src/i18n/de.json: tasklist.open_tasks_page, tasks_page.{title,progress,filter_placeholder,empty,empty_filtered,show_note_checklists,calendar_no_tasks}.
- docs/SPEC.md §11.1 P37; nextnotes/build/upstream-patches.txt + src/i18n/de.json.
- Tests: e2e/assignments-m6g.mjs (Leisten-Text ohne „(Team)“), e2e/diary-tasks.mjs (Seitentitel „To-dos“).
- make -C nextnotes build rc 0; check-upstream rc 0 (grün).
- NN_PROJECT=nextnotes-names NN_PORT_BASE=8900: up+install+enable sqlite; THEMES=light run-e2e.sh sqlite diary-tasks assignments-m6g ui-nav-vt → rc 0, grün (3 Specs × light); down.sh rc 0, keine Container/Volumes übrig.

## Nächster Schritt
- keiner
