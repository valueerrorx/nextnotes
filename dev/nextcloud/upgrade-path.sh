#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# upgrade-path.sh <env> <from-ref>: alter Stand aus git → Beispieldaten → M7a-Paket → occ upgrade → Schema, Migrationen, Jobs, Daten, smoke-m6 (M7e).
# NN_APP_TARBALL=<paket> (Standard nextnotes/dist/nextnotes-<Version>.tar.gz aus make dist), NN_UPGRADE_BREAK=schema|jobs (Gegenprobe, muss rot sein),
# NN_UPGRADE_E2E=0 ohne smoke-m6, KEEP=1 lässt die Umgebung stehen. Frische Referenz je DB und Paket in .cache/upgrade/. Exit 0 = grün.
set -euo pipefail
dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$dir/env.sh"

env="${1:?Aufruf: upgrade-path.sh <env> <from-ref>}"
ref="${2:?Aufruf: upgrade-path.sh <env> <from-ref>}"
check_env "$env"
brk="${NN_UPGRADE_BREAK:-}"
[[ -z "$brk" || "$brk" == schema || "$brk" == jobs ]] || die "NN_UPGRADE_BREAK nur schema|jobs"
db="$env"; [[ "$env" == mariadb ]] && db=mysql; [[ "$env" == nginx ]] && db=sqlite
step() { echo "== $env ($ref): $*"; }
fails=()
fail() { echo "FAIL $*" >&2; fails+=("$*"); }
xmlver() { sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$1" | head -n1; }
nnprobe() { dc "$env" exec -T -u www-data "$(svc_for "$env")" php -- "$@" < "$dir/scripts/upgrade-probe.php"; }
appver() { occ "$env" app:list --output=json | jq -r '.enabled.nextnotes // empty'; }

# Paket (M7a)
pkg="${NN_APP_TARBALL:-$NN_REPO_ROOT/nextnotes/dist/nextnotes-$(xmlver "$NN_REPO_ROOT/nextnotes/appinfo/info.xml").tar.gz}"
[[ -f "$pkg" ]] || die "Paket fehlt: $pkg (make -C nextnotes dist)"
"$dir/check-dist.sh" "$pkg" >/dev/null
nn_need_gnu_tar
work="$(mktemp -d)"
wt="$work/src"
cleanup() {
	git -C "$NN_REPO_ROOT" worktree remove --force "$wt" >/dev/null 2>&1 || true
	git -C "$NN_REPO_ROOT" worktree prune
	rm -rf "$work"
	[[ "${KEEP:-0}" == 1 ]] || "$dir/down.sh" "$env" >/dev/null 2>&1 || true
}
trap cleanup EXIT
tar -xzf "$pkg" -O nextnotes/appinfo/info.xml > "$work/pkg-info.xml"
pkgver="$(xmlver "$work/pkg-info.xml")"
mapfile -t pjobs < <(sed -n 's:.*<job>\([^<]*\)</job>.*:\1:p' "$work/pkg-info.xml")
tar -tzf "$pkg" | sed -n 's:^nextnotes/lib/Migration/Version\([0-9A-Za-z_]*\)\.php$:\1:p' | sort > "$work/pkg-migrations"
pkgsha="$(nn_sha256 "$pkg" | cut -c1-12)"
echo "upgrade-path: $env, von $ref nach Paket $(basename "$pkg") ($pkgsha), ${#pjobs[@]} Jobs, $(wc -l < "$work/pkg-migrations") Migrationen"

# nur die Tabellen der App, Schlüssel sortiert
nn_schema() { occ "$env" db:schema:export --output=json | jq -S 'with_entries(select(.key | startswith("oc_nextnotes_")))'; }

# Frische Referenz: Paket direkt installiert (gleiche DB, gleiches Paket)
fresh="$NN_CACHE/upgrade/fresh-$env-$pkgsha.json"
if [[ ! -s "$fresh" ]]; then
	step "frische Referenz aus dem Paket"
	mkdir -p "$NN_CACHE/upgrade"
	"$dir/down.sh" "$env" >/dev/null 2>&1 || true
	"$dir/up.sh" "$env"
	NN_APP_TARBALL="$pkg" "$dir/install-app.sh" "$env"
	occ "$env" app:enable nextnotes
	nn_schema > "$fresh.tmp" && mv "$fresh.tmp" "$fresh"
fi

step "alten Stand bauen"
git -C "$NN_REPO_ROOT" worktree add --detach --force "$wt" "$ref" >/dev/null 2>&1 || die "git worktree add $ref fehlgeschlagen"
fromver="$(xmlver "$wt/nextnotes/appinfo/info.xml")"
assets=keine
if [[ -f "$wt/nextnotes/Makefile" ]] && grep -q '^build:' "$wt/nextnotes/Makefile"; then
	if cmp -s "$wt/nextnotes/build/package-lock.json" "$NN_REPO_ROOT/nextnotes/build/package-lock.json" && [[ -d "$NN_REPO_ROOT/nextnotes/build/node_modules" ]]; then
		ln -s "$NN_REPO_ROOT/nextnotes/build/node_modules" "$wt/nextnotes/build/node_modules"
		make -C "$wt/nextnotes" build >/dev/null
		assets=gebaut
	else
		# Plan M7 §3: abweichende Lockfile → Schema-Upgrade ohne Assets (kein Netz, kein npm install)
		echo "WARNUNG: Lockfile von $ref weicht ab, Upgrade ohne Assets des alten Stands" >&2
		assets="fehlen (Lockfile)"
	fi
fi
echo "alter Stand: $(git -C "$wt" log -1 --format='%h %s' | cut -c1-70), Version $fromver, Assets $assets"

step "alten Stand installieren"
"$dir/down.sh" "$env" >/dev/null 2>&1 || true
"$dir/up.sh" "$env"
excl=()
while IFS= read -r p; do [[ -z "$p" || "$p" == \#* ]] || excl+=("--exclude=$p"); done < "$NN_REPO_ROOT/nextnotes/build/dist-exclude.txt"
nn_tar -C "$wt/nextnotes" --anchored "${excl[@]}" --exclude=./build -cf - . \
	| dc "$env" exec -T -u root "$(svc_for "$env")" sh -c \
		"rm -rf /var/www/html/custom_apps/nextnotes && mkdir -p /var/www/html/custom_apps/nextnotes && tar -xf - -C /var/www/html/custom_apps/nextnotes && chown -R www-data:www-data /var/www/html/custom_apps/nextnotes"
occ "$env" app:enable nextnotes
[[ "$(appver)" == "$fromver" ]] || die "alte Version nicht aktiv: '$(appver)' statt $fromver"
if ! occ "$env" user:info "$NN_TEST_USER2" >/dev/null 2>&1; then
	dc "$env" exec -T -u www-data -e OC_PASS="$NN_TEST_PASS2" "$(svc_for "$env")" php occ user:add --password-from-env "$NN_TEST_USER2" >/dev/null
fi

# REST als Benutzer: api <user> <pass> <method> <path> [json]; SQLite-503 wird wiederholt
api() {
	local u="$1" p="$2" m="$3" path="$4" data="${5:-}" out code i
	local c=("${NN_CURL[@]}" -sS -u "$u:$p" -H 'OCS-APIRequest: true' -H 'Accept: application/json' -X "$m" -w '\n%{http_code}')
	[[ -n "$data" ]] && c+=(-H 'Content-Type: application/json' --data "$data")
	for i in 1 2 3 4 5 6; do
		out="$("${c[@]}" "$(base_url "$env")/index.php/apps/nextnotes/api/v1$path")" || out=$'\n000'
		code="${out##*$'\n'}"
		[[ "$code" == 503 ]] || break
		sleep 2
	done
	[[ "$code" =~ ^2 ]] || { echo "API $u $m $path → $code ${out:0:300}" >&2; return 1; }
	printf '%s' "${out%$'\n'*}"
}
A=("$NN_TEST_USER" "$NN_TEST_PASS")
B=("$NN_TEST_USER2" "$NN_TEST_PASS2")
routes="$wt/nextnotes/appinfo/routes.php"
seed=0; seed_team=0
grep -q "'/api/v1/workspaces'" "$routes" 2>/dev/null && seed=1
grep -q "'/api/v1/assignments'" "$routes" 2>/dev/null && seed_team=1
WS="Upgrade WS"; TW="Upgrade Team"; HEAD_N="Upgrade Notiz"; CONTENT='<p>Upgrade-Inhalt äöü ß 🙂</p>'; TAG=upgradetag
printf 'Anhang für den Upgrade-Test äöü\n' > "$work/att.txt"
attsha="$(nn_sha256 < "$work/att.txt" | cut -c1-64)"

if [[ $seed == 1 ]]; then
	step "Beispieldaten anlegen"
	api "${A[@]}" POST /workspaces "$(jq -nc --arg n "$WS" '{name:$n}')" >/dev/null
	FID="$(api "${A[@]}" POST /folders "$(jq -nc --arg w "$WS" '{folder_name:"Upgrade Ordner", workspace:$w}')" | jq -r .folder_id)"
	NID="$(api "${A[@]}" POST /notes "$(jq -nc --arg w "$WS" --arg h "$HEAD_N" --arg c "$CONTENT" --argjson f "$FID" '{heading:$h, content:$c, workspace:$w, folder_id:$f}')" | jq -r .note.id)"
	api "${A[@]}" PUT "/notes/$NID/tags" "$(jq -nc --arg w "$WS" --arg t "$TAG" '{tags:[$t], workspace:$w}')" >/dev/null
	ATT="$("${NN_CURL[@]}" -sS -u "${A[0]}:${A[1]}" -H 'OCS-APIRequest: true' -F "file=@$work/att.txt;type=text/plain;filename=upgrade.txt" \
		"$(base_url "$env")/index.php/apps/nextnotes/api/v1/notes/$NID/attachments?workspace=$(jq -rn --arg w "$WS" '$w|@uri')" | jq -r .attachment_id)"
	[[ "$FID" =~ ^[0-9]+$ && "$NID" =~ ^[0-9]+$ && "$ATT" =~ ^[0-9]+$ ]] || die "Beispieldaten unvollständig (Ordner $FID, Notiz $NID, Anhang $ATT)"
	echo "Daten: Workspace „$WS“, Ordner $FID, Notiz $NID (Tag $TAG), Anhang $ATT"
fi
if [[ $seed_team == 1 ]]; then
	TEAM="$(nnprobe team "${A[0]}" "Upgrade Klasse" "{\"${B[0]}\":1}")"
	api "${A[@]}" POST /workspaces "$(jq -nc --arg n "$TW" --arg t "$TEAM" '{name:$n, team:$t}')" >/dev/null
	MF="$(api "${A[@]}" POST /folders "$(jq -nc --arg w "$TW" '{folder_name:"Material", workspace:$w}')" | jq -r .folder_id)"
	api "${A[@]}" PUT "/folders/$MF/material" '{"material":true}' >/dev/null
	SRC="$(api "${A[@]}" POST /notes "$(jq -nc --arg w "$TW" --argjson f "$MF" '{heading:"Arbeitsblatt Upgrade", content:"<p>Arbeitsblatt Upgrade</p>", workspace:$w, folder_id:$f}')" | jq -r .note.id)"
	AID="$(api "${A[@]}" POST /assignments "$(jq -nc --arg t "$TEAM" --argjson s "$SRC" --argjson d "$(( $(date +%s) + 604800 ))" '{team:$t, title:"Aufgabe Upgrade", due_at:$d, source_note_id:$s, max_points:10}')" | jq -r .assignment.id)"
	api "${A[@]}" POST "/assignments/$AID/distribute" '{}' >/dev/null
	sub="$(api "${A[@]}" GET "/assignments/$AID/submissions" | jq -c --arg u "${B[0]}" '.submissions[] | select(.student_uid == $u)')"
	SID="$(jq -r .id <<<"$sub")"; SNOTE="$(jq -r .note_id <<<"$sub")"
	api "${B[@]}" POST "/submissions/$SID/submit" '{}' >/dev/null
	SSTATUS="$(api "${A[@]}" GET "/assignments/$AID/submissions" | jq -r --arg u "${B[0]}" '.submissions[] | select(.student_uid == $u) | .status')"
	[[ "$MF" =~ ^[0-9]+$ && "$AID" =~ ^[0-9]+$ && "$SID" =~ ^[0-9]+$ && -n "$SSTATUS" ]] || die "Team-Beispieldaten unvollständig"
	echo "Daten: Team $TEAM, Team-Workspace „$TW“, Material-Ordner $MF, Aufgabe $AID, Abgabe $SID ($SSTATUS, Notiz $SNOTE)"
fi

step "Paket einspielen + occ upgrade"
NN_APP_TARBALL="$pkg" "$dir/install-app.sh" "$env"
code=0; occ "$env" upgrade -n || code=$?
[[ $code -eq 0 ]] || fail "occ upgrade Exit $code"
[[ "$(appver)" == "$pkgver" ]] || fail "App-Version nach Upgrade '$(appver)', erwartet $pkgver"
occ "$env" status --output=json | jq -e '.needsDbUpgrade == false and .maintenance == false' >/dev/null || fail "status: needsDbUpgrade/maintenance"
# Webserver/PHP neu starten wie im NC-Handbuch: occ (CLI) leert den OPcache des Webservers nicht, alte Klassen bis revalidate_freq (60 s) → 500
dc "$env" restart "$(svc_for "$env")" >/dev/null 2>&1
for i in $(seq 1 60); do
	"${NN_CURL[@]}" -fs -o /dev/null "$(base_url "$env")/status.php" && break
	[[ $i -eq 60 ]] && die "$env: Webserver nach Neustart nicht erreichbar"
	sleep 1
done

[[ "$brk" == schema ]] && nnprobe break-schema
step "Schema"
nn_schema > "$work/schema-$env.json"
php "$NN_REPO_ROOT/tests/nextnotes/schema/check-schema.php" "$work/schema-$env.json" --db="$db" || fail "check-schema.php (SPEC §2.1)"
if diff -u "$fresh" "$work/schema-$env.json" > "$work/schema.diff"; then
	echo "Schema identisch zur frischen Installation ($(jq 'length' "$fresh") Tabellen)"
else
	head -n 40 "$work/schema.diff" >&2
	fail "Schema weicht von frischer Installation ab"
fi
nnprobe migrations > "$work/db-migrations"
if cmp -s "$work/pkg-migrations" "$work/db-migrations"; then
	echo "Migrationen vollständig ($(wc -l < "$work/db-migrations"))"
else
	diff "$work/pkg-migrations" "$work/db-migrations" >&2 || true
	fail "oc_migrations ≠ Migrationen im Paket"
fi

[[ "$brk" == jobs ]] && nnprobe break-jobs "${pjobs[0]}"
step "Hintergrundjobs"
nnprobe jobs "${pjobs[@]}" || fail "Jobliste ≠ info.xml"

if [[ $seed == 1 ]]; then
	step "Daten lesbar"
	ws="$(jq -rn --arg w "$WS" '$w|@uri')"
	api "${A[@]}" GET /workspaces | jq -e --arg w "$WS" '[.workspaces[].name] | index($w) != null' >/dev/null || fail "Workspace „$WS“ fehlt"
	api "${A[@]}" GET "/folders?workspace=$ws" | jq -e --argjson f "$FID" '[.. | objects | select((.id? | tostring) == ($f | tostring) and .name? == "Upgrade Ordner")] | length > 0' >/dev/null || fail "Ordner $FID fehlt"
	note="$(api "${A[@]}" GET "/notes/$NID?workspace=$ws" || true)"
	jq -e --arg h "$HEAD_N" --arg c "$CONTENT" --argjson f "$FID" '.note.heading == $h and .note.content == $c and (.note.folder_id | tostring) == ($f | tostring)' >/dev/null <<<"$note" \
		|| fail "Notiz $NID: Titel/Inhalt/Ordner abweichend: ${note:0:300}"
	jq -e --arg t "$TAG" '.note.tags | tostring | contains($t)' >/dev/null <<<"$note" || fail "Notiz $NID: Tag fehlt"
	api "${A[@]}" GET "/tags?workspace=$ws" | jq -e --arg t "$TAG" 'tostring | contains($t)' >/dev/null || fail "Tag-Liste ohne $TAG"
	api "${A[@]}" GET "/notes/$NID/attachments?workspace=$ws" | jq -e --argjson a "$ATT" '[.attachments[] | select((.id | tostring) == ($a | tostring))] | length == 1' >/dev/null || fail "Anhang $ATT nicht gelistet"
	got="$("${NN_CURL[@]}" -fsS -u "${A[0]}:${A[1]}" -H 'OCS-APIRequest: true' "$(base_url "$env")/index.php/apps/nextnotes/api/v1/notes/$NID/attachments/$ATT?workspace=$ws" | nn_sha256 | cut -c1-64 || true)"
	[[ "$got" == "$attsha" ]] || fail "Anhang $ATT: Inhalt abweichend"
fi
if [[ $seed_team == 1 ]]; then
	tw="$(jq -rn --arg w "$TW" '$w|@uri')"
	api "${A[@]}" GET /workspaces | jq -e --arg w "$TW" '[.workspaces[] | select(.name == $w and .team == "Upgrade Klasse")] | length == 1' >/dev/null || fail "Team-Workspace fehlt"
	api "${A[@]}" GET "/folders?workspace=$tw" | jq -e --argjson f "$MF" '[.. | objects | select((.id? | tostring) == ($f | tostring)) | .material | tostring | select(test("^(1|true|own)$"))] | length > 0' >/dev/null || fail "Material-Kennzeichen fehlt"
	api "${A[@]}" GET "/assignments/$AID" | jq -e '.assignment.title == "Aufgabe Upgrade"' >/dev/null || fail "Aufgabe $AID nicht lesbar"
	got="$(api "${A[@]}" GET "/assignments/$AID/submissions" | jq -r --arg u "${B[0]}" '.submissions[] | select(.student_uid == $u) | .status' || true)"
	[[ "$got" == "$SSTATUS" ]] || fail "Abgabe $SID: Status '$got' statt '$SSTATUS'"
	api "${B[@]}" GET "/submissions/$SID" >/dev/null || fail "Abgabe $SID für ${B[0]} nicht lesbar"
	api "${B[@]}" GET "/notes/$SNOTE" | jq -e '.note.content | contains("Arbeitsblatt Upgrade")' >/dev/null || fail "Abgabe-Notiz $SNOTE nicht lesbar"
fi
[[ $seed == 1 && ${#fails[@]} -eq 0 ]] && echo "Beispieldaten vollständig lesbar"

if [[ "${NN_UPGRADE_E2E:-1}" == 1 && -z "$brk" ]]; then
	step "smoke-m6 hell"
	THEMES=light "$dir/run-e2e.sh" "$env" smoke-m6 || fail "smoke-m6"
fi

if [[ ${#fails[@]} -gt 0 ]]; then
	echo "upgrade-path $env $ref → $pkgver: ROT (${#fails[@]}: ${fails[*]})" >&2
	exit 1
fi
echo "upgrade-path $env $ref ($fromver) → $pkgver: grün"
