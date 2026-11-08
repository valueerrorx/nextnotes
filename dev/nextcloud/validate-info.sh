#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# validate-info.sh: prüft appinfo/info.xml gegen das App-Store-XSD und die Pflichtwerte (Done M1.2),
# URLs nur syntaktisch (kein Abruf), Drittanbieter-Hinweise und CHANGELOG (M7c). Netz nur für den ersten XSD-Download.
# NN_APP_DIR= prüft eine Kopie des App-Ordners (Gegenproben).
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

xsd="$NN_CACHE/info.xsd"
app="${NN_APP_DIR:-$NN_REPO_ROOT/nextnotes}"
xml="$app/appinfo/info.xml"
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
[[ "$(xmllint --xpath 'string(/info/version)' "$xml")" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Version nicht X.Y.Z"
expect 'count(/info/description[not(@lang)])' 1
expect 'count(/info/description[@lang="de"])' 1
expect 'count(/info/website) = 1 and count(/info/documentation/user) = 1 and count(/info/documentation/admin) = 1 and count(/info/screenshot) >= 1' true

# URLs nur syntaktisch: HTTPS, Host, Pfad im Repo; kein Abruf (Erreichbarkeit erst nach Push, Release-Checkliste)
url_re='^https://(github\.com|raw\.githubusercontent\.com)/valueerrorx/nextnotes(/[^[:space:]<>"]*)?$'
urls=()
for q in /info/website /info/bugs /info/repository '/info/documentation/*' /info/screenshot /info/screenshot/@small-thumbnail; do
	c="$(xmllint --xpath "count($q)" "$xml")"
	for ((i = 1; i <= c; i++)); do urls+=("$(xmllint --xpath "normalize-space(($q)[$i])" "$xml")"); done
done
for u in "${urls[@]}"; do
	[[ "$u" =~ $url_re ]] || die "URL unzulässig: '$u' (nur https://github.com/valueerrorx/nextnotes… bzw. raw.githubusercontent.com)"
done
# Screenshots: PNG, Datei im App-Ordner vorhanden, ≤ 2 MiB (App-Store-Grenze)
c="$(xmllint --xpath 'count(/info/screenshot)' "$xml")"
for ((i = 1; i <= c; i++)); do
	u="$(xmllint --xpath "normalize-space(/info/screenshot[$i])" "$xml")"
	[[ "$u" =~ ^https://raw\.githubusercontent\.com/valueerrorx/nextnotes/[^/]+/nextnotes/(screenshots/[a-z0-9-]+\.png)$ ]] || die "Screenshot-URL nicht …/nextnotes/screenshots/<name>.png: $u"
	f="$app/${BASH_REMATCH[1]}"
	[[ -f "$f" ]] || die "Screenshot-Datei zur URL fehlt: ${BASH_REMATCH[1]}"
	(( $(nn_size "$f") <= 2097152 )) || die "Screenshot größer als 2 MiB: ${BASH_REMATCH[1]}"
done

node "$NN_DEV_DIR/scripts/check-notices.mjs" "$app" "$NN_REPO_ROOT" || die "Drittanbieter-Hinweise/CHANGELOG"
echo "info.xml: OK (${#urls[@]} URLs syntaktisch geprüft, $c Screenshots)"
