# M4-plan – Architekt

Status: fertig (r2)

## Auftrag
Plan M4 nach `docs/slices/M4-plan.md` (Slices ≤ 30 min Testzeit, B-Nummern, §11.1-Patches, Fragen [THOMAS]). Kein Code, kein Commit.

## Erledigt
- SPEC §1–4, §11–13, STATUS, M3-plan, tester.md gelesen
- Inventur B §1.1/§1.6, A #186–199/#208/#237, ADR 0008/0009/0012 gelesen
- Code-Stand: 501 in CompatController (exportNote/exportFolder), NotesApiController (convert*); keine Routen calendar/diary/export_entries/structured/attachments/import_dropped/upload_background/storage-stats; kein lib/Settings; FORCED_HIDDEN wsmenu:new-workspace/edit-workspaces; bundles exclude calendar.js/background-settings.js; cssPages für alle M4-Seiten schon da
- Befunde: Sanitizer-Liste fest vs. CSP-Liste Admin → B41; Upstream-CSP exakter Host vs. Sanitizer/JS Subdomain → B41; linked-Download leer → B42; Hintergrund ohne Auth per nginx, Name-Pfad → B43; Import-Ziel „erster Workspace“ → B44; Anhang-Export inkl. Papierkorb → B45; favorites.php/welcome.php unverlinkt → nicht portieren
- Excalidraw-Vendor: pica-Worker per data:-URL + WebAssembly → Probe in M4a, ggf. ADR
- Export: Upstream ZipArchive; NC ZipResponse liefert bei Mac-UA tar → ZipArchive + ITempManager
- Upstream-Helfer (markdown_parser, html_to_markdown, export/import_helpers) ohne require → Host-PHP-Fixtures möglich
- Plan geschrieben: `docs/slices/M4-plan.md` (Slices M4a–M4i)

## r2 (nach Review r1 + Lead-Entscheidungen)
- Review r1 + Lead-Auftrag gelesen
- Messung Upstream-Parser Host-PHP 8.5 (`php -n`, Skripte nur im Scratchpad): normal 1 MiB md→html 0,32 s; Liste 1 MiB 0,54 s; `_a ` quadratisch (64 KiB 0,87 s, 128 KiB 3,5 s, 256 KiB 13,9 s); html→md `<a href>`/verschachtelt quadratisch (128 KiB 0,7 s/0,5 s); `*`×1 MiB und `_a `×1 MiB → Regex-Fehler still → leere Ausgabe (B46)
- OCP: `ITempManager::getTemporaryFolder(postFix)`, `StreamResponse` nimmt Ressource (`setReadfile`) → Datei vor Rückgabe löschbar
- Festgelegt: Render-/Convert-Eingabe 1 MiB (Rückfall 64 KiB, falls quadratische Pfade nicht behebbar), Render-Budget 1 MiB/Request, Kanban-Vorschau 4 KiB/Karte, Export ≤ 1 GiB, Mention ≤ 20 Empfänger, Import ≤ 64 MiB/Request

- Plan r2 eingearbeitet: §1.2 (Pcre, Grenzen, Budget), §1.3 (Temp-Ordner, finally, ExportTempCleanupJob, 1 GiB via octetLength/sum, B47-Referenzen, disposition), §1.4 (Ein-Label/PSL), M4a–M4i Kriterien, B46–B48, R2/R3, O7/O8, Q1/Q2 vorläufig + bedingt

- Lead-Änderung: keine PSL; exakte Hosts ohne Wildcard, eine Liste für Sanitizer/CSP/JS, Ein-Label + IP-Literal abgelehnt; JS-Patch P17 (`markdown-parser.js` exakter Vergleich) eingeplant

## Nächster Schritt
- speccritic-Review r2 (Lead)

## Offene Punkte
- Q1/Q2 vorläufig Lead-Entscheidung, Thomas gefragt
- Rückfall Render-Grenze 64 KiB, falls B46 nicht behebbar
- Excalidraw-Bild: Probe entscheidet ADR-0012-Nachtrag vs. ADR 0018 (M4a)
- SPEC-Änderungen erst mit den Slices (Plan §6)
