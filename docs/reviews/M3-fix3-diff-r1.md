# M3-fix3 Diff-Review r1

VERDICT: FREIGABE

Keine Blocker.

Geprüft:
- Datenverlust: `speed-dial` bleibt als verstecktes `.clickable-tag` im DOM, `updateTagsInput` sammelt ihn mit; Backspace überspringt ihn. `PUT notes/{id}/tags` liefert `version`, `setNoteSpeedDial` übernimmt sie → kein 409. e2e tag-filter Schritt 2 deckt Speichern nach Speed Dial indirekt ab (Tag-Autosave muss durchgehen).
- XSS: `tags_search` nur über `URLSearchParams`, `textContent`/`classList` beim Hervorheben; Hidden-Input serverseitig mit `p()`. Kein innerHTML mit URL-Daten.
- Rechte: Fragment über bestehenden `refreshNotesListAfterFolderAction` (P18) auf derselben Index-Route; kein neuer Pfad.
- Zurück/Vor: `applyTagFilterFromUrl` macht kein `pushState`; `popstate` nur bei Abweichung von `#search-tags-hidden` (liegt in `#left_col`, wird mit ersetzt) → keine Schleife.
- Test J: Wechsel von `waitForNavigation` auf Fragment-Antwort ist durch P19 bedingt; die nachfolgende Assertion prüft URL und gefilterte Liste weiter hart (`.catch` am Warten verdeckt nichts).
- SPEC §11.1 P19, §11.2 B50–B52 vorhanden und konsistent mit Code.

Hinweise:
1. B52-Rest (Ordner, `POST folders/{id}/tags` mit `speed-dial`): offene Notiz im Ordner bekommt beim nächsten Speichern 409. Kein stiller Verlust (Konfliktdialog/Draft), in SPEC als „unverändert“ dokumentiert; in M4g (Speed Dial) nachziehen.
2. Eigener Tag `speed-dial` (Eintippen oder Umbenennen eines Tags auf `speed-dial`) setzt die Notiz in Speed Dial und blendet den Tag aus. Entspricht Upstream-Semantik (Tag ist der Speed-Dial-Marker), akzeptabel; ggf. in M4g Eingabe/Umbenennen auf den reservierten Namen abweisen.
3. Platzhalter „Add tag...“ statt „Add tags...“, wenn nur der versteckte Tag vorhanden ist (kosmetisch).
