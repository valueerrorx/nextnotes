# ADR 0013: Frontend-Strategie

Status: entschieden (Thomas, 2026-11-02): Richtung (a). Aufwandsschätzung zur Dokumentation.

## Kontext
Upstream-Frontend: ≈ 224 eigene JS-Dateien (≈ 140 000 Zeilen, klassische Skripte mit Globals), ≈ 60 000 Zeilen PHP-Views/Includes, ≈ 95 CSS-Dateien (≈ 44 000 Zeilen), 4 480 i18n-Schlüssel in 10 Sprachen, Vendor: CodeMirror 6, Excalidraw, Mermaid, KaTeX, highlight.js, Sortable. Leitprinzipien (Thomas, SPEC 1–4): Das Original ist Vorlage, kein Zwang; schlank, wartbar, Nextcloud-nativ, Einheitlichkeit vor 1:1-Treue; gezielte Änderungen in `src/` erlaubt, gelistet in SPEC §11.1.

## Optionen
**(a) Poznote-Frontend behalten und anpassen** – Adapter-JS, abgeleitete Templates (ADR 0004), Token-Mapping (ADR 0016), wenige gelistete Änderungen in `src/` (ADR 0007).

| Arbeitspaket | PT |
|---|---|
| Adapter `nextnotes-bootstrap.js` (Basis-URL, CSRF, Beacon, Navigation, InitialState→`window.*`) | 4 |
| Build (Bundles aus `index_js.php`-Gruppen, CSS, Assets) | 3 |
| Templates/Partials für ≈ 20 Ansichten | 12 |
| Inline-Skripte (≈ 64) und Inline-Handler (≈ 70) entfernen, `new Function` ersetzen | 4 |
| `tokens.css` → NC-Variablen, Layout im NC-Rahmen, Hell/Dunkel | 4 |
| Editoren unter CSP (Excalidraw-Assetpfad, Mermaid/KaTeX-Nachladen) | 2 |
| Gestrichene Funktionen ausblenden (Login, Konten, AI, Links, PWA, Backup, Themes) | 3 |
| i18n-Sprachabbildung NC → Upstream | 1 |
| Parität und Fehlerbehebung je Ansicht (M3/M4) | 8 |
| **Summe** | **≈ 41 (35–55)** |

**(b) Neubau mit Vue 3 / `@nextcloud/vue`**

| Arbeitspaket | PT |
|---|---|
| App-Shell, Navigation, Workspaces/Ordner/Notizliste | 23 |
| Rich-Text-Editor mit Upstream-Funktionen (Slash-Befehle, Tabellen, Bilder, Erwähnungen, Links) | 30 |
| Markdown-Editor (CodeMirror) + Vorschau, Mermaid, KaTeX | 8 |
| Tasklisten, Kanban, Dashboard, Diary/Kalender, Graph, Speed Dial | 32 |
| Excalidraw-Einbindung, Papierkorb, Revisionen mit Diff, Anhänge, Suche/Tags/Favoriten | 21 |
| Einstellungen, i18n nach NC-l10n überführen | 10 |
| Parität und Tests | 20 |
| **Summe** | **≈ 145 (130–200)** |

Backend (M2) ist in beiden Varianten gleich und nicht enthalten.

## Empfehlung
(a), das Poznote-UI bleibt. Begründung: Aufwand und Wartbarkeit (≈ 41 gegenüber ≈ 145 PT; Upstream-Features bleiben per Merge übernehmbar) und ein einheitliches Frontend statt eines Mischbetriebs aus Poznote- und Vue-Oberflächen. (b) wäre optisch näher an Nextcloud, jedes spätere Upstream-Feature müsste aber neu gebaut werden.

## Konsequenzen
- Optik: NC-Farben, Schrift und Hell/Dunkel übernommen, Bedienelemente bleiben Poznote-Stil.
- `@nextcloud/vue` nur für Nextcloud-eigene Dialoge, falls nötig (z. B. Benutzer-/Team-Auswahl beim Teilen), als kleines separat gebautes Bundle; sonst nicht.
- App-interne Navigation (Upstream-Icon-Leiste) bleibt innerhalb `#content`.
- Einzelne neue Teile (Austeilen/Einsammeln, M6) dürfen eigenes JS nutzen, im Upstream-Stil (klassisches Skript, `data-action`).
