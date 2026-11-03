# M3c2 – Tester
Auftrag: Slice M3c2 (Allowlists, PageIsolationTest excalidraw/api_save_excalidraw/workspaces.php, e2e/excalidraw.mjs, B35/B36, 503-Retry) auf sqlite; danach M3-Abschluss volle Matrix (run-m3 alle, run-m2, run-m1, E2E hell+dunkel).
Status: wartet auf Fix 3 (Teil 2 nicht gestartet, Gate rot)
## Erledigt
- Allowlists: ControllerAttributeTest (+excalidrawEditor NoCSRF; saveExcalidraw/workspaces CSRF), TemplateOutputTest (+excalidraw_editor.php ALLOW + Flags-Provider); php -l ok
- Support/ApiClient::form (urlencoded/multipart für Compat-POSTs)
- PageIsolationTest +4 Tests (Owner, Foreign 404 alle ID-Stellen + 8 Aktionen + gemischt, ReadShare 403, Lock 423); php -l ok
- Page/ExcalidrawSaveTest.php neu (B35, B36 HTML, B36 Markdown); php -l ok
- e2e/excalidraw.mjs neu (Slash→Editor→Rechteck→Speichern→Vorschau; Sperre 423 + Meldung + nach Freigabe 200; Lesefreigabe Ansichtsmodus); node --check ok
- e2e/lib/api.mjs: call() wiederholt 503 nur mit Retry-After (≤5×); e2e/lib/docker.mjs: share(perm)
- make build rc0 (129); down/up/install/enable sqlite rc0 (Instanz bleibt, KEEP)
- PHPUnit sqlite --filter ControllerAttribute|TemplateOutput|PageIsolation|ExcalidrawSave: 23 Tests, nach 2 Testkorrekturen (data-excalidraw in '…', JSON-escaped /) grün; ExcalidrawSave 3/3 rc0
- Lead-Auftrag: ExcalidrawSaveTest +4 Große-Inhalte-Tests (MD 2 MB PATCH, MD mit 2-MB-data-excalidraw-Tag PATCH, save_embedded 2 MB html+md inkl. Ersetzen/PATCH, >7 MiB 413, HTML 2 MB PATCH)
- Lauf vor Fix (pu5): rc1, 7 Tests, 2 rot: MD-Tag-PATCH → 0 Bytes; save_embedded markdown → 0 Bytes (Blocker bestätigt); HTML-Pfade + MD-Klartext grün
- e2e excalidraw: 3 Testkorrekturen (Benutzer-2-Requests in eigenem Kontext, da page.request Sitzungs-Cookie von alice mitsendet; data-excalidraw in '…'; alert = DOM-Modal aus modal-alerts.js)
- run-e2e.sh sqlite excalidraw rc0: hell 46, dunkel 46 grün (423 + Modal, Lesefreigabe view-mode, 0 CSP)
- Hinweis: Sperr-Meldung englisch („This note is currently being edited by bob“) bei Sprache de
- Lead: B37-Fix da; Security/SanitizerB37Test.php neu (MD-Handler je Tag, ungültiges UTF-8 → 400, move_notes gelöschtes Ziel → 404); ApiClient::formMethod
- NACH FIX: make build rc0, install-app sqlite; PHPUnit --filter ControllerAttribute|TemplateOutput|PageIsolation|ExcalidrawSave|SanitizerB37|HtmlSanitizer|XssValidation|WorkspaceDelete|WorkspaceName: 74 Tests, 1 rot nur Testdaten (>7 MiB-Payload war < 7 MiB) → korrigiert; ExcalidrawSave 7/7 rc0; Rest grün
- Teil 2 gestartet und auf Lead-Anweisung abgebrochen (nur Build reproduzierbar 129 + check-upstream grün gelaufen, sqlite PHPUnit abgebrochen); down.sh all rc0
- Lead r2: Security/SanitizerB38Test.php neu (5 MB tief <1 s?, >16 MiB 413, Tiefe>2048 413, MD Kommentar/Rawtext-Umgehung, ungültiges UTF-8 Titel/Tags/Inhalt/Ordner/Workspace 400)
- VOR FIX 2 (Stand Fix 1): run-phpunit sqlite --filter SanitizerB38|SanitizerB37 rc1, 8 Tests, 5 rot = alle B38 (5 MB Tiefe 2000: 200 in 25,05 s; 16 MiB: 200; Tiefe 3000: 200; MD <!-- …--><img onerror> bleibt; Inhalt mit ungültigem UTF-8: 200); B37 3/3 grün; down.sh all rc0
- Fix 2 da: B38-Test fordert App-413 mit JSON-Body;- NACH FIX 2 (sqlite): PHPUnit-Filter (13 Klassen) 91 Tests, 3 rot: Tiefe 3000 → 200 (Inhalt vollständig erhalten, PARSEHUGE hebt libxml-Tiefengrenze auf; keine Kürzung); 5 MB ungeschlossene <span> → 200 in 0,84 s; Ordner POST ungültiges UTF-8 → 500 + Ordner angelegt
- B38-Zeiten: 5 MB Tiefe 2000 ×N 200 in 0,43 s; 16 MiB 413 (App-JSON) 0,09 s
- neuer Test testManyUnclosedTagsBelowLimitNo500: 16 MiB '<b>' → 500 in 4,76 s (libxml_get_errors Speicher, HtmlSanitizer.php:163; direkt mit 1 GB memory_limit Fatal)
- run-e2e sqlite excalidraw rc0 hell/dunkel 46 (deutsche Sperr-Meldung); down.sh all rc0
## Nächster Schritt
- nach Fix 3: build, sqlite PHPUnit-Filter + SanitizerB38Test, dann Teil 2 Matrix
## Offen
- Tiefe>2048: Inhalt bleibt vollständig (kein stilles Kürzen) statt 413 → Lead entscheidet, ob Test auf „erhalten“ umgestellt wird
## Fortsetzung (Fix r3–r6, Lead: Teil 1 + M3-Abschluss)
- Security/SanitizerB39Test.php neu: (a) 16 MiB-1 KiB <b> 413, (b) UTF-8 Ordner/Tag/Task/Reminder/Setting 400, (c) MD a<b + 5000 Wörter byte-gleich, (d) MD-Excalidraw „a onclick=x“ byte-gleich, (e) 300 Attribute 413; php -l ok
- build rc0 (129), check-upstream rc0; up/install/enable sqlite rc0 (KEEP)
- PHPUnit sqlite Filter 14 Klassen: 97 Tests, 1 rot nur Testartefakt (B39 (d) json_decode assoc {}→[]) → korrigiert; B39 5/5 rc0; Rest grün
- B38-Zeiten: 5 MB Tiefe 2000 ×N 200 0,50 s; 5 MB ungeschl. span 413 0,08 s; 5 MB Kette 200 0,52 s; 16 MiB b/i/p 413 0,14 s; HTML 16 MiB 413 0,09 s; B39 <b> 413 0,15 s
- run-e2e sqlite excalidraw rc0: hell 46, dunkel 46 grün (423 deutsch, 0 CSP) → Teil 1 grün
- Teil 2: down.sh all, dann run-m3.sh (alle), run-m2.sh, run-m1.sh nacheinander (Logs scratchpad m3/m2/m1.log)
- run-m3.sh gestartet (Hintergrund, scratchpad/m3.log)
- run-m3 rc1: sqlite grün (274 OK, E2E 9×2), pgsql 274 OK, nginx E2E 9×2 grün, Build 2× 129 + check-upstream grün; mariadb ROT: B38 testContentOverLimitIs413Unchanged 'Markdown knapp unter 16 MiB' → 500 → Teil 2 gestoppt, Reproduktion mariadb
- Reproduktion mariadb: Markdown-PATCH 2–7 MiB 200, 8/12/15/15,9 MiB 500; DB-Log „Got a packet bigger than 'max_allowed_packet'“ (16777216, MariaDB-Standard), NC-Log „2006 MySQL server has gone away“, danach Transaction::rollBack „There is no active transaction“ (Ursache maskiert). App-Fehler → Teil 2 gestoppt (run-m2/run-m1 nicht gelaufen)
- down.sh all rc0; nc35-gftest unberührt
Status: fertig
## Nächster Schritt
- nach Fix: mariadb run-phpunit --filter SanitizerB38Test, dann Teil 2 ab run-m3.sh mariadb, run-m2.sh, run-m1.sh
## Nachlauf Fix r7
- B38 testContentOverLimitIs413Unchanged plattformabhängig: MySQL Gegenprobe maskiert ≤ max_allowed_packet−1MiB−4KiB (200, md5-gleich) + knapp darüber <16 MiB roh → App-413 unverändert; php -l ok
- mariadb up/install/enable rc0; run-phpunit mariadb --filter B38|B39|ExcalidrawSave: 18 Tests rc0 (unter 14,06 MiB 200 md5-gleich, darüber 413)
- run-phpunit pgsql gleicher Filter: 18 Tests rc0
- Teil 2: down.sh all, dann run-m3.sh mariadb; run-m2.sh; run-m1.sh (Logs scratchpad m3/m2/m1.log)
- Kette m3 mariadb → m2 → m1 läuft im Hintergrund (scratchpad rc.txt)
- run-m3.sh mariadb rc0 (Build 2× 129 reproduzierbar, check-upstream, info.xml, PHPUnit 274 OK)
- run-m2.sh rc0 (sqlite/mariadb/pgsql je 274 OK); run-m1.sh rc0 (sqlite/mariadb/pgsql/nginx grün)
- down.sh all rc0; nc35-gftest unberührt
## Nächster Schritt
- keiner (M3-Matrix vollständig grün)
## Nachlauf r8
Auftrag: B38-Tests (a) 10,5 MiB „wortanfang “+İ PATCH 200/byte-gleich/Suche, (b) Duplikat über Grenze 413 ohne Kopie; build; PHPUnit-Filter mariadb+sqlite; down.sh all
- build + up/install sqlite, mariadb gestartet (Hintergrund, scratchpad r8-prep.txt)
- build rc0; up+install sqlite/mariadb rc0; Tests (a) testDottedCapitalISearchTextFitsNo500, (b) testDuplicateGrowingOverLimitIs413NoCopy in SanitizerB38Test (php -l ok); mysqlMaskedLimit auch PLATFORM_MARIADB
- Lauf 1 (Filter, je ~10,5 min): sqlite rc1, mariadb rc1, je 276 Tests, 1 rot = nur Testartefakt (b): GET liefert Anhang-URLs umgeschrieben (+15 B/Link), Prüfung auf DB-Zeile umgestellt; (a) beide DBs 200, byte-gleich, Suche trifft
- Nachlauf (b) --filter 'SanitizerB38Test::testDuplicate': sqlite rc0 (413 „max 16 MB“), mariadb rc0 (413 „max 15 MB“), Notiz + Ordner, dump unverändert
- down.sh all rc0; nc35-gftest unberührt
Status: fertig
## Nächster Schritt
- keiner
