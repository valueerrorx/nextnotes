# M4c-fix – Architekt

Status: fertig

## Auftrag
Nachbesserung M4c nach r1: loadHTML-Flags (B39), Download-Rate-Limit, Transaction-Jobs herabstufen, resolveReferences via filterReadable+IN, KaTeX ≥0.16.10, Plan-Text an B47.

## Erledigt
- Progress + Review gelesen
- P1: HtmlSanitizer::loadHtmlQuiet (Flags+Handler, kein Sammeln); genutzt in HtmlSanitizer::sanitizeDom, ExportFormatter::cleanContent, ImportService::tasksFromHtml, MarkdownRenderer::sanitizeMarkdownExcalidrawContainer; grep: keine weiteren Stellen; check-upstream (h) neu
- P2: CompatController::downloadNote #[UserRateLimit(30,60)]; SPEC §4.5 Z.327
- P3: Transaction::run – innerer Commit stuft onRollback-Jobs auf Ebene-1; innerer Rollback verwirft eigene FileCleanupQueue-Aufträge (truncate, symmetrischer Fall); discard() entfernt. Tests ExportTest::testSiblingRollbackKeepsCommittedFiles, testInnerRollbackDropsQueuedDeletion (noch nicht gelaufen)
- P4: NoteMapper::findReferenced (ID IN + Titel-Kandidaten per search_text = / LIKE "t %", ohne Papierkorb, gestückelt 1000/400); AccessService::filterReadableBeside (ohne Abfrage, gemeinsame Regel inWorkspaces); ExportService::resolveReferences nutzt beide. Test testTitleReferencesMatchFoldedOnly neu
- P5: KaTeX 0.16.47 (npm-Tarball, sha512 = Registry) nach src/public/js/katex (js+css; woff2 byte-gleich); SPEC P22, upstream-patches.txt +2, UPSTREAM.md Hinweis
- P6: M4-plan §1.3 Referenzen, M4c App-Punkt, Done 5, Tabelle B47 an SPEC B47 angeglichen; SPEC B47 ergänzt
- make build rc=0 (134 Dateien); check-upstream rc=0 (h neu grün); check-forbidden-sql 0
- Instanz nextnotes-m4cfix (scratchpad/nc, Ports 8111/8114) sqlite+nginx up, App installiert; libxml 2.9.14, PHP 8.5.12
- Messung 500k mark (measure.php im Container): alt 184 MB Heap/0,7 s, neu 22 MB/0,46 s; Download/Export 2,7–3,9 s, 51 MB Heap; HTTP 200; SPEC B39 ergänzt
- Download-Rate: 30 ok, dann 429 (curl)

- PHPUnit sqlite --filter Export|PageIsolation|TemplateOutput|Conversion|PageCsp|EditorPage|Sanitizer|Xss|FilterReadable|Attachment|JobsTest: 116 Tests, 1 Fehler (eigene Test-Zählung traf CSS-Klasse) → korrigiert, ExportTest erneut 9 Tests/321 Assertions rc=0
- E2E nginx hell: export-note 36 (KaTeX gerendert), assets 82 (KaTeX-Fonts/lazy) rc=0
- Instanz nextnotes-m4cfix abgebaut (down.sh all rc=0), Demo/nc35-gftest unberührt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte / Annahmen
- Tests in tests/ ergänzt, weil Lead Tests verlangt (wie M4b/M4c)
- AttachmentService::discardNew ruft cleanup->flush() auch innerhalb offener Transaktion (nicht angefasst, gemeldet)
- Titel-Kandidaten über search_text: setzt synchronen search_text voraus
