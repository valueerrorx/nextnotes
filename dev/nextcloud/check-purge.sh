#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# check-purge.sh <env>: M7b Done 7 – PurgeTest (löscht ALLE App-Daten der Instanz), danach app:remove (Exit 0), Neuinstallation, app:enable, Prüfung.
# NN_INSTALL=<skript> ersetzt install-app.sh für die Neuinstallation (z. B. Paket- oder Teilstand); NN_APP_TARBALL wirkt wie bei install-app.sh.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

env="${1:?Aufruf: check-purge.sh <env>}"
check_env "$env"
install="${NN_INSTALL:-$NN_DEV_DIR/install-app.sh}"

"$NN_DEV_DIR/run-phpunit.sh" "$env" --group destructive

out="$(occ "$env" nextnotes:purge)"
grep -q 'background jobs: 0' <<<"$out" || die "$env: nach purge noch Jobs der App"$'\n'"$out"
[[ "$(grep -c ': 0 row(s)' <<<"$out")" == 15 ]] || die "$env: nach purge nicht alle Tabellen leer"$'\n'"$out"
# M9a Done 6a: keine onenote_*-Schlüssel (auch sensible) mehr in der App-Config
occ "$env" config:list nextnotes --private --output=json | jq -e '[.apps.nextnotes // {} | keys[] | select(startswith("onenote_"))] | length == 0' >/dev/null \
	|| die "$env: nach purge noch onenote_*-Schlüssel"

occ "$env" app:remove nextnotes || die "$env: app:remove nach purge fehlgeschlagen"
occ "$env" app:list --output=json | jq -e '.enabled.nextnotes == null and .disabled.nextnotes == null' >/dev/null \
	|| die "$env: App nach app:remove noch gelistet"

"$install" "$env"
occ "$env" app:enable nextnotes || die "$env: app:enable nach purge/app:remove fehlgeschlagen"
out="$(occ "$env" nextnotes:purge)"
echo "$out"
[[ "$(grep -c ': 0 row(s)' <<<"$out")" == 15 ]] || die "$env: Tabellen nach Neuinstallation nicht leer"
jobs="$(grep -c '<job>' "$NN_REPO_ROOT/nextnotes/appinfo/info.xml")"
grep -q "background jobs: $jobs" <<<"$out" || die "$env: Hintergrundjobs nach app:enable nicht neu registriert"
echo "$env: check-purge OK (purge, app:remove Exit 0, app:enable legt neu an)"
