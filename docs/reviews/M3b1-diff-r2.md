# M3b1 Diff-Review r2 (speccritic)

VERDICT: BLOCKER

Geprüft: `git diff 3dee857e` (Arbeitsbaum) + neue Templates/Tests gegen r1-Befunde, SPEC §4.3/§11.1/§11.2, M3-plan, ADR 0005. `php -l` lokal (kein Container).

## Blocker
1. `tests/nextnotes/php/Page/TemplateOutputTest.php:23` – r1-Blocker inhaltlich adressiert, aber Datei parst nicht: `"$note['display']"` in doppelten Anführungszeichen ist PHP-Interpolation mit quotiertem Schlüssel → `php -l`: „syntax error, unexpected string content … on line 23“ (Exit 255). Ganze Testklasse fällt aus (Done M3a2.4/M3b.8 nicht nachweisbar). Fix: einfache Anführungszeichen `'$note[\'display\']'` bzw. `"\$note['display']"`.

## r1-Hinweise: Stand
- CONTENT_ACTIONS: `Object.create(null)` + `Object.hasOwn` (`nextnotes-bootstrap.js:301,308`) – erledigt, `constructor`/`toString` greifen nicht mehr.
- contenteditable: `note_display.php:16,143` nur bei `canWrite` (Markdown immer `false`), `data-nextnotes-readonly` bei fehlendem Schreibrecht – erledigt.
- `fragment()` (`IndexViewService.php`): `keyFor` null → 404 statt Standard-Workspace – erledigt; ungültige/fremde/gelöschte Notiz → 404 (EditorPageTest:39-43).
- P10: Basis ist jetzt der gesendete Zustand (`sentState`, vor Adoption/Titelanpassung ermittelt) – erledigt.
- Doku: §4.3 Reihenfolge 8/9/10, B32 in §11.2 (5 Spalten), P9–P11 in M3-plan §1, `wsmenu:new-workspace` → M4-Vormerkung – erledigt.

## Neue Änderungen ohne Befund
- P11 (`note-edit-lock.js:866-872`): Marker kommt nur serverseitig bei `!canWrite` (`workspaceLevel` = Basis von `noteLevel`, gleiche Quelle wie PATCH/Lock-Prüfung). Ein Schreibberechtigter erhält den Marker nie → läuft wie bisher über `acquireLock`; Manipulation des DOM ändert nichts, PATCH/Lock verlangen serverseitig `write`. `releaseCurrentLock()` gibt die Sperre der Vornotiz frei. Autosave/Notfallspeichern/Entwurfswiederherstellung prüfen `isNoteEditingLocked(id)` mit expliziter ID → für die Lesenotiz gesperrt, kein PATCH.
- Laufzeitwechsel: Lese→Schreib wird erst beim nächsten Laden der Notiz (Fragment) wirksam (nur Komfort). Schreib→Lese bei offener Notiz: Server antwortet 403 auf PATCH/Heartbeat; kein Rechteleck.
- P10: kein Datenverlust erkennbar; Entwurf wird in `_updateUIAfterSave` gelöscht, aber `markNoteAsModified` → `runChangeCheck` schreibt ihn sofort neu und plant das Speichern; Rückweg auf den gesendeten Stand → Hash gleich → kein Extra-PATCH.

## Hinweise
- P10: Ein während der Anfrage bereits geplantes Upstream-Speichern plus das von P10 ausgelöste können zwei PATCH nacheinander erzeugen (zweiter mit aktueller Version, gleicher Inhalt) – unschädlich, im B32-Test beobachten.
- P11: Wechsel von Notiz A (Acquire noch unterwegs) zu Lesenotiz B → Antwort für A wird verworfen, gewährte Sperre läuft erst per TTL ab (gleiches Verhalten wie Upstream beim Notizwechsel).
