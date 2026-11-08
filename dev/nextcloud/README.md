<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Testsetup Next Notes (nur Tests, nicht für den Betrieb)

Benötigt: Docker mit Compose v2 (Profile; getestet mit 5.6.1), `curl`, `jq`, `xmllint`, `php` auf dem Host (Schematest, `make -C nextnotes check-upstream`), Node 20+ (Build `make -C nextnotes build` mit esbuild aus `nextnotes/build/`, einmalig mit Netz `npm ci --prefix nextnotes/build`; danach offline) und Chromium (CSP-Prüfung, E2E, `check-upstream` (e2); `playwright-core` wird lokal per `npm ci` in `dev/nextcloud/` installiert, ohne Browser-Download). Chromium wird gesucht (`/usr/bin/chromium`, Playwright-Cache, `/Applications`), sonst `CHROMIUM=<pfad>`.
Alle Skripte brauchen bash ≥ 4.4 und GNU tar (`dev/portable.sh` prüft beides mit Hinweis); `make dist` und `sign-release.sh` zusätzlich GNU gzip (gleicher sha256 auf Linux und macOS).
Images (gepinnt): `nextcloud:35.0.2-apache`, `nextcloud:35.0.2-fpm`, `nginx:1.30.6-alpine`, `mariadb:11.4.13`, `postgres:17.11-alpine` (so getestet).
Die App wird in den Container **kopiert**, nie gemountet: `occ app:remove` löscht das App-Verzeichnis.

| Skript | Zweck |
|---|---|
| `validate-info.sh` | `info.xml` gegen App-Store-XSD (Cache in `.cache/`, danach offline), Pflichtwerte, URLs nur syntaktisch (HTTPS, `github.com`/`raw.githubusercontent.com` unter `valueerrorx/nextnotes`, kein Abruf), Screenshot-Dateien vorhanden und ≤ 2 MiB; `scripts/check-notices.mjs`: jede ausgelieferte Vendor-Bibliothek (`bundles.json` → `vendor.json`, Kennungen lucide/devicon im Build, Pakete aus den Lockfiles der Bündel) in `THIRD-PARTY-NOTICES.md`, Versionen wie ausgeliefert, `LICENSES/`-Verweise vorhanden, CHANGELOG-Version = `info.xml`. `NN_APP_DIR=` prüft eine Kopie (Gegenproben) |
| `scripts/screenshots.mjs <base-url>` | App-Store-Screenshots (M7c) nach `nextnotes/screenshots/` (nicht im Paket): legt auf einer **frischen** eigenen Instanz Beispieldaten an (erfundene Namen, Englisch; Team „Biology 9b“ mit Material und Aufgabe), hell/dunkel; `SHOT_OUT=` anderes Ziel. Beispiel: `NN_PROJECT=nextnotes-shots NN_PORT_BASE=9600 ./up.sh sqlite && ./install-app.sh sqlite`, App aktivieren, `node scripts/screenshots.mjs http://localhost:9601` |
| `up.sh <env\|all>` / `down.sh <env\|all>` | Umgebung starten (inkl. Testnutzer `alice`) / Container und Volumes löschen |
| `install-app.sh <env>` | `nextnotes/` nach `custom_apps/nextnotes` kopieren (Ausschlüsse aus `nextnotes/build/dist-exclude.txt`, wie `make dist`; vorher `make -C nextnotes build`); `NN_APP_TARBALL=<paket>` entpackt stattdessen ein Paket aus `make -C nextnotes dist` (vorher `check-dist.sh`) |
| `check-dist.sh <tarball>` | Paketprüfung: genau eine Wurzel `nextnotes/`, `appinfo/info.xml` mit `<id>nextnotes</id>`, Version = Dateiname, keine verbotenen Pfade (`.git`, `node_modules`, `tests`, `.ref`; in der Wurzel `build/`, `dist/`, `*-src/`, `Makefile`, `.gitignore`), PHP nur in `appinfo/`/`lib/`/`templates/`, `CHANGELOG.md`/`COPYING`/`LICENCE`/`THIRD-PARTY-NOTICES.md`/`LICENSES/` vorhanden; gibt Einträge und Größe aus. `make dist` ruft es selbst auf |
| `upgrade-path.sh <env> <from-ref>` | Upgrade-Test (M7e): `<from-ref>` per `git worktree` bauen (Assets nur bei gleicher Lockfile, sonst ohne), frisch installieren, Beispieldaten per REST (ab REST-Stand: Workspace, Ordner, Notiz mit Tag und Anhang; ab Aufgaben-Stand: Team-Workspace, Material-Ordner, ausgeteilte Aufgabe mit Abgabe), Paket aus `make -C nextnotes dist` einspielen, `occ upgrade`; danach `check-schema.php` und Diff gegen frische Paket-Installation derselben DB (Cache `.cache/upgrade/`), `oc_migrations` = Migrationen im Paket, `oc_jobs` = `<background-jobs>` (`scripts/upgrade-probe.php`), Daten lesbar, `smoke-m6` hell. `NN_APP_TARBALL=`, `NN_UPGRADE_BREAK=schema\|jobs` (Gegenprobe, muss rot sein), `NN_UPGRADE_E2E=0`, `KEEP=1`. Startpunkte: `fe470b2c` (0.1.0, ohne Daten), `86e514e6` (0.1.2), `1378e5d8` (0.3.3) |
| `sign-release.sh <tarball> <key> <crt>` / `--verify <paket> <crt> [<sig>]` | Signatur (M7f): `integrity:sign-app` in Wegwerf-Umgebung (`nextnotes-sign`, Port 9300), Neupacken nach `dist/signed/` + `.sha256`, Release-Signatur `.sig`; Probelauf nur mit Wegwerf-Schlüssel, `NN_SIGN_TEST_CA=<crt>` nur dafür; Schlüssel nie ins Repo (Checkliste `docs/app/release-checklist.md`) |
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

