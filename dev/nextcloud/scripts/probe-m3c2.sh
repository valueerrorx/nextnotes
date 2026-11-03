#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# Nur Tests: HTTP-Probe M3c2 (excalidraw_editor.php, api_save_excalidraw.php, workspaces.php POST; Fremdzugriff, Lesefreigabe, Sperre).
# Aufruf: scripts/probe-m3c2.sh <env>; Benutzer alice/bob wie run-e2e.sh. Exit 0 nur wenn alle Prüfungen grün.
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
env="${1:?Aufruf: probe-m3c2.sh <env>}"
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
expect() { local who="$1" want="$2" m="$3" path="$4"; shift 4; local got; got="$(req "$who" "$m" "$path" "$@")"
	ok "$([[ "$got" == "$want" ]] && echo 1)" "$who $m $path → $got (erwartet $want)"
	[[ "$got" == "$want" ]] || head -c 300 "$tmp/body" >&2
	if [[ "$who" == Bo && "$want" =~ ^40[34]$ ]]; then ok "$(grep -q "$MARK" "$tmp/body" || echo 1)" "  kein A-Marker im Body"; fi; }
json() { php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$d=$d[$k]??null;} echo is_scalar($d)?$d:json_encode($d);' "$tmp/body" "$1"; }
note() { req A GET "api/v1/notes/$1" >/dev/null; json note.content; }
atts() { req A GET "api/v1/notes/$1/attachments" >/dev/null; php -r 'echo count(json_decode(file_get_contents($argv[1]),true)["attachments"]??[]);' "$tmp/body"; }
wsjson() { req A GET api/v1/workspaces >/dev/null; cat "$tmp/body"; }

MARK="Mk$(date +%s%N | tail -c 9)"
WS="Probe $MARK"; WS2="Ziel $MARK"
XSS='<img src=x onerror=alert(1)>'
req A POST api/v1/workspaces -H 'Content-Type: application/json' -d "{\"name\":\"$WS\"}" >/dev/null
req A POST api/v1/workspaces -H 'Content-Type: application/json' -d "{\"name\":\"$WS2\"}" >/dev/null
req A POST api/v1/notes -H 'Content-Type: application/json' -d "{\"heading\":\"Zeichnung $MARK $XSS\",\"content\":\"<p>Hallo $MARK Welt</p>\",\"workspace\":\"$WS\"}" >/dev/null; N="$(json note.id)"
req A POST api/v1/notes -H 'Content-Type: application/json' -d "{\"heading\":\"MD $MARK\",\"type\":\"markdown\",\"content\":\"Zeile 1\\nZeile 2 $MARK\",\"workspace\":\"$WS\"}" >/dev/null; M="$(json note.id)"
echo "Daten: workspace=$WS note=$N markdown=$M"
[[ -n "$N" && -n "$M" ]] || { echo "FEHLER: Testdaten unvollständig" >&2; exit 1; }
WQ="workspace=$(php -r 'echo rawurlencode($argv[1]);' "$WS")"
DATA='{"type":"excalidraw","elements":[{"id":"e1","type":"text","text":"metadata: javascript:x <b> \"q\""}],"appState":{},"files":{}}'
SVG='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="0" y="0" width="10" height="10" fill="#ffffff"/><path d="M0 0L10 10" stroke="#000"/></svg>'
save() { local who="$1"; shift; req "$who" POST api_save_excalidraw.php "$@"; }

