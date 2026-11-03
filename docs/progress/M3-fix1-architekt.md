# M3-fix1 – Architekt
Auftrag: eigene Sperre nach Anhang-Seite/Excalidraw ~1 min als fremder Tab erkannt; beheben, E2E-Test, sqlite prüfen.

## Erledigt
- Ursache (genauer): Upstream gibt bei pagehide frei (events-auto-save.js), aber späte Heartbeat/Notfall-PATCH-Requests der alten Seite holen die Sperre für die alte Sitzung zurück; Adapter `nextnotes-bootstrap.js` §10 löscht `poznote_editor_session_id` bei jedem Laden außer Reload → Rückkehr von `attachments.php`/`excalidraw_editor.php` (Navigation) = neue Sitzung, alte Sperre (TTL 90 s) gilt als fremder Tab. Upstream gibt beim Verlassen nicht frei.

- Fix: `nextnotes/js-src/nextnotes-bootstrap.js` §10 Marker `nextnotes_editor_tab_live` (pagehide entfernt, pageshow persisted setzt); nur Top-Level. Keine Freigabe bei pagehide (Reload würde Sperre an Duplikat verlieren).
- SPEC §4.3 Punkt 8 angepasst. Kein src/-Patch.
- E2E `tests/nextnotes/e2e/lock-return.mjs` (A Anhang-PDF, B Excalidraw speichern, C Excalidraw schließen, D Gegenprobe Kopie-Tab).
- `make -C nextnotes build` rc=0, `check-upstream` rc=0.

- Test mit simuliertem späten Request (lateLock); Negativprobe gegen alten Adapter: 16 FAIL (Bug reproduziert).

- Mit Fix: build rc=0; check-upstream rc=0 (erster Lauf hing in check-css.mjs ohne CPU, abgebrochen, Wiederholung grün); THEMES=light run-e2e.sh sqlite lock-return editor excalidraw rc=0 (54/118/46); down.sh sqlite.

## Status: fertig

## Offen/Annahmen
- Keine Freigabe-Änderung; Crash ohne pagehide → nächstes Laden im Tab = neue Sitzung (wie bisher bis 90 s).

## Runde 2 (Review docs/reviews/M3-fix1-diff-r1.md BLOCKER: Marker fehlt nach Seite ohne Bootstrap)
- Lead: Marker → Web Locks (`nextnotes-editor-session:<id>`, ifAvailable), ohne locks altes Verhalten; Test E ergänzen.
- Umgesetzt: bootstrap §10 Web Lock + Fetch-Gate (§4), Timeout 2 s, ohne locks altes Verhalten; SPEC §4.3/8 neu; Test E (E1 nacheinander, E2 gleichzeitig). Lauf 1: alle Funktionsprüfungen grün, nur NC-Text-412 auf Dateien-Seite → erlaubt.
- Negativprobe r1-Marker: E1/E2 4 FAIL (Blocker nachgestellt). Test F (Zurück-Knopf) ergänzt; headless Chromium nutzte kein bfcache (INFO false).
- Final: build rc=0, check-upstream rc=0, THEMES=light run-e2e.sh sqlite lock-return editor excalidraw rc=0 (76/118/46), down.sh sqlite rc=0.
- Status r2: fertig. Vorher Plan: §10 Web Lock + Fetch-Gate (App-Requests warten auf Entscheidung, alte ID in Body/Header ersetzt), pagehide gibt frei, pageshow persisted holt neu.
