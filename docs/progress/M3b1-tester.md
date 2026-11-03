# M3b1 – Tester
Auftrag: Slice-Abschluss M3b1 (PHPUnit voll auf sqlite/Apache, E2E hell+dunkel auf nginx). Allowlist note_display, neue E2E editor.mjs, bestehende E2E + check-upstream, Fremdzugriff Fragment/Lesefreigabe.
Status: fertig
## Erledigt
- make build rc0 (123), check-upstream grün; up+install+enable sqlite (8081), nginx (8084)
- tests/nextnotes/php/Support/ApiClient.php: withHeaders()
- BEFUND 1: $note['display'] nicht nachweislich sanitisiert: tasklist mit Inhalt "\x0B[...]" (oder "\0[...]") → HtmlSanitizer::isStructuredJson (trim) = JSON, roh gespeichert; noteView json_decode(ungetrimmt) scheitert → print_unescaped roh. Live bestätigt (POST /notes type=tasklist, GET index.php?note= enthält <img onerror>). Allowlist NICHT ergänzt.
- tests/nextnotes/php/Page/EditorPageTest.php (Fragment eigen/fremd, Lesefreigabe, Tasklist-Bypass); vor Fix: Fragment PASS, Lesefreigabe FAIL (contenteditable=true; PATCH 403 PASS), Tasklist FAIL (Befund 1)
- tests/nextnotes/e2e/editor.mjs angelegt (+B26-Gegenprobe, Prototyp-Aktionen, Lesefreigabe per docker exec); vor Fix nginx light: 69 ok, 1 FAIL (.btn-move unsichtbar → Testfehler, korrigiert)
- Allowlist note_display auf Anweisung Lead ergänzt (Lead korrigierte Quoting)
- Nachlauf 2 nginx hell+dunkel: editor 118 ok, assets 82, budget 21, xss 82 grün; frame: veraltete Prüfung „rechte Spalte leer (erst M3b)“ → auf Notiz in #right_col umgestellt, danach 143 grün je Theme
- down.sh all rc0; nc35-gftest unberührt
- Nachlauf 3 (nach Lead-Fix Befund 1): EditorPageTest Präfixe \x0B,\x0C,\0,\xC2\xA0 ergänzt + testTasklistWithLeadingWhitespaceStaysTasklist; build rc0, up+install+enable sqlite/nginx rc0; THEMES=light run-e2e nginx editor rc0 (118)
- PHPUnit voll sqlite: OK 235/235 (7486 Assertions), rc0, 9:50 min; down.sh all rc0
## Nächster Schritt
- keiner
## Offen
- Nachlauf (nach Fix) PHPUnit voll sqlite: 234 Tests, 1 Failure = EditorPageTest::testTasklistContentNeverRenderedUnsanitized (Befund 1, VT) – Lesefreigabe/Fragment/TemplateOutput grün
- Nachlauf nginx: stripped hell+dunkel grün (113); editor: Testfehler Prototyp-Probe (Body-Knopf, Klick auf NC-Logo) → korrigiert; Lesefreigabe-Asserts (kein Lock-POST/Banner) + B32 zweiter PATCH ergänzt
- Befund 1 behoben (Nachlauf 3 grün)
