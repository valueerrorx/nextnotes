# M3c2 speccritic
Auftrag r1: Diff-Review M3c2 gegen HEAD 710792b3 -> docs/reviews/M3c2-diff-r1.md (fertig, BLOCKER)
Auftrag r2: Nacharbeit B37 pruefen -> docs/reviews/M3c2-diff-r2.md
Status r2: fertig (BLOCKER: PARSEHUGE ohne Inhaltslimit + O(n*Tiefe)-Schleife)
Erledigt r1: Diff gelesen; Proben t2.php/t3.php; Review geschrieben
Erledigt r2: -
Naechster Schritt: r3 nur nach Lead-Entscheid (max. 2 Runden)
- r2: Pcre.php, HtmlSanitizer.php, Diff NoteService/ContentUrlRewriter/Duplicate/InputValidator/WorkspaceMapper gelesen
- r2 Verdacht: Scanner ueberspringt quoted Werte -> Kommentar/RCDATA-Kontext (<!-- <p title="--><img onerror>">) laesst onerror durch; pruefen
- r2 Probe r2a.php (scratchpad): Scanner ok fuer /, Quotes, Gross, NUL, svg/math, unvollst.; Regression ggue. alt: Kommentar/RCDATA (<!--, textarea,title,noscript,xmp) laesst onerror durch. Anzeige: p() + Client-/Upstream-Parser escapen alles -> nur Defense-in-Depth -> Hinweis
- r2 Probe r2b/r2c: kein Inhaltslimit bei PATCH/create; PARSEHUGE hebt Tiefe 256->2048; sanitizeHtml-Ahnenschleife O(n*Tiefe): 1,3MB 6,5s, 5,2MB 26s -> Blocker-Kandidat. Ohne HUGE: Tiefe>256 schneidet Rest ab (Altlast)
- r2: md <script>+>1M '<' im Absatz -> 413 (kein Datenverlust, ok)
- r2 lockRows: FOUND_ROWS in NC gesetzt (ok MySQL); moveNotes ok. Luecke: delete sperrt Ziel nicht -> paralleles delete(ws1)/delete(Ziel) laesst Notizen in geloeschtem WS (Hinweis)
- r2 NoteService 79-85 gleichwertig
- r2 Probe r2d (Differential-Fuzz toServer alt/neu 300k): Abweichungen nur bei mehreren URLs im selben Token, neu verlustfrei (alt verschluckte) -> ok
- r2: docs/reviews/M3c2-diff-r2.md geschrieben
Auftrag r3: Diff r7 (Note/NoteMapper/InputValidator/NoteService/Transaction, SPEC B37) -> docs/reviews/M3c2-diff-r3.md
- r3: Diff gelesen; QBMapper/Entity (.ref) geprueft: kein resetUpdatedFields, setter ueberspringt gleiche Werte -> Split ok
- r3: alle content-Schreiber (create/update/beacon/restoreSnapshot/saveContent/Duplicate/Attachment) in tx->run -> konsistent
- r3 Befund: mb_strtolower expandiert U+023A/U+0130/U+023E 2->3 Byte (php -r geprueft) -> search_text > Paket -> 500 (blockierend)
- r3 Hinweis: Duplicate rewriteLinks/cloneNote ohne storable; Kommentare nennen B38 statt B37
Status r3: fertig (BLOCKER)
