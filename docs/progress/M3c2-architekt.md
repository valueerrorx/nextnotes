# M3c2 – Architekt

Auftrag: excalidraw_editor.php + api_save_excalidraw.php (Rechte, Lesefreigabe nur ansehen, Sperre ADR 0005, CSP, CSRF), workspaces.php POST (Lesefreigabe 403), slash:excalidraw einblenden, M3-Done-Prüfung.

## Status: fertig (Nacharbeit r3)

## Erledigt
- Start, Vorgänger M3c-architekt gelesen


- Gelesen: Plan M3c, SPEC §4/§11/§13, ADR 0005/0012/0017, Upstream excalidraw_editor/api_save_excalidraw/workspaces.php, excalidraw*.js, Bestand (PageController, CompatController, WorkspaceService, AttachmentService, HtmlSanitizer, LockService)
- Entwurf: ExcalidrawService (Tag-Scanner statt Regex-Backtracking, Einbett- + Vollmodus nur für bestehende Notiz, kein Anlegen note_id=0), AttachmentService createFromString/retire, SnapshotMapper contains, Sanitizer data-excalidraw ohne DANGEROUS_PATTERNS (B35), Bildstil wächst je Speichern (B36), P14 viewModeEnabled bei Lesefreigabe, workspaces.php: delete/rename/set_tags/set_color/reorder/move_notes, share/unshare → Rechte + 501
- WorkspaceService: moveContents (aus delete), moveNotes (beide manage), reorder; AttachmentService createFromString/discardNew/retire; SnapshotMapper::contains (LIKE escaped); HtmlSanitizer B35
- lib/Service/ExcalidrawService.php neu; Host-Probe scratchpad exc.php/san.php: Einfügen/Ersetzen/Markdown ohne Wachstum/Vollmodus/6 MB linear, Sanitizer-Rundlauf ok; >10 MB verliert libxml das Attribut → Grenze 7 MiB (413)
- PageController::excalidrawEditor, PageAssets::page(vendor), bundles.json pages.excalidraw_editor, templates/excalidraw_editor.php, P14 excalidraw-editor.js (viewModeEnabled bei readOnly) + upstream-patches.txt
- routes.php (+3), CompatController saveExcalidraw/workspaces, FORCED_HIDDEN ohne slash:excalidraw, layout.css Excalidraw-Regeln (1,1,0/2,0,0 + !important)
- make build rc0 (129 Dateien), make check-upstream rc0 grün (16 Patch-Dateien, Seiten-CSS excalidraw_editor geprüft)
- nginx-Instanz up (KEEP, bleibt bis Ende), install-app; Fund: NC detectString meldet kurzes SVG als text/plain → finfo-Prüfung ersetzt durch Wurzel-svg-Prüfung (Refusals bleiben)
- dev/nextcloud/scripts/probe-m3c2.sh nginx → 94 Prüfungen grün (rc 0): Seite, CSP, CSRF 412, Eingaben 400, Einbetten/Ersetzen/Markdown/Vollmodus, B35/B36, Fremd 404, Lesefreigabe 200/403, Sperre 423, workspaces.php alle Aktionen
- dev/nextcloud/scripts/probe-m3c2-excalidraw.mjs (Slash → Editor → Rechteck → Speichern+zurück → Vorschau, Lesefreigabe Ansichtsmodus): nginx hell+dunkel grün, 0 CSP/Konsole/≥400
- Fund behoben: excalidraw.css färbte body (NC-Kopf weiß auf weiß) → layout.css body#body-user.excalidraw-editor-page NC-Hintergrund
- Hinweis Probe: abgebrochene Browserläufe halten die Sperre 90 s (pagehide fehlt) → Probe geht vor dem Schließen auf about:blank
- probe-m3c2.sh 94 grün, probe-m3c.sh 59 grün (Regression), check-forbidden-sql 0 (nach Reinstall)
- Docs: SPEC §4.2 workspaces.php-Zeile, §4.3.13, §11.1 P14, §11.2 B35/B36; M3-plan M3c2-Umsetzung + M4-Vormerkungen; ADR 0012 Nachtrag M3c2
- Build zweimal: 129 Dateien identische Prüfsummen
- PHPUnit sqlite gefiltert (ControllerAttribute, TemplateOutput, PageRoutes/Isolation/Csp, EditorPage, Attachment, Isolation, RouteDiff, WorkspaceDelete/Name, HtmlSanitizer, Csrf, LockService, DenyByDefault): 193 Tests, 2 erwartete Fehler = Allowlists (Tester): ControllerAttributeTest (+excalidrawEditor NoCSRF, saveExcalidraw/workspaces CSRF aktiv), TemplateOutputTest (+1 print_unescaped JSON-Insel-Schleife excalidraw_editor.php)
- run-e2e nginx (alle Specs hell+dunkel): alles grün außer attachments-search hell 1× 503 „Database busy“ (SQLite, parallel zu meinem sqlite-Start); Einzellauf hell grün 137
- down.sh all rc 0; nc35-gftest unberührt

