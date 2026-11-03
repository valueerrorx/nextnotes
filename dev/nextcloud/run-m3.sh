#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# run-m3.sh [env…]: Done M3 (Standard: sqlite mariadb pgsql nginx). Build zweimal (zweiter ohne Netz) mit Prüfsummenvergleich,
# check-upstream, info.xml, SQL-Prüfung; je Umgebung frisch: PHPUnit (Apache), Playwright (sqlite, nginx). KEEP=1 lässt rote Umgebungen stehen.
set -euo pipefail
dir="$(dirname "${BASH_SOURCE[0]}")"
source "$dir/env.sh"

envs=("$@"); [[ ${#envs[@]} -gt 0 ]] || envs=("${NN_ENVS[@]}")
for env in "${envs[@]}"; do check_env "$env"; done
trap '"$dir/down.sh" all >/dev/null 2>&1 || true; exit 130' INT TERM
app="$NN_REPO_ROOT/nextnotes"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

# Build: Netz nur, falls build/node_modules fehlt
[[ -d "$app/build/node_modules/esbuild" ]] || npm ci --prefix "$app/build" --no-audit --no-fund
sums() { (cd "$app" && find js css i18n -type f | sort | xargs sha256sum); }
make -C "$app" clean build
sums > "$tmp/sum1"
unshare -rn make -C "$app" clean build
sums > "$tmp/sum2"
cmp -s "$tmp/sum1" "$tmp/sum2" || { diff "$tmp/sum1" "$tmp/sum2" | head -20 >&2; die "Build nicht reproduzierbar"; }
echo "Build reproduzierbar ($(wc -l < "$tmp/sum1") Dateien, zweiter Lauf ohne Netz)"
make -C "$app" check-upstream
"$dir/validate-info.sh"
"$dir/check-forbidden-sql.sh"

run_env() {
	local env="$1"
	"$dir/down.sh" "$env" >/dev/null 2>&1 || true
	"$dir/up.sh" "$env"
	"$dir/install-app.sh" "$env"
	occ "$env" app:enable nextnotes
	case "$env" in sqlite|mariadb|pgsql) "$dir/run-phpunit.sh" "$env" ;; esac
	case "$env" in sqlite|nginx) "$dir/run-e2e.sh" "$env" ;; esac
}

failed=()
for env in "${envs[@]}"; do
	echo "=== $env"
	set +e
	(set -e; run_env "$env")
	rc=$?
	set -e
	if [[ $rc -ne 0 ]]; then
		failed+=("$env")
		echo "=== $env: ROT (Exit $rc)" >&2
		[[ "${KEEP:-0}" == 1 ]] && continue
	else
		echo "=== $env: grün"
	fi
	"$dir/down.sh" "$env" >/dev/null 2>&1 || true
done

if [[ ${#failed[@]} -gt 0 ]]; then
	echo "run-m3: rot in ${failed[*]} (grün: $(( ${#envs[@]} - ${#failed[@]} ))/${#envs[@]})" >&2
	exit 1
fi
echo "run-m3: alle Umgebungen grün (${envs[*]})"
