#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# run-m6.sh [env…]: einzige volle Matrix (Done M1–M6, ersetzt run-m5.sh; Standard: sqlite mariadb pgsql nginx).
# Vorab: Build zweimal (zweiter ohne Netz) mit Prüfsummenvergleich, check-upstream, info.xml, SQL-Prüfung.
# Phase 1 je Umgebung parallel (PARALLEL=0: nacheinander): Lebenszyklus M1 (frisch, Seite, Spike/CSP je Webserver, Schema),
# danach frisch: App, Circles-Loopback (up.sh, circles:check), mysql.utf8mb4 (mariadb), PHPUnit (Apache), Großanhang-Probe (mariadb, pgsql),
# Circles-Aus-Lauf über HTTP (M5 Krit. 3, alle Umgebungen).
# Phase 2 nacheinander, ohne Parallel-Last (Zeitmessungen): Playwright Hell+Dunkel (sqlite, nginx); E2E_PARALLEL=1 gleichzeitig.
# M6: PHPUnit enthält AssignmentIsolationTest (Krit. 4), MaterialIsolationTest mit Aufgaben-Pfaden, AssignmentBudgetTest (R8, 50 Aufgaben/30 Schüler);
# E2E enthält assignments-m6g (Krit. 3) und smoke-m6 (Q1–Q5).
# Logs je Umgebung in NN_LOG_DIR (Standard dev/nextcloud/.cache/run-m6-<Zeit>). KEEP=1 lässt rote Umgebungen stehen.
set -euo pipefail
dir="$(dirname "${BASH_SOURCE[0]}")"
source "$dir/env.sh"

