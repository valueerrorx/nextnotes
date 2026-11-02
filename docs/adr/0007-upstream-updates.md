# ADR 0007: Upstream-Updates nachziehen (Auftragsfrage 6)

Status: entschieden (Lead, 2026-11-02)

## Kontext
Upstream (`timothepoznanski/poznote`, Basis `6.136.2` / `7e2e0b6a`, siehe `UPSTREAM.md`) veröffentlicht häufig. Das Backend wird neu gebaut (Nextcloud-Konvention, kein Upstream-Backend-Code), das Frontend (JS, CSS, i18n, Vendor-Bibliotheken, Markup) bleibt. Leitprinzipien (Thomas, SPEC Leitprinzipien 1–4): Das Original ist Vorlage, kein Zwang; schlank, wartbar, Nextcloud-nativ, Einheitlichkeit vor 1:1-Treue. Gezielte direkte Änderungen in `src/` sind erlaubt, jede steht in SPEC §11.1. Ziel: Ein Upstream-Release soll per `git merge` und Build übernommen werden können; Abweichungen fallen automatisch auf.

## Optionen
1. **Gezielte direkte Änderungen in `src/`, gelistet in SPEC §11.1; die App in `nextnotes/` baut aus `src/`.**
2. `src/` unberührt, Änderungen als Build-Zeit-Patches auf Kopien (mehr Werkzeug, Patches brechen bei jedem Upstream-Update still).
3. Upstream als Git-Submodul (Fork-Historie und Änderungen in `src/` werden umständlicher).

## Entscheidung
Option 1. Ein direkter Edit plus `git merge` ist schlanker als eine Patch-Kette; Git zeigt Konflikte genau an der geänderten Stelle.

**Grundsätzlich unverändert** (Merge konfliktfrei): `build/`, `tools/` (Upstream), `docker/`, `mcp-server/`, `poznote-chrome-extension/`, `vendor.json`, `Dockerfile`, `docker-compose*.yml`, Upstream-Dokumente unter `docs/` (außer neuen Dateien `SPEC.md`, `INVENTORY.md`, `adr/`, `inventory/`, `reviews/`), `README*.md`, `LICENCE`. In `src/` nur Dateien aus SPEC §11.1; Upstream-Backend-PHP in `src/` wird nie geändert (es läuft nicht).

**Eigene Dateien**: `nextnotes/` (App), `dev/nextcloud/` (Docker-Testsetup), `tests/nextnotes/`, neue Dateien in `docs/`, `STATUS.md`, `UPSTREAM.md`, `.claude/`.

**Anpassungsebenen** (Wahl nach „schlank, wartbar“, nicht nach Ebene um jeden Preis):
- Kompatibilitätsrouten mit gleichen `api/v1`-Pfaden und JSON-Formaten (SPEC §4).
- Adapter `nextnotes/js-src/nextnotes-bootstrap.js` für Querschnittsthemen (Basis-URL, `requesttoken`, Beacon, InitialState → `window.*`, Hell/Dunkel).
- Abgeleitete Templates `nextnotes/templates/` (ADR 0004), Kopfkommentar mit Quelldatei + Upstream-Commit.
- Direkte, kleine Edits in `src/`, wenn das einfacher ist als eine Umgehung (CSP-Fixes, Bugfixes). Jeder Edit: Eintrag in SPEC §11.1 mit Begründung und Markierung „Upstream-PR-Kandidat“; Pfad in `nextnotes/build/upstream-patches.txt`.

**Merge-Workflow** (je Upstream-Release, eigener Slice):
1. `git fetch upstream --tags && git merge <tag>` auf `nextcloud-port`. Konflikte sind nur in Dateien aus SPEC §11.1 zu erwarten; jeder Konflikt wird dort aufgelöst und der Eintrag geprüft (ist der Fix inzwischen upstream, Eintrag entfernen).
2. `make -C nextnotes check-upstream OLD=<alter Upstream-Commit> NEW=<tag>` meldet:
   - Abweichung zwischen `git diff --name-only <NEW> -- src/` und `upstream-patches.txt` (ungelistete Edits oder verwaiste Einträge);
   - geänderte Quellen abgeleiteter Templates (`git diff --name-only OLD NEW` gegen die Kopfkommentare);
   - geänderte Bundle-Listen (`src/public/index_js.php`, `index_css.php`, `css_assets.php`) gegenüber `nextnotes/build/bundles.json`;
   - neue/entfernte Routen in `src/public/api/v1/index.php` gegenüber `nextnotes/appinfo/routes.php` (Routen-Diff);
   - Schemaänderungen (`git diff OLD NEW -- src/db_connect.php src/users/db_master.php`) → Migrations-Review;
   - neue `fetch`-/`location`-Ziele auf `*.php` in `src/public/js`, neue Inline-Skripte/`on…=`/`new Function`;
   - neue i18n-Schlüssel (nur Info) und `vendor.json`-Änderungen (CSP-Review nach ADR 0006).
3. Fehlende Backend-Funktionen nachbauen, `UPSTREAM.md` (Release + Commit) aktualisieren, Tests auf NC 35 laufen lassen.

## Konsequenzen
- Upstream-Backend-PHP wird zur Laufzeit nicht verwendet; Verhaltensänderungen dort werden über den Routen-Diff erkannt und nachgebaut.
- Kein Upstream-PHP im Paket. Konvertierung HTML↔Markdown über gepflegte Bibliotheken (`league/commonmark`, `league/html-to-markdown`), Parität per Fixture-Tests (SPEC §1.2).
- Frontend-Bugfixes in `src/` sind Kandidaten für Upstream-PRs; jeder angenommene PR entfernt einen Eintrag aus SPEC §11.1.
- Je kürzer die Liste in §11.1, desto billiger ein Update. Ziel: < 15 Einträge in v1.
