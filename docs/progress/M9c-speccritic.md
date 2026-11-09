# M9c speccritic (Diff-Review r1)
Status: fertig
Auftrag: Diff-Review M9c OneNote-Seitenkonverter (Sicherheit, Robustheit, B37/libxml, Treue, Tests) -> docs/reviews/M9c-r1.md
## Erledigt
- Gelesen: Plan §5/§6/M9c, Bericht Architekt, Convert/*.php, HtmlSanitizer.php, Tests, expected.json
- Scratchpad raw.php/conv.php/big.php/raw2.php auf Host (libxml 2.15.4) und nextcloud:35.0.2-apache (2.9.14, docker run --rm, ro-Mounts), alle Exit 0
- Befund: Konverter ohne PARSEHUGE kürzt still (Attr >10 MB unter 2.15, Text >10 MB unter 2.9.14; Level-2-Fehler, Fatal-Check greift nicht) -> Blocker
- B37 Sanitizer: keine stille Kürzung (2.9.14+HUGE ohne Tiefengrenze, 2.15 Fatal ab 2048 abfragbar); Architekten-Vermutung widerlegt
- Sicherheitsproben href/style/iframe/math/Platzhalter/applyResources ohne Befund
- Review geschrieben: docs/reviews/M9c-r1.md (VERDICT: BLOCKER, 1 Blocker, 5 Hinweise)
## Nächster Schritt
- keiner (r2 nach Behebung)
## Offen
- Altbestand ImportService/MarkdownRenderer ohne PARSEHUGE: Lead entscheidet
