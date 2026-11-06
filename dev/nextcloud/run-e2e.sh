#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# run-e2e.sh <env> [spec…]: Playwright-Specs (tests/nextnotes/e2e/<spec>.mjs) je Theme hell und dunkel; App muss aktiviert sein.
# Aufruf je Spec: node <spec>.mjs <base-url> <light|dark>; Zugang über NN_USER/NN_PASS, NN_USER2/NN_PASS2, PW_CORE, CHROMIUM.
# E2E_DIR= anderes Spec-Verzeichnis, THEMES= Auswahl (Standard "light dark"). Exit 0 nur wenn alle Läufe grün.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

env="${1:?Aufruf: run-e2e.sh <env> [spec…]}"; shift
check_env "$env"
dir="${E2E_DIR:-$NN_REPO_ROOT/tests/nextnotes/e2e}"
[[ -d "$NN_DEV_DIR/node_modules/playwright-core" ]] || npm --prefix "$NN_DEV_DIR" ci --no-audit --no-fund

specs=()
if [[ $# -gt 0 ]]; then
	for s in "$@"; do specs+=("$dir/${s%.mjs}.mjs"); done
else
	shopt -s nullglob
	specs=("$dir"/*.mjs)
	shopt -u nullglob
fi
[[ ${#specs[@]} -gt 0 ]] || die "keine Specs in $dir"
for s in "${specs[@]}"; do [[ -f "$s" ]] || die "Spec fehlt: $s"; done

# Benutzer (Sprache de), kein Einführungsdialog (Overlay, Netzlast), keine Datei-Empfehlungen
svc="$(svc_for "$env")"
for pair in "$NN_TEST_USER:$NN_TEST_PASS" "$NN_TEST_USER2:$NN_TEST_PASS2"; do
	u="${pair%%:*}"; p="${pair#*:}"
	if ! occ "$env" user:info "$u" >/dev/null 2>&1; then
		dc "$env" exec -T -u www-data -e OC_PASS="$p" "$svc" php occ user:add --password-from-env "$u" >/dev/null
	fi
	occ "$env" user:setting "$u" core lang de
done
# recommendations fragt auf der Dateien-Startseite Vorschauen an, die ohne Office-Provider 404 liefern (Rauschen, nicht App)
for a in firstrunwizard recommendations; do occ "$env" app:disable "$a" >/dev/null 2>&1 || true; done

export NN_USER="$NN_TEST_USER" NN_PASS="$NN_TEST_PASS" NN_USER2="$NN_TEST_USER2" NN_PASS2="$NN_TEST_PASS2"
export PW_CORE="$NN_DEV_DIR/node_modules/playwright-core" CHROMIUM="${CHROMIUM:-/usr/bin/chromium}"
failed=()
for theme in ${THEMES:-light dark}; do
	for u in "$NN_TEST_USER" "$NN_TEST_USER2"; do
		occ "$env" user:setting "$u" theming enabled-themes "[\"$theme\"]"
	done
	for s in "${specs[@]}"; do
		echo "--- $env $theme $(basename "$s")"
		t0=$SECONDS
		if ! timeout 600 node "$s" "$(base_url "$env")" "$theme"; then
			failed+=("$theme:$(basename "$s")")
		fi
		echo "--- $env $theme $(basename "$s"): $((SECONDS - t0)) s"
	done
done
if [[ ${#failed[@]} -gt 0 ]]; then
	echo "run-e2e $env: rot (${failed[*]})" >&2
	exit 1
fi
echo "run-e2e $env: grün (${#specs[@]} Specs × ${THEMES:-light dark})"
