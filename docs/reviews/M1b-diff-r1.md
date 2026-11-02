VERDICT: FREIGABE

Geprüft: `git diff 660a275e` + neue Dateien in `dev/nextcloud/` (run-m1.sh, check-csp.sh, spike-urls.sh, scripts/check-csp.mjs, nginx/nextcloud.conf, package*.json), `docs/adr/0004-seiten-abloesung.md`; Maßstab SPEC §13 M1, §4.6, ADR 0004/0012, `docs/slices/M1-plan.md` (M1b), Review M1a-diff-r1.

Keine Blocker.

Befund im Einzelnen:
- M1a-Hinweise 1–3 erledigt: `lifecycle.sh:61` (jq über keys, `[]`-fest), `env.sh` `NN_CURL=(curl --max-time 30)` in allen curl-Aufrufen, `down.sh:10` ohne `--profile '*'`, README nennt Compose-Version.
- Frische Instanz je Umgebung: `run-m1.sh:18` → `lifecycle.sh:21-24` (down mit Volume-Löschung, up, Abbruch bei vorhandenen `oc_nextnotes_*`; wirkt, weil app:remove Tabellen behält).
- Exit-Codes: `set -euo pipefail` + `inherit_errexit`; EXIT-Trap `run-m1.sh:10` mit `|| true` ändert den Exit-Status nicht (bash behält `$?` nach EXIT-Trap); curl-Fehler in `$(…)` brechen ab; `check-csp.sh:12` mit `timeout 180` (124 ≠ 0); Login-Timeout in `check-csp.mjs` wirft → Exit 1.
- CSP-Prüfung echt: Listener per `addInitScript` vor Seitenskripten, plus Konsolenfilter; Selbsttest `check-csp.mjs:56-63` mit verbotener Bildquelle verhindert falsches Grün (fehlende Policy oder toter Detektor → rot).
- Spike: `spike-urls.sh` prüft 200 + `id="nextnotes-root"` für alle vier URLs aus Plan §5. ADR 0004 Nachtrag passt dazu; `htaccess.RewriteBase '/'` stammt aus `config/apache-pretty-urls.config.php` des Images (geprüft), `NEXTCLOUD_INIT_HTACCESS=1` gesetzt.
- nginx (`nginx/nextcloud.conf`): entspricht der NC-Referenz (Legacy-`rewrite` auf `/index.php$request_uri`, `fastcgi_split_path_info`, `front_controller_active true`, Sperr-Locations, statische Dateien mit `try_files … /index.php$request_uri`); Abweichungen im Kopf dokumentiert.
- Keine Secrets außer Test-Passwörtern; Abhängigkeiten nur `dev/nextcloud/package.json` (playwright-core 1.64.1 mit Lockfile) sowie Host-Werkzeuge laut README; `tests/nextnotes/schema/check-schema.php` ist seit M1a im Repo. Kein Scope-Wachstum gegenüber M1-Plan (M1b, Abweichungen Z. 109-111).

Hinweise (nicht blockierend):
1. `compose.yaml` `web-nginx`: `nginx:stable-alpine` ist ein wandernder Tag (Plan Z. 66 so freigegeben), README nennt die Images aber „gepinnt“. Versionstag (z. B. `nginx:1.28-alpine`) setzen oder in ADR 0004 die tatsächlich getestete nginx-Version nennen, damit das Spike-Ergebnis reproduzierbar bleibt. Gleiches gilt schwächer für `mariadb:11.4`/`postgres:17-alpine` (Patch wandert).
2. `down.sh:14`: `mapfile … < <(dc … config --services)` – Fehler der Prozesssubstitution wird von `set -e` nicht erfasst. Kein falsches Grün (Frische-Check in `lifecycle.sh:24`), aber robuster: `svcs="$(dc … config --services)"` und dann splitten.
3. `run-m1.sh:4` sagt „KEEP=1 lässt die letzte Umgebung stehen“, `run-m1.sh:21` fährt jede Umgebung nach Erfolg herunter; KEEP wirkt nur bei Fehlschlag. Kommentar/README angleichen.
4. ADR 0004 Nachtrag nennt Datum, aber keinen Lauf-Nachweis (Log/Exit-Code). Der Tester-Nachweis von `run-m1.sh` (alle vier Umgebungen, Exit 0) gehört in STATUS bzw. Commit-Text, bevor M1 als fertig gilt.
5. CSP (Krit. 4) läuft nur auf `sqlite` und `nginx`, nicht auf mariadb/pgsql. Ist DB-unabhängig und `check-page.sh` läuft überall; als bewusste Entscheidung vertretbar.
