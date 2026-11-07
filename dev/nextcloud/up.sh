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
	dc "$env" up -d
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
		"${NN_CURL[@]}" -fs -o /dev/null "$(base_url "$env")/status.php" && break
		[[ $i -eq 60 ]] && die "$env: Webserver antwortet nicht"
		sleep 1
	done
	# Kein externer Abruf aus der Testumgebung (z. B. password_policy-HIBP beim Anlegen von Testbenutzern)
	occ "$env" config:system:set has_internet_connection --type=boolean --value=false >/dev/null
	if ! occ "$env" user:info "$NN_TEST_USER" >/dev/null 2>&1; then
		dc "$env" exec -T -u www-data -e OC_PASS="$NN_TEST_PASS" "$svc" \
			php occ user:add --password-from-env "$NN_TEST_USER" >/dev/null
	fi
	# Circles-Loopback (R17): nginx/fpm erreicht sich nicht über localhost → Dienstname als Loopback und vertrauenswürdige Domain
	[[ "$env" == nginx ]] && occ "$env" config:system:set trusted_domains 1 --value=web-nginx >/dev/null
	# direkt nach Konfig-Änderungen antwortet der Loopback kurz mit 401 → bis zu 5 Versuche; ein Fehlschlag leert loopback_tmp_*
	for i in $(seq 1 5); do
		if [[ "$env" == nginx ]]; then
			occ "$env" config:app:set circles loopback_tmp_scheme --value=http >/dev/null
			occ "$env" config:app:set circles loopback_tmp_id --value=web-nginx >/dev/null
		fi
		occ "$env" circles:check --type loopback -n </dev/null >/dev/null 2>&1 && break
		[[ $i -eq 5 ]] && die "$env: circles:check loopback fehlgeschlagen"
		sleep 3
	done
	echo "$env: bereit ($(base_url "$env"), Nextcloud $ver)"
}

target="${1:?Aufruf: up.sh <env|all>}"
if [[ "$target" == all ]]; then
	for e in "${NN_ENVS[@]}"; do up_one "$e"; done
else
	up_one "$target"
fi