# Seite: A (Besitzer)
expect A 200 GET "excalidraw_editor.php?note_id=$N&diagram_id=excalidraw-1&$WQ"
ok "$(grep -q 'id="excalidraw-config"' "$tmp/body" && grep -q 'id="saveBtn"' "$tmp/body" && grep -q 'nextnotes-page-excalidraw_editor' "$tmp/body" && grep -q 'excalidraw-bundle.iife' "$tmp/body" && echo 1)" "  Editor: Konfiguration, Speichern-Knopf, Bündel"
ok "$(! grep -qF "$XSS" "$tmp/body" && grep -qF '&lt;img src=x onerror=alert(1)&gt;' "$tmp/body" && echo 1)" "  XSS: Titel escaped"
expect A 200 GET "excalidraw_editor.php?note_id=$N"
expect A 404 GET "excalidraw_editor.php"
expect A 404 GET "excalidraw_editor.php?note_id=$N&diagram_id=a%22b"
expect A 404 GET "excalidraw_editor.php?note_id=999999999"
csp="$("${NN_CURL[@]}" -s -o /dev/null -D - "${A[@]}" "$B/excalidraw_editor.php?note_id=$N" | grep -i '^content-security-policy' | tr -d '\r')"
ok "$([[ -n "$csp" && "$csp" != *unsafe-eval* && "$csp" != *wasm-unsafe-eval* && "$csp" != *"worker-src 'self' blob:"* ]] && echo 1)" "  CSP ohne Ausnahme: ${csp:0:160}"

# Speichern: CSRF, Eingaben, Einbetten, Ersetzen, Vorschau
got="$("${NN_CURL[@]}" -s -o /dev/null -w '%{http_code}' -X POST "${A[@]}" -F action=save_embedded_diagram -F "note_id=$N" -F diagram_id=excalidraw-1 --form-string "diagram_data=$DATA" "$B/api_save_excalidraw.php")"
ok "$([[ "$got" == 412 ]] && echo 1)" "A POST ohne requesttoken → $got (erwartet 412)"
expect A 400 POST api_save_excalidraw.php -F action=save_embedded_diagram -F "note_id=$N" -F 'diagram_id=x"y' --form-string "diagram_data=$DATA"
expect A 400 POST api_save_excalidraw.php -F action=save_embedded_diagram -F "note_id=$N" -F diagram_id=excalidraw-1 -F 'diagram_data=nojson'
expect A 400 POST api_save_excalidraw.php -F action=save_embedded_diagram -F "note_id=$N" -F diagram_id=excalidraw-1 --form-string "diagram_data=$DATA" --form-string 'preview_svg=<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'
expect A 400 POST api_save_excalidraw.php -F "note_id=0" --form-string "diagram_data=$DATA"
expect A 200 POST api_save_excalidraw.php -F action=save_embedded_diagram -F "note_id=$N" -F diagram_id=excalidraw-1 -F "workspace=$WS" --form-string "diagram_data=$DATA" --form-string "preview_svg=$SVG" -F cursor_position=5
c="$(note "$N")"
ok "$([[ "$c" == *'id="excalidraw-1"'* && "$c" == *"/attachments/"* && "$c" == *"Welt"* && "$c" == *excalidraw-placeholder* ]] && echo 1)" "  Inhalt: Container mit Vorschau, Text erhalten"
a1="$(atts "$N")"; ok "$([[ "$a1" == 1 ]] && echo 1)" "  1 Vorschau-Anhang ($a1)"
expect A 200 GET "excalidraw_editor.php?note_id=$N&diagram_id=excalidraw-1"
ok "$(grep -q 'metadata: javascript:x' "$tmp/body" && echo 1)" "  Editor lädt gespeichertes Diagramm (data:/javascript:-Text erhalten, B35)"
expect A 200 POST api_save_excalidraw.php -F action=save_embedded_diagram -F "note_id=$N" -F diagram_id=excalidraw-1 --form-string "diagram_data=$DATA" --form-string "preview_svg=$SVG"
c2="$(note "$N")"; a2="$(atts "$N")"
ok "$([[ "$a2" == 1 && "$(grep -o 'excalidraw-container' <<<"$c2" | wc -l)" == 1 && "$(grep -o 'max-width: 100%' <<<"$c2" | wc -l)" == 1 ]] && echo 1)" "  2. Speichern: ersetzt, alte Vorschau entfernt ($a2), Stil nicht verdoppelt (B36)"
expect A 200 POST api_save_excalidraw.php -F action=save_embedded_diagram -F "note_id=$M" -F diagram_id=excalidraw-2 --form-string "diagram_data=$DATA" --form-string "preview_svg=$SVG" -F cursor_position=7
expect A 200 POST api_save_excalidraw.php -F action=save_embedded_diagram -F "note_id=$M" -F diagram_id=excalidraw-2 --form-string "diagram_data=$DATA" --form-string "preview_svg=$SVG"
cm="$(note "$M")"; ok "$([[ "$cm" == "Zeile 1"* && "$cm" == *"Zeile 2 $MARK" && "$(grep -o 'excalidraw-container' <<<"$cm" | wc -l)" == 1 ]] && echo 1)" "  Markdown: an Cursor eingefügt, ersetzt"
expect A 200 POST api_save_excalidraw.php -F "note_id=$N" --form-string "diagram_data=$DATA" --form-string "preview_svg=$SVG"
c3="$(note "$N")"; ok "$([[ "$c3" == *'class="excalidraw-data"'* && "$c3" == *'id="excalidraw-1"'* ]] && echo 1)" "  Vollmodus: Container angehängt, eingebettetes Diagramm bleibt"
expect A 200 GET "excalidraw_editor.php?note_id=$N"; ok "$(grep -q 'metadata: javascript:x' "$tmp/body" && echo 1)" "  Vollmodus: Editor lädt Diagramm"

