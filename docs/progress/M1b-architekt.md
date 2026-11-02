# M1b – Arbeitsstand Architekt

Status: fertig

## Auftrag
M1b umsetzen: Compose-Profile mariadb/pgsql/nginx, URL-Spike (Krit. 5), CSP-Prüfung per Playwright (Krit. 4), `run-m1.sh` über alle Umgebungen, Hinweise aus `M1a-diff-r1`.

## Erledigt
- `dev/nextcloud/compose.yaml`: Profile `mariadb` (mariadb:11.4), `pgsql` (postgres:17-alpine), `nginx` (nextcloud:35.0.2-fpm + nginx:stable-alpine, gleiches Volume ro); NC auf 35.0.2 gepinnt.
- `dev/nextcloud/nginx/nextcloud.conf` (NC-Admin-Doku-Referenz, nur HTTP).
- `env.sh` (alle Umgebungen, `NN_CURL` mit `--max-time 30`), `up.sh`, `down.sh` (Profile explizit statt `'*'`), `check-page.sh`, `validate-info.sh`, `lifecycle.sh` (jq-Fix, Hooks `CHECK_SPIKE`/`CHECK_CSP`).
- Neu: `spike-urls.sh`, `check-csp.sh`, `scripts/check-csp.mjs` (mit Detektor-Selbsttest), `package.json`/`package-lock.json` (playwright-core 1.64.1), `run-m1.sh`.
- `./run-m1.sh` (alle 4 Umgebungen): Exit 0. Spike auf Apache und nginx: 4×200.

- ADR 0004 „Spike-Ergebnis M1“ (bestanden), `dev/nextcloud/README.md`, Abweichungen in `docs/slices/M1-plan.md`.
- `./down.sh all`: Exit 0; danach 0 Container, 0 Volumes, 0 Netze `nextnotes-dev`.

## Nächster Schritt
Übergabe an Lead (Tester/Speccritic).

## Offene Punkte
- keine
