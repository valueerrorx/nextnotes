# M1 – Installierbares Skelett: Plan

Stand: 2026-11-02, Architekt, r2 (nach Review `docs/reviews/M1-plan-r1.md`). Grundlage: SPEC §1, §2.1, §4.6, §8, §13 M1, §14; ADR 0001, 0004, 0012.
Kein Upstream-Bundle-Build, keine Änderung in `src/`, kein Eintrag in §11.1 nötig.

## 1. Dateien

### App `nextnotes/`
- `nextnotes/appinfo/info.xml` – App-Metadaten: id `nextnotes`, Name „Next Notes“, Version `0.1.0`, `<namespace>NextNotes</namespace>`, `<licence>AGPL-3.0-or-later</licence>` (Thomas), Autoren, Kategorie `office`/`organization`, `bugs`/`repository` (Fork-URL), `<dependencies><php min-version="8.3" max-version="8.5"/><nextcloud min-version="35" max-version="35"/></dependencies>`, `<navigations><navigation>` mit `name` „Next Notes“, `route` `nextnotes.page.index`, `icon` `app.svg`. Herkunftssatz „Basiert auf Poznote von Timothé Poznanski (MIT)“ in `description`.
- `nextnotes/appinfo/routes.php` – Routen `page#index` (`/`), `page#index` mit `postfix` (`/index.php`), `page#dashboard` (`/dashboard.php`, nur Spike, leere Seite). Kein `.php`-Routing für weitere Seiten in M1.
- `nextnotes/lib/AppInfo/Application.php` – `extends App implements IBootstrap`, `APP_ID = 'nextnotes'`, `register()`/`boot()` leer (Platzhalter für M2+).
- `nextnotes/lib/Controller/PageController.php` – `index()` und `dashboard()`: `#[NoAdminRequired]`, `#[NoCSRFRequired]`, `TemplateResponse(..., 'main', [], RENDER_AS_USER)`, CSP aus `CspFactory`. Kein `#[PublicPage]` → abgemeldet greift NC-Login-Redirect.
- `nextnotes/lib/Http/CspFactory.php` – einzige CSP-Quelle (ADR 0012); M1 liefert unveränderte `ContentSecurityPolicy` (keine Ausnahmen).
- `nextnotes/lib/Migration/Version000100Date20261102000000.php` – `SimpleMigrationStep::changeSchema`, legt 11 Tabellen aus §2.1 an (`workspaces`, `teams`, `ws_shares`, `folders`, `notes`, `note_tags`, `attachments`, `snapshots`, `reminders`, `locks`, `mentions_handled`; ohne `assignments`/`submissions`), Spalten/Typen/Null/Default/Indizes exakt nach §2.1. **Kein `hasTable`-Guard** (erste Migration; eine kaputte Migration soll scheitern, nicht übersprungen werden). Nur `Types::*`, keine Roh-SQL.
- `nextnotes/templates/main.php` – leeres Gerüst `<div id="app-content"><div id="nextnotes-root"></div></div>`; kein Inline-Skript, kein `on…=`.
- `nextnotes/img/app.svg`, `nextnotes/img/app-dark.svg` – eigenes monochromes Navigations-Icon.
- `nextnotes/README.md` – drei Zeilen: Zweck, Herkunft, Lizenz (App AGPL-3.0-or-later, übernommene Poznote-Teile MIT), Verweis auf `docs/`.
- `nextnotes/COPYING` – AGPL-3.0-Volltext (Lizenz der App).
- `nextnotes/LICENCE` – Kopie des Upstream-MIT-Hinweises aus `/LICENCE` (bleibt im App-Verzeichnis, ADR 0001).
- `nextnotes/.gitignore` – Build-Ausgaben (`js/ css/ i18n/ vendor-assets/`) für spätere Meilensteine; Root-`.gitignore` bleibt unverändert (Upstream).

Jede neue Datei unter `nextnotes/` trägt einen SPDX-Kopf (`SPDX-License-Identifier: AGPL-3.0-or-later`).

Nicht in M1 (SPEC §1.1, später): `Makefile`, `build/`, `js-src/`, `css-src/`, `CHANGELOG.md`, Composer.

