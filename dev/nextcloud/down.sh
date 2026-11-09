#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# down.sh <env|all>: entfernt Container und Volumes einer Umgebung (oder aller).
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

target="${1:?Aufruf: down.sh <env|all>}"
if [[ "$target" == all ]]; then
	profiles=(--profile msmock); for e in "${NN_ENVS[@]}"; do profiles+=(--profile "$e"); done
	docker compose -p "$NN_PROJECT" -f "$NN_DEV_DIR/compose.yaml" "${profiles[@]}" down -v --remove-orphans
else
	check_env "$target"
	# Nur Dienste und Volumes dieses Profils; Netz bleibt für andere Profile
	svcs="$(dc "$target" config --services)"
	mapfile -t svcs <<<"$svcs"
	dc "$target" rm -sfv "${svcs[@]}"
	for v in $(vols_for "$target"); do docker volume rm -f "${NN_PROJECT}_$v" >/dev/null; done
fi
