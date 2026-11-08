#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# install-app.sh <env>: kopiert nextnotes/ nach custom_apps (kein Bind-Mount, R1); NN_APP_TARBALL=<paket> installiert stattdessen das Paket.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

env="${1:?Aufruf: install-app.sh <env>}"
check_env "$env"
apps=/var/www/html/custom_apps
dest=$apps/nextnotes
if [[ -n "${NN_APP_TARBALL:-}" ]]; then
	"$NN_DEV_DIR/check-dist.sh" "$NN_APP_TARBALL" >/dev/null
	dc "$env" exec -T -u root "$(svc_for "$env")" sh -c \
		"rm -rf '$dest' && mkdir -p '$apps' && tar -xzf - -C '$apps' && chown -R www-data:www-data '$dest'" < "$NN_APP_TARBALL"
	echo "$env: Paket $(basename "$NN_APP_TARBALL") nach $dest entpackt"
	exit 0
fi
[[ -f "$NN_REPO_ROOT/nextnotes/js/nextnotes-app.js" ]] || echo "WARNUNG: Build-Ausgabe fehlt (make -C nextnotes build), Seite ohne Assets" >&2
nn_need_gnu_tar
# Ausschlüsse aus derselben Liste wie make dist
excl=()
while IFS= read -r p; do [[ -z "$p" || "$p" == \#* ]] || excl+=("--exclude=$p"); done < "$NN_REPO_ROOT/nextnotes/build/dist-exclude.txt"
nn_tar -C "$NN_REPO_ROOT/nextnotes" --anchored "${excl[@]}" -cf - . \
	| dc "$env" exec -T -u root "$(svc_for "$env")" sh -c \
		"rm -rf '$dest' && mkdir -p '$dest' && tar -xf - -C '$dest' && chown -R www-data:www-data '$dest'"
echo "$env: App nach $dest kopiert"
