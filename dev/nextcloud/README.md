<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Testsetup Next Notes (nur Tests, nicht für den Betrieb)

Benötigt: Docker mit Compose v2 (Profile; getestet mit 5.6.1), `curl`, `jq`, `xmllint`, `php` (Schematest), Node 20+ und `/usr/bin/chromium` (CSP-Prüfung; `playwright-core` wird lokal per `npm ci` in `dev/nextcloud/` installiert, ohne Browser-Download; anderer Pfad über `CHROMIUM=`).
Images (gepinnt): `nextcloud:35.0.2-apache`, `nextcloud:35.0.2-fpm`, `nginx:stable-alpine`, `mariadb:11.4`, `postgres:17-alpine`.
Die App wird in den Container **kopiert**, nie gemountet: `occ app:remove` löscht das App-Verzeichnis.

| Skript | Zweck |
|---|---|
| `validate-info.sh` | `info.xml` gegen App-Store-XSD (Cache in `.cache/`) |
| `up.sh <env\|all>` / `down.sh <env\|all>` | Umgebung starten (inkl. Testnutzer `alice`) / Container und Volumes löschen |
| `install-app.sh <env>` | `nextnotes/` nach `custom_apps/nextnotes` kopieren |
| `lifecycle.sh <env>` | frisch → enable → Schema-Export (`.cache/schema-<env>.json`) → disable → remove; `CHECK_PAGE`/`CHECK_SPIKE`/`CHECK_CSP=1` rufen die Prüfungen bei aktivierter App |
| `check-page.sh <env>` | leere Seite angemeldet (200) und Login-Redirect abgemeldet (303) |
| `spike-urls.sh <env>` | `.php`-Routen über `/index.php/apps/…` und `/apps/…` (200), ADR 0004 |
| `check-csp.sh <env>` | Browser-Login, App-Seiten ohne CSP-Verletzung (mit Detektor-Selbsttest) |
| `run-m1.sh [env…]` | alle Done-Kriterien M1 je Umgebung frisch, danach `down.sh all`; Exit 0 nur wenn alles grün (`KEEP=1` lässt Container stehen) |

Umgebungen: `sqlite` (Apache, 8081), `mariadb` (Apache, 8082), `pgsql` (Apache, 8083), `nginx` (nginx + fpm, SQLite, 8084). Zugang: `admin`/`admin`, `alice` (Passwort in `env.sh`).
Beispiel: `./run-m1.sh` oder `./run-m1.sh pgsql`