### Testsetup `dev/nextcloud/` (nur Tests)
- `dev/nextcloud/compose.yaml` – Dienste mit Profilen (s. §3).
- `dev/nextcloud/nginx/nextcloud.conf` – nginx-Referenzkonfiguration aus der NC-Admin-Doku (Quelle + Datum als Kopfkommentar), `fastcgi_param front_controller_active true`.
- `dev/nextcloud/env.sh` – gemeinsame Variablen (Ports, Admin-Zugang `admin`/`admin`, Testnutzer `alice`), Hilfsfunktion `occ <env> …` (`docker compose exec -u www-data … php occ`).
- `dev/nextcloud/up.sh <env|all>` / `down.sh <env|all>` – Umgebung starten bis `occ status` „installed: true“ und Testnutzer `alice` angelegt / Container **und Volumes** entfernen (`docker compose down -v`).
- `dev/nextcloud/install-app.sh <env>` – kopiert `nextnotes/` per `tar | docker compose exec … tar x` nach `/var/www/html/custom_apps/nextnotes`, `chown www-data`. **Kein Bind-Mount** (s. Risiko R1).
- `dev/nextcloud/validate-info.sh` – lädt `https://apps.nextcloud.com/schema/apps/info.xsd` nach `dev/nextcloud/.cache/` (falls fehlt) und ruft `xmllint --noout --schema … nextnotes/appinfo/info.xml`.
- `dev/nextcloud/lifecycle.sh <env>` – **`down.sh <env>` → `up.sh <env>`** (immer frische Instanz) → Frische-Prüfung: `occ db:schema:export --output=json | jq` findet keine Tabelle `oc_nextnotes_*`, sonst Abbruch → install → `app:enable` → Schema-Export nach `dev/nextcloud/.cache/schema-<env>.json` → Probe-Zeile → `app:disable` → Probe zählen → `app:remove` → `app:list` → Probe zählen; bricht bei jedem Fehler ab (`set -euo pipefail`).
- `dev/nextcloud/check-page.sh <env>` (M1a) – curl-Prüfungen Kriterium 4, HTTP-Teil: angemeldet 200 + `id="nextnotes-root"` + `id="content"`; abgemeldet 303 mit `Location` passend zu Regex `/login\?redirect_url=` (gilt für `/login?…` und `/index.php/login?…`). Setzt eine installierte und aktivierte App voraus; `run-m1.sh` ruft es zwischen `app:enable` und `app:disable` über einen Hook in `lifecycle.sh` auf (`CHECK_PAGE=1`).
- `dev/nextcloud/spike-urls.sh <env>` (M1b) – curl-Prüfungen Kriterium 5 (vier URLs, s. §2).
- `dev/nextcloud/scripts/probe-row.php` – im Container: `insert`/`count` einer Zeile in `nextnotes_workspaces` über `OCP\Server::get(IDBConnection::class)` + QueryBuilder (DB-neutral).
- `dev/nextcloud/run-m1.sh` – validate → je Umgebung `lifecycle.sh` mit `CHECK_PAGE=1` (sqlite, mariadb, pgsql; jeweils frisch durch `down`/`up`) → `spike-urls.sh` (sqlite/Apache, nginx; vorher frisch + install + enable) → `down.sh all`. Exit 0 nur wenn alles grün.
- `dev/nextcloud/.gitignore` – `.cache/`.
- `dev/nextcloud/README.md` – Kurzanleitung, benötigte Images.

### Doku
- `docs/slices/M1-plan.md` (diese Datei).
- `docs/adr/0004-seiten-abloesung.md` – Abschnitt „Spike-Ergebnis M1“ (Ergebnis oder Fallback), nach Ausführung.
- `docs/SPEC.md` §14 – Ergänzung `DB\Schema\ITable`/`IColumn` (35.0.0) und `Server::get` (25), falls verwendet.

Tester (nicht Architekt): `tests/nextnotes/` – Schema-Vergleich gegen §2.1 (auf `schema-<env>.json`), Playwright-CSP-Check.
Hinweis für die Erwartungsdatei (Normalisierung durch `SchemaEncoder` je Plattform): Index-Namen klein vergleichen (PostgreSQL); Primärschlüssel nur über `primary`/Spalten prüfen, nicht über den Namen (`PRIMARY` MySQL, `<table>_pkey` PostgreSQL); Integer-Längen ignorieren (null); AI-`bigint`-PK auf SQLite als `integer` akzeptieren.

