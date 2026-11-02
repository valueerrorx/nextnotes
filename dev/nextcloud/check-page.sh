#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# check-page.sh <env>: HTTP-Teil von Done M1.4; App muss installiert und aktiviert sein.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

env="${1:?Aufruf: check-page.sh <env>}"
check_env "$env"
url="$(base_url "$env")/index.php/apps/nextnotes/"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

# APCu im Webserver kennt die per occ aktivierte App ggf. erst nach kurzer Zeit (404)
for i in $(seq 1 30); do
	code="$(curl -sS -u "$NN_TEST_USER:$NN_TEST_PASS" -o "$tmp/body" -w '%{http_code}' "$url")"
	[[ "$code" == 404 ]] || break
	sleep 1
done
[[ "$code" == 200 ]] || die "angemeldet: HTTP $code statt 200 ($url)"
grep -q 'id="nextnotes-root"' "$tmp/body" || die 'id="nextnotes-root" fehlt'
grep -q 'id="content"' "$tmp/body" || die 'id="content" (NC-Rahmen) fehlt'

# Accept wie ein Browser, sonst antwortet NC mit 401-JSON
code="$(curl -sS -H "Accept: text/html" -o /dev/null -D "$tmp/head" -w '%{http_code}' "$url")"
[[ "$code" == 303 ]] || die "abgemeldet: HTTP $code statt 303"
grep -iE '^location: .*/login\?redirect_url=' "$tmp/head" >/dev/null \
	|| die "abgemeldet: Location passt nicht: $(grep -i '^location:' "$tmp/head" || echo keine)"
echo "$env: Seite OK (200 angemeldet, 303 Login abgemeldet)"
