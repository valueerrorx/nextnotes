# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# Gemeinsame Variablen und Hilfsfunktionen; wird von den Skripten per source geladen.

shopt -s inherit_errexit

NN_DEV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NN_REPO_ROOT="$(cd "$NN_DEV_DIR/../.." && pwd)"
NN_CACHE="$NN_DEV_DIR/.cache"
NN_ENVS=(sqlite mariadb pgsql nginx)
NN_ADMIN_USER=admin
NN_ADMIN_PASS=admin
NN_TEST_USER=alice
NN_TEST_PASS='NextNotes-Alice-2026!'
NN_NC_VERSION=35.0.2

# Alle Aufrufe mit Zeitlimit, damit ein hängender Webserver nicht blockiert
NN_CURL=(curl --max-time 30)

die() { echo "FEHLER: $*" >&2; exit 1; }

check_env() {
	local e
	for e in "${NN_ENVS[@]}"; do [[ "$1" == "$e" ]] && return 0; done
	die "unbekannte Umgebung '$1' (erlaubt: ${NN_ENVS[*]})"
}

# Dienst, in dem occ läuft
svc_for() {
	case "$1" in
		sqlite|mariadb|pgsql) echo "nc-$1" ;;
		nginx) echo nc-fpm ;;
		*) die "keine Dienstzuordnung für '$1'" ;;
	esac
}

# Benannte Volumes einer Umgebung (für down.sh)
vols_for() {
	case "$1" in
		sqlite) echo nc-sqlite ;;
		mariadb) echo nc-mariadb db-mariadb ;;
		pgsql) echo nc-pgsql db-pgsql ;;
		nginx) echo nc-nginx ;;
		*) die "keine Volumes für '$1'" ;;
	esac
}

port_for() {
	case "$1" in
		sqlite) echo 8081 ;;
		mariadb) echo 8082 ;;
		pgsql) echo 8083 ;;
		nginx) echo 8084 ;;
		*) die "kein Port für '$1'" ;;
	esac
}

base_url() { echo "http://localhost:$(port_for "$1")"; }

dc() {
	local profile="$1"; shift
	docker compose -f "$NN_DEV_DIR/compose.yaml" --profile "$profile" "$@"
}

# occ <env> <args…>
occ() {
	local env="$1"; shift
	dc "$env" exec -T -u www-data "$(svc_for "$env")" php occ "$@"
}

# probe <env> insert|count – PHP-Skript über stdin, nichts wird in den Container kopiert
probe() {
	local env="$1"; shift
	dc "$env" exec -T -u www-data "$(svc_for "$env")" php -- "$@" < "$NN_DEV_DIR/scripts/probe-row.php"
}
