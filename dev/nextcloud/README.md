<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Testsetup Next Notes (nur Tests, nicht für den Betrieb)

Benötigt: Docker mit Compose v2 (Profile; getestet mit 5.6.1), `curl`, `jq`, `xmllint`, `php` auf dem Host (Schematest, `make -C nextnotes check-upstream`), Node 20+ (Build `make -C nextnotes build` mit esbuild aus `nextnotes/build/`, einmalig mit Netz `npm ci --prefix nextnotes/build`; danach offline) und `/usr/bin/chromium` (CSP-Prüfung, `check-upstream` (e2); `playwright-core` wird lokal per `npm ci` in `dev/nextcloud/` installiert, ohne Browser-Download; anderer Pfad über `CHROMIUM=`).
Images (gepinnt): `nextcloud:35.0.2-apache`, `nextcloud:35.0.2-fpm`, `nginx:1.30.6-alpine`, `mariadb:11.4.13`, `postgres:17.11-alpine` (so getestet).
Die App wird in den Container **kopiert**, nie gemountet: `occ app:remove` löscht das App-Verzeichnis.

| Skript | Zweck |
|---|---|
| `validate-info.sh` | `info.xml` gegen App-Store-XSD (Cache in `.cache/`) |
| `up.sh <env\|all>` / `down.sh <env\|all>` | Umgebung starten (inkl. Testnutzer `alice`) / Container und Volumes löschen |
| `install-app.sh <env>` | `nextnotes/` nach `custom_apps/nextnotes` kopieren (Ausschlüsse aus `nextnotes/build/dist-exclude.txt`, wie `make dist`; vorher `make -C nextnotes build`); `NN_APP_TARBALL=<paket>` entpackt stattdessen ein Paket aus `make -C nextnotes dist` (vorher `check-dist.sh`) |
| `check-dist.sh <tarball>` | Paketprüfung: genau eine Wurzel `nextnotes/`, `appinfo/info.xml` mit `<id>nextnotes</id>`, Version = Dateiname, keine verbotenen Pfade (`.git`, `node_modules`, `tests`, `.ref`; in der Wurzel `build/`, `dist/`, `*-src/`, `Makefile`, `.gitignore`), PHP nur in `appinfo/`/`lib/`/`templates/`; gibt Einträge und Größe aus. `make dist` ruft es selbst auf |
| `lifecycle.sh <env>` | frisch → enable → Schema-Export (`.cache/schema-<env>.json`) → disable → remove; `CHECK_PAGE`/`CHECK_SPIKE`/`CHECK_CSP=1` rufen die Prüfungen bei aktivierter App |
| `check-page.sh <env>` | leere Seite angemeldet (200) und Login-Redirect abgemeldet (303) |
| `spike-urls.sh <env>` | `.php`-Routen über `/index.php/apps/…` und `/apps/…` (200), ADR 0004 |
| `check-csp.sh <env>` | Browser-Login, App-Seiten ohne CSP-Verletzung (mit Detektor-Selbsttest) |
| `check-forbidden-sql.sh [dir]` | verbotene SQL-/Sitzungsmuster in `nextnotes/lib` (SPEC §13 M2.5); `SELFTEST=1` prüft den Detektor |
| `run-phpunit.sh <env> [args…]` | PHPUnit aus `tests/nextnotes` im Container (www-data) gegen die Instanz-DB; vorher `composer install -d tests/nextnotes` |
| `run-m5.sh [env…]` | **Einzige volle Matrix** (Done M1–M5, Meilenstein-Ende): Build zweimal (zweiter mit `unshare -rn` ohne Netz) mit Prüfsummenvergleich, `check-upstream`, info.xml, SQL-Prüfung; Phase 1 je Umgebung parallel (`PARALLEL=0` nacheinander): Lebenszyklus wie `run-m1.sh` (Seite, Spike/CSP je Webserver, Schema), danach frisch App (Circles-Loopback per `up.sh` mit `circles:check`), `mysql.utf8mb4`, PHPUnit (Apache), `scripts/probe-grenzen.sh` (mariadb/pgsql), `scripts/probe-circles-off.sh` (alle; M5 Krit. 3 über HTTP: Circles aus/an); Phase 2 nacheinander Playwright Hell+Dunkel (sqlite, nginx), ohne Parallel-Last wegen Zeitmessungen. Logs je Umgebung in `NN_LOG_DIR` (Standard `.cache/run-m5-<Zeit>/`); `KEEP=1` lässt rote Umgebungen stehen |
| `run-m1.sh [env…]` | nur Lebenszyklus M1 (Teilmenge von `run-m5.sh`, für Slices mit Migrationen); nicht zusätzlich zur vollen Matrix |
| `run-e2e.sh <env> [spec…]` | Playwright-Specs aus `tests/nextnotes/e2e/` je Theme (hell, dunkel); legt `alice`/`bob` (Sprache de) an, deaktiviert `firstrunwizard` und `recommendations` (Vorschau-404 auf der Dateien-Startseite); Dauer je Spec im Log; Spec-Aufruf `node <spec>.mjs <base-url> <theme>`, Zugang über `NN_USER`/`NN_PASS`/`NN_USER2`/`NN_PASS2`, `PW_CORE`, `CHROMIUM`; `E2E_DIR=`, `THEMES=` |

Upstream-Fixtures: `../upstream/up.sh` (Build einmalig mit Netz, Port 8090), `php ../upstream/capture.php upstream tests/nextnotes/fixtures/upstream`, `../upstream/down.sh`.

Umgebungen: `sqlite` (Apache, 8081), `mariadb` (Apache, 8082), `pgsql` (Apache, 8083), `nginx` (nginx + fpm, SQLite, 8084). Eigene Instanz neben anderen: `NN_PROJECT=nextnotes-<name> NN_PORT_BASE=8190` (Ports Basis+1 … +4) vor jedem Skript setzen; `nextnotes-demo` und `nc35-gftest` sind gesperrt.
`run-m2.sh`/`run-m3.sh` entfallen (M4i-fix): vollständig in `run-m5.sh` enthalten (M5e: `run-m4.sh` → `run-m5.sh`). Laufzeit vorher seriell 2 h 43 min (run-m4) plus run-m3/m2/m1 als Regression. Zugang: `admin`/`admin`, `alice` (Passwort in `env.sh`).
Beispiel: `./run-m5.sh`, `./run-m5.sh pgsql` oder `./run-m1.sh pgsql`
