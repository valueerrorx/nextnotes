Status: fertig

# M8a – architekt (Formel-Block + Block-Grundlage)

Auftrag: M8-plan §1 (inkl. §1.1a) + §2 M8a umsetzen (B69, P38, MathLive lazy, BlockMarkup, NextBlocks, ADR-0018-Nachtrag), Tests PHPUnit + E2E `blocks-formula.mjs`, Instanz `nextnotes-m8a` Port 10000.

## Erledigt
- Plan gelesen, Bestand gelesen (Sanitizer, Konverter, bootstrap, build, slash-command, note_display, Tests)
- Instanz nextnotes-m8a sqlite up (Port 10001) Exit 0
- `npm install --save-exact --ignore-scripts mathlive@0.111.4` in nextnotes/build (package.json dependencies, Lockfile +13)

- PHP fertig: lib/Service/BlockMarkup.php (neu), HtmlSanitizer.php (div data-math, isDangerous-Ausnahme, normalize nach unwrap), Convert/NextBlocks.php (neu), ConversionService (noteToMarkdown/htmlToMarkdown), View/BlocksUiConfig.php (neu) + PageController (config.blocks), l10n de/de_DE (+9)
- Host-PHP-Probe (Scratchpad php/t.php): Normalisierung + MD-Round-Trip ok
- Build: bundles.json npmVendor + adapterHook P38, build.mjs §3a; P38 in slash-command.js (1 Zeile); js-src nextnotes-blocks.js (neu), nextnotes-formula.js (neu), bootstrap poznoteEnsureMathLive; css-src nextnotes-blocks.css; PageAssets; note_display.php (Knopf + Mobil-Eintrag)
- make build Exit 0 (164 Dateien); check-upstream grün Exit 0
- Größen: nextnotes-blocks.js 8381 B (gz 2825), nextnotes-formula.js 3389 (gz 1455), mathlive.min.js 847547 (gz 228610), nextnotes-blocks.css 5141 (gz 1400)
- check-notices.mjs um npmVendor erweitert; THIRD-PARTY-NOTICES mathlive; check-notices Exit 0

- Tests neu: php/Security/BlockMarkupTest.php, php/Scenario/BlocksConversionTest.php, php/Scenario/FormulaB69Test.php; PHPUnit (Filter neue + Sanitizer/Conversion/Export) 124 Tests OK Exit 0
- Fixes aus Tests: Platzhalter ohne Steuerzeichen (NNBLK+id), feste Attributreihenfolge (idempotent), Regex \s*+\n, SplObjectStorage (8.5 deprecated) → spl_object_id
- E2E neu tests/nextnotes/e2e/blocks-formula.mjs; Fixes: Knopf als note-action-btn (text-format-btn nur bei Auswahl sichtbar), Fokus per setTimeout, keyup nach Enter/Esc geschluckt (sonst Autosave-Markierung + beforeunload), Popover/Blatt über Bildschirmtastatur (geometrychange)
- blocks-formula hell: grün 147 Prüfungen
- Hilfsskript Scratchpad e2e.sh (build+install+apache graceful wegen opcache 60 s)

- Doku: SPEC §4.3 Punkt 9, neuer §4.7, §11.1 P38, §11.2 B69, §13 M8; ADR 0018 Nachtrag (a)–(d) + Formel; nextnotes/CHANGELOG.md [Unreleased]; docs/app/user.md NICHT (M7h-Bereich)

- E2E: blocks-formula dunkel grün 147; editor 119, lists-katex 28, smoke-m6 48 hell grün (Exit 0)
- check-upstream Exit 0; check-notices nextnotes . Exit 0 (9 Bibliotheken inkl. mathlive)
- make dist Exit 2 erwartet (Lockfile ≠ HEAD); Nachbildung aus Arbeitsbaum + check-dist OK: 8122824 B (HEAD-Paket 0.9.0: 7882194 B)
- Größen: blocks.js 8381/2825 gz, formula.js 4096/1641 gz, mathlive 847547/228610 gz, blocks.css 5141/1400 gz

- PHPUnit voll sqlite: 599 Tests, 1 skipped, Exit 0 (44 min); assets hell grün 82
- Instanz nextnotes-m8a abgebaut (down.sh all, Exit 0)

## Nächster Schritt
- keiner; Bericht an Lead

## Offen/Annahmen
- Shadow-DOM-Mount + Fallback-Neuerzeugung (§1.1) erst M8b (Formel braucht keine Laufzeit-UI im Host)
- make dist baut HEAD (Lockfile-Vergleich) → ohne Commit nur nachgebildet (Stage aus Arbeitsbaum + check-dist)
- Inline-Formel mit `$`: `\char36 ` statt `\$` (Inline-Regex des Renderers erlaubt kein `$`), sonst Code-Span-Rückfall
