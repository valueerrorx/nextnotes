VERDICT: BLOCKER

1. Rechte-Nachweis fehlt fuer `X-Poznote-Fragment: left_col`. SPEC §4.3 Nr. 10 (M3-fix2) sagt: „unbekannte/fremde `?note=` → 404“. Der Code nutzt denselben `indexView->build`-Pfad wie die Seite und ist korrekt (`PageController::index`, catch → `blankNotFound`). Es gibt aber keinen PHP-Test dafuer: `tests/nextnotes/php/Page/EditorPageTest.php` deckt nur `right_col` ab, `tree-inplace.mjs` prueft nur den eigenen 200-Fall. Noetig ist ein Test wie `testFragmentOwnAndForeign` fuer `left_col`. Er prueft: eigene Notiz → 200, ohne `id="right_col"`. Fremde Notiz (Benutzer B) und `?note=abc`/`999999` → 404, ohne fremden Titel oder Markierung im Body.

Hinweise (kein Blocker):
- Caching ist ok: Die TemplateResponse setzt standardmaessig `Cache-Control: no-cache, no-store`, also kein `Vary` noetig.
- XSS ist ok: In `left_col.php`/`fragment_left.php` gibt es kein `print_unescaped`, nur `p()`. `requesttoken` kommt aus `Template\Base`.
- Handler sind ok: Die Ereignisse laufen ueber data-action-Delegation. `refreshNotesListAfterFolderAction` initialisiert Click-, Drag&Drop-, Tag- und Kalender-Handler neu, Testschritt 7 belegt den Notiz-Klick.
- Datenverlust: Beim In-place-Austausch bleibt `#right_col` erhalten, also besser als bisher. Der Reload-Zweig (offene Notiz verlaesst den Ordner) erkennt die offene Notiz nur ueber die gerenderte Zeile in `#folder-<id>`. Ist der Baum gefiltert (Suche/Ordnerfilter), bleibt die nun im Papierkorb liegende Notiz rechts offen. Das ist kein Verlust, wohl aber ein veralteter Zustand. Dieser Zweig ist im e2e ungetestet.
- Erfolgsdialoge: Der Wegfall ist vertretbar und in P18 begruendet. Fehlerdialoge bleiben.
- Testluecken im e2e: Archivieren, Verschieben per Modal (`executeMoveFolderToSubfolder`), `executeMoveAllFiles` und das Loeschen eines Ordners mit offener Notiz (Reload-Pfad) fehlen.
- Ein 404 auf das Fragment (z. B. Notiz parallel endgueltig geloescht) fuehrt still zu keinem Austausch. Das ist akzeptabel.
