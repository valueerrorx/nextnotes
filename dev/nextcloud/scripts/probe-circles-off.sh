#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# probe-circles-off.sh <env>: SPEC §13 M5 Krit. 3 über HTTP (auch nginx): Circles aus → private Workspaces normal, keine 500,
# Team-Workspace eingefroren (created_by liest, Mitglied 404, Schreiben 403/404), Zustand bleibt active; Circles an → wieder normal.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"

env="${1:?Aufruf: probe-circles-off.sh <env>}"
check_env "$env"
svc="$(svc_for "$env")"
base="$(base_url "$env")"
A="$NN_TEST_USER"; PA="$NN_TEST_PASS"; B="$NN_TEST_USER2"; PB="$NN_TEST_PASS2"
run="$(date +%s)$RANDOM"
out="$(mktemp)"; trap 'rm -f "$out"' EXIT
fail=0

# api <user> <pass> <method> <path> [json] → setzt $code, Body in $out
api() {
	local u="$1" p="$2" m="$3" path="$4" data="${5:-}" args=()
	[[ -n "$data" ]] && args=(-H 'Content-Type: application/json' --data "$data")
	code="$("${NN_CURL[@]}" -sS -o "$out" -w '%{http_code}' -u "$u:$p" -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
		-X "$m" "${args[@]}" "$base/index.php/apps/nextnotes/api/v1$path")"
}
check() { if [[ "$2" == *" $code "* ]]; then echo "ok   $1 ($code)"; else echo "FAIL $1: HTTP $code, erwartet$2 $(head -c 200 "$out")" >&2; fail=1; fi; }
php_nc() { dc "$env" exec -T -u www-data "$svc" php -r "require '/var/www/html/lib/base.php'; $1" -- "${@:2}"; }

if ! occ "$env" user:info "$B" >/dev/null 2>&1; then
	dc "$env" exec -T -u www-data -e OC_PASS="$PB" "$svc" php occ user:add --password-from-env "$B" >/dev/null
fi
occ "$env" app:enable circles >/dev/null
team="$(php_nc '$cm = \OCP\Server::get(\OCA\Circles\CirclesManager::class);
	$cm->startSession($cm->getLocalFederatedUser($argv[1]));
	try { $c = $cm->createCircle($argv[3], $cm->getLocalFederatedUser($argv[1]), false, false, false);
		$cm->addMember($c->getSingleId(), $cm->getLocalFederatedUser($argv[2])); echo $c->getSingleId(); }
	finally { $cm->stopSession(); }' "$A" "$B" "Frost $run")"
