#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# run-m2.sh [env…]: Done M2 je Umgebung frisch (Standard: sqlite mariadb pgsql); läuft alle Umgebungen, Exit 0 nur wenn alle grün. KEEP=1 lässt fehlgeschlagene Umgebungen stehen.
set -euo pipefail
dir="$(dirname "${BASH_SOURCE[0]}")"
source "$dir/env.sh"

envs=("$@"); [[ ${#envs[@]} -gt 0 ]] || envs=(sqlite mariadb pgsql)
for env in "${envs[@]}"; do check_env "$env"; done
trap '"$dir/down.sh" all >/dev/null 2>&1 || true; exit 130' INT TERM

"$dir/validate-info.sh"
"$dir/check-forbidden-sql.sh"

run_env() {
	local env="$1" got
	"$dir/down.sh" "$env" >/dev/null 2>&1 || true
	"$dir/up.sh" "$env"
	"$dir/install-app.sh" "$env"
	occ "$env" app:enable nextnotes
	if [[ "$env" == mariadb ]]; then
		got="$(occ "$env" config:system:get mysql.utf8mb4 || true)"
		[[ "$got" == true ]] || die "mariadb: mysql.utf8mb4 = '$got', erwartet true"
		echo "mariadb: mysql.utf8mb4 = true"
	fi
	"$dir/run-phpunit.sh" "$env"
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
	echo "run-m2: rot in ${failed[*]} (grün: $(( ${#envs[@]} - ${#failed[@]} ))/${#envs[@]})" >&2
	exit 1
fi
echo "run-m2: alle Umgebungen grün (${envs[*]})"