## 2. Done-Kriterien

1. **Enable auf 3 DBs** – Migration nur mit OCP-Schema-API, `Types::*`, ohne `hasTable`-Guard. „Frisch“: `lifecycle.sh` startet je Umgebung mit `down`/`up` und bricht ab, wenn vor `app:enable` schon eine `oc_nextnotes_*`-Tabelle existiert. Prüfung: `dev/nextcloud/lifecycle.sh sqlite|mariadb|pgsql`, darin `occ app:enable nextnotes; echo $?` = 0 und `occ app:list --output=json | jq -e '.enabled.nextnotes'`.
2. **info.xml** – Felder wie oben. Prüfung: `dev/nextcloud/validate-info.sh` (Exit 0) plus `xmllint --xpath` auf `//dependencies/nextcloud/@min-version`=35, `@max-version`=35, `//php/@min-version`=8.3, `@max-version`=8.5, `count(//navigations/navigation)`≥1.
3. **Tabellen** – Migration wie oben. Prüfung: `occ db:schema:export --output=json` je DB (Fundstelle: in `.ref` fehlt `core/Command/`, registriert in `.ref/nextcloud-server/core/register_command.php` Z. 46/192; Klasse im Image `nextcloud:35` unter `/usr/src/nextcloud/core/Command/Db/ExportSchema.php`, liefert notnull/default/length/autoincrement/unique); Tester vergleicht Tabellen/Spalten (Typ, notnull, default, length, autoincrement)/Indizes (Name, Spalten, unique) mit einer aus §2.1 abgeleiteten Erwartungsdatei (Normalisierungsregeln s. §1 „Tester“); `oc_nextnotes_assignments`/`_submissions` dürfen nicht existieren.
4. **Leere Seite** – PageController + `main.php`. Prüfung `check-page.sh` (ab M1a): `curl -u alice:… …/index.php/apps/nextnotes/` = 200, HTML enthält `id="nextnotes-root"` und `id="content"` (NC-Rahmen); ohne Anmeldung 303, `Location` passt auf Regex `/login\?redirect_url=` (Apache mit RewriteBase: `/login?…`; nginx/fpm: `/index.php/login?…`). CSP: Tester-Playwright (System-Chromium) meldet 0 `securitypolicyviolation`-Ereignisse und 0 CSP-Konsolenfehler.
5. **Spike** – Routen `/dashboard.php`, `/index.php`. Prüfung `spike-urls.sh apache|nginx`, angemeldet je 200 für: `/index.php/apps/nextnotes/dashboard.php`, `/apps/nextnotes/dashboard.php`, `/index.php/apps/nextnotes/index.php`, `/apps/nextnotes/index.php`. Erwartung aus Quellen: Apache-`.htaccess` (pretty-URL-Block aus `lib/private/Setup.php` Z. 617–628) leitet alles außer statischen Endungen und Kern-PHP auf `index.php`; nginx-Referenz schreibt nicht freigegebene `.php` per `rewrite … /index.php$request_uri` um. Ergebnis → ADR 0004; bei Fehlschlag Fallback dort vermerken.
6. **Disable/Remove** – keine `repair-steps/uninstall`; Verhalten nach SPEC §8 („App entfernt“: Tabellen und `IAppData` bleiben; Löschen nur per späterem `occ nextnotes:purge --force`). Prüfung in `lifecycle.sh`: `probe-row.php insert` → `occ app:disable nextnotes` (0) → `probe-row.php count` = 1 → `occ app:remove nextnotes` (0) → `occ app:list` ohne `nextnotes` → `probe-row.php count` = 1 (deckt §8 ab). Gesamtskript Exit 0.

## 3. Docker-Testsetup

