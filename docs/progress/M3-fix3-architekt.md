# M3-fix3 Architekt
Auftrag: Speed-Dial-Tag `speeddial` + Tag-Filter (Ausschalten verliert Workspace/Notiz, voller Reload). Fix (a) Pflicht, (b) In-place über left_col, (c) speeddial ausblenden. E2E.
## Erledigt
- Start
- Probe (Scratchpad nc3/probe): Klick1 → ?workspace&tags_search (note weg, voller Reload), Klick2 → ?workspace (keine Notiz = Startseite)
- Ursache: redirectToTag() baut URL neu (nur workspace/created_*/tags_search) + location.href
- src/public/js/clickable-tags.js (P19): redirectToTag behält alle Parameter, pushState + refreshNotesListAfterFolderAction (left_col), popstate-Listener; speed-dial-Wrapper versteckt, Backspace überspringt ihn; highlightMatchingTags('') entfernt Klasse am Wrapper
- TagService::SPEED_DIAL/isInternal, index() ohne speed-dial; KanbanViewService Karten-Tags ohne speed-dial; php -l ok
## Nächster Schritt
- SPEC P19/B50, upstream-patches.txt; E2E tests/nextnotes/e2e/tag-filter.mjs; build/check/tests
- Instanz: Scratchpad nc3, Projekt nextnotes-fix3, Port 8096 (up läuft)
- Befund: Tag heißt `speed-dial` (src/lib/speed-dial.php, clickable-tags.js isSpeedDialTag); redirectToTag() baut URL neu: nur workspace/created_*/tags_search, `note` fällt weg, voller Reload
- B52 gefunden (E2E: 409 nach Speed Dial + Tag-Edit): NoteService::setTags liefert version, NotesApiController updateTags note.version, setNoteSpeedDial setLiveNoteContentVersion
- SPEC P19, B50–B52; upstream-patches.txt + clickable-tags.js
- E2E tests/nextnotes/e2e/tag-filter.mjs; build rc=0, install/enable rc=0, tag-filter hell grün (63)
- Nächster Schritt: check-upstream, editor, attachments-search (hell), pages; down
- check-upstream rc=0 (24 Dateien); E2E tag-filter/editor/attachments-search/pages hell läuft
- E2E hell: tag-filter 63, editor 118, pages 186 grün; attachments-search J erwartete vollen Reload → Test auf Fragment-Antwort angepasst, danach 137 grün rc=0
- Instanz nextnotes-fix3 abgebaut (down -v) rc=0; nc35-gftest unberührt
## Offen
- Ordner-Speed-Dial (POST folders/{id}/tags) kann bei offener Notiz weiter 409 auslösen (B52 Rest)
- Dunkel nicht gelaufen (Auftrag: hell)
## Status: fertig
