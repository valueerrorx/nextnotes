# M3b1 speccritic (Diff r1 + r2)
Auftrag r2: Arbeitsbaum gegen 3dee857e; r1-Befunde erledigt? CONTENT_ACTIONS, contenteditable/canWrite, P11, P10, fragment()-404, Doku.

Status: fertig

Erledigt:
- r1: docs/reviews/M3b1-diff-r1.md (BLOCKER, 1 Blocker, 7 Hinweise)
- r2: git diff 3dee857e (alle Dateien) gelesen, Exit 0
- r2: php -l TemplateOutputTest.php → Parse-Fehler Z.23 (Exit 255); EditorPageTest.php ok
- r2: P11/P10 gegen note-edit-lock.js, events-auto-save.js, notes.js, AccessService geprüft
- r2: docs/reviews/M3b1-diff-r2.md (BLOCKER, 1 Blocker: Parse-Fehler Allowlist)

Nächster Schritt: keiner (r3 nur Lead-Entscheid / Nachprüfung Z.23)
Offen/Annahmen: Allowlist-Eintrag evtl. noch in Arbeit beim Tester; Browserverhalten nur statisch geprüft.