`dev/nextcloud/compose.yaml`, Projektname `nextnotes-dev`, Profile:
| Profil | Dienste | Port | Zweck |
|---|---|---|---|
| `sqlite` | `nc-sqlite` (`nextcloud:35.0.2-apache`, `SQLITE_DATABASE`) | 8081 | Krit. 1–6, Apache-Spike |
| `mariadb` | `db-mariadb` (`mariadb:11.4`), `nc-mariadb` (`nextcloud:35.0.2-apache`) | 8082 | Krit. 1, 3, 6 |
| `pgsql` | `db-pgsql` (`postgres:17-alpine`), `nc-pgsql` (`nextcloud:35.0.2-apache`) | 8083 | Krit. 1, 3, 6 |
| `nginx` | `nc-fpm` (`nextcloud:35.0.2-fpm`, SQLite), `web-nginx` (`nginx:stable-alpine`, `nginx/nextcloud.conf`) | 8084 | Krit. 4 + 5 auf nginx |
- Gemeinsam: `NEXTCLOUD_ADMIN_USER/PASSWORD`, `NEXTCLOUD_TRUSTED_DOMAINS=localhost`, Apache zusätzlich `NEXTCLOUD_INIT_HTACCESS=1` (Image setzt `htaccess.RewriteBase '/'` per `apache-pretty-urls.config.php`). Benannte Volumes, nur `down.sh` löscht sie.
- nginx: `web-nginx` bindet **dasselbe benannte Volume** `/var/www/html` wie `nc-fpm` (read-only), damit die nach `custom_apps` kopierte App und statische Dateien sichtbar sind.
- NC-Images auf `35.0.2` gepinnt (Apache und fpm gleich); Pull der M1b-Images ist freigegeben (Lead, nur Tests).
- DB-Versionen im NC-35-Supportfenster (`SupportedDatabase.php`: MariaDB 10.11–12.3, PostgreSQL 14–18).
- App-Einbindung: Kopie nach `custom_apps/nextnotes` (writable App-Root laut `config/apps.config.php` im Image).

## 4. OCP-Klassen (Nachweis `.ref/nextcloud-server/lib/public/`)
- `AppFramework/App.php` (@since 6; nur `buildAppNamespace` deprecated, nicht genutzt)
- `AppFramework/Bootstrap/IBootstrap.php`, `IRegistrationContext.php`, `IBootContext.php` (@since 20)
- `AppFramework/Controller.php` (@since 6; nur `getResponderByHTTPHeader` deprecated, nicht genutzt)
- `AppFramework/Http/TemplateResponse.php` (`RENDER_AS_USER` @since 20)
- `AppFramework/Http/ContentSecurityPolicy.php` (@since 8.1)
- `AppFramework/Http/Attribute/NoAdminRequired.php`, `NoCSRFRequired.php` (@since 27)
- `IRequest.php` (@since 6)
- `Migration/SimpleMigrationStep.php` (@since 13), `Migration/IOutput.php` (@since 9.1)
- `DB/ISchemaWrapper.php` (@since 13; `createTable` liefert in 35 `DB/Schema/ITable.php` @since 35), `DB/Schema/IColumn.php` (35), `DB/Types.php` (@since 21; nur `JSON` deprecated, nicht genutzt)
- Nur Testskript: `Server.php` (@since 25), `IDBConnection.php` (@since 6), `DB/QueryBuilder/IQueryBuilder.php` (@since 8.2)
- Routing: `appinfo/routes.php` mit `postfix` (`lib/private/AppFramework/Routing/RouteParser.php` Z. 77) – keine Attribut-Routen, SPEC §4.1.
- XSD: nicht in `.ref`; Quelle `https://apps.nextcloud.com/schema/apps/info.xsd` (verwiesen in `App/AppInfoDefinition.php` Z. 272). Pflichtfelder: id, name, summary, description, version, licence, author, category, bugs, dependencies.

