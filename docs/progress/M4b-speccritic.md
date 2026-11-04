# M4b speccritic (Diff-Review r1)
Auftrag: uncommitteten Diff M4b gegen M4-plan (M4b, Lead-Auflagen r2), SPEC §1.2/§2.2/§4.5/§11, ADRs prüfen (XSS, Rechte, Daten, DoS).
Erledigt:
- Plan, SPEC-Diff, Controller, NoteService, ConversionService, EmbeddedImageService, ImportService, Kanban, Templates, Pcre/PcreCalls, AttachmentResponse, Transaction, ConversionTest gelesen
- grep preg_ in Convert/Import: nur PcreCalls (Wrapper) – ok; sha256 upstream-ported.txt: alle gleich
- Review geschrieben: docs/reviews/M4b-r1.md (FREIGABE, 5 MINOR)
Nächster Schritt: keiner
Offene Punkte: MarkdownRenderer/HtmlToMarkdown (2400 Zeilen) nur stichprobenartig (B46-Stellen), Laufzeit verlässt sich auf Testlauf
Status: fertig
