# Upstream

- Projekt: https://github.com/timothepoznanski/poznote (MIT, siehe `LICENCE`)
- Basis-Release: `6.136.2`
- Basis-Commit: `7e2e0b6aa501ba95ac1e9789fd2bb477c281a001`
- Port-Branch: `nextcloud-port` (abgezweigt am 2026-11-02)

Upstream-Updates: siehe ADR zu Frage 6 in `docs/adr/`.

Vendor-Abweichungen (SPEC §11.1): KaTeX in `src/public/js/katex/` auf 0.16.47 statt 0.16.9 (P22, CVE-2024-28243/28244).

Repo-Infrastruktur (M7-web): Upstream-Workflows `.github/workflows/ci-*.yml` und `publish-docker.yml` entfernt (bauen/veröffentlichen Poznote-Images); eigenes `pages.yml` für `site/`. Root-`README.md` ist das Next-Notes-README (Poznote-README entfällt). Poznote-Benutzerdokumente (`docs/*.md` außer eigenen, `images/`, `CONTRIBUTING.md`) entfernt, siehe ADR 0007 Nachtrag.
