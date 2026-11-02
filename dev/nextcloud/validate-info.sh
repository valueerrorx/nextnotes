#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# validate-info.sh: prüft appinfo/info.xml gegen das App-Store-XSD und die Pflichtwerte (Done M1.2).
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

xsd="$NN_CACHE/info.xsd"
xml="$NN_REPO_ROOT/nextnotes/appinfo/info.xml"
mkdir -p "$NN_CACHE"
[[ -s "$xsd" ]] || "${NN_CURL[@]}" -fsSL -o "$xsd" https://apps.nextcloud.com/schema/apps/info.xsd
xmllint --noout --schema "$xsd" "$xml"

expect() {
	local got; got="$(xmllint --xpath "$1" "$xml")"
	[[ "$got" == "$2" ]] || die "$1 = '$got', erwartet '$2'"
}
expect 'string(/info/id)' nextnotes
expect 'string(/info/licence)' AGPL-3.0-or-later
expect 'string(//dependencies/nextcloud/@min-version)' 35
expect 'string(//dependencies/nextcloud/@max-version)' 35
expect 'string(//dependencies/php/@min-version)' 8.3
expect 'string(//dependencies/php/@max-version)' 8.5
expect 'count(//navigations/navigation) >= 1' true
echo "info.xml: OK"
