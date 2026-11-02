#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# down.sh <env|all>: entfernt Container und Volumes einer Umgebung (oder aller).
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

target="${1:?Aufruf: down.sh <env|all>}"
if [[ "$target" == all ]]; then
	dc '*' down -v --remove-orphans
else
	check_env "$target"
	# Nur Dienste und Volumes dieses Profils
	dc "$target" rm -sfv "$(svc_for "$target")"
	docker volume rm -f "nextnotes-dev_$(svc_for "$target")" >/dev/null
fi