## macOS (Apple Silicon, Docker Desktop)

Ungetestet auf echter Hardware (M7-mac, nur unter Linux nachgestellt). Einmalig:

1. Docker Desktop installieren und starten; Repo unter `/Users/…` (Docker-Dateifreigabe, `nginx/nextcloud.conf` wird gemountet). Alle Images sind multi-arch (arm64), keine `platform:`-Angabe nötig.
2. Homebrew-Pakete: `brew install bash gnu-tar gzip jq php composer node` (Node ≥ 20, Linux-Stand 24; `xmllint`, `curl`, `openssl`, `lsof`, `shasum` bringt macOS mit). `bash` und `gzip` aus Homebrew müssen vor `/bin` bzw. `/usr/bin` im `PATH` stehen (`eval "$(/opt/homebrew/bin/brew shellenv)"`); `bash --version` ≥ 4.4, `gzip --version` beginnt mit `gzip 1.`. GNU tar wird als `gtar` gefunden, `timeout` fällt ohne `coreutils` auf einen eingebauten Watchdog zurück.
3. OCP-Referenz (nur lesen, nicht im Repo, in `.git/info/exclude`): `git clone --filter=blob:none --depth 1 --branch v35.0.2 https://github.com/nextcloud/server.git .ref/nextcloud-server`
4. Node-Abhängigkeiten (einmalig mit Netz): `npm ci --prefix nextnotes/build`, `npm ci --prefix dev/nextcloud`, `composer install -d tests/nextnotes`.
5. Browser: `node dev/nextcloud/node_modules/playwright-core/cli.js install --no-shell chromium` (landet in `~/Library/Caches/ms-playwright/`, wird automatisch gefunden); alternativ Google Chrome in `/Applications` oder `CHROMIUM=<pfad>`.

Prüfen: `make -C nextnotes build check-upstream`, `make -C nextnotes dist` (sha256 muss dem Linux-Paket desselben Commits entsprechen).
Test-Instanz: `NN_PROJECT=nextnotes-mac NN_PORT_BASE=10200 dev/nextcloud/up.sh sqlite`, dann `install-app.sh sqlite`, `occ app:enable nextnotes` (`source dev/nextcloud/env.sh`), `run-phpunit.sh sqlite --filter RouteDiffTest`, `run-e2e.sh sqlite smoke-m6`, Abbau `down.sh all` (gleiche Variablen).
Unterschiede: kein `unshare` → „ohne Netz“-Läufe in `run-m5/6/7.sh` laufen mit Netz (Warnung); Port-Prüfung über `lsof` statt `ss`.