## 5. Offene Punkte und Risiken
- **R1** `occ app:remove` löscht das App-Verzeichnis per `Files::rmdirr` (`lib/private/Installer.php` Z. 448–461). Bind-Mount des Repos würde den Quellbaum löschen → nur kopieren.
- **R2** Lokal vorhanden ist nur `nextcloud:35` (= 35.0.2). M1b zieht `nextcloud:35.0.2-apache`, `nextcloud:35.0.2-fpm`, `nginx:stable-alpine`, `mariadb:11.4`, `postgres:17-alpine` (freigegeben). Ohne Netz ist nur M1a prüfbar; M1a nutzt `nextcloud:35.0.2-apache`, falls lokal nicht vorhanden, Tag `35` nach Prüfung `version.php` = 35.0.2.
- **R3** XSD-Download braucht Netz (einmalig, gecacht, gitignored). Alternative: Datei einchecken (AGPL-Quelle, nur Dev).
- **R4** Playwright-Browser nicht installiert; System-Chromium (`/usr/bin/chromium`) per `executablePath` nutzen (Tester).
- **R5** Basic-Auth-curl für Kriterium 4/5 deckt den Rahmen ab; die echte Browser-Sitzung (Cookie-Login) prüft erst Playwright.
- **R6** PHP 8.3/8.4 nicht im Image testbar (Image hat 8.5); `<php>`-Grenzen nur per XSD/Lint, ggf. `php -l` mit 8.3 lokal nicht verfügbar.
- Lizenz entschieden (Thomas): `AGPL-3.0-or-later` in `info.xml`, Upstream-MIT-Hinweis als `nextnotes/LICENCE`.
- `app:remove`: entschieden in SPEC §8 (Daten bleiben); keine offene Frage.
- SPEC-Hinweis: §13 M1 Kriterium 3 nennt „alle Tabellen außer assignments/submissions“ – das sind 11 Tabellen inkl. `nextnotes_teams`; Spalte `folders.assignment_id`/`notes.assignment_id` werden schon angelegt (nur Spalten, keine Logik).

**Vorschlag Teilung (zwei Commits):**
Teilung angenommen (Lead). **M1 ist erst nach M1b fertig** (Krit. 1/3/6 verlangen 3 DBs).
- **M1a** App + Migration + info.xml: `nextnotes/**`, `validate-info.sh`, `compose.yaml` (nur `sqlite`), `env.sh`, `up/down/install-app.sh`, `lifecycle.sh`, `check-page.sh`, `probe-row.php`. Done 1–3, 6 auf SQLite; 4 HTTP-Teil über `check-page.sh`.
- **M1b** Matrix + Spike: Profile `mariadb`, `pgsql`, `nginx`, `nginx/nextcloud.conf`, `spike-urls.sh`, `run-m1.sh`, ADR-0004-Nachtrag; Done 1, 3, 6 auf 3 DBs, 4 (CSP/Playwright, `check-page.sh` auch auf nginx), 5.

**Umsetzung M1a – Abweichungen**
- `check-page.sh` sendet abgemeldet `Accept: text/html` (sonst liefert `SecurityMiddleware` 401-JSON statt 303) und wiederholt den angemeldeten Aufruf bis 30 s bei 404 (APCu im Webserver kennt die per `occ` aktivierte App kurz nicht).
- `db:schema:export --output=json` hat die Tabellennamen auf oberster Ebene (kein `.tables`).
- `env.sh` wählt `nextcloud:35.0.2-apache`, sonst lokales `nextcloud:35`; `up.sh` bricht ab, wenn die Version nicht 35.0.2 ist. `probe-row.php` läuft per stdin (`php --`), wird nicht kopiert.
- `info.xml`: `summary` englisch, Kategorie `office` und `organization`, Autor Thomas Weissel, Fork-URL `github.com/valueerrorx/nextnotes`.

**Umsetzung M1b – Abweichungen**
- Spike und CSP laufen als Hooks in `lifecycle.sh` (`CHECK_SPIKE=1`, `CHECK_CSP=1`) bei aktivierter App statt eigener Frisch-Runde; `nginx` durchläuft den vollen Lebenszyklus plus Schematest (`--db=sqlite`).
- CSP-Prüfung (`check-csp.sh`, `scripts/check-csp.mjs`) im Testsetup statt unter `tests/` (Auftrag Lead): `playwright-core` (kein Browser-Download) nur in `dev/nextcloud/package.json`, System-Chromium; echter Formular-Login, Detektor-Selbsttest mit verbotener Bildquelle.
- `down.sh all` nennt die Profile explizit statt `--profile '*'`; alle `curl` mit `--max-time 30` (`NN_CURL`); jq-Prüfung nach `app:remove` robust gegen leere Listen (`[]`).
- `env.sh` ohne Fallback auf `nextcloud:35`: Images gepinnt.
