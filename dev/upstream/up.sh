#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# up.sh: baut (einmalig, braucht Netz) und startet Upstream-Poznote auf http://127.0.0.1:8090; nur Tests.
set -euo pipefail
dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
docker compose -f "$dir/compose.yaml" up -d --build
for i in $(seq 1 60); do
	curl -fs -o /dev/null --max-time 5 http://127.0.0.1:8090/api/health && { echo "upstream: bereit (http://127.0.0.1:8090)"; exit 0; }
	sleep 2
done
echo "FEHLER: Upstream antwortet nicht" >&2; exit 1
