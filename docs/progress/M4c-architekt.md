# M4c – Architekt (Umsetzung)

Status: fertig

## Auftrag
Export Einzelnotiz (api_export_note.php, api_download_note.php, Druckansicht), verknüpfte Notizen (B42), B47/B48, Menüpunkte; plus MINOR aus M4b-r1 (ImportService-Rollback, Quote Ziel-Workspace, Snapshot render=1 413 Frontend-Fallback).

## Erledigt
- Plan gelesen (M4-plan §1.3, M4c, Auflagen, Nachtrag); Bestand erkundet (CompatController export 501, downloadNote roh; Upstream api_export_note/api_download_note; utils-export-create.js im app-Bündel; exportModal fehlt in partials/modals.php; Menüpunkte fehlen in note_display.php)

## Entwurf (Entscheidungen)
- ExportService (Ablauf, Rechte, ZIP, Referenzen) + ExportFormatter (Port Upstream-Formatierung, Pcre)
- Druckansicht: RENDER_AS_BASE (NC-Nonce/Assets) statt BLANK, Template export_print.php, js-src/nextnotes-print.js, css-src/nextnotes-print.css, jsSingle math-renderer/mermaid-theme
- Download = eine HTML-Formatierung (generateStyledHtml) statt zweiter Upstream-Variante; markdown → .md roh, excalidraw → HTML (B56)
- format=markdown nur markdown/tasklist (sonst 400), json nur tasklist
- linked: AccessService::resolveLinked (eine Stufe), IndexViewService zeigt Ziel, Schreibrecht nach Ziel
- [[…]]: numerisch → Titel, Titel-Referenz → Text; nicht lesbar/fehlt: numerisch „Notiz nicht verfügbar“, Titel-Referenz Text als defekter Link
- MINOR 1: Transaction::onRollback (Ebenen-genau), EmbeddedImageService registriert Datei-Löschung; discard()-Aufrufe entfallen
- MINOR 2: NoteService::update Quote gegen Ziel-Workspace
- MINOR 3: P21 revisions-page.js fetchRevision ohne render bei Fehler

- MINOR 1: Transaction::onRollback + Tiefe auch bei Commit-Fehler korrekt; EmbeddedImageService registriert AttachmentService::deleteFileNow; discard()/$created entfernt (NoteService, ConversionService)
- MINOR 2: NoteService::update prepare() gegen Ziel-Workspace
- MINOR 3: P21 revisions-page.js fetchRevision Rückfall ohne render; upstream-patches.txt ergänzt

- AccessService::resolveLinked, NoteMapper::findListByIds, IndexViewService shownView (Ziel, Rechte des Ziels) + readingSettings
- ExportFormatter (neu), templates/export/note-file.css (Upstream-CSS), ExportService (neu), ConversionService::markdownForExport, AttachmentService::fileFor
- CompatController exportNote/downloadNote (Rate 30/min), templates/export_print.php (RENDER_AS_BASE), PageAssets::print, CspFactory export_print, js-src/nextnotes-print.js, css-src/nextnotes-print.css, bundles jsSingle math-renderer/mermaid-theme
- UI: note_display Download-Knopf + Menü Download/Drucken; modals exportModal; tests e2e/stripped.mjs erlaubt Notiz-Export-Aktionen

- make build ok; check-upstream rc=0 (f: 10 Dateien, upstream-ported.txt +3, (g) Export*.php); check-forbidden-sql 0
- Druck-CSS umbenannt nextnotes-printview.css (NC hängt *print.css sonst als media=print an)
- Eigene Instanz: Kopie dev/nextcloud → scratchpad/nc, Projekt nextnotes-m4c, Ports 8101–8104; sqlite + nginx laufen; curl-Smoke Export/Druck ok

- Tests: PageIsolationTest (Export 200, html_embedded), TemplateOutputTest (export_print Allowlist; Zeichenketten in PHP-Blöcken ausgeblendet wegen 'common.print'), neu Scenario/ExportTest (6 Tests)
- Referenzauflösung: eine findList des Quell-Workspace statt findListByIds+filterReadable (≤3 Abfragen); findListByIds entfernt
- PHPUnit sqlite --filter Export|PageIsolation|TemplateOutput|Conversion|PageCsp|EditorPage: rc=0, 37 Tests, 1756 Assertions

- HtmlSanitizer: span data-math erlaubt (Formeln gingen in Export/Druck, Snapshot-Vorschau, convert-markdown verloren; Port-Fehler aus M4b)
- note_display: Drucken-Eintrag als HTML statt $item (TemplateOutputTest-Regex)
- e2e/export-note.mjs neu: nginx hell+dunkel rc=0 (36+36); Apache hell export-note grün 36
- Doku: SPEC P21, B42/B47/B48/B56, §4.5 Export-Grenzen, §4.6 Druckansicht, Done-M4-Notiz; ADR 0012 Nachtrag M4c

- Apache hell: export-note 36, menu-501 91, stripped 126, convert-import 32 – rc=0
- PHPUnit sqlite --filter Export|PageIsolation|TemplateOutput|Conversion|PageCsp|EditorPage|Sanitizer|Xss: rc=0, 76 Tests, 1845 Assertions
- check-upstream grün, check-forbidden-sql 0, php -l ok; eigene Instanz nextnotes-m4c abgebaut (down.sh all, rc=0); Demo/nc35-gftest unberührt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte / Annahmen
- tests/: Lead verlangt Tests im Auftrag → wie M4b in tests/ ergänzt
