#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# run-m1.sh [env…]: Done M1 auf allen Umgebungen; Exit 0 nur wenn alles grün. KEEP=1 lässt die letzte Umgebung stehen.
set -euo pipefail
dir="$(dirname "${BASH_SOURCE[0]}")"
source "$dir/env.sh"

envs=("$@"); [[ ${#envs[@]} -gt 0 ]] || envs=("${NN_ENVS[@]}")
[[ "${KEEP:-0}" == 1 ]] || trap '"$dir/down.sh" all >/dev/null 2>&1 || true' EXIT

"$dir/validate-info.sh"
for env in "${envs[@]}"; do
	check_env "$env"
	echo "######## $env"
	# Spike und CSP nur je Webserver (Apache = sqlite, nginx)
	web=0; [[ "$env" == sqlite || "$env" == nginx ]] && web=1
	CHECK_PAGE=1 CHECK_SPIKE=$web CHECK_CSP=$web "$dir/lifecycle.sh" "$env"
	db="$env"; [[ "$env" == mariadb ]] && db=mysql; [[ "$env" == nginx ]] && db=sqlite
	php "$NN_REPO_ROOT/tests/nextnotes/schema/check-schema.php" "$NN_CACHE/schema-$env.json" --db="$db"
	"$dir/down.sh" "$env"
done
echo "M1: alle Prüfungen grün (${envs[*]})"
