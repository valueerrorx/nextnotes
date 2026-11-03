# M3b1 Diff-Review r1 (speccritic)

VERDICT: BLOCKER

Geprüft: `git diff 3dee857e` + `nextnotes/templates/{fragment.php,partials/modals.php,partials/note_display.php}` gegen M3-plan §M3b (M3b1-Teil), SPEC §1.2/§4.3/§4.6/§11, ADR 0005, ADR 0017.

## Blocker
1. `nextnotes/templates/partials/note_display.php:142` `print_unescaped($note['display'])` ist nicht in der Allowlist von `tests/nextnotes/php/Page/TemplateOutputTest.php:18-20` (nur `index.php`). `testPrintUnescapedOnlyAllowlisted` schlägt damit fehl (Regression Done M3a2.4, Done M3b.8; SPEC §4.6 „Allowlist, per Test geprüft“). Nötig: Eintrag `'partials/note_display.php' => ["\$note['display']"]` mit Begründung (Inhalt beim Speichern durch `HtmlSanitizer::sanitizeForType`, beim Ausliefern nur `ContentUrlRewriter::toClient`; Tasklist mit JSON → leer; Markdown über `p()`).

## Geprüft ohne Befund
- Rechte Fragment-Modus: `IndexViewService::fragment` → `requireNote(uid, id, 'read')`, nicht numerisch/fremd → 404 (`PageController::fragment`), CSP wie Seite. Lesefreigabe: Lesen erlaubt; Schreiben serverseitig (PATCH/Lock-Acquire `write`) → 403.
- Escaping: alle anderen Ausgaben über `p()`; `style` nur mit ints bzw. `iconColor()`-geprüften Farben; Markdown-Inhalt und `data-tasklist-json` escaped. `display` ist sanitisiert: Typ ist nach Anlage unveränderlich (`setType` nur in `create`), alle Schreibpfade über `applyContent`; Duplikat-/Anhang-Ersetzungen ändern nur IDs.
- CSRF: Beacon-Route ohne `NoCSRFRequired`, Adapter hängt `requesttoken` an FormData; >64-KiB-Pfad läuft als normaler `fetch` mit Header `requesttoken`.
- Patches P7/P9/P10 minimal, in SPEC §11.1 und `upstream-patches.txt`; `bundles.json`-Anker ergänzt.
- Fragment-Pfad: konstante Zahl Abfragen (Tags, Anhänge, Linked, Erinnerung, Ordner je 1), keine N+1.

## Hinweise (kein Blocker)
- B26 Klick-Schutz `nextnotes-bootstrap.js` (Abschnitt 11): `CONTENT_ACTIONS = {}` → `data-action="constructor"/"toString"` gilt als erlaubt (Prototyp). `Object.create(null)` bzw. `hasOwnProperty` verwenden, bevor P2 (M3b2) Einträge hinzufügt.
- B26: Der Schutz stoppt bei Klassen-/ID-Treffern **alle** Klick-Handler, auch legitime auf Inhaltselementen (Checklisten, Tasklist, interne Links, Bildmenü, Codeblock). Done M3b.1/M3b.7a sollte einen Gegenprobe-Klick auf solche Elemente enthalten (Handler läuft, Speichern erfolgt).
- B26: Altbestand mit `data-action` wird nicht nachträglich gesäubert (nur beim Speichern); Schutz greift clientseitig. Vor M4-Import sicherstellen, dass Import über `NoteService::applyContent` läuft.
- P10 `src/public/js/notes.js:390-398`: Basis wird auf den Stand **vor** dem gesendeten zurückgesetzt (nicht auf den gesendeten); Folge: Live-Refresh-Merge (`getNoteSavedBaseline`) mit veralteter Basis; außerdem ein zusätzlicher PATCH nach serverseitiger Titel-Eindeutigmachung bzw. Anhang-Übernahme. Funktional kein Datenverlust, aber im Test (B32-Probe) beobachten.
- Lesefreigabe: `note_display.php:142` setzt `contenteditable="true"` unabhängig von `canWrite`; Nur-Lesen hängt am Lock-403-Banner. In M3c.1a im Browser bestätigen (kein Dauer-Speicherversuch, Entwurf).
- `IndexViewService::fragment`: liefert `keyFor` null, fällt `resolveWorkspace` auf den Standard-Workspace zurück → `canWrite`/Ordner aus falschem Workspace (nur bei Inkonsistenz).
- Doku: SPEC §4.3 Punkt „10“ steht vor „9“; Zeile B32 steht in der Patch-Tabelle §11.1 (gehört nach §11.2) mit 4 statt 5 Spalten; P10 fehlt in M3-plan §1 Patch-Tabelle. `wsmenu:new-workspace` von M3b nach M4 verschoben – im Plan nachtragen.