## Nächster Schritt
- Tester: Allowlists (ControllerAttributeTest, TemplateOutputTest), PageIsolationTest um excalidraw_editor/api_save_excalidraw/workspaces.php, Abschlusslauf run-m3/run-m2/run-m1 alle Umgebungen; speccritic Diff

## Offene Punkte / Annahmen
- [THOMAS] workspaces.php delete verschiebt Inhalte (wie REST, B18) statt sie wie Upstream zu löschen (vorläufig)
- [THOMAS] workspaces.php Teilen 501 bis M5 (Empfänger über NC-Teilen-Regeln/ISearch) (vorläufig)
- Vollmodus (div.excalidraw-data) nur für bestehende Notizen; Anlegen per note_id=0 entfällt (kein UI-Weg)
- Diagramm-JSON > 7 MiB → 413 (libxml-Grenze im Sanitizer)
- verwaiste Vorschauen nach Snapshot-Ausdünnung bleiben als Anhang (wie gelöschte Diagramme)

## Nacharbeit r1 (Review docs/reviews/M3c2-diff-r1.md)
Auftrag: B1 Regex-Fehler → nie leerer/unbereinigter Inhalt (Exception, linear), moveNotes in tx neu lesen, JSON_INVALID_UTF8_SUBSTITUTE in Insel-Templates, Vormerkungen M5. Kein Docker/make/tests (Tester parallel).
- Bestand gelesen: alle preg_* in lib/; Schreibpfade: HtmlSanitizer, ContentUrlRewriter::toServer, NoteService::applyContent(79), ExcalidrawService, DuplicateService, InputValidator
- Plan: lib/Service/Pcre.php (wirft BadRequest bei UTF-8, sonst TooLarge 413), sanitizeMarkdown linear (Blockmuster possessiv, on*-Attribute per Tag-/Attribut-Scanner wie Browser), toServer linear
- lib/Service/Pcre.php neu (replace/replaceCallback/match/matchAll/split; UTF-8 → 400, sonst 413)
- HtmlSanitizer: alle preg → Pcre; sanitizeMarkdown possessiv + stripEventHandlers (Browser-Zerlegung, alle on* je Tag); maskCode Inline possessiv; sanitizeHtml LIBXML_PARSEHUGE (Attribut > 10 MB ging verloren)
- Probe scratchpad b37.php (php -d memory_limit=512M) rc 0: MD/HTML 0,5/1/2/7 MB erhalten + on* weg, HTML 12 MB Attribut erhalten, backtrack_limit=10 → 413, Upstream-Muster liefert '' (Gegenprobe); eq.php alt/neu: gleich außer 5 Sicherheitsfällen (3. Handler, „>“ im Wert, Anführungszeichen-Umgehung, „<1“-Name, Handler ohne Wert)
- ContentUrlRewriter::toServer linear (Treffer per Literal-Regex, Tokenanfang per strrpos, kein Regex über Token); url.php: gleich bis auf 1 Fall (alt verschluckte ersten von 2 Treffern im Token), 7+7 MB Token 0,25 s
- NoteService::applyContent markdown-editor-Hülle per strpos statt .*?
- ExcalidrawService, DuplicateService, InputValidator: preg → Pcre (spans/attrs/references/attachmentIds werfen statt leer)
- Proben: unit.php (HtmlSanitizerTest mit Stubs, 44 Assertions grün), exc2.php (placeEmbedded+toServer+Sanitizer MD/HTML 0,5–7 MB, Ersetzen statt Duplikat) rc 0
- Nicht geändert (Lesepfade/Ableitungen, Rückfall = Eingabe): SearchTextNormalizer, MentionService::plainText, LinkService, AttachmentService::stripReferences (Fehler → Verweis bleibt, Inhalt unverändert)
- WorkspaceMapper::lockRows; moveNotes: in tx beide Zeilen sperren (≠2 → 404) und per requireWorkspace neu lesen; delete: eigene Zeile zuerst sperren (sonst landen parallel verschobene Notizen im gelöschten Workspace)
- Templates index/revisions/attachments/excalidraw_editor: JSON_INVALID_UTF8_SUBSTITUTE (attachments auch filesize-units)
- Docs: SPEC §11.2 B37, §4.3 Nr. 13 Grenzen-Text; M3-plan M4-Vormerkungen + M5-Punkte (moveNotes/reorder Besitzer B17, CleanupJob Vorschauen)
- php -l alle geänderten Dateien ok; check-forbidden-sql 0 Treffer; Proben erneut rc 0

