# M3c – Architekt

Auftrag: Seiten-/Kompatibilitätsrouten (revisions, attachments, kanban_content, excalidraw_editor, workspaces.php POST, api_note_info, api_save_excalidraw, api_download_note, api_export_*, api_attachments Alt-URL), Fremdzugriff 404/403, KNOWN_MISSING leer, M3-Done-Prüfung.

## Status: fertig (M3c1 inkl. Tester-Befunde; M3c2 offen)

## Erledigt
- Plan M3c, SPEC §3/§4/§10/§13 M3, STATUS gelesen
- Umfang Upstream ≈ 6000 Zeilen (11 Dateien) → Teilung: M3c1 (dieser Lauf) = revisions.php, attachments.php, kanban_content.php, api_note_info.php, api_download_note.php, api_export_note/folder (Prüfung + 501), api_attachments.php Alt-URL, KNOWN_MISSING leer; M3c2 = excalidraw_editor.php, api_save_excalidraw.php, workspaces.php POST (inkl. Lesefreigabe-403), Abschlusslauf M3

- Bestand gesichtet (PageController, IndexViewService, AccessService, build.mjs, bundles.json cssPages, Upstream-Seiten)
- Entwurf M3c1: Kanban → KanbanViewService + templates/kanban_content.php (RENDER_AS_BLANK); revisions/attachments → eigene Seiten im NC-Rahmen ohne Icon-Leiste, Bundles `pages` in bundles.json (css aus cssPages, js-Liste) → js/css nextnotes-page-<p>; CompatController (api_note_info, api_download_note gespeicherte Form, api_export_* Prüfung+501, api_attachments.php Download); Http/AttachmentResponse aus AttachmentsApiController; note_display: Revisionen-Knopf/Menü + Info-Knopf, modals: noteInfoModal
- IndexViewService iconClass/iconColor/colorHex public static; NoteService::comparePlaced public
- lib/Service/View/KanbanViewService.php, templates/kanban_content.php
- lib/Http/AttachmentResponse.php (aus AttachmentsApiController), lib/Controller/CompatController.php (noteInfo, downloadNote, exportNote/Folder 501, attachments Alt-URL); Middleware: CompatController mit „message“
- Build: bundles.json `pages` (revisions, attachments), build.mjs, check-upstream.mjs (Skriptliste gegen src/public/<seite>.php), check-css.mjs (Seiten-CSS); make build 0, make check-upstream grün
- PageAssets::page; PageController kanbanContent/revisions/attachments (+provideConfig, notFound); NotePageViewService; templates revisions.php, attachments.php
- note_display: Revisionen-Knopf + Menüpunkt, Info-Knopf; modals: noteInfoModal (ohne full_path); routes.php (3 Seiten, 5 Compat)
- Layout-CSS Unterseiten + Lesefreigabe-Anhangliste; P13 attachments-page.js embed→iframe (SPEC §11.1, upstream-patches.txt); CspFactory: attachments mit frame-src self
- FORCED_HIDDEN slash:excalidraw bis M3c2; M3-plan „Teilung M3c“ ergänzt
- make build 0, check-upstream grün (rc 0; ein Lauf hing in check-css/Chromium, Neustart ok)
- nginx-Instanz (KEEP, bleibt bis Ende): dev/nextcloud/scripts/probe-m3c.sh nginx → 59 Prüfungen grün
- Browser-Probe dev/nextcloud/scripts/probe-m3c-pages.mjs nginx hell+dunkel grün (Revisionen, Anhänge, Index, Notiz-Info, Kanban, Navigation: 0 CSP/Konsole/≥400)
- Funde dabei behoben: Adapter isAppCall ohne /index.php-Pfad bei hübschen URLs (412 api_note_info) → indexPath; show_note_created-Default '1' wie Upstream-DB (Info-Knopf sonst unerreichbar)
- run-e2e nginx stripped (hell) grün 113; open-kanban-view 0 Fehlerereignisse → KNOWN_MISSING-Eintrag kann weg
- SPEC: §1.2 Seitenbündel, §4.3.12, §11.1 P13, §11.2 B33/B34
- XSS-Stichprobe: Notiztitel mit <img onerror> in Kanban/Info/Revisionen escaped; check-forbidden-sql 0
- PHPUnit sqlite gefiltert (ControllerAttribute, TemplateOutput, PageRoutes, EditorPage, Attachment, Isolation, RouteDiff, Csrf): 135 Tests, 1 erwarteter Fehler TemplateOutputTest (Allowlist um JSON-Inseln in attachments.php/revisions.php erweitern, Tester)
- down.sh all rc 0 (nc35-gftest unberührt)

## Nacharbeit Review r1 (fertig)
- CspFactory: attachments nur frame-src 'self' (SELF_FRAME_VIEWS); ADR 0012 Nachtrag M3c1
- AccessService::filterReadable (visibleWorkspaceIds, 2 Abfragen); KanbanViewService nutzt es statt noteLevel je Ziel
- revisions.php: KI/MCP-Absatz per str_contains 'MCP' gefiltert (alle 10 i18n geprüft: genau Absatz 2 enthält MCP)
- note_display: Menüpunkt show-note-info nur bei !showDates (unter Bearbeitungssperre deaktiviert wie andere Menüpunkte)
- SPEC §13 M4 Krit. 2 + Plan M4-Vormerkungen: api_download_note linked offen; SPEC B34 ergänzt
- php -l 5 Dateien rc 0; keine Container/make (Tester parallel)

## Nacharbeit Tester-Befunde (M3c1-tester)
- B1: note_display.php:94 speed-dial-state-off ergänzt (vorher fehlte Klasse → TypeError und Klick entfernte statt hinzuzufügen); übrige Menü-Selektoren geprüft ok
- B2: attachments-page.js showFileName: PDF-Zweig (embed data:) entfernt → nur Name/Größe; SPEC P13/B34
- B3: P5 umgesetzt (NEXTNOTES.url): attachments-page.js buildAttachmentUrl, attachments.js Preview-/Audio-URL, events-utils.js buildAudioPlayerUrl, slash-command.js audioPlayerUrl; upstream-patches.txt +2
- make build rc0 (127 Dateien), check-upstream grün rc0 (15 Patch-Dateien); php -l, node --check ok; keine Container

## Nächster Schritt
- Tester: Nachlauf nginx pages hell+dunkel (+ editor/attachments-search); danach M3c2

## Offene Punkte / Annahmen
- M3c2 (neu): excalidraw_editor.php, api_save_excalidraw.php, workspaces.php POST, Abschlusslauf M3; slash:excalidraw bis dahin FORCED_HIDDEN
- Tester: KNOWN_MISSING leeren; ControllerAttributeTest-Allowlist (CompatController: noteInfo CSRF aktiv, Download/Export/Alt-URL NoCSRFRequired; PageController revisions/attachments/kanbanContent); TemplateOutputTest-Allowlist (+2 print_unescaped JSON-Inseln); PageIsolationTest
- Abweichungen: Kanban-Kartentext ohne Links (#1672 → M4); Markdown-Revision zeigt Quelltext statt Vorschau (API liefert kein html); Unterseiten ohne Icon-Leiste; Download = gespeicherte Form
- Produktfrage vorläufig: Notiz-Info zeigt Anzeigenamen „Erstellt/Geändert von“ (Workspace-Mitglieder, wie Sperr-Banner O12)
