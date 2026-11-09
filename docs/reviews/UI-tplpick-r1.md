# UI-tplpick – Diff-Review Runde 1 (speccritic)

VERDICT: FREIGABE

Geprüft: git diff + neue Dateien (AssignmentService, AssignmentsViewService, nextnotes-assignments.js/.css, l10n, SPEC §4.3 Nr. 30 / §9.1 Nr. 1, AssignmentSourceWorkspaceTest, DistributeTest, AssignmentLifecycleTest, E2E assignment-tplpick.mjs). `php -l` aller geänderten PHP-Dateien: ohne Fehler.

Ergebnis der Prüfpunkte:
- Schreibwege: Quelle wird nur in `AssignmentService::create` gesetzt (`setSourceNoteId` einzige Stelle); PATCH/distribute/Duplizieren lesen die Quelle nicht. `?create=` (`createSource`) zusätzlich auf Frame-Workspace begrenzt; Notiz-Menü übergibt `workspace=` des aktuellen Workspace.
- Ohne `workspace`: Regel verlangt weiterhin `owner_type=team`, `kind=normal`, `owner_id=team` → privat, Lesefreigabe, anderes Team, Abgabe-WS bleiben 403; nicht-String-`workspace` fällt auf dieselbe Regel zurück. Keine Umgehung gefunden.
- Mit `workspace`: Gleichheit Ziel-WS = Quell-WS; fremder/privater Schlüssel → 403, unbekannter → 404.
- Leck: `requireNote(read)` läuft vor allen 403-Prüfungen → unlesbare/Papierkorb-Notizen 404; 403 nur für ohnehin lesbare Notizen.
- `GET notes?workspace=&limit=200`: `resolveOptional` → `requireWorkspace(read)`, `findList` nur `role=note`, ohne Papierkorb; keine neuen Daten.
- XSS: Titel/Ordner über `el()` → `textContent`.
- Tests: alle verbotenen Fälle (anderer Team-WS, Verknüpfung, privat mit/ohne workspace, Lesefreigabe, anderes Team, Fremd-Team-Schlüssel, Abgabe-WS, Papierkorb, unbekannter WS) plus `createSource`; DistributeTest verschärft (privat jetzt 403), LifecycleTest sinnvoll umgebaut statt aufgeweicht.

Hinweise (nicht blockierend):
1. HINWEIS nextnotes/js-src/nextnotes-assignments.js (browse, `sort=updated_desc`) / lib/Service/NoteService.php:208 ff.: `sortList` gruppiert vor dem Datum nach Ordner/Wurzel (`notes_without_folders_after_folders`). Bei > 200 Notizen sind die geladenen 200 daher nicht die zuletzt geänderten; Client-Sortierung gilt nur innerhalb dieser 200. SPEC Nr. 30 sagt „zuletzt geändert zuerst“. Nur bei großen Workspaces relevant.
2. HINWEIS nextnotes-assignments.js `renderAll`: mit 1 Zeichen Filter und `all.more` zeigt der Hinweis `total = list.length` der geladenen 200, nicht die echte Gesamtzahl.
3. HINWEIS SPEC §9.1 Nr. 1: „Abgabe-Workspace → 403“, Test akzeptiert 403 oder 404 (Lehrkraft liest fremde Abgabe-WS i. d. R. nicht → 404). Spec-Satz ggf. auf „403 bzw. 404 (nicht lesbar)“ angleichen.