## Vormerkungen (STATUS-tauglich)
- M5: moveNotes/reorder ausdrücklich owner_type=user && owner_id=uid prüfen, sobald teamLevel manage liefert (Quota B17)
- M5 CleanupJob: verwaiste Excalidraw-Vorschauen nach Snapshot-Ausdünnung entfernen

## Nächster Schritt (Tester)
- Neue Fälle: Markdown + Diagramm ≥ 2 MB → Inhalt erhalten; > 7 MiB → 413; Markdown mit 3 on*-Handlern in einem Tag → alle entfernt; moveNotes auf gelöschtes Ziel → 404

## Nacharbeit r2 (Lead-Entscheidung, Review docs/reviews/M3c2-diff-r2.md)
Auftrag: 16 MiB-Grenze applyContent (+Duplikat), inCode/inSvg linear, libxml-Tiefenfehler → 413, Handler-Scanner Kommentare/RAWTEXT, delete lockRows([ws,target]), stripReferences über Pcre. Kein Docker/make/tests.
- Start r2: Review gelesen
- Zusatz Lead: (5) ungültiges UTF-8 im Inhalt → 400 in applyContent; (6) Excalidraw-Sperrmeldung übersetzen
- Entwurf Handler-Scanner: Pass A (Markup-Sicht, wie bisher) + Pass B (Text-Sicht: Kommentare, Bogus, RAWTEXT/RCDATA übersprungen) bis Fixpunkt (max 4 Runden, sonst 413), dann Pass C lexikalisch (old-Region `<x[^<>]*`, `on*=` → `xon*=`, ändert keine Tokenisierung); unterminiertes Anführungszeichen → Name neutralisieren statt Rest des Dokuments löschen
- HtmlSanitizer::sanitizeHtml: iterative Vorordnung mit geerbtem inCode/inSvg, Editor-UI-Entfernung im Durchlauf (statt XPath), libxml FATAL → 413, isDangerous mit stripos-Vorfilter, hasAttributes-Abkürzung; Proben r2arch/cmpdom.php (30076 Fälle alt=neu), unit.php 44 ok, upstream.php 15 ok, perf.php (r2arch/perf.out): deep 16 MB 0,94 s, wide 16 MB ~1,9 s, mixed 1,07 s, Tiefe 2100 → 413
- stripEventHandlers: Pass A/B bis Fixpunkt + Pass C (geschrieben, Fuzz offen)
- stripEventHandlers fertig: Schnellweg ohne `[\s/"']on`, Tag+Attribute ein Regex (Rückfall einzeln bei PCRE-Grenze), Kopie nur bei Änderung; r2arch/fuzz.php 3×300k + 36 Fälle: new_lex 0, new_browser 0 (Lexbor Dom\HTMLDocument, Kontexte body/noscript/svg), Regressionen 0, alt 8081 Browser-Treffer; mdperf.out: max 2,7 s (16 MB `<a onx onx=1`), sonst ≤ 2,2 s; unit 44 ok, upstream 15 ok
- Nächster: NoteService Grenze + UTF-8, WorkspaceService::delete, AttachmentService Pcre, Excalidraw-Sperrmeldung i18n, SPEC
- InputValidator::MAX_CONTENT_BYTES 16 MiB + content() (413 / UTF-8 400), NoteService::applyContent ruft es zuerst; Duplikat kopiert gespeicherten Inhalt (keine Eingabe, keine Prüfung); Import gibt es noch nicht
- WorkspaceService::delete: lockRows([ws, target]) nach Zielwahl, < 2 → 404 (frühere Einzelsperre entfällt)
- AttachmentService::stripReferences über Pcre, Linkinhalt possessiv; r2arch/strip.php 100k alt=neu, 3 MB Linktext entfernt (alt ließ ihn stehen)
- CompatController::saveExcalidraw: LockedException → note_lock.lost_to_user per PageTranslator (B38)
- SPEC §4.3 Nr. 13, §11.2 B37 Nachtrag, B38
- r2arch/chain.php (Validator→toServer→Sanitizer): deep 1,3/5/16 MB 0,07/0,29/0,95 s, breit 16 MiB 1,11 s, gemischt 1,06 s, Markdown 16 MiB 1,44 s; Tiefe 2100 → 413, 16 MiB+1 → 413, ungültiges UTF-8 → 400
- php -l alle geänderten Dateien ok
- Status r2: fertig; Nächster Schritt: Tester (PATCH 16 MiB+1 → 413, UTF-8 → 400, Kommentar-/RAWTEXT-Fälle, Excalidraw 423 deutsch, Tiefe > 2048 → 413)

