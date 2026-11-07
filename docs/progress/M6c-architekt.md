# M6c – Architekt

Status: fertig

## Auftrag
`@`-Autovervollständigung in Tasklist-Eingaben (input/textarea), Patch P33. Plan: `docs/slices/M6-plan.md` §2.2 + Done M6c. Instanz `nextnotes-m6c`, Port 8300.

## Erledigt
- P33 `src/public/js/mention-autocomplete.js`: `INPUT_SELECTOR` (`.task-list-container input[type=text]`, `.tasklist-embed input[type=text]`, `#taskEditTextarea`), `handleFieldInput`, Einfügen `setRangeText`+`input`-Event, Menü unter dem Feld, Scroll des Feldes selbst schließt Menü nicht
- Befund: schmales Feld (eingebettete Tasklist) scrollt beim Tippen → Menü zu → Enter legte Task an; behoben im Scroll-Listener
- Befund Server: UI speichert per PATCH /notes/{id} → `NoteService::notifyMentions` ok. `TaskService::mutate`→`saveContent` (REST /notes/{id}/tasks, API/MCP) ruft kein `notifyNew` → an Lead gemeldet, nicht gefixt
- SPEC §11.1 P33, `nextnotes/build/upstream-patches.txt` (+1 Zeile)
- E2E neu `tests/nextnotes/e2e/mention-tasks-m6c.mjs` (Name nach Plan): hell 80 / dunkel 80 grün; ohne P33 rot
- Regression hell: teams-m5d 82, editor 119, smoke-m4 164 grün
- `make -C nextnotes build` 0, `check-upstream` 0, `check-forbidden-sql` 0 Treffer
- Instanz nextnotes-m6c abgebaut (keine Container/Volumes)

## Nächster Schritt
- keiner

## Offene Punkte
- TaskService-REST ohne Erwähnungs-Benachrichtigung (Lead entscheidet)
- Inline-Task-Bearbeiten nur Rückfall ohne Dialog; E2E prüft es per entferntem Dialog
