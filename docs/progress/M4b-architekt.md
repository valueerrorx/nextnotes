# M4b – Architekt (Umsetzung)
Auftrag: M4b laut docs/slices/M4-plan.md (Konvertierung, /convert-*, notes/{id}/convert, Snapshot render=1, Base64→Anhänge, Drop-Import B44, Kanban-Links); slash:paste-markdown einblenden; Vorlage anderen Typs; menu-501 ohne M4b-501.
Status: fertig

## Erledigt
- Start, Plan gelesen

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen/Annahmen
- Instanz: Scratchpad nc/ (Kopie dev/nextcloud), Projekt nextnotes-m4b, sqlite Port 8098 (nginx 8099), down.sh-Präfix nextnotes-m4b_ angepasst; up.sh sqlite gestartet
- Bestandsaufnahme: Stubs NotesApiController convert/convertHtml/convertMarkdown (501); kein Route api_import_dropped_notes.php; FE nutzt adopted_attachments {url,id} aus PATCH
- Port-Generator nextnotes/build/port-convert.php; Rohfassungen lib/Service/Convert/{MarkdownRenderer,HtmlToMarkdown,EmbeddedDiagrams} + MediaTags (Hand, iframe-Hosts über Hook B41) + PcreCalls (Trait → Pcre); Pcre::quote neu
- Korpus tests/nextnotes/fixtures/convert/{corpus.php (55 Fälle), gen-expected.php, expected.json}; Host-Parität Port roh = Upstream: 0 Abweichungen (Skript scratchpad/hostport.php, regen.sh)
- B46 Hand: MarkdownRenderer replaceBeforeLastCloser (Unterstriche, Fuzz 20k = Upstream); HtmlToMarkdown closed*-Aufrufe (nur bis letzten Schließer), scanGuard/listGuard (413), img [^<>]*; PcreCalls nach Service/ (direkt preg_*, Fehler → Pcre::fail)
- Messung Host: 1 MiB Tabellen 1,5 s (linear), Spannen > ~1 Mio Zeichen → Backtrack-Grenze 413; 512 KiB: alle Fälle ≤ ~0,8 s oder 413 → Kandidat MAX_RENDER_INPUT 512 KiB (im Container bestätigen)
- EmbeddedImageService (neu), AttachmentService maxFileBytes/generatedName, NoteService update→updateLocked (Quote vor lockRow, adopted_attachments mit new_url) + storeConverted, SnapshotService beforeConversion
- Kanban #1672 wie Upstream (Text + Links, keine Render-Pipeline/Budget nötig): KanbanViewService excerpt() aus ersten 4 KiB, Template Segmente mit p()
- ConversionService (neu, MAX_RENDER_INPUT 512 KiB), Controller: NotesApiController convert*/UserRateLimit, SnapshotsApiController render=1, CompatController importDroppedNotes + Route; ImportService (neu)
- UI: note_display Umwandeln-Knopf + Menüpunkt (nur canWrite), modals convertNoteModal, FORCED_HIDDEN ohne slash:paste-markdown
- check-upstream (f) upstream-ported.txt (7 Einträge), (g) kein preg_ in Convert/Import
- P20 sidebar-file-import.js: file_count mitsenden (PHP max_file_uploads verwirft still); ImportService prüft min(50, max_file_uploads); upstream-patches.txt ergänzt
- make build rc=0, App in nextnotes-m4b (8098) installiert, curl-Smoke convert-* ok
- tests: ApiClient::uploadFiles (files[]) ergänzt
- Nächster Schritt: PHPUnit Scenario/ConversionTest.php, dann E2E
- PHPUnit Scenario/ConversionTest.php (7 Tests) sqlite grün; langsamster Grenzfall im Container md-table 1,15 s bei 512 KiB
- Befund: Sanitizer entfernt data-note-id an <a> (bestehend, nicht M4b); interne Links tragen href=index.php?note=ID
- Nächster Schritt: E2E convert-import.mjs, menu-501.mjs, volle PHPUnit sqlite
- E2E convert-import.mjs hell grün (26 Prüfungen)
- MAX_RENDER_INPUT 512 → 384 KiB (Container: md-table 512 KiB = 1,1 s parse+sanitize, 384 KiB = 0,82 s)
- PHPUnit erweitert: 51 Dateien → 400, 3 Bilder/3. zu groß, Rollback nach store (Inhalt > 16 MiB) inkl. IAppData-Dateiliste
- E2E erweitert: Base64-Bild → Anhang → Umwandeln, Kanban-Links
- SPEC: §1.2 Nr. 5, §2.2, §4.5 Grenzen, §11.1 P20, §11.2 B44, B46, B55
- läuft: menu-501 + stripped hell (Hintergrund), danach Neuinstallation und erneuter Lauf
- menu-501 hell grün (91, 501 gesamt 0), stripped hell grün (126)
- Fix: Markdown-Notiz in der Seite ohne toClient (Anhang-Bilder 404, bestehend seit M3, durch Umwandlung sichtbar) → IndexViewService; 51-Dateien-Test simuliert (display_errors im Testcontainer)
- E2E convert-import hell grün (32 Prüfungen) nach Neuinstallation
- PHPUnit voll sqlite: 320 Tests, 6 Fehler = alte 501-Erwartungen + Allowlist → angepasst: ControllerAttributeTest (importDroppedNotes CSRF), IsolationTest (convert 2xx), CoreScenarioTest (200), SettingsSystemTest (testConversionRights), allowed-diff.json (convert-Einträge entfernt); Nachlauf gefiltert läuft
- Nachlauf gefiltert (ControllerAttribute|IsolationTest::testRoute|CoreScenario|SettingsSystem|Conversion): 134 Tests OK, Exit 0
- check-upstream grün ((f) 7, (g) 0), check-forbidden-sql 0, validate-info OK, make build rc=0
- eigene Instanz nextnotes-m4b abgebaut (0 Volumes), nextnotes-demo und nc35-gftest laufen unverändert
- Planabweichungen: Grenze 384 KiB statt 1 MiB; Kanban Text+Links ohne Render (Done 3 entfällt); Hervorhebung ohne 4096-Spanne (volle Parität)
