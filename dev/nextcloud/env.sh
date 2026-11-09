# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# Gemeinsame Variablen und Hilfsfunktionen; wird von den Skripten per source geladen.

source "$(dirname "${BASH_SOURCE[0]}")/../portable.sh"
shopt -s inherit_errexit

NN_DEV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NN_REPO_ROOT="$(cd "$NN_DEV_DIR/../.." && pwd)"
NN_CACHE="$NN_DEV_DIR/.cache"
NN_ENVS=(sqlite mariadb pgsql nginx)
NN_ADMIN_USER=admin
NN_ADMIN_PASS=admin
NN_TEST_USER=alice
NN_TEST_PASS='NextNotes-Alice-2026!'
NN_TEST_USER2=bob
NN_TEST_PASS2='NextNotes-Bob-2026!'
NN_NC_VERSION=35.0.2
# Compose-Projekt und Ports überschreibbar (parallele Instanzen): NN_PROJECT=nextnotes-x NN_PORT_BASE=8190 → Ports 8191–8194
NN_PROJECT="${NN_PROJECT:-nextnotes-dev}"
NN_PORT_BASE="${NN_PORT_BASE:-8080}"
# Nur eigene Testprojekte (nie die Demo); Ports nicht von fremden Containern oder Prozessen belegt
if [[ ! "$NN_PROJECT" =~ ^nextnotes-[a-z0-9-]+$ || "$NN_PROJECT" == nextnotes-demo ]]; then
	echo "FEHLER: NN_PROJECT '$NN_PROJECT' unzulässig (nur nextnotes-<a-z0-9->, nie nextnotes-demo)" >&2; exit 1
fi
[[ "$NN_PORT_BASE" =~ ^[0-9]+$ ]] || { echo "FEHLER: NN_PORT_BASE '$NN_PORT_BASE' keine Zahl" >&2; exit 1; }
export NN_PORT_SQLITE=$((NN_PORT_BASE + 1)) NN_PORT_MARIADB=$((NN_PORT_BASE + 2)) NN_PORT_PGSQL=$((NN_PORT_BASE + 3)) NN_PORT_NGINX=$((NN_PORT_BASE + 4))
# Port belegt? Linux: ss, macOS: lsof; ohne beides keine Prüfung
nn_port_listening() {
	if command -v ss >/dev/null 2>&1; then [[ -n "$(ss -Hltn "sport = :$1" 2>/dev/null)" ]]; return; fi
	command -v lsof >/dev/null 2>&1 && [[ -n "$(lsof -nP -iTCP:"$1" -sTCP:LISTEN -t 2>/dev/null)" ]]
}
nn_check_ports() {
	local own=() foreign=() c proj ports p ids="" try
	# Container-Ports lesen: alle Container, auch gestoppte (z. B. nc35-gftest): Projekt und gebundene Host-Ports
	nn_read_ports() {
		own=(); foreign=(8090); ids=""
		command -v docker >/dev/null 2>&1 && ids="$(docker ps -aq 2>/dev/null || true)"
		[[ -n "$ids" ]] || return 0
		while read -r c proj ports; do
			[[ -n "$c" ]] || continue
			if [[ "$proj" == "$NN_PROJECT" ]]; then own+=($ports); else foreign+=($ports); fi
		done < <(docker inspect -f '{{.Name}} {{index .Config.Labels "com.docker.compose.project"}} {{range $k, $v := .HostConfig.PortBindings}}{{range $v}}{{.HostPort}} {{end}}{{end}}' $ids 2>/dev/null \
			| awk '{ if (NF == 1 || $2 ~ /^[0-9]+$/) { n = $1; $1 = ""; print n, "-", $0 } else print }')
	}
	nn_read_ports
	for p in "$NN_PORT_SQLITE" "$NN_PORT_MARIADB" "$NN_PORT_PGSQL" "$NN_PORT_NGINX"; do
		[[ " ${foreign[*]} " == *" $p "* ]] && { echo "FEHLER: Port $p gehört einem fremden Container oder der Demo (NN_PORT_BASE ändern)" >&2; exit 1; }
		# Lauscher ohne eigenen Container: parallele Umgebung desselben Projekts kann gerade neu starten
		# (Colima/Lima-Portweiterleitung per ssh folgt dem Container verzögert) → bis 10 s neu lesen
		for try in 1 2 3 4 5 6; do
			[[ " ${own[*]} " != *" $p "* ]] && nn_port_listening "$p" || break
			[[ $try -lt 6 ]] || { echo "FEHLER: Port $p ist schon belegt (NN_PORT_BASE ändern)" >&2; exit 1; }
			sleep 2; nn_read_ports
		done
	done
	return 0
}
nn_check_ports

# Browser für Playwright und check-css (CHROMIUM=, sonst gefunden)
nn_chromium

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
		sqlite) echo "$NN_PORT_SQLITE" ;;
		mariadb) echo "$NN_PORT_MARIADB" ;;
		pgsql) echo "$NN_PORT_PGSQL" ;;
		nginx) echo "$NN_PORT_NGINX" ;;
		*) die "kein Port für '$1'" ;;
	esac
}

base_url() { echo "http://localhost:$(port_for "$1")"; }

dc() {
	local profile="$1"; shift
	docker compose -p "$NN_PROJECT" -f "$NN_DEV_DIR/compose.yaml" --profile "$profile" "$@"
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
