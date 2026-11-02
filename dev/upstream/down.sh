#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# down.sh: entfernt Upstream-Container und Volume (Image bleibt für Offline-Läufe).
set -euo pipefail
docker compose -f "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/compose.yaml" down -v --remove-orphans
