# M3a2 – Architekt (Umsetzung)

Auftrag: M3a2 nach `docs/slices/M3-plan.md` §2 umsetzen (Hauptansicht-Rahmen, Ausgaberegel, Gestrichenes, Navigation, input/button-Reset) + Nacharbeit (tasklist_insert_order doppelt, X-Requested-With XHR, check-upstream Inter). Done selbst prüfen, Screenshots `docs/screenshots/M3a2-{light,dark}.png`.

Status: fertig (inkl. Nacharbeit r1)

## Erledigt
- Plan/STATUS/M3a1-Progress gelesen; Bestand gesichtet; sqlite-Umgebung up (rc0)
- Neu: `lib/Service/View/IndexViewService.php`, `templates/index.php`, `templates/partials/{icon_sidebar,folders_display,notes_list}.php` (umbenannt von parts/: Upstream-.gitignore ignoriert `parts/`) (php -l ok)
- Geändert: `PageController.php` (index → View, 404 Gast-Seite), `NoteService::compareNotes` public static, `PageTranslator::defaultNoteTitles`, `build/build.mjs` (i18n/default-note-titles.json, 123 Dateien)
- Adapter `js-src/nextnotes-bootstrap.js`: JSON-Inseln globals/body, frühe Klassen, globalHidden, prefetched settings (GET synthetisch, Cache nach PUT), XHR-Header-Dedupe (node --check ok)
- build rc0, install-app sqlite, app:enable; Probe (scratchpad frame-probe.mjs) hell: Rahmen/Ordner/Liste ok; Befund: NC-Kern-CSS (server.css button/input-Regeln) zerlegt Buttons/Suche → Reset nötig

- `build/build.mjs`: bumpSpecificity (+1 ID-Stelle für Upstream-CSS, reine Element-Selektoren als auf #nextnotes-root begrenzte Kopie) → ADR 0017 nötig
- `css-src/nextnotes-layout.css`: Logo-Maske app.svg, Reset (revert) für button/input/select/textarea in #nextnotes-root
- Probe sqlite hell+dunkel grün: Rahmen, Menüs ohne Gestrichenes, Workspace-Wechsel, 0 Fehler; 404 fremde/ungültige Notiz; unbekannter WS → Standard; XSS-Titel als Text; kein doppelter API-Request; XHR-Header einfach; TTFB 25 ms
- P8: keine Kollision (NC-Div #unified-search schon vor DOMContentLoaded durch Vue ersetzt) → kein Patch

- check-upstream (e) Inter: grün; Gegenprobe Inter in css → rc≠0, zurückgesetzt
- budget.mjs KNOWN_DUPLICATES = {}
- ADR 0017 geschrieben; SPEC §1.2, §4.3, §4.6, §11.1 (P4/P8 entfallen), §11.2 B27–B29
- run-m3.sh sqlite nginx gestartet (Log scratchpad m3.log)

- run-m3 sqlite nginx rc0 (vor Chrome-Fix): PHPUnit 225 OK, assets 82/Theme, budget grün (API 1, CSS gz 138,5 KB, TTFB 24 ms)
- templates/parts → templates/partials (Upstream-.gitignore `parts/`)
- Befund NC-Benutzermenü blau (Upstream `a{color}` global, schon M3a1) → build: reine Element-Selektoren außerhalb NC-Oberfläche; Regel-Scan: 0 App-Regeln auf NC-Kopf/Menü
- Screenshots nginx docs/screenshots/M3a2-{light,dark}.png

- Bootstrap prefetchedKey vereinfacht; ADR 0017 ergänzt (NC-Oberfläche, CSS gz 139,0 KB)
- Abschluss: run-m3 sqlite nginx rc0 (PHPUnit 225 OK, assets 82 + budget 21 je Theme/Umgebung, API 1, TTFB ≈25 ms), run-m2 sqlite rc0, run-m1 sqlite nginx rc0, down.sh all rc0

## Nacharbeit r1
- Auftrag: Blocker Pseudo-Element-Bump + Prüfung; SPEC ensureDefault-Ausnahme; Plan partials/B-Nummern; SPEC-Kopf ADR 0017. Kein Docker/make, Build nur nach OUT_DIR im scratchpad.
- Plan: Bump-Code nach build/css-bump.mjs (+Validator), build.mjs OUT_DIR, build/check-css.mjs (Chromium-CSSOM Regelzahl vor/nach) in check-upstream (e)

- build/css-bump.mjs neu (Bump aus build.mjs verschoben, PSEUDO_TAIL inkl. nachfolgender Pseudo-Klassen, checkBumped/findBumpAfterPseudo); build.mjs: OUT_DIR, cssFile/writeCss prüfen → die
- build OUT_DIR=scratch/m3a2r1/new rc0 (123 Dateien), deterministisch (2× diff leer); alter Repo-Build: 29 Zusätze hinter Pseudo-Element, neu 0
- build/check-css.mjs (Chromium-CSSOM: Regeln vor/nach Zusatz, Bundle-Regel nur durch Zusatz ungültig, Muster) + check-upstream.sh (e2), OUT_DIR
- Nachweis: check-upstream OUT_DIR=new rc0 grün; Repo-css (alt) rc1 (12 verlorene Regeln: core 7, modals 2, dark 3); alter Regex in Kopie: build rc1, check-css (1) rc1

- Doku: ADR 0017 (Pseudo-Folge, Prüfung), SPEC Kopf 0002–0017, §1.1 Baum, §1.2 Prüfung, §4.4/§4.5 ensureDefault-Ausnahme, §11.2 B30 (64 KiB, vorgemerkt); M3-plan partials (+Grund .gitignore), B27→B30 (Done M3b.3, R7); dev/nextcloud/README chromium für (e2)

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen/Annahmen
- Repo-Build nextnotes/css ist noch der alte (fehlerhafte) Stand; neu bauen erst nach Tester-Lauf (kein make durch Architekt)
- Kanban-Menüpunkt bleibt (Route kommt M3c)
- M3b: Suche (POST index.php fehlt noch) schreibt requesttoken per history in die URL → in M3b beheben
