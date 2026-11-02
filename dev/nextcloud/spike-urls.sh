#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# spike-urls.sh <env>: Done M1.5 – .php-Routen der App über Front-Controller und pretty URL; App muss aktiviert sein.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

env="${1:?Aufruf: spike-urls.sh <env>}"
check_env "$env"
base="$(base_url "$env")"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

fail=0
for path in /index.php/apps/nextnotes/dashboard.php /apps/nextnotes/dashboard.php \
	/index.php/apps/nextnotes/index.php /apps/nextnotes/index.php; do
	code="$("${NN_CURL[@]}" -sS -u "$NN_TEST_USER:$NN_TEST_PASS" -o "$tmp/body" -w '%{http_code}' "$base$path")"
	if [[ "$code" == 200 ]] && grep -q 'id="nextnotes-root"' "$tmp/body"; then
		echo "$env: 200 $path"
	else
		echo "$env: FEHLER $code $path" >&2
		fail=1
	fi
done
[[ $fail == 0 ]] || die "$env: URL-Spike fehlgeschlagen"
echo "$env: URL-Spike OK"
