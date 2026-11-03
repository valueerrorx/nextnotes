# M4a – Architekt (Umsetzung)
Auftrag: M4a laut docs/slices/M4-plan.md (§1.4, M4a, P17, B41, Admin-Hinweis). Q1: Embed-Liste ab Werk leer.
Status: fertig

## Erledigt
- Plan, SPEC, ADR 0012 gelesen; up.sh sqlite rc=0
- Neu: lib/Service/EmbedDomainService.php, lib/Controller/AdminSettingsController.php, lib/Settings/{AdminSettings,AdminSection}.php, templates/settings/admin.php, js-src/nextnotes-admin.js
- Geändert: HtmlSanitizer (IFRAME_DOMAINS weg, exakt über Service), CspFactory (Service), PageController (InitialState), AttachmentService (DEFAULT_*-Konstanten), IndexViewService (new-workspace aus FORCED_HIDDEN), routes.php (POST /admin/settings), info.xml <settings> (validate-info OK)
- src-Patches P17: markdown-parser.js, events-rte-paste.js (zweite JS-Liste, gleiche Regel B41); upstream-patches.txt ergänzt

## Nächster Schritt
- l10n de, build, check-upstream, Tests (PHPUnit EmbedDomain/AdminSettings, Sanitizer/Csp anpassen), E2E admin/workspace-create/excalidraw-Bild, SPEC/ADR

## Offen/Annahmen
- Done 4 „Name mit '“: Upstream-/Server-Regel erlaubt ' im Namen nicht (nur in Tags, B20) → Test: ' im Tag, ' und < im Namen → Fehler im Dialog
- events-rte-paste.js hat eigene Domainliste → in P17 mit aufgenommen (sonst CSP-Verletzung beim Einfügen)
- Excalidraw-Probe (E2E excalidraw.mjs Abschnitt D, hell): 200 KB + 3 MB (2000 px) eingefügt, gespeichert 200, neu geladen, Vorschau ok; einzige CSP-Meldung `script-src wasm-eval` (pica-WASM), Verkleinerung über JS-Rückfall → Fall (b), keine Lockerung
- 413 Diagramm: ExcalidrawService max_mb-Extra, CompatController übersetzt (attachments.errors.file_too_large)
- Tests: neu tests/nextnotes/php/Settings/AdminSettingsTest.php; angepasst HtmlSanitizerTest (feste Liste per Unterklasse), ExcalidrawSaveTest (Meldung)
- build rc=0, check-upstream rc=0 (vor Tests); install + app:enable ok
- PHPUnit sqlite gefiltert: AdminSettingsTest 30/30 rc=0; Sanitizer/Xss/PageCsp/ExcalidrawSave/TemplateOutput/ControllerAttribute/RouteDiff/PageRoutes/Attachment/EditorPage/Csrf 88/88 rc=0
- E2E hell sqlite: admin.mjs grün (42), workspace-create.mjs grün (22); excalidraw+stripped laufen
- Docs: SPEC §1.4 (Admin-Formular), §4.6 (Excalidraw wasm-eval), §11.1 P17, §11.2 B41, §14; ADR 0012 Nachtrag M4a
- E2E hell: excalidraw.mjs grün (71), stripped.mjs grün (126, „Neuer Workspace“ aus Verbotsliste); Endprüfung build/check-upstream/forbidden-sql/validate-info rc=0; down.sh sqlite rc=0
- Hinweis: Arbeitsbaum enthält parallele, fremde Änderungen (M3-fix2: index.php, left_col.php, fragment_left.php, src/js tree/folders/share/drag-drop, tree-inplace.mjs) – nicht von M4a

## r2 (Flake AdminSettingsTest:151)
- Ursache belegt (pgsql, curl): CLI-deleteKey < 3 s nach Web-Zugriff → Web-AppConfig-Lokalcache (APCu, TTL 3 s) hält Schlüssel für vorhanden → UPDATE auf gelöschte Zeile, 200 ohne Speichern. NC-Kern-Verhalten, Testfehler (Änderung am Webserver vorbei ohne Wartezeit)
- Fix Test: settle() nach CLI-deleteKey und am Anfang jedes HTTP-Speichertests; stored() schließt Cursor
- Läufe pgsql: AdminSettingsTest 5× rc=0 (30/30), kombiniert mit PageCsp|HtmlSanitizer|EditorPage 3× rc=0 (70/70); down.sh pgsql rc=0; r2 fertig
