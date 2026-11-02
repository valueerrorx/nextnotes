#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# up.sh <env|all>: startet eine Umgebung, wartet auf die Installation, legt Testnutzer an.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

up_one() {
	local env="$1" svc status i
	check_env "$env"
	svc="$(svc_for "$env")"
	dc "$env" up -d "$svc"
	for i in $(seq 1 120); do
		status="$(occ "$env" status --output=json 2>/dev/null || true)"
		if jq -e '.installed == true' >/dev/null 2>&1 <<<"$status"; then
			break
		fi
		[[ $i -eq 120 ]] && die "$env: Nextcloud nicht installiert nach 240 s"
		sleep 2
	done
	local ver; ver="$(jq -r '.versionstring' <<<"$status")"
	[[ "$ver" == "$NN_NC_VERSION" ]] || die "$env: Nextcloud $ver statt $NN_NC_VERSION"
	# Warten, bis der Webserver antwortet
	for i in $(seq 1 60); do
		curl -fs -o /dev/null "$(base_url "$env")/status.php" && break
		[[ $i -eq 60 ]] && die "$env: Webserver antwortet nicht"
		sleep 1
	done
	if ! occ "$env" user:info "$NN_TEST_USER" >/dev/null 2>&1; then
		dc "$env" exec -T -u www-data -e OC_PASS="$NN_TEST_PASS" "$svc" \
			php occ user:add --password-from-env "$NN_TEST_USER" >/dev/null
	fi
	echo "$env: bereit ($(base_url "$env"), Nextcloud $ver, Image $NC_APACHE_IMAGE)"
}

target="${1:?Aufruf: up.sh <env|all>}"
if [[ "$target" == all ]]; then
	for e in "${NN_ENVS[@]}"; do up_one "$e"; done
else
	up_one "$target"
fi
