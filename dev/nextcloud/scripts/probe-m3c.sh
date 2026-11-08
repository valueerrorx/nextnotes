#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# Nur Tests: HTTP-Probe M3c1 (Seiten-/Kompatibilitätsrouten, Fremdzugriff, Lesefreigabe). App muss aktiviert sein.
# Aufruf: scripts/probe-m3c.sh <env>; Benutzer alice/bob wie run-e2e.sh. Exit 0 nur wenn alle Prüfungen grün.
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
env="${1:?Aufruf: probe-m3c.sh <env>}"
check_env "$env"
B="$(base_url "$env")/index.php/apps/nextnotes"
svc="$(svc_for "$env")"
for pair in "$NN_TEST_USER:$NN_TEST_PASS" "$NN_TEST_USER2:$NN_TEST_PASS2"; do
	u="${pair%%:*}"; p="${pair#*:}"
	occ "$env" user:info "$u" >/dev/null 2>&1 || dc "$env" exec -T -u www-data -e OC_PASS="$p" "$svc" php occ user:add --password-from-env "$u" >/dev/null
done
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
fail=0; n=0
A=(-u "$NN_TEST_USER:$NN_TEST_PASS"); Bo=(-u "$NN_TEST_USER2:$NN_TEST_PASS2")
req() { local who="$1" m="$2" path="$3"; shift 3; local -n cred="$who"
	"${NN_CURL[@]}" -s -o "$tmp/body" -w '%{http_code}' -X "$m" "${cred[@]}" -H 'OCS-APIREQUEST: true' "$@" "$B/$path"; }
ok() { n=$((n+1)); if [[ "$1" == 1 ]]; then echo "ok   $2"; else echo "FAIL $2" >&2; fail=1; fi; }
expect() { local who="$1" want="$2" path="$3"; shift 3; local got; got="$(req "$who" GET "$path" "$@")"
	ok "$([[ "$got" == "$want" ]] && echo 1)" "$who GET $path → $got (erwartet $want)"
	if [[ "$who" == Bo && "$want" == 404 ]]; then ok "$(grep -q "$MARK" "$tmp/body" || echo 1)" "  kein A-Marker im Body"; fi; }
json() { php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$d=$d[$k]??null;} echo is_scalar($d)?$d:json_encode($d);' "$tmp/body" "$1"; }

MARK="Mk$(date +%s | tail -c 6)$RANDOM"
WS="Probe $MARK"
req A POST api/v1/workspaces -H 'Content-Type: application/json' -d "{\"name\":\"$WS\"}" >/dev/null
req A POST api/v1/folders -H 'Content-Type: application/json' -d "{\"folder_name\":\"Board $MARK\",\"workspace\":\"$WS\"}" >/dev/null; F="$(json folder_id)"
req A POST api/v1/folders -H 'Content-Type: application/json' -d "{\"folder_name\":\"Spalte\",\"parent_folder_id\":$F,\"workspace\":\"$WS\"}" >/dev/null; S="$(json folder_id)"
req A POST api/v1/notes -H 'Content-Type: application/json' -d "{\"heading\":\"Karte $MARK\",\"content\":\"<p>Text $MARK</p>\",\"folder_id\":$F,\"workspace\":\"$WS\"}" >/dev/null; N="$(json note.id)"
req A POST api/v1/notes -H 'Content-Type: application/json' -d "{\"heading\":\"Aufgaben $MARK\",\"type\":\"tasklist\",\"content\":\"[{\\\"id\\\":1,\\\"text\\\":\\\"Task $MARK\\\",\\\"completed\\\":false,\\\"dueAt\\\":\\\"2020-01-01\\\"}]\",\"folder_id\":$S,\"workspace\":\"$WS\"}" >/dev/null; T="$(json note.id)"
printf 'Anhang %s\n' "$MARK" > "$tmp/a.txt"
req A POST "api/v1/notes/$N/attachments" -F "file=@$tmp/a.txt" >/dev/null; AT="$(json attachment_id)"
echo "Daten: workspace=$WS folder=$F sub=$S note=$N tasklist=$T attachment=$AT"
[[ -n "$F" && -n "$S" && -n "$N" && -n "$T" && -n "$AT" ]] || { echo "FEHLER: Testdaten unvollständig" >&2; exit 1; }
WQ="workspace=$(php -r 'echo rawurlencode($argv[1]);' "$WS")"