## Nacharbeit r3 (Tester-Befunde nach Fix 2)
Auftrag: (1) Tiefe > 2048 bleibt erhalten, SPEC + B38-Test umstellen (413 nur bei echter Kürzung); (2) libxml-Fehler nicht sammeln (16 MiB `<b>` → nie 500, < 256 MB, < 3 s), MD-Pfad/Excalidraw prüfen; (3) ungültiges UTF-8 in allen Eingaben zentral → 400.
- Start r3
- Befund libxml 2.9.14 (nextcloud:35): LIBXML_NOERROR wirkt nicht (PHP setzt eigene sax-Handler); libxml selbst ~185 B RSS je Knoten (16 MiB `<b>` = 1 GB RSS nur Parser); Tiefe 1.000.000 mit PARSEHUGE vollständig; libxml 2.15 (Host) kürzt bei 2048 trotz PARSEHUGE (fatal, letzter Fehler)
- HtmlSanitizer: use_internal_errors(false)+set_error_handler nur um loadHTML, fatal über libxml_get_last_error; Wrapper ohne `</body></html>` (sonst Fehler je offenem Tag); MAX_TAGS 500000 ('<' minus '</') → 413; Zeiger-Durchlauf statt Knotenliste (Entfernen/Auspacken im Lauf, leere Ersatz-divs per Marke danach entfernt), cleanAttributes gibt bool
- scratchpad r3/cmpdom.php: container 142/30076 Abweichungen, nur leere Ersatz-divs / Wrapper-Text (neu besser)
- r3/perf.out: 16 MiB b/span/p/li/x → 413 0,03 s; amp 4,8 s 211 MB RSS; end 2,45 s; Tiefe 100k erhalten
- OFFEN: 500k verschachtelte `<x>` → Segfault/quadratisch (Auspacken verschiebt Teilbaum je Ebene) – in Arbeit
- Fund: verschachtelte unbekannte Tags quadratisch (auch r2: 100k `<x>` 84 s; PHP gleicht je Verschieben den Teilbaum ab) → Auspacken im 2. Durchlauf (Vorordnung, = alte Semantik), Budget MAX_UNWRAP_MOVES 20 Mio (Summe unbekannter Vorfahren je Knoten) → 413; cmpdom container 0/30076 Abweichungen, Host nur Wrapper-Text
- ExcalidrawService: spans() (matchAll aller div/button, Speicher O(Tags)) → eachSpan/firstSpan (ein int je offenem Tag), placeFull Datenstellen per Binärsuche statt substr je Container; r3/exc3.php 7 OK, 16 MiB `<div>`×: 127 MB / ~1 s je Aufruf
- Fund (nicht r3, Lead melden): Markdown-Pass C ändert ` on*=` auch in Attributwerten (data-excalidraw-Text „a onclick=x“ → „a xonclick=x“); exc2.php markdown FAIL deshalb
- Nächster: (3) UTF-8 zentral
- (3) InputEncodingMiddleware (alle Parameter inkl. Schlüssel, vor Controller → 400), registriert nach ApiExceptionMiddleware; AttachmentService::cleanName + LockService::session prüfen UTF-8 (Dateiname/Header nicht in Params)
- SPEC: §4.3 Nr. 13 (500.000 Tags), B37 Tiefe-Text (erhalten, 413 nur bei libxml-Abbruch), B39 (Speicher/Zeit), B40 (UTF-8)
- tests: SanitizerB38Test Tiefe-Fälle (c) + testDeepNestingKeptCompleteOr413 (3000/100000) auf „erhalten oder 413 unverändert“
- Nächster: sqlite up/install/phpunit
- up.sh sqlite rc0, install-app rc0, app:enable rc0; run-phpunit sqlite --filter SanitizerB38|B37|HtmlSanitizer|XssValidation: 48 Tests OK rc0 (5 MB Kette 200 0,50 s; 16 MiB b/i/p 413 0,15 s)
- läuft: volle PHPUnit sqlite (pu2); danach r3/u8.sh (UTF-8 an 12 Endpunkte), down.sh all
- volle PHPUnit sqlite: 269 Tests OK rc0 (vor LockService::assertCanSave-Fix)
- r3/u8.sh: Fund Editor-Sitzung-Header ungültiges UTF-8 → assertCanSave nahm Sperre mit dieser Sitzung (DB) → Prüfung in assertCanSave → 400; u8.sh 13/13 OK rc0 (nach apachectl graceful wegen opcache 60 s)
- PHPUnit sqlite --filter Lock|B38|B37|HtmlSanitizer|Xss|NoteVersion|ExcalidrawSave|PageIsolation|Attachment: 79 OK rc0
- down.sh all rc0; check-forbidden-sql rc0; php -l ok
- Status r3: fertig. Nächster Schritt: Tester (B38-Test neu, Matrix)

