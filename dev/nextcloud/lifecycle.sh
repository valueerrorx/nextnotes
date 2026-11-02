#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# lifecycle.sh <env>: frische Instanz → enable → Schema → disable → remove (Done M1.1/3/6); CHECK_PAGE/CHECK_SPIKE/CHECK_CSP=1 prüfen zusätzlich.
set -euo pipefail
dir="$(dirname "${BASH_SOURCE[0]}")"
source "$dir/env.sh"

env="${1:?Aufruf: lifecycle.sh <env>}"
check_env "$env"
step() { echo "== $env: $*"; }

# Schema-Export: oberste Ebene = Tabellennamen
nn_tables() {
	local s
	s="$(occ "$env" db:schema:export --output=json)"
	jq -r 'keys[] | select(startswith("oc_nextnotes_"))' <<<"$s"
}

step "frische Instanz"
"$dir/down.sh" "$env"
"$dir/up.sh" "$env"
tables="$(nn_tables)"
[[ -z "$tables" ]] || die "Instanz nicht frisch: oc_nextnotes_*-Tabellen vorhanden"

step "install + enable"
"$dir/install-app.sh" "$env"
occ "$env" app:enable nextnotes
occ "$env" app:list --output=json | jq -e '.enabled.nextnotes' >/dev/null || die "nextnotes nicht aktiviert"

step "Schema-Export"
mkdir -p "$NN_CACHE"
occ "$env" db:schema:export --output=json > "$NN_CACHE/schema-$env.json"
tables="$(nn_tables)"
n="$(grep -c . <<<"$tables" || true)"
[[ "$n" -eq 11 ]] || die "$n statt 11 oc_nextnotes_*-Tabellen"

# Optionale Prüfungen bei aktivierter App
if [[ "${CHECK_PAGE:-0}" == 1 ]]; then
	step "Seite"
	"$dir/check-page.sh" "$env"
fi
if [[ "${CHECK_SPIKE:-0}" == 1 ]]; then
	step "URL-Spike"
	"$dir/spike-urls.sh" "$env"
fi
if [[ "${CHECK_CSP:-0}" == 1 ]]; then
	step "CSP"
	"$dir/check-csp.sh" "$env"
fi

step "Probe + disable"
probe "$env" insert
occ "$env" app:disable nextnotes
[[ "$(probe "$env" count)" == 1 ]] || die "Probe-Zeile nach app:disable fehlt"

step "remove"
occ "$env" app:remove nextnotes
apps="$(occ "$env" app:list --output=json)"
# PHP gibt leere Listen als [] aus, daher über keys prüfen
jq -e '[(.enabled // {}), (.disabled // {})] | map(keys[]) | index("nextnotes") == null' >/dev/null <<<"$apps" \
	|| die "nextnotes noch in app:list"
[[ "$(probe "$env" count)" == 1 ]] || die "Probe-Zeile nach app:remove fehlt (SPEC §8)"
echo "== $env: Lebenszyklus OK"
