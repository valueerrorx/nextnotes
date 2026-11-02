#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# run-m2.sh [env…]: Done M2 je Umgebung frisch (Standard: sqlite mariadb pgsql); Exit 0 nur wenn alles grün. KEEP=1 lässt bei Fehlschlag die Umgebung stehen.
set -euo pipefail
dir="$(dirname "${BASH_SOURCE[0]}")"
source "$dir/env.sh"

envs=("$@"); [[ ${#envs[@]} -gt 0 ]] || envs=(sqlite mariadb pgsql)
trap 'rc=$?; [[ "${KEEP:-0}" == 1 && $rc -ne 0 ]] || "$dir/down.sh" all >/dev/null 2>&1 || true' EXIT

"$dir/validate-info.sh"
"$dir/check-forbidden-sql.sh"
for env in "${envs[@]}"; do
	echo "=== $env"
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
	"$dir/down.sh" "$env" >/dev/null
done
echo "run-m2: alle Umgebungen grün (${envs[*]})"