# B ohne Freigabe: 404, nichts geändert
before="$(note "$N")"
expect Bo 404 GET "excalidraw_editor.php?note_id=$N&diagram_id=excalidraw-1"
expect Bo 404 GET "excalidraw_editor.php?note_id=$N"
expect Bo 404 POST api_save_excalidraw.php -F action=save_embedded_diagram -F "note_id=$N" -F diagram_id=excalidraw-1 --form-string "diagram_data=$DATA" --form-string "preview_svg=$SVG"
expect Bo 404 POST api_save_excalidraw.php -F "note_id=$N" --form-string "diagram_data=$DATA"
for act in "action=rename&name=$WS&new_name=Neu" "action=delete&name=$WS" "action=set_color&name=$WS&color=%23ff0000" "action=set_tags&name=$WS&tags=x" "action=reorder&names[]=$WS" "action=move_notes&name=$WS&target=$WS2" "action=share_workspace&name=$WS"; do
	expect Bo 404 POST workspaces.php --data "$act"
done
ok "$([[ "$(note "$N")" == "$before" ]] && echo 1)" "  A-Notiz unverändert"
req Bo POST api/v1/notes -H 'Content-Type: application/json' -d "{\"heading\":\"Eigene $MARK\",\"content\":\"<p>b</p>\"}" >/dev/null; BN="$(json note.id)"
expect Bo 404 GET "excalidraw_editor.php?note_id=$BN&$WQ"
expect Bo 200 GET "excalidraw_editor.php?note_id=$BN"

# Lesefreigabe: ansehen 200 ohne Speichern, schreiben 403
dc "$env" exec -T -u www-data "$svc" php -r '
require "/var/www/html/lib/base.php";
$ws = \OCP\Server::get(\OCA\NextNotes\Service\WorkspaceService::class);
$o = $ws->resolveKey($argv[1], $argv[2]);
$ws->share($argv[1], (int)$o->getId(), $argv[3], "read");' "$NN_TEST_USER" "$WS" "$NN_TEST_USER2"
wsBefore="$(wsjson)"
expect Bo 200 GET "excalidraw_editor.php?note_id=$N&diagram_id=excalidraw-1&$WQ"
ok "$(! grep -q 'id="saveBtn"' "$tmp/body" && grep -q 'id="cancelBtn"' "$tmp/body" && grep -qF '"readOnly":true' "$tmp/body" && echo 1)" "  lesend: ohne Speichern, readOnly"
expect Bo 403 POST api_save_excalidraw.php -F action=save_embedded_diagram -F "note_id=$N" -F diagram_id=excalidraw-1 --form-string "diagram_data=$DATA" --form-string "preview_svg=$SVG"
expect Bo 403 POST api_save_excalidraw.php -F "note_id=$N" --form-string "diagram_data=$DATA"
for act in "action=rename&name=$WS&new_name=Neu" "action=delete&name=$WS" "action=set_color&name=$WS&color=%23ff0000" "action=set_tags&name=$WS&tags=x" "action=reorder&names[]=$WS" "action=move_notes&name=$WS&target=$WS" "action=share_workspace&name=$WS" "action=unshare_workspace&name=$WS"; do
	expect Bo 403 POST workspaces.php --data "$act"
