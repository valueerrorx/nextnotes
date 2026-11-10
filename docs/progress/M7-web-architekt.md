# M7-web – architekt

Status: fertig

## Auftrag
Root-README komplett neu (EN, Next Notes, Credits Poznote), statische Seite `site/` + Pages-Workflow, Poznote-Workflows entfernen. Ein Commit, kein Push.

## Erledigt
- Worktree auf `nextcloud-port` 0732a66c gesetzt (`git reset --hard`, rc 0)
- Gelesen: nextnotes/README.md, info.xml (0.9.3, AGPL), UPSTREAM.md, LICENCE, Doku-Gliederung, STATUS
- Befunde: AGPL-Text liegt in `nextnotes/COPYING`; Screenshots 9× 1400×900; Root-README nur von Poznote-Tools (`tools/docs-i18n.py`, ci-docs) referenziert; kein PyYAML

- Root-`README.md` ersetzt (EN, Features, Galerie 9 Bilder, Credits Poznote/MIT, AGPL `nextnotes/COPYING`); Dashboard-Widget gibt es nicht → nicht genannt

- `site/index.html`, `style.css`, `icon.svg`, `.gitignore` (screenshots/ werden beim Deploy kopiert)
- `.github/workflows/pages.yml` (checkout@v7, configure-pages@v6, upload-pages-artifact@v5, deploy-pages@v5 = aktuelle Releases laut GitHub-API)
- Entfernt (rm per git): ci-docker/ci-docs/ci-js/ci-php/publish-docker.yml; UPSTREAM.md vermerkt

- Bildunterschriften korrigiert (notes-dark = Aufgabenliste, team-workspace-dark = Arbeitsblatt), README + site
- Prüfung: python http.server + Playwright (Chrome for Testing 1248) 1400/390/360 × hell/dunkel: 0 Konsolenfehler, 0 ≥400, 0 kaputte Bilder, kein X-Overflow, Anker ok, 11 relative Ziele 200; Screenshots angesehen (nur Scratchpad)
- YAML per Ruby geparst ok; README-Relativlinks alle vorhanden
- `make -C nextnotes build` + `check-upstream` grün (node_modules per Symlink aus Hauptbaum, danach entfernt)

## Nächster Schritt
Commit

## Offen/Annahmen
- `docs/README.*.md` (Poznote-Übersetzungen) und `tools/docs-i18n.py` bleiben (Upstream-Dateien), nur gemeldet
- `.github/FUNDING.yml` (ko_fi timothepoznanski) bleibt: Entscheidung Thomas
- Links der Seite zeigen auf `blob/main` und Pages-Trigger ist `main`: greifen erst nach Übernahme nach main
