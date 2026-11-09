Status: fertig

# M8b speccritic (Diff-Review r1)
Auftrag: Diff-Review M8b Tabellenblock gegen M8-plan §1/§1.1/§1.1a/M8b, ADR 0018, SPEC §4.7; Ergebnis docs/reviews/M8b-r1.md.

## Erledigt
- Plan, M8a-Review, Architekt-Bericht, PHP-/JS-/Build-/Doku-Diff, SheetMarkupTest, E2E-Abdeckung (grep), l10n-Abgleich gelesen
- Node-Proben (Scratchpad hf/): COUNTIF-Wildcards ReDoS (>60 s, Exit 124; ohne Wildcards 54 ms); OFFSET 1e6 Zeilen 1,4 s, 1e8 OOM (Exit 134); BINOM.VERT groß unkritisch
- Review geschrieben: VERDICT BLOCKER (B1 Wildcard-ReDoS, B2 BEREICH.VERSCHIEBEN ohne Grenze), 6 Hinweise

## Nächster Schritt
- keiner (r2 nach Nachbesserung)

## Offen/Annahmen
- Keine Container gestartet; E2E/PHPUnit nicht selbst ausgeführt

# r2 (Diff-Review nach Fix)
Auftrag: B1/B2 nachmessen, Budget, H1–H5, notify-Risiko; Ergebnis docs/reviews/M8b-r2.md
## Erledigt
- r1/Progress gelesen; Architekt fix gelesen; sheet.js guard/cost/setCells/structure/paste/history gelesen; HF LexerConfig verglichen
- Probe r2/p1-p3.cjs (Scratchpad hf/r2): Bypass Blattname mit " ('x"'!B2 … "") versteckt Aufrufe+Bereiche vor code(): NN_GESPERRT_OFFSET 1e6 Zeilen 1,4 s, 1e8 OOM (Exit 134); Budget-Bypass 520 Zellen MODUS.EINF 9,6 s (ohne Trick 102 ms) → BLOCKER
- p4: Sheet1!A1:Sheet1!Z30 unterschätzt (1,3 s); p5: Budget legitim ≤ 132 ms; node --test sheet-engine.mjs 10/10 Exit 0
- H1–H5 geprüft (ok), notify: showNotification undefiniert (nur showNotificationPopup) → Hinweis
- Review geschrieben: docs/reviews/M8b-r2.md, VERDICT BLOCKER (1 Blocker, 7 Hinweise)
## Offen
- keine Container; E2E/PHPUnit nicht selbst ausgeführt
## Nächster Schritt
- keiner