done
ok "$([[ "$(note "$N")" == "$before" && "$(wsjson)" == "$wsBefore" && "$(atts "$N")" == 2 ]] && echo 1)" "  A-Zustand unverändert (Notiz, Workspaces, Anhänge)"

# Sperre (ADR 0005): fremde gültige Sperre → 423
dc "$env" exec -T -u www-data "$svc" php -r '
require "/var/www/html/lib/base.php";
$ws = \OCP\Server::get(\OCA\NextNotes\Service\WorkspaceService::class);
$o = $ws->resolveKey($argv[1], $argv[2]);
$ws->unshare($argv[1], (int)$o->getId(), $argv[3]);
$ws->share($argv[1], (int)$o->getId(), $argv[3], "write");' "$NN_TEST_USER" "$WS" "$NN_TEST_USER2"
req Bo POST "api/v1/notes/$N/lock" -H 'X-Editor-Session-ID: probe-b' >/dev/null
expect A 423 POST api_save_excalidraw.php -F action=save_embedded_diagram -F "note_id=$N" -F diagram_id=excalidraw-1 --form-string "diagram_data=$DATA"
req Bo POST "api/v1/notes/$N/lock/release" -H 'X-Editor-Session-ID: probe-b' >/dev/null
expect A 200 POST api_save_excalidraw.php -F action=save_embedded_diagram -F "note_id=$N" -F diagram_id=excalidraw-1 --form-string "diagram_data=$DATA"

# workspaces.php: Besitzer
expect A 200 POST workspaces.php --data "action=set_color&name=$WS2&color=%23ff0000"; ok "$([[ "$(json color)" == "#ff0000" ]] && echo 1)" "  Farbe gesetzt"
expect A 200 POST workspaces.php --data "action=set_tags&name=$WS2&tags=a,b"
expect A 400 POST workspaces.php --data "action=set_color&name=$WS2&color=red;x"
expect A 501 POST workspaces.php --data "action=share_workspace&name=$WS2"
expect A 400 POST workspaces.php --data "action=frobnicate&name=$WS2"
expect A 200 POST workspaces.php --data "action=reorder&names[]=$(php -r 'echo rawurlencode($argv[1]);' "$WS2")&names[]=$(php -r 'echo rawurlencode($argv[1]);' "$WS")"
ok "$(php -r '$l=array_column(json_decode($argv[1],true)["workspaces"],"name"); echo array_search($argv[2],$l) < array_search($argv[3],$l) ? 1 : 0;' "$(wsjson)" "$WS2" "$WS")" "  Reihenfolge gespeichert"
expect A 200 POST workspaces.php --data "action=move_notes&name=$WS&target=$WS2"; ok "$([[ "$(json moved)" -ge 2 ]] && echo 1)" "  Notizen verschoben ($(json moved))"
expect A 200 POST workspaces.php --data "action=rename&name=$WS2&new_name=Umbenannt $MARK"; ok "$([[ "$(json name)" == "Umbenannt $MARK" ]] && echo 1)" "  umbenannt"
expect A 400 POST workspaces.php --data "action=rename&name=Umbenannt $MARK&new_name=bad%3Cx"
expect A 404 POST workspaces.php --data "action=rename&name=Gibtsnicht $MARK&new_name=x"
expect A 200 POST workspaces.php --data "action=delete&name=$WS"
got="$("${NN_CURL[@]}" -s -o /dev/null -w '%{http_code}' -X POST "${A[@]}" --data "action=delete&name=Umbenannt $MARK" "$B/workspaces.php")"
ok "$([[ "$got" == 412 ]] && echo 1)" "A workspaces.php ohne requesttoken → $got (erwartet 412)"

echo "probe-m3c2: $n Prüfungen, $([[ $fail -eq 0 ]] && echo grün || echo ROT)"
exit $fail