## Nacharbeit r4 (Pass C ändert Attributwerte)
Auftrag: Pass C nur Stellen umschreiben, die Attributname sein könnten; quotierte Werte vollständig geparster Tags unverändert (exc2 markdown, `&quot; onclick=`); Fuzz 0/0, Zeiten nicht schlechter; phpunit sqlite Filter, down.sh.
- Start r4; Sicherung scratchpad/r3/HtmlSanitizerR3pre.php; exc2.php vorher: markdown FAIL ×4
- Ansatz: Tag-Starts, die Markup- UND Text-Sicht im Fixpunkt vollständig (bis `>`) geparst haben, sammeln; Pass C lässt in deren eigenem Bereich die Innenseite terminierter quotierter Werte aus; `<` in Werten bleibt eigener Bereich (altes Netz)
- HtmlSanitizer: scanHandlers merkt geschlossene Start-Tags mit möglichem `on…=` (je Sicht), Schnittmenge → neutralizeHandlers schont Innenbereiche quotierter Werte (quotedInside); ohne geschützte Tags alter Regionsweg; Treffer-Schleife linear (Lücke seit letztem Treffer, Sprung zum nächsten `<`)
- Fund Fuzz erweitert (Kontexte +math/select/table/template/foreignObject/mtext, Fragmente CDATA/&quot;/data-excalidraw): 3 Handler bei CDATA in SVG/MathML, auch r3 (keine Regression) → dritte Sicht VIEW_FOREIGN (CDATA bis `]]>`, kein RAWTEXT), nur wenn `<![CDATA[` vorkommt
- Proben: r4/same.php 8 ok, exc2.php 8 OK, unit 44 ok, upstream 15 ok, r4/mdperf.out (= r3 ± Rauschen), r4/perf2.out (adversarial quoted on= +1 s, cdata +1 s, region ohne treffer 11 s schon r3)
- Nächster: Fuzz 3 Seeds final, chain, SPEC B37, sqlite phpunit, down.sh
- Fuzz r4/fuzz.out 3×300k: new_browser 0, Regressionen 0 (lex_info ~800 = geschonte Werte, gewollt); r4/chain.out ≈ r3
- SPEC §11.2 B37 ergänzt (Fremd-Sicht, Schonung quotierter Werte)
- up.sh sqlite rc0, install-app rc0, app:enable rc0; run-phpunit sqlite --filter SanitizerB38|B37|HtmlSanitizer|XssValidation|ExcalidrawSave: 55 Tests OK rc0; down.sh all rc0
- Status r4: fertig

