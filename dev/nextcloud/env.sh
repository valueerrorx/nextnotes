# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# Gemeinsame Variablen und Hilfsfunktionen; wird von den Skripten per source geladen.

shopt -s inherit_errexit

NN_DEV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NN_REPO_ROOT="$(cd "$NN_DEV_DIR/../.." && pwd)"
NN_CACHE="$NN_DEV_DIR/.cache"
NN_ENVS=(sqlite)
NN_ADMIN_USER=admin
NN_ADMIN_PASS=admin
NN_TEST_USER=alice
NN_TEST_PASS='NextNotes-Alice-2026!'
NN_NC_VERSION=35.0.2

# Gepinntes Image bevorzugen, sonst lokales nextcloud:35 (Version wird in up.sh geprüft).
if [[ -z "${NC_APACHE_IMAGE:-}" ]]; then
	if docker image inspect nextcloud:35.0.2-apache >/dev/null 2>&1; then
		NC_APACHE_IMAGE=nextcloud:35.0.2-apache
	else
		NC_APACHE_IMAGE=nextcloud:35
	fi
fi
export NC_APACHE_IMAGE

die() { echo "FEHLER: $*" >&2; exit 1; }

check_env() {
	local e
	for e in "${NN_ENVS[@]}"; do [[ "$1" == "$e" ]] && return 0; done
	die "unbekannte Umgebung '$1' (erlaubt: ${NN_ENVS[*]})"
}

svc_for() {
	case "$1" in
		sqlite) echo nc-sqlite ;;
		*) die "keine Dienstzuordnung für '$1'" ;;
	esac
}

port_for() {
	case "$1" in
		sqlite) echo 8081 ;;
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
