# M3b2 – Architekt (Umsetzung)

Auftrag: M3b2 aus M3-plan §2 M3b (Teil 2): Anhänge (Zeile, Upload, Bilder, B22), PDF-iFrame, Mermaid/KaTeX unter CSP, Such-POST + requesttoken-URL-Bug, P2 (onclick → data-action, CONTENT_ACTIONS), Tag-Klick-Filter, body-Elemente/Formular-Reset.

Status: fertig (Nacharbeit r1)

## Erledigt
- Start: Plan M3b, Review r2, M3b1-Progress, STATUS gelesen
- Umgebung: make build rc0, up.sh sqlite rc0, install-app + app:enable (KEEP, Port 8081)
- P2 src-Patches: tasklist-render/-subtasks/-order-drag, ui.js, note-image-actions, utils-kanban, attachments.js (onclick/onchange → data-action)
- Adapter bootstrap §11: CONTENT_ACTIONS (Aufgaben, Object.hasOwn) + UI_ACTIONS, Delegation capture auf document; §6a history.push/replaceState ohne requesttoken
- Suche: SearchService::pageSearch, IndexViewService (Filter, Ordner ohne Treffer weg, Treffer-Ordner offen, Links mit Suche, isSearchMode), PageController::search + Route POST index.php, notes_list.php Formular vorbelegt (php -l ok)
- Anhangzeile note_display.php (+ IndexViewService attachmentLinks/attachmentsAtBottom), audio_player.php (Route, PageController::audioPlayer, Template ohne Inline-Skript)
- PDF: AttachmentsApiController EmptyContentSecurityPolicy + frame-ancestors 'self'
- P12: note-attachment-menu.js ATTACHMENT_PATH ohne ^ (App-Präfix)
- Probe scratchpad m3b2/probe.mjs (light, sqlite/Apache): 80 ok nach 2 Fixes (Klick-Schutz ließ task-important wegen Klasse btn-favorite nicht durch → belegte Inhaltsaktion = App-Element; Probe-Artefakte A/C)
- Body-Elemente (bodyel.mjs, hell+dunkel): nur #inputModal mit NC-min-height/margin, unauffällig → ADR 0017 Nachtrag, kein Reset-Ausbau
- Doku: SPEC §4.3.11, §4.5, §11.1 P2/P5/P12; upstream-patches.txt (+8)
- check-upstream rc0 (11 Patch-Dateien); Probe + XSS (Dateiname, Such-GET) hell+dunkel je 89 ok (sqlite)
- PHPUnit gefiltert: EditorPage/PageRoutes/TemplateOutput/RouteDiff/Csrf/UnifiedSearch 23 ok; ControllerAttribute + Scenario\AttachmentTest 7 ok
- PageController::audioPlayer: Array-Parameter abgefangen (is_string), php -l ok; nginx neu installiert
- nginx (8084): Probe hell 89 ok
- sqlite E2E: editor hell 118 ok, stripped/xss hell+dunkel grün; editor dunkel zuerst rot (Testhilfe shareRead fand bei parallel laufendem nginx-Container den falschen NC-Container, gleiches Compose-Projekt) → nach down.sh nginx: editor dunkel 118 ok
- nginx: editor hell 118 ok; down.sh all rc0; nc35-gftest unberührt

## Nacharbeit r1 (docs/reviews/M3b2-diff-r1.md)
- H2 erledigt: NoteMapper::idsMatchingAll (eine Abfrage, nur IDs, LIKE escaped), SearchService::MAX_TERMS=20, pageSearch(notes, tagMap, q), IndexViewService tagsFor einmal → tree; php -l ok
- H1: P1 umgesetzt (events-drag-drop.js:169/:181, node --check ok, upstream-patches.txt +1); P3 entfällt (SPEC §11.1, M3-plan Z.76/78); SPEC R10 MAX_TERMS
- Build OUT_DIR=scratchpad rc0; check-upstream OUT_DIR rc0 (12 Patch-Dateien)

## Nächster Schritt
- keiner (Übergabe tester/speccritic); Probe: scratchpad m3b2/probe.mjs (A–H), bodyel.mjs

## Offen/Annahmen
- Mermaid/KaTeX-Pfade bereits in nextnotes-late.js (M3a1) → nur prüfen
- attachments.php-Seite (Anhang-Dialog „show-attachment-dialog“) ist M3c; Editor-Ansicht: Anhangzeile + Vorschauen + Bild-Upload
- Vorschau-Karten (attachment_previews_in_note) rendert attachments.js clientseitig beim Laden → kein Server-Rendering (schlank)
- Vorläufig (Produkt): Suche öffnet rechts den neuesten Treffer, wenn ?note= fehlt oder nicht trifft (wie Upstream prepareSearchResults); ohne Suche bleibt rechts leer (M3b1)
- Vorläufig: Büroklammer „Anhänge“ (show-attachment-dialog) führt auf attachments.php → bis M3c 404; Upload in der Editor-Ansicht über Drop/Einfügen/Slash-Befehl
- Audio-Player ohne Inline-Skript: Rechtsklick im Player zeigt Browser-Menü statt Anhang-Menü
- Löschen eines im Inhalt verlinkten Anhangs: Server entfernt Verweis (Version ändert sich) → nächstes Autosave 409 → Upstream-Wiederholung (liveRefreshShouldRetryVersionConflict) greift, Probe B grün; Notfall-Speichern direkt danach (Navigation < 1 s) kann 409 liefern
- editor.mjs shareRead: findet bei mehreren laufenden Umgebungen (gleiches Compose-Projekt) ggf. falschen Container → Tester-Hinweis
- stripped.mjs KNOWN_MISSING-Text Kanban: script-src-attr (P2) erledigt, 404 bleibt M3c