## Nacharbeit r5 (Attribut-Budget, Worst Case ≤ 5 s)
Auftrag: Attribute je Tag/gesamt begrenzen → 413 vor teuren Durchläufen (MD + HTML), Worst Case 16 MiB alle Formen ≤ 5 s, Fuzz r4 0/0, PHPUnit-Filter sqlite, down.sh, SPEC B39.
- Probe scratchpad/r5/worst.php + all.sh (Host) + dk.sh (nextcloud:35, libxml 2.9.14); vorher dk-before.out: MD attr2 10,7 s, MD attr4 2,5 s/379 MB, HTML attr1/attr5/attrs1k/amp 4,8–5,0 s
- Befund libxml 2.9: Fehler-Callback je Fehler (doppeltes Attribut, nacktes &, Endtag) kostet ~2,6× → LIBXML_NOERROR|NOWARNING (err.php); eindeutige Attribute je Tag quadratisch (1024×1000 Tags 2,4 s; 256×4000 0,55 s; 1,5 Mio in einem Tag > 3 min)
- Entscheidung: MAX_ATTRS_PER_TAG 256, MAX_ATTRS 1.000.000 gesamt, lexikalisch gezählt (Tokenizer-Regeln wie Pass A) vor loadHTML bzw. vor stripEventHandlers (nach maskCode)
- HtmlSanitizer: assertAttributeBudget (Regex springt zu Start-Tags mit Attribut, MD_ATTR_AT je Attribut) in sanitizeHtml (nach MAX_TAGS) und sanitizeMarkdown (nach maskCode); loadHTML + LIBXML_NOERROR|NOWARNING (Fatal-Erkennung Host libxml 2.15 Tiefe 2100 → 413 weiter ok)
- r5/dk-after.out (nextcloud:35): alle Formen ≤ 3,7 s (MD cdata), HTML amp 1,9 s, end 1,0 s; Budget-Grenzfälle b_* ≤ 2,1 s; r5/chain.out breit +0,2 s, markdown 16 MiB 1,8 s
- r5/cmp.php (DOM-Pfad alt r3 vs neu): 0/20076 Abweichungen Host und Container; Fuzz r5/fuzz.out 3×300k 0/0; same/exc2/unit/upstream ok
- SPEC B39 Nachtrag, §4.3 Nr. 13 Attributgrenze
- Nächster: sqlite phpunit, down.sh
- up/install/app:enable sqlite rc0; run-phpunit sqlite Filter: 55 Tests OK rc0; down.sh all rc0
- Status r5: fertig

## Nacharbeit r6 (Fließtext nach „a<b“)
- Markdown ohne Grenze je Tag (Scanner linear), nur MAX_ATTRS gesamt; offene Tags bis Inhaltsende zählen weiter (Begründung: Markdown-Ausgabe endet nicht am Notizende, Entschärfung liest sie; sonst 16 MiB offenes Tag 11 s); HTML-Pfad unverändert (libxml parst offene Tags auch, quadratisch; Editor-HTML escaped `<`)
- r5/dk-r6.out (nextcloud:35): prose 5.000 Wörter 200 byte-gleich, prose300 mit späterem `>` 200 byte-gleich, 990k Wörter 200 0,2 s, 2,8 Mio. Wörter 413; Einzeltag 1 Mio. Attribute ≤ 1,8 s (243 MB PHP bei 1 Mio. quotierten Werten), Maximum 3,7 s (cdata)
- Fuzz r5/fuzz-r6.out 3×300k 0/0; same/exc2/unit/upstream ok; SPEC B39 + §4.3 Nr. 13 angepasst
- up/install/app:enable rc0; PHPUnit sqlite Filter 55 OK rc0; down.sh all rc0
- Status r6: fertig