# A (Besitzer): Seiten und Kompatibilitätsrouten
expect A 200 "kanban_content.php?ajax=1&folder_id=$F&$WQ"; ok "$(grep -q "Karte $MARK" "$tmp/body" && grep -q "Task $MARK" "$tmp/body" && grep -q 'data-action="create-kanban-note"' "$tmp/body" && echo 1)" "  Kanban: Karten, Aufgabe, Anlegen-Knopf"
ok "$(grep -q 'kanban-card-due overdue' "$tmp/body" && echo 1)" "  Kanban: überfällige Aufgabe markiert"
got="$(req A GET "kanban_content.php?folder_id=$F")"; ok "$([[ "$got" == 303 ]] && grep -qi "kanban=$F" <(curl -s -o /dev/null -D - "${A[@]}" "$B/kanban_content.php?folder_id=$F") && echo 1)" "A kanban_content.php ohne ajax → $got Weiterleitung index.php?kanban="
expect A 200 "revisions.php?note_id=$N"; ok "$(grep -q 'id="revisions-config"' "$tmp/body" && grep -q 'nextnotes-page-revisions' "$tmp/body" && echo 1)" "  Revisionen: Konfiguration und Seitenbündel"
expect A 200 "attachments.php?note_id=$N&$WQ"; ok "$(grep -q 'id="uploadBtn"' "$tmp/body" && ! grep -q nextnotes-readonly "$tmp/body" && echo 1)" "  Anhänge: Hochladen sichtbar"
expect A 200 "api_note_info.php?note_id=$N&$WQ"; ok "$([[ "$(json info.title)" == "Karte $MARK" && "$(json info.attachments)" =~ 1 ]] && echo 1)" "  Notiz-Info: Titel, 1 Anhang"
expect A 200 "api_download_note.php?id=$N"; ok "$(grep -q "Text $MARK" "$tmp/body" && echo 1)" "  Download: gespeicherter Inhalt"
expect A 501 "api_export_note.php?id=$N"
expect A 501 "api_export_folder.php?folder_id=$F"
expect A 200 "api_attachments.php?action=download&note_id=$N&attachment_id=$AT"; ok "$(grep -q "Anhang $MARK" "$tmp/body" && echo 1)" "  Alt-URL: Anhang ausgeliefert"
expect A 404 "api_attachments.php?action=delete&note_id=$N&attachment_id=$AT"
expect A 404 "revisions.php"
expect A 404 "kanban_content.php?ajax=1&folder_id=abc"

# B ohne Freigabe: jede ID-Stelle einzeln fremd → 404, kein Inhalt von A
expect Bo 404 "kanban_content.php?ajax=1&folder_id=$F"
expect Bo 404 "kanban_content.php?folder_id=$F"
expect Bo 404 "revisions.php?note_id=$N"
expect Bo 404 "attachments.php?note_id=$N"
expect Bo 404 "api_note_info.php?note_id=$N"
expect Bo 404 "api_download_note.php?id=$N"
expect Bo 404 "api_export_note.php?id=$N"
expect Bo 404 "api_export_folder.php?folder_id=$F"
expect Bo 404 "api_attachments.php?action=download&note_id=$N&attachment_id=$AT"
expect Bo 404 "audio_player.php?note=$N&attachment=$AT"
req Bo POST api/v1/notes -H 'Content-Type: application/json' -d "{\"heading\":\"Eigene $MARK\",\"content\":\"<p>b</p>\"}" >/dev/null; BN="$(json note.id)"
req Bo GET api/v1/folders >/dev/null
expect Bo 404 "api_attachments.php?action=download&note_id=$BN&attachment_id=$AT"
expect Bo 404 "revisions.php?note_id=$BN&$WQ"
expect Bo 404 "api_note_info.php?note_id=$BN&$WQ"
expect Bo 200 "api_note_info.php?note_id=$BN"
expect Bo 200 "revisions.php?note_id=$BN"

# Lesefreigabe A → B: Lesen 200 (Export 501), keine Schreib-Knöpfe
dc "$env" exec -T -u www-data "$svc" php -r '
require "/var/www/html/lib/base.php";
$ws = \OCP\Server::get(\OCA\NextNotes\Service\WorkspaceService::class);
$wsObj = $ws->resolveKey($argv[1], $argv[2]);
$ws->share($argv[1], (int)$wsObj->getId(), $argv[3], "read");
echo "Freigabe lesend: #" . $wsObj->getId() . "\n";' "$NN_TEST_USER" "$WS" "$NN_TEST_USER2"
expect Bo 200 "kanban_content.php?ajax=1&folder_id=$F"; ok "$(grep -q "Karte $MARK" "$tmp/body" && ! grep -q 'data-action="create-kanban-note"\|data-action="toggle-kanban-completed"\|data-action="rename-folder"' "$tmp/body" && echo 1)" "  Kanban lesend: Karten ohne Bearbeiten-Knöpfe"
expect Bo 200 "revisions.php?note_id=$N"
expect Bo 200 "attachments.php?note_id=$N"; ok "$(grep -q nextnotes-readonly "$tmp/body" && ! grep -q 'id="uploadBtn"' "$tmp/body" && echo 1)" "  Anhänge lesend: ohne Hochladen"
expect Bo 200 "api_note_info.php?note_id=$N"
expect Bo 200 "api_download_note.php?id=$N"
expect Bo 501 "api_export_note.php?id=$N"
expect Bo 501 "api_export_folder.php?folder_id=$F"
expect Bo 200 "api_attachments.php?action=download&note_id=$N&attachment_id=$AT"
got="$(req Bo DELETE "api/v1/notes/$N/attachments/$AT")"; ok "$([[ "$got" == 403 ]] && echo 1)" "Bo DELETE Anhang lesend → $got (erwartet 403)"
got="$(req Bo POST "api/v1/notes/$N/snapshot" -H 'Content-Type: application/json' -d '{}')"; ok "$([[ "$got" == 403 ]] && echo 1)" "Bo POST Revision lesend → $got (erwartet 403)"

echo "probe-m3c: $n Prüfungen, $([[ $fail -eq 0 ]] && echo grün || echo ROT)"
exit $fail
