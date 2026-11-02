#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# check-csp.sh <env>: Browser-Login als Testnutzer, App-Seiten ohne CSP-Verletzung (Done M1.4); App muss aktiviert sein.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

env="${1:?Aufruf: check-csp.sh <env>}"
check_env "$env"
# playwright-core nur lokal in dev/nextcloud, ohne Browser-Download
[[ -d "$NN_DEV_DIR/node_modules/playwright-core" ]] || npm --prefix "$NN_DEV_DIR" ci --no-audit --no-fund
timeout 180 node "$NN_DEV_DIR/scripts/check-csp.mjs" "$(base_url "$env")" "$NN_TEST_USER" "$NN_TEST_PASS" \
	/index.php/apps/nextnotes/ /index.php/apps/nextnotes/dashboard.php
echo "$env: CSP OK"