## Nacharbeit r7 (MariaDB 8 MiB → 500, max_allowed_packet)
Auftrag: Ursache (Inhalt mehrfach im Statement?), Grenze an max_allowed_packet koppeln falls nötig, rollBack nur bei aktiver Transaktion; PHPUnit mariadb+sqlite Filter B38|B39|ExcalidrawSave.
- Start r7
- Ursache: PDO-MySQL emulierte Prepares, UPDATE/INSERT notes schreibt content + search_text (Markdown ≈ gleich groß) in ein Paket → 8 MiB × 2 > 16 MiB; Escaping (\n, ", \\ …) vergrößert zusätzlich bis 2×
- Note::takeSearchTextUpdate + NoteMapper insert/update überschrieben: search_text eigenes UPDATE (auch DuplicateService, AttachmentService)
- InputValidator: Konstruktor IDBConnection, storable() (16 MiB; MySQL/MariaDB maskierte Länge ≤ max_allowed_packet − 1 MiB, einmal je Request per QB @@max_allowed_packet), content() nutzt storable; NoteService::applyContent prüft nach Sanitizer erneut
- Transaction::run rollBack nur bei inTransaction, Fehler beim rollBack verschluckt (ursprüngliche Ausnahme bleibt); keine weiteren Helfer (grep)
- php -l 5 Dateien ok, check-forbidden-sql rc0
- Nächster: mariadb up/install/phpunit Filter
- mariadb up/install/enable rc0; PHPUnit mariadb Filter: 17/18, Fehler nur testContentOverLimitIs413Unchanged „knapp unter 16 MiB“ (16 MiB−4 KiB, 1/15 Zeilenumbrüche → maskiert ~17 MiB > Paket) → 413 statt 200: Test muss effektive Grenze nutzen (Tester)
- scratchpad/r7/size.sh mariadb: MD 7/8/10/13/14 MiB PATCH 200, GET 200 Länge gleich, DUP 200; 14,5/15,9 → 413 (max 15 MB), 16,5 → 413; MariaDB-Log 0 Paketfehler
- Nächster: SPEC B38/§4.3 Nr. 13, sqlite
- SPEC §4.3 Nr. 13 + B37 (Größen-Nacharbeit) ergänzt
- sqlite up/install/enable rc0; PHPUnit sqlite Filter B38|B39|ExcalidrawSave 18/18 rc0; size.sh sqlite 15,9 → 200 (GET Länge gleich, DUP 200), 16,5 → 413
- läuft: PHPUnit mariadb --filter Search|Duplicate|Note|Snapshot|Attachment|Lock (Mapper-Regression)
- PHPUnit mariadb --filter Search|Duplicate|Note|Snapshot|Attachment|Lock: 274 Tests, 273 OK, 1 Fehler = derselbe B38-Gegenprobe-Fall (Testanpassung nötig) rc1
- down.sh all rc0 (nc35-gftest unberührt)
- Status r7: fertig (offen: Tester passt Gegenprobe „knapp unter 16 MiB“ in SanitizerB38Test auf effektive MySQL-Grenze an)

## Nacharbeit r8 (search_text-Paket, Duplikat storable, B-Nummern)
Auftrag: search_text beim Bau auf speicherbare Länge kürzen (UTF-8-Grenze); Duplikat-Umschreibung mit storable (413); Kommentare B38→B37; MariaDB-Probe 10,5 MiB U+0130.
- InputValidator: storable() über packetBytes() (Semantik gleich), neu fitStorable() (16 MiB per mb_strcut, dann maskierter Überschuss in einem Schnitt)
- SearchTextNormalizer: Konstruktor InputValidator, build() → fitStorable
- DuplicateService: storable vor setContent in cloneNote (Anhang-IDs) und rewriteLinks
- Note.php/NoteMapper.php/InputValidator Kommentare B37
- php -l 5 Dateien ok
- Nächster: check-forbidden-sql, MariaDB-Probe
- check-forbidden-sql rc0
- MariaDB belegt (Tester run-m3/m2/m1) → eigene Instanz scratchpad/r8/compose.yaml (Projekt nn-r8-architekt, Port 8092, max_allowed_packet 16 MiB); r8/probe.sh:
  - 10,5 MiB U+0130 (11.010.059 B) PATCH 200, GET byte-gleich, Suche „wortanfang“ findet, DUP 200; DB search_text 15.728.640 B (gekürzt), nextcloud.log 0 Fehler
  - Ordner-Duplikat mit Link-Umschreibung über Grenze → 413 App-JSON, kein Kopie-Ordner, keine Kopie-Notiz
- eigene Instanz down -v rc0; down.sh mariadb NICHT ausgeführt (Tester-Instanz)
- Status r8: fertig
