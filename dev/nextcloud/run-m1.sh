#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# run-m1.sh [env…]: Done M1 auf allen Umgebungen; Exit 0 nur wenn alles grün. KEEP=1 lässt bei Fehlschlag die Umgebung stehen.
set -euo pipefail
dir="$(dirname "${BASH_SOURCE[0]}")"
source "$dir/env.sh"

envs=("$@"); [[ ${#envs[@]} -gt 0 ]] || envs=("${NN_ENVS[@]}")
trap 'rc=$?; [[ "${KEEP:-0}" == 1 && $rc -ne 0 ]] || "$dir/down.sh" all >/dev/null 2>&1 || true' EXIT

"$dir/validate-info.sh"
for env in "${envs[@]}"; do
	check_env "$env"
	echo "######## $env"
	# Spike und CSP nur je Webserver (Apache = sqlite, nginx)
	web=0; [[ "$env" == sqlite || "$env" == nginx ]] && web=1
	CHECK_PAGE=1 CHECK_SPIKE=$web CHECK_CSP=$web "$dir/lifecycle.sh" "$env"
	# M9a Done 9: Local-Address-Schutz an; allow_local_remote_servers gibt es nur im Profil msmock (msmock.sh)
	if occ "$env" config:system:get allow_local_remote_servers >/dev/null 2>&1; then die "$env: allow_local_remote_servers ist gesetzt (nur msmock)"; fi
	db="$env"; [[ "$env" == mariadb ]] && db=mysql; [[ "$env" == nginx ]] && db=sqlite
	php "$NN_REPO_ROOT/tests/nextnotes/schema/check-schema.php" "$NN_CACHE/schema-$env.json" --db="$db"
	"$dir/down.sh" "$env"
done
echo "M1: alle Prüfungen grün (${envs[*]})"