[[ "$team" =~ ^[A-Za-z0-9]+$ ]] || die "$env: Team nicht angelegt ($team)"
state() { php_nc '$qb = \OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
	echo (string)$qb->select("state")->from("nextnotes_teams")->where($qb->expr()->eq("team_id", $qb->createNamedParameter($argv[1])))->executeQuery()->fetchOne();' "$team"; }

api "$A" "$PA" POST /workspaces "{\"name\":\"Frost $run\",\"team\":\"$team\"}"; check 'Team-Workspace anlegen' ' 201 '
key="$(jq -r .name "$out")"
api "$B" "$PB" POST /notes "$(jq -nc --arg k "$key" '{heading:"Team", content:"<p>t</p>", workspace:$k}')"; check 'B schreibt im Team-Workspace' ' 200 201 '
n="$(jq -r .note.id "$out")"
api "$B" "$PB" POST /workspaces "{\"name\":\"Privat $run\"}"; check 'B privater Workspace' ' 201 '
api "$B" "$PB" POST /notes "$(jq -nc --arg k "Privat $run" '{heading:"Privat", content:"<p>p</p>", workspace:$k}')"; check 'B privat anlegen' ' 200 201 '
own="$(jq -r .note.id "$out")"

occ "$env" app:disable circles >/dev/null
trap 'occ "$env" app:enable circles >/dev/null 2>&1 || true; rm -f "$out"' EXIT
# APCu/App-Liste im Webserver: bis zu 30 s, bis der Team-Workspace eingefroren ist
for i in $(seq 1 30); do api "$B" "$PB" GET "/notes/$n"; [[ "$code" == 404 ]] && break; sleep 1; done
check 'eingefroren: B liest Team-Notiz nicht' ' 404 '
api "$B" "$PB" GET "/notes/$own"; check 'eingefroren: B liest privat' ' 200 '
api "$B" "$PB" PATCH "/notes/$own" '{"heading":"Privat","content":"<p>neu</p>"}'; check 'eingefroren: B schreibt privat' ' 200 '
for p in /workspaces /notes '/notes/search?q=t' /graph /tasks /folders /reminders /mentions /teams /tags; do
	for who in "$A:$PA" "$B:$PB"; do
		api "${who%%:*}" "${who#*:}" GET "$p"; check "eingefroren: ${who%%:*} GET $p ohne 500" ' 200 400 403 404 '
	done
done
for page in index.php settings.php dashboard.php; do
	code="$("${NN_CURL[@]}" -sS -o "$out" -w '%{http_code}' -u "$B:$PB" -H 'Accept: text/html' "$base/index.php/apps/nextnotes/$page")"
	check "eingefroren: Seite $page ohne 500" ' 200 302 303 '
done
api "$A" "$PA" GET "/notes/$n"; check 'eingefroren: created_by liest' ' 200 '
api "$A" "$PA" PATCH "/notes/$n" '{"heading":"x","content":"<p>x</p>"}'; check 'eingefroren: created_by schreibt nicht' ' 403 '
api "$B" "$PB" PATCH "/notes/$n" '{"heading":"x","content":"<p>x</p>"}'; check 'eingefroren: B schreibt nicht' ' 403 404 '
s="$(state)"; [[ "$s" == active ]] && echo "ok   Zustand bleibt active" || { echo "FAIL Zustand '$s' statt active" >&2; fail=1; }

occ "$env" app:enable circles >/dev/null
trap 'rm -f "$out"' EXIT
for i in $(seq 1 30); do api "$B" "$PB" GET "/notes/$n"; [[ "$code" == 200 ]] && break; sleep 1; done
check 'Circles an: B liest wieder' ' 200 '
api "$B" "$PB" PATCH "/notes/$n" '{"heading":"Team","content":"<p>wieder</p>"}'; check 'Circles an: B schreibt wieder' ' 200 '
s="$(state)"; [[ "$s" == active ]] && echo "ok   Zustand active" || { echo "FAIL Zustand '$s'" >&2; fail=1; }
# Aufräumen: Team zerstören, Team-Workspace und Team-Zeile löschen (folgende E2E-Läufe sehen nichts davon)
php_nc '$cm = \OCP\Server::get(\OCA\Circles\CirclesManager::class);
	$cm->startSession($cm->getLocalFederatedUser($argv[1])); try { $cm->destroyCircle($argv[2]); } finally { $cm->stopSession(); }
	$db = \OCP\Server::get(\OCP\IDBConnection::class);
	$qb = $db->getQueryBuilder();
	$qb->select("id")->from("nextnotes_workspaces")->where($qb->expr()->eq("owner_type", $qb->createNamedParameter("team")))
		->andWhere($qb->expr()->eq("owner_id", $qb->createNamedParameter($argv[2])));
	foreach ($qb->executeQuery()->fetchFirstColumn() as $w) { \OCP\Server::get(\OCA\NextNotes\Service\WorkspaceDeletion::class)->purge((int)$w); }
	$qb = $db->getQueryBuilder();
	$qb->delete("nextnotes_teams")->where($qb->expr()->eq("team_id", $qb->createNamedParameter($argv[2])))->executeStatement();' "$A" "$team" >/dev/null || true
[[ $fail -eq 0 ]] || die "$env: probe-circles-off rot"
echo "$env: probe-circles-off grün"
