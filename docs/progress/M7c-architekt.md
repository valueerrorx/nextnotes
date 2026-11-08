# M7c – Architekt (Status: fertig)
Auftrag: info.xml 0.9.0 (EN+DE, website/doc/screenshot), CHANGELOG, THIRD-PARTY-NOTICES, l10n aufräumen, Screenshot-Skript + Screenshots (Instanz nextnotes-m7c, Port 9600), Prüfungen in validate-info.sh.
## Erledigt
- l10n: 4 ungenutzte Schlüssel aus de.json/de_DE.json entfernt (Tile-Hinweis, All teams, Search … or your own notes, not a teacher … of any team).
- Befunde Lizenzen: KaTeX-Schriften OFL-1.1, Mermaid-Bündel mit DOMPurify (Apache-2.0 OR MPL-2.0), lodash-es, js-yaml, cytoscape; Excalidraw-Assets mit LICENSE.txt und Schriften Assistant/Cascadia/Virgil; KaTeX ausgeliefert 0.16.47 (P22).
- nextnotes/LICENSES/ (Apache-2.0, BSD-3-Clause, ISC, MIT, OFL-1.1), THIRD-PARTY-NOTICES.md, CHANGELOG.md (## [Unreleased], ## 0.9.0 – 2026-11-08), info.xml (0.9.0, summary/description EN + de, documentation user/admin, website, 4 Screenshot-URLs auf raw.githubusercontent …/main/nextnotes/screenshots/).
- dev/nextcloud/scripts/check-notices.mjs; validate-info.sh erweitert (URL-Syntax ohne Abruf, Screenshots ≤ 2 MiB, NN_APP_DIR, check-notices); check-dist.sh prüft Begleitdateien; dev/nextcloud/README.md.
- screenshots.mjs; frische Instanz nextnotes-m7c: 4 PNG (98–173 KiB) in nextnotes/screenshots/. Instanz abgebaut (down.sh rc 0, 0 Container/Volumes).
- Tests: make build rc 0; check-upstream rc 0; validate-info.sh unter unshare -rn rc 0; Gegenproben (Vendor-Zeile, Lockfile-Paket, lucide, KaTeX-/mermaid-/devicon-Version, CHANGELOG-Version, Unreleased, LICENSES-Datei, http-URL, fremder Host, Screenshot fehlt/>2 MiB, de-Beschreibung, js/ fehlt) alle rc 1; make dist in Scratch-Klon mit Arbeitsstand: nextnotes-0.9.0.tar.gz, check-dist OK (432 Einträge, 7,6 MB), Gegenprobe ohne NOTICES rc 1.
- M7-plan M7g: check-purge.sh in run-m7.sh vermerkt.
## Nächster Schritt
keiner (Bericht an Lead).
## Offen
- [THOMAS] Virgil-Schrift (Excalidraw): Name-Tabelle „Freeware for personal use“, Excalidraw-Repo nennt OFL-1.1.
- [THOMAS] Q5 Screenshots freigeben; URLs zeigen auf Branch main.
- Port-Bugs (an Lead): Aufzählungszeichen fehlen in Notizen (NC-Reset ul/ol list-style none); KaTeX-404 bei Direktaufruf einer Notiz mit Formel (lazy-libs vor nextnotes-late).
