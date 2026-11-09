#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# msmock.sh <env> up|down|ctl <query>: OneNote-Mock (Profil msmock, M9a) im Projektnetz der Umgebung.
# up: Test-CA + Serverzertifikat (SAN login.microsoftonline.com, graph.microsoft.com) in .cache/msmock, Mock starten,
#     CA per occ security:certificates:import, allow_local_remote_servers=true NUR hier. down: alles zurück, Mock weg.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

env="${1:?Aufruf: msmock.sh <env> up|down|ctl <query>}"
action="${2:?Aufruf: msmock.sh <env> up|down|ctl <query>}"
check_env "$env"
case "$env" in sqlite|mariadb|pgsql) ;; *) die "msmock nur mit sqlite, mariadb oder pgsql" ;; esac
svc="$(svc_for "$env")"
certs="$NN_CACHE/msmock"
mock() { docker compose -p "$NN_PROJECT" -f "$NN_DEV_DIR/compose.yaml" --profile msmock "$@"; }
ctl() { dc "$env" exec -T "$svc" curl -fsS --max-time 10 "http://ms-mock/__ctl?$1"; }

make_certs() {
	[[ -s "$certs/server.crt" && -s "$certs/ca.crt" ]] && openssl x509 -checkend 86400 -noout -in "$certs/server.crt" >/dev/null 2>&1 && return 0
	mkdir -p "$certs"
	local tmp; tmp="$(mktemp -d)"
	openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=Next Notes msmock test CA" \
		-keyout "$tmp/ca.key" -out "$certs/ca.crt" -addext basicConstraints=critical,CA:TRUE -addext keyUsage=critical,keyCertSign,cRLSign 2>/dev/null
	openssl req -newkey rsa:2048 -nodes -subj "/CN=graph.microsoft.com" -keyout "$certs/server.key" -out "$tmp/server.csr" 2>/dev/null
	printf 'subjectAltName=DNS:login.microsoftonline.com,DNS:graph.microsoft.com\nextendedKeyUsage=serverAuth\nbasicConstraints=CA:FALSE\n' > "$tmp/ext"
	openssl x509 -req -in "$tmp/server.csr" -CA "$certs/ca.crt" -CAkey "$tmp/ca.key" -CAcreateserial -days 30 -extfile "$tmp/ext" -out "$certs/server.crt" 2>/dev/null
	# Mock-Prozess (www-data) liest den Schlüssel; Wegwerf-Schlüssel nur für Tests
	chmod 644 "$certs/server.key" "$certs/server.crt" "$certs/ca.crt"
	rm -rf "$tmp"
}

case "$action" in
	up)
		make_certs
		mock up -d ms-mock
		for i in $(seq 1 60); do
			ctl op=reset >/dev/null 2>&1 && break
			[[ $i -eq 60 ]] && die "Mock antwortet nicht"
			sleep 1
		done
		dc "$env" exec -T -u www-data "$svc" sh -c 'cat > /tmp/msmock-ca.crt' < "$certs/ca.crt"
		occ "$env" security:certificates:import /tmp/msmock-ca.crt >/dev/null
		occ "$env" config:system:set allow_local_remote_servers --value=true --type=boolean >/dev/null
		# TLS mit der Test-CA aus dem NC-Container: Graph ohne Token → 401 vom Mock
		code="$(dc "$env" exec -T "$svc" curl -sS -o /dev/null -w '%{http_code}' --cacert /tmp/msmock-ca.crt https://graph.microsoft.com/v1.0/me/onenote/notebooks)"
		[[ "$code" == 401 ]] || die "Mock über TLS nicht erreichbar (HTTP $code)"
		ctl op=reset >/dev/null
		echo "$env: msmock bereit (login.microsoftonline.com, graph.microsoft.com → ms-mock, CA importiert, allow_local_remote_servers=true)"
		;;
	down)
		occ "$env" config:system:delete allow_local_remote_servers >/dev/null 2>&1 || true
		occ "$env" security:certificates:remove msmock-ca.crt >/dev/null 2>&1 || true
		mock rm -sf ms-mock >/dev/null 2>&1 || true
		echo "$env: msmock entfernt"
		;;
	ctl)
		ctl "${3:?Aufruf: msmock.sh <env> ctl <query>}"
		echo
		;;
	*) die "Aktion up|down|ctl" ;;
esac
