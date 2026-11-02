<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Testsetup Next Notes (nur Tests, nicht für den Betrieb)

Benötigt: Docker mit Compose, `curl`, `jq`, `xmllint`. Image `nextcloud:35.0.2-apache` (sonst lokales `nextcloud:35`, Version wird geprüft).
Die App wird in den Container **kopiert**, nie gemountet: `occ app:remove` löscht das App-Verzeichnis.

| Skript | Zweck |
|---|---|
| `validate-info.sh` | `info.xml` gegen App-Store-XSD (Cache in `.cache/`) |
| `up.sh <env\|all>` / `down.sh <env\|all>` | Umgebung starten (inkl. Testnutzer `alice`) / Container und Volumes löschen |
| `install-app.sh <env>` | `nextnotes/` nach `custom_apps/nextnotes` kopieren |
| `lifecycle.sh <env>` | frisch → enable → Schema-Export (`.cache/schema-<env>.json`) → disable → remove; `CHECK_PAGE=1` ruft `check-page.sh` |
| `check-page.sh <env>` | leere Seite angemeldet (200) und Login-Redirect abgemeldet (303) |

Umgebungen (M1a): `sqlite` (Apache, Port 8081). Zugang: `admin`/`admin`, `alice` (Passwort in `env.sh`).
Beispiel: `./validate-info.sh && CHECK_PAGE=1 ./lifecycle.sh sqlite; ./down.sh all`