envs=("$@"); [[ ${#envs[@]} -gt 0 ]] || envs=("${NN_ENVS[@]}")
for env in "${envs[@]}"; do check_env "$env"; done
trap '"$dir/down.sh" all >/dev/null 2>&1 || true; exit 130' INT TERM
app="$NN_REPO_ROOT/nextnotes"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
logs="${NN_LOG_DIR:-$NN_CACHE/run-m6-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$logs"
start=$SECONDS

# Build: Netz nur, falls build/node_modules fehlt
[[ -d "$app/build/node_modules/esbuild" ]] || npm ci --prefix "$app/build" --no-audit --no-fund
sums() { (cd "$app" && find js css i18n -type f | sort | xargs "${NN_SHA256[@]}"); }
make -C "$app" clean build
sums > "$tmp/sum1"
nn_offline make -C "$app" clean build
sums > "$tmp/sum2"
cmp -s "$tmp/sum1" "$tmp/sum2" || { diff "$tmp/sum1" "$tmp/sum2" | head -20 >&2; die "Build nicht reproduzierbar"; }
echo "Build reproduzierbar ($(wc -l < "$tmp/sum1") Dateien, zweiter Lauf ohne Netz)"
make -C "$app" check-upstream
"$dir/validate-info.sh"
"$dir/check-forbidden-sql.sh"
[[ -d "$NN_DEV_DIR/node_modules/playwright-core" ]] || npm --prefix "$NN_DEV_DIR" ci --no-audit --no-fund

phase1() {
	local env="$1" web=0 db="$1" got
	[[ "$env" == sqlite || "$env" == nginx ]] && web=1
	[[ "$env" == mariadb ]] && db=mysql
	[[ "$env" == nginx ]] && db=sqlite
	CHECK_PAGE=1 CHECK_SPIKE=$web CHECK_CSP=$web "$dir/lifecycle.sh" "$env"
	php "$NN_REPO_ROOT/tests/nextnotes/schema/check-schema.php" "$NN_CACHE/schema-$env.json" --db="$db"
	"$dir/down.sh" "$env" >/dev/null 2>&1 || true
	"$dir/up.sh" "$env"
	"$dir/install-app.sh" "$env"
	occ "$env" app:enable nextnotes
	if [[ "$env" == mariadb ]]; then
		got="$(occ "$env" config:system:get mysql.utf8mb4 || true)"
		[[ "$got" == true ]] || die "mariadb: mysql.utf8mb4 = '$got', erwartet true"
		echo "mariadb: mysql.utf8mb4 = true"
	fi
	case "$env" in sqlite|mariadb|pgsql) "$dir/run-phpunit.sh" "$env" ;; esac
	case "$env" in mariadb|pgsql) "$dir/scripts/probe-grenzen.sh" "$env" ;; esac
	"$dir/scripts/probe-circles-off.sh" "$env"
}

declare -A rc=()
finish() {
	local env="$1" code="$2" what="$3"
	rc[$env]=$code
	if [[ $code -ne 0 ]]; then
		echo "=== $env: $what ROT (Exit $code, Log $logs/$env.log)" >&2
		tail -n 30 "$logs/$env.log" >&2
	else
		echo "=== $env: $what grün ($(( (SECONDS - start) / 60 )) min seit Start)"
	fi
}

# Netz des Compose-Projekts vorab anlegen: parallele "up" würden es sonst gleichzeitig erzeugen
dc "${envs[0]}" create >/dev/null 2>&1 || true
declare -A pid=()
for env in "${envs[@]}"; do
	echo "=== $env: Phase 1 (Log $logs/$env.log)"
	# im Hintergrund, damit set -e im Subshell wirkt (in einer ||-Liste wäre es aus)
	(set -e; phase1 "$env") > "$logs/$env.log" 2>&1 &
	pid[$env]=$!
	if [[ "${PARALLEL:-1}" != 1 ]]; then
		code=0; wait "${pid[$env]}" || code=$?
		unset "pid[$env]"
		finish "$env" "$code" "Phase 1"
	fi
done
for env in "${!pid[@]}"; do
	code=0; wait "${pid[$env]}" || code=$?
	finish "$env" "$code" "Phase 1"
done

e2e() {
	local env="$1" code=0
	echo "=== $env: Phase 2 E2E (Log $logs/$env.log)"
	"$dir/run-e2e.sh" "$env" >> "$logs/$env.log" 2>&1 || code=$?
	grep '^run-e2e ' "$logs/$env.log" | tail -n 1 || true
	return "$code"
}
e2e_envs=()
for env in "${envs[@]}"; do
	if [[ ${rc[$env]} -eq 0 && ( "$env" == sqlite || "$env" == nginx ) ]]; then
		e2e_envs+=("$env")
	else
		[[ "${KEEP:-0}" == 1 && ${rc[$env]} -ne 0 ]] || "$dir/down.sh" "$env" >/dev/null 2>&1 || true
	fi
done
# E2E_PARALLEL=1: sqlite und nginx gleichzeitig (schneller, Zeitmessungen wie anti-flicker/budget dann weniger belastbar)
declare -A epid=()
for env in "${e2e_envs[@]}"; do
	if [[ "${E2E_PARALLEL:-0}" == 1 ]]; then
		e2e "$env" & epid[$env]=$!
	else
		code=0; e2e "$env" || code=$?
		finish "$env" "$code" "E2E"
	fi
done
for env in "${!epid[@]}"; do
	code=0; wait "${epid[$env]}" || code=$?
	finish "$env" "$code" "E2E"
done
for env in "${e2e_envs[@]}"; do
	[[ "${KEEP:-0}" == 1 && ${rc[$env]} -ne 0 ]] || "$dir/down.sh" "$env" >/dev/null 2>&1 || true
done

failed=()
for env in "${envs[@]}"; do [[ ${rc[$env]} -eq 0 ]] || failed+=("$env"); done
echo "Dauer gesamt: $(( (SECONDS - start) / 60 )) min; Logs: $logs"
# Netz des Projekts entfernen, sofern nichts stehen bleiben soll
[[ "${KEEP:-0}" == 1 ]] || "$dir/down.sh" all >/dev/null 2>&1 || true
if [[ ${#failed[@]} -gt 0 ]]; then
	echo "run-m6: rot in ${failed[*]} (grün: $(( ${#envs[@]} - ${#failed[@]} ))/${#envs[@]})" >&2
	exit 1
fi
echo "run-m6: alle Umgebungen grün (${envs[*]})"
