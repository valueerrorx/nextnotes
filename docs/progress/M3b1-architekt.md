# M3b1 – Architekt (Umsetzung)

Auftrag: M3b1 aus M3-plan §2 M3b (Teil 1): Editor in rechter Spalte (note_display, Fragment-Modus), Beacon-Token (B4), frühes Speichern visibilitychange, Sperre/Banner P7, duplizierter Tab, B26, B30-Messung, „+“-Menü, Verschieben-Dialoge, Tags mit `'`. KNOWN_MISSING (M3b) in stripped.mjs entfernen.

Status: fertig

## Erledigt
- Plan, Review r2, STATUS, SPEC §4/§11, ADR 0005/0017 gelesen
- IndexViewService: noteView/fragment/iconPalette (php -l ok)
- templates: partials/note_display.php, partials/modals.php (Auswahl aus src/modals.php, konvertiert), fragment.php; index.php (Modals vor Spalten, Notiz in #right_col, Inseln tasklist/markdown-init)
- PageController: Fragment-Modus X-Poznote-Fragment → RENDER_AS_BLANK, fremd → 404

- HtmlSanitizer: button ohne data-action (B26); Adapter: Klick-Schutz .noteentry (B26), keepalive ≥ 64 KiB → normaler Request (B30)
- P7 in src/public/js/note-edit-lock.js (+ kein Übernahme-Knopf), upstream-patches.txt, bundles.json adapterHooks (keepalive, X-Poznote-Fragment)
- make build rc0 (123 Dateien), check-upstream rc0; sqlite up (KEEP), stripped.mjs ohne M3b-KNOWN_MISSING: light grün (113)

- Probe (light): „+“, Fragment, Schließen <64 KiB, Beacon+Token/412, Sperre/Banner/kein PATCH/Übergabe, Duplikat-Tab grün; B30 gemessen: ohne Abstufung verloren, mit gespeichert
- Befund B31: updateBrowserUrl → /index.php (NC-Wurzel) → Patch P9 note-loader.js
- Befund B32: Tippen während laufendem Speichern geht verloren → Patch P10 notes.js
- SPEC §4.3 (8, 10), §11.1 P7/P9/P10, §11.2 B26/B30/B31/B32; M3-plan §1.5 P9 + Teilung

- Probe-Lauf 5 light+dark: 58 ok je Theme; einziger FAIL = Konsolenzeile des erzwungenen Notfallpfads (Probe-Artefakt, fetch absichtlich gesperrt)
- Screenshot Editor hell geprüft: Layout ok

- check-upstream rc0; stripped sqlite light+dark grün (113 je); PHPUnit Page/Sanitizer/Routes: 40 Tests, 1 Failure (TemplateOutputTest-Allowlist, erwartet → tester)
- down.sh all rc0; nc35-gftest unberührt

## Nächster Schritt
- keiner (Übergabe an tester/speccritic); Probe: scratchpad m3b1/e2e/probe-editor.mjs als Vorlage

## Offen/Annahmen
- → M3b2: Anhänge/Anhang-Dialog, PDF, Mermaid/KaTeX, Such-POST + requesttoken-URL-Bug, P2 (Tasklisten-Knöpfe onclick → data-action, dann CONTENT_ACTIONS im Adapter füllen), Tag-Klick-Filter (Suche)
- frühes Speichern: statt eigenem saveNoteToServer-Aufruf (Doppel-PATCH mit emergencySave → 409-Risiko) stuft der Adapter keepalive-Requests ≥ 64 KiB auf normale Requests ab
- ohne ?note= bleibt rechte Spalte leer (wie M3a2), Upstream springt zur zuletzt geöffneten Notiz → vorläufig so
- verknüpfte Notiz (type linked) per ?note=: kein Redirect auf Ziel wie Upstream → offen (M4)
- weggelassen bis Portierung: Download/Drucken/Umwandeln (M4), Revisionen/Notiz-Info (M3c), Anhang-Zeile/Audio (M3b2), „Neuer Workspace“ (bleibt FORCED_HIDDEN, M4)
- TemplateOutputTest ALLOW braucht `partials/note_display.php` => `$note['display']` (Tester)

## Nacharbeit r1 (Status: fertig)
Auftrag: CONTENT_ACTIONS ohne Prototyp, contenteditable nur bei canWrite, P10 Basis = gesendeter Stand, fragment keyFor null → 404, Doku SPEC §4.3/§11.2/M3-plan. Kein tests/, kein make/Container.
- r1: P10 neu (notes.js: sentState vor fetch, typedInFlight vor Adoption, Basis = gesendeter Stand + Antwort-Titel), node --check ok
- r1: SPEC §4.3 9/10 getauscht, B32 → §11.2 (4 Spalten wie Tabellenkopf), P10-Text neu
- r1: bootstrap CONTENT_ACTIONS Object.create(null) + Object.hasOwn, node --check ok
- r1: fragment(): keyFor null → NotFoundException (404), php -l ok
- r1: note_display.php contenteditable nur bei canWrite + data-nextnotes-readonly; note-edit-lock.js P11 (kein Lock-Request/Banner bei Nur-Lesen), node --check ok
- r1: SPEC §11.1 P11, §4.3.10 ergänzt; M3-plan §1.5 P10/P11, M4-Vormerkung new-workspace
- r1: OUT_DIR=scratchpad/m3b1r1 build rc0 (123), check-upstream (inkl. check-css) rc0; node-Probe hasOwn ok; Bundles enthalten P10/P11
- Nächster Schritt r1: tester baut Repo-js neu (make build) und prüft Lesefreigabe-Browser (kein Lock-POST, kein Banner), B32-Probe
- Annahme: §11.2 hat 4 Spalten (Tabellenkopf), B32 entsprechend 4-spaltig
