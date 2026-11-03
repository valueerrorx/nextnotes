#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# install-app.sh <env>: kopiert nextnotes/ nach custom_apps (kein Bind-Mount, R1).
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

env="${1:?Aufruf: install-app.sh <env>}"
check_env "$env"
dest=/var/www/html/custom_apps/nextnotes
[[ -f "$NN_REPO_ROOT/nextnotes/js/nextnotes-app.js" ]] || echo "WARNUNG: Build-Ausgabe fehlt (make -C nextnotes build), Seite ohne Assets" >&2
tar -C "$NN_REPO_ROOT/nextnotes" --exclude=.gitignore --exclude=./build --exclude=./Makefile --exclude=./js-src --exclude=./css-src -cf - . \
	| dc "$env" exec -T -u root "$(svc_for "$env")" sh -c \
		"rm -rf '$dest' && mkdir -p '$dest' && tar -xf - -C '$dest' && chown -R www-data:www-data '$dest'"
echo "$env: App nach $dest kopiert"
