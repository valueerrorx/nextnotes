#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# probe-grenzen.sh <env>: Großanhang-Export (Plan M4d Done 6, M4i Grenzen-Gesamtlauf) gegen eine laufende Apache-Umgebung.
# 200 MiB-Anhang → „alle Anhänge“-ZIP 200 und byte-gleich (sha256), danach Temp ohne Export-Ordner; Abbruch nach 1 MiB → Temp leer.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"

env="${1:?Aufruf: probe-grenzen.sh <env>}"
check_env "$env"
case "$env" in sqlite|mariadb|pgsql) ;; *) die "nur Apache-Umgebungen" ;; esac
svc="$(svc_for "$env")"
base="$(base_url "$env")/index.php/apps/nextnotes"
auth=(-u "$NN_TEST_USER:$NN_TEST_PASS" -H 'OCS-APIRequest: true' -H 'Accept: application/json')
work="$(mktemp -d)"; trap 'rm -rf "$work"; restore' EXIT
fail=0
bad() { echo "FAIL: $*" >&2; fail=1; }

# Grenzen nur für den Lauf anheben (Upstream-Standard 100 MiB, Quote)
old_file="$(occ "$env" config:app:get nextnotes attachment_max_file_mb 2>/dev/null || true)"
old_quota="$(occ "$env" config:app:get nextnotes attachment_quota_mb 2>/dev/null || true)"
restore() {
	for k in attachment_max_file_mb:"$old_file" attachment_quota_mb:"$old_quota"; do
		if [[ -n "${k#*:}" ]]; then occ "$env" config:app:set nextnotes "${k%%:*}" --value "${k#*:}" --type integer >/dev/null || true
		else occ "$env" config:app:delete nextnotes "${k%%:*}" >/dev/null || true; fi
	done
}
occ "$env" config:app:set nextnotes attachment_max_file_mb --value 256 --type integer >/dev/null
occ "$env" config:app:set nextnotes attachment_quota_mb --value 2048 --type integer >/dev/null

if ! occ "$env" user:info "$NN_TEST_USER" >/dev/null 2>&1; then
	dc "$env" exec -T -u www-data -e OC_PASS="$NN_TEST_PASS" "$svc" php occ user:add --password-from-env "$NN_TEST_USER" >/dev/null
fi
ws="Grenzen $(date +%s)"
"${NN_CURL[@]}" -sSf "${auth[@]}" -H 'Content-Type: application/json' -d "{\"name\":\"$ws\"}" "$base/api/v1/workspaces" >/dev/null
note="$("${NN_CURL[@]}" -sSf "${auth[@]}" -H 'Content-Type: application/json' \
	-d "{\"heading\":\"Großanhang\",\"content\":\"x\",\"workspace\":\"$ws\"}" "$base/api/v1/notes" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["note"]["id"];')"
head -c $((200 * 1024 * 1024)) /dev/urandom > "$work/gross.bin"
want="$(sha256sum "$work/gross.bin" | cut -d' ' -f1)"
code="$(curl --max-time 300 -sS "${auth[@]}" -o "$work/up.json" -w '%{http_code}' -F "file=@$work/gross.bin;type=application/octet-stream" "$base/api/v1/notes/$note/attachments")"
[[ "$code" == 200 || "$code" == 201 ]] || die "Upload HTTP $code: $(head -c 300 "$work/up.json")"

tmpdir="$(occ "$env" config:system:get tempdirectory 2>/dev/null || true)"; tmpdir="${tmpdir:-/tmp}"
leftover() { dc "$env" exec -T "$svc" sh -c "ls -d '$tmpdir'/oc_tmp_*.nextnotes-export* 2>/dev/null | wc -l"; }

out="$(curl --max-time 300 -sS "${auth[@]}" -o "$work/all.zip" -w '%{http_code} %{time_total}' "$base/api_export_attachments.php")"
code="${out% *}"; secs="${out#* }"
[[ "$code" == 200 ]] || bad "Export HTTP $code"
mkdir -p "$work/x" && (cd "$work/x" && unzip -q "$work/all.zip") || bad "ZIP nicht lesbar"
got="$(find "$work/x" -type f -size +100M -exec sha256sum {} + | cut -d' ' -f1 | grep -x "$want" || true)"
[[ "$got" == "$want" ]] || bad "Anhang im ZIP nicht byte-gleich"
echo "Export „alle Anhänge“ 200 MiB: HTTP $code, ${secs} s, sha256 $([[ "$got" == "$want" ]] && echo gleich || echo ABWEICHEND)"
sleep 2
n="$(leftover)"; [[ "$n" == 0 ]] || bad "nach Export $n Export-Ordner im Temp"
echo "Temp nach Export: $n Export-Ordner"

# Abbruch nach 1 MiB (curl endet mit SIGPIPE)
curl --max-time 300 -sS "${auth[@]}" "$base/api_export_attachments.php" 2>/dev/null | head -c 1048576 >/dev/null || true
for _ in $(seq 1 30); do n="$(leftover)"; [[ "$n" == 0 ]] && break; sleep 1; done
[[ "$n" == 0 ]] || bad "nach Abbruch $n Export-Ordner im Temp"
echo "Temp nach Abbruch: $n Export-Ordner"

# Aufräumen: Workspace löschen (Anhang mit)
"${NN_CURL[@]}" -sS "${auth[@]}" -X DELETE "$base/api/v1/workspaces/$(php -r 'echo rawurlencode($argv[1]);' "$ws")" >/dev/null || true
[[ $fail -eq 0 ]] && echo "probe-grenzen $env: grün" || { echo "probe-grenzen $env: ROT" >&2; exit 1; }
