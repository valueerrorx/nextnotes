#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# run-m7.sh [env…]: einzige volle Matrix (Done M1–M7, ersetzt run-m6.sh; Standard: sqlite mariadb pgsql nginx).
# Vorab: make dist zweimal (zweiter ohne Netz) mit gleicher sha256, check-dist, Build + check-upstream, validate-info (ohne Netz),
# check-forbidden-sql, Node-Tests (tests/nextnotes/node). Die App kommt in ALLEN Umgebungen aus dem Paket (NN_APP_TARBALL, auch in lifecycle/check-purge/upgrade-path).
# Phase 1 je Umgebung parallel (PARALLEL=0: nacheinander): Lebenszyklus M1 (frisch, Seite, Spike/CSP je Webserver, Schema),
# danach frisch: App, Circles-Loopback (up.sh), mysql.utf8mb4 (mariadb), occ --help der Befehle aus docs/app/admin.md + upgrade.md,
# PHPUnit (Apache), PHPUnit-Gruppe msmock mit OneNote-Mock (msmock.sh, Umgebungen nacheinander), Log-Suche, Großanhang-Probe (mariadb, pgsql), Circles-Aus-Lauf (alle), check-purge (Apache, M7b Done 7; zuletzt, löscht alles).
# Phase 2 nacheinander, ohne Parallel-Last: Playwright Hell+Dunkel (sqlite, nginx), alle Specs (Glob); onenote-* nur auf sqlite mit Mock,
#   danach Log-Suche; E2E_PARALLEL=1 gleichzeitig.
# Phase 3: upgrade-path.sh ab 0.1.0, 0.1.2, 0.3.3 (UPGRADE_REFS) auf sqlite, mariadb, pgsql; je DB parallel (UPGRADE_PARALLEL=0: nacheinander).
# ONLY="<schritte>" führt nur diese Schritte aus (Nachlauf/Probelauf), z. B. ONLY="dist install help purge e2e" SPECS=lists-katex THEMES=light.
#   Schritte: dist checkdist upstream info sql node | lifecycle install utf8mb4 help phpunit msmock logscan grenzen circlesoff purge | e2e | upgrade
#   install = frische Umgebung + Paket + app:enable; ohne install/lifecycle muss die Umgebung schon laufen.
# SPECS= Auswahl der E2E-Specs (Standard alle), THEMES= wie run-e2e.sh. NN_APP_TARBALL= vorhandenes Paket statt dist (dann kein dist-Schritt).
# Logs je Umgebung in NN_LOG_DIR (Standard dev/nextcloud/.cache/run-m7-<Zeit>). KEEP=1 lässt rote Umgebungen stehen.
set -euo pipefail
dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$dir/env.sh"

envs=("$@"); [[ ${#envs[@]} -gt 0 ]] || envs=("${NN_ENVS[@]}")
for env in "${envs[@]}"; do check_env "$env"; done
all_steps="dist checkdist upstream info sql node lifecycle install utf8mb4 help phpunit msmock logscan grenzen circlesoff purge e2e upgrade"
only="${ONLY:-$all_steps}"
for s in $only; do [[ " $all_steps " == *" $s "* ]] || die "unbekannter Schritt '$s' (erlaubt: $all_steps)"; done
want() { [[ " $only " == *" $1 "* ]]; }
trap '"$dir/down.sh" all >/dev/null 2>&1 || true; exit 130' INT TERM
app="$NN_REPO_ROOT/nextnotes"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
logs="${NN_LOG_DIR:-$NN_CACHE/run-m7-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$logs"
start=$SECONDS
upgrade_refs="${UPGRADE_REFS:-fe470b2c 86e514e6 1378e5d8}" # 0.1.0 (M1b), 0.1.2 (M4d), 0.3.3 (M6)
version="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$app/appinfo/info.xml" | head -n1)"

# --- Vorab ---------------------------------------------------------------------------------------------
[[ -n "$(git -C "$NN_REPO_ROOT" status --porcelain -- nextnotes)" ]] \
	&& echo "WARNUNG: nextnotes/ hat nicht committete Änderungen; das Paket enthält nur HEAD" >&2
if [[ -n "${NN_APP_TARBALL:-}" ]]; then
	want dist && die "NN_APP_TARBALL gesetzt und Schritt dist gewählt: eins von beiden"
	pkg="$(cd "$(dirname "$NN_APP_TARBALL")" && pwd)/$(basename "$NN_APP_TARBALL")"
else
	pkg="$app/dist/nextnotes-$version.tar.gz"
fi
if want dist; then
	# node_modules nur aus dem Hauptbaum (dist.sh); Netz nur, falls es fehlt
	[[ -x "$app/build/node_modules/.bin/esbuild" ]] || npm ci --prefix "$app/build" --no-audit --no-fund
	make -C "$app" dist
	nn_sha256 < "$pkg" > "$tmp/sha1"
	nn_offline make -C "$app" dist
	nn_sha256 < "$pkg" > "$tmp/sha2"
	cmp -s "$tmp/sha1" "$tmp/sha2" || die "Paket nicht reproduzierbar: $(cut -c1-16 "$tmp/sha1") vs. $(cut -c1-16 "$tmp/sha2")"
	echo "Paket reproduzierbar: $(basename "$pkg") sha256 $(cut -d' ' -f1 "$tmp/sha1") (zweiter Lauf ohne Netz)"
fi
[[ -f "$pkg" ]] || die "Paket fehlt: $pkg (Schritt dist oder NN_APP_TARBALL)"
# eigene Kopie: ein paralleles make dist (anderer Slice) darf das getestete Paket nicht austauschen
mkdir -p "$logs/pkg"
cp "$pkg" "$logs/pkg/" && pkg="$logs/pkg/$(basename "$pkg")"
(cd "$logs/pkg" && nn_sha256 "$(basename "$pkg")" > "$(basename "$pkg").sha256")
export NN_APP_TARBALL="$pkg"
echo "Paket für alle Umgebungen: $pkg ($(cut -c1-16 "$pkg.sha256"))"
want checkdist && "$dir/check-dist.sh" "$pkg"
if want upstream; then
	# check-upstream prüft u. a. die gebaute CSS im Hauptbaum; Build ohne Netz
	nn_offline make -C "$app" clean build
	make -C "$app" check-upstream
fi
want info && nn_offline "$dir/validate-info.sh"
want sql && "$dir/check-forbidden-sql.sh"
want node && node --test "$NN_REPO_ROOT"/tests/nextnotes/node/*.mjs
[[ -d "$NN_DEV_DIR/node_modules/playwright-core" ]] || npm --prefix "$NN_DEV_DIR" ci --no-audit --no-fund

# occ --help aller in admin.md/upgrade.md genannten Befehle; Optionen aus den Abschnittsköpfen (### `occ nextnotes:…`) müssen in der Hilfe stehen
check_help() {
	local env="$1" docs=("$NN_REPO_ROOT/docs/app/admin.md" "$NN_REPO_ROOT/docs/app/upgrade.md") cmds cmd out line opt n=0 need
	cmds="$(grep -ohE 'occ [a-z][a-z0-9_-]*(:[a-z0-9_-]+)*' "${docs[@]}" | cut -d' ' -f2 | sort -u)"
	for need in nextnotes:team:orphan-check nextnotes:assignment:transfer nextnotes:export-user nextnotes:delete-user-data nextnotes:purge; do
		grep -qx "$need" <<<"$cmds" || die "$env: $need fehlt in docs/app/admin.md"
	done
	while read -r cmd; do
		out="$(occ "$env" "$cmd" --help </dev/null 2>&1)" || die "$env: occ $cmd --help fehlgeschlagen:"$'\n'"$out"
		grep -q '^Usage:' <<<"$out" || die "$env: occ $cmd --help ohne Usage:"$'\n'"$out"
		n=$((n + 1))
	done <<<"$cmds"
	while read -r line; do
		cmd="$(grep -oE 'nextnotes:[a-z:-]+' <<<"$line" | head -n1)"
		out="$(occ "$env" "$cmd" --help </dev/null 2>&1)"
		for opt in $(grep -oE -- '--[a-z][a-z-]*' <<<"$line" | sort -u); do
			grep -qE -- "(^|[ ,])$opt([ =[]|$)" <<<"$out" || die "$env: occ $cmd --help nennt $opt nicht (docs/app/admin.md)"
		done
	done < <(grep -h '^### `occ nextnotes:' "${docs[@]}")
	echo "$env: occ --help OK ($n Befehle: $(tr '\n' ' ' <<<"$cmds"))"
}

# nextcloud.log ohne Token/Secret (M9a Done 6): Mock-Token, Secrets aus PHPUnit (OneNoteTestCase) und E2E, JWT-Anfang eyJ
check_log() {
	local env="$1" svc hits
	svc="$(svc_for "$env")"
	hits="$(dc "$env" exec -T -u www-data "$svc" sh -c 'cat "$(php occ config:system:get datadirectory)/nextcloud.log" 2>/dev/null || true' \
		| grep -nE 'msmock-access-|msmock-refresh-|mock-secret-|E2E-Secret-|eyJ' | cut -c1-300 || true)"
	[[ -z "$hits" ]] || { echo "FEHLER: $env: nextcloud.log enthält Token/Secret:"$'\n'"$hits" >&2; return 1; }
	echo "$env: nextcloud.log ohne Token/Secret/eyJ"
}
# OneNote-Mock: ein Dienst je Projekt, Zähler global → Umgebungen nacheinander (Sperre per mkdir, auch macOS)
mock_lock() { until mkdir "$logs/msmock.lock" 2>/dev/null; do sleep 2; done; }
mock_unlock() { rmdir "$logs/msmock.lock" 2>/dev/null || true; }
phpunit_msmock() {
	local env="$1" code=0
	mock_lock
	{ "$dir/msmock.sh" "$env" up && "$dir/run-phpunit.sh" "$env" --group msmock; } || code=$?
	"$dir/msmock.sh" "$env" down || true
	mock_unlock
	return "$code"
}

phase1() {
	local env="$1" web=0 db="$1" got
	[[ "$env" == sqlite || "$env" == nginx ]] && web=1
	[[ "$env" == mariadb ]] && db=mysql
	[[ "$env" == nginx ]] && db=sqlite
	if want lifecycle; then
		CHECK_PAGE=1 CHECK_SPIKE=$web CHECK_CSP=$web "$dir/lifecycle.sh" "$env"
		php "$NN_REPO_ROOT/tests/nextnotes/schema/check-schema.php" "$NN_CACHE/schema-$env.json" --db="$db"
	fi
	if want install; then
		"$dir/down.sh" "$env" >/dev/null 2>&1 || true
		"$dir/up.sh" "$env"
		"$dir/install-app.sh" "$env"
		occ "$env" app:enable nextnotes
		got="$(occ "$env" app:list --output=json | jq -r '.enabled.nextnotes // empty')"
		[[ "$got" == "$version" ]] || die "$env: aktive Version '$got' statt $version (Paket)"
	fi
	if want utf8mb4 && [[ "$env" == mariadb ]]; then
		got="$(occ "$env" config:system:get mysql.utf8mb4 || true)"
		[[ "$got" == true ]] || die "mariadb: mysql.utf8mb4 = '$got', erwartet true"
		echo "mariadb: mysql.utf8mb4 = true"
	fi
	want help && check_help "$env"
	case "$env" in sqlite|mariadb|pgsql) want phpunit && "$dir/run-phpunit.sh" "$env" ;; esac
	case "$env" in sqlite|mariadb|pgsql) want msmock && phpunit_msmock "$env" ;; esac
	want logscan && check_log "$env"
	case "$env" in mariadb|pgsql) want grenzen && "$dir/scripts/probe-grenzen.sh" "$env" ;; esac
	want circlesoff && "$dir/scripts/probe-circles-off.sh" "$env"
	# zuletzt: löscht alle App-Daten und installiert das Paket neu (Umgebung danach frisch für E2E)
	case "$env" in sqlite|mariadb|pgsql) want purge && "$dir/check-purge.sh" "$env" ;; esac
	return 0
}

declare -A rc=()
for env in "${envs[@]}"; do rc[$env]=0; done
finish() {
	local env="$1" code="$2" what="$3"
	[[ $code -eq 0 ]] || rc[$env]=$code
	if [[ $code -ne 0 ]]; then
		echo "=== $env: $what ROT (Exit $code, Log $logs/$env.log)" >&2
		tail -n 30 "$logs/$env.log" >&2
	else
		echo "=== $env: $what grün ($(( (SECONDS - start) / 60 )) min seit Start)"
	fi
}

# --- Phase 1 -------------------------------------------------------------------------------------------
p1="lifecycle install utf8mb4 help phpunit msmock logscan grenzen circlesoff purge"
p1_any=0; for s in $p1; do want "$s" && p1_any=1; done
if [[ $p1_any == 1 ]]; then
	# Netz des Compose-Projekts vorab anlegen: parallele "up" würden es sonst gleichzeitig erzeugen
	dc "${envs[0]}" create >/dev/null 2>&1 || true
	declare -A pid=()
	for env in "${envs[@]}"; do
		echo "=== $env: Phase 1 (Log $logs/$env.log)"
		# im Hintergrund, damit set -e im Subshell wirkt (in einer ||-Liste wäre es aus)
		(set -e; phase1 "$env") > "$logs/$env.log" 2>&1 &
		pid[$env]=$!
		if [[ "${PARALLEL:-1}" != 1 ]]; then
			code=0; wait "${pid[$env]}" || code=$?
			unset "pid[$env]"
			finish "$env" "$code" "Phase 1"
		fi
	done
	for env in "${!pid[@]}"; do
		code=0; wait "${pid[$env]}" || code=$?
		finish "$env" "$code" "Phase 1"
	done
fi

# --- Phase 2: E2E --------------------------------------------------------------------------------------
e2e() {
	local env="$1" code=0 s plain=() one=()
	echo "=== $env: Phase 2 E2E (Log $logs/$env.log)"
	if [[ -n "${SPECS:-}" ]]; then for s in $SPECS; do s="${s%.mjs}"; [[ "$s" == onenote-* ]] && one+=("$s") || plain+=("$s"); done
	else for s in "$NN_REPO_ROOT"/tests/nextnotes/e2e/*.mjs; do s="$(basename "$s" .mjs)"; [[ "$s" == onenote-* ]] && one+=("$s") || plain+=("$s"); done; fi
	[[ ${#plain[@]} -eq 0 ]] || "$dir/run-e2e.sh" "$env" "${plain[@]}" >> "$logs/$env.log" 2>&1 || code=$?
	grep '^run-e2e ' "$logs/$env.log" | tail -n 1 || true
	# OneNote-Specs brauchen den Mock (nur Apache-Umgebungen); danach Log-Suche
	if [[ ${#one[@]} -gt 0 && "$env" == sqlite ]]; then
		mock_lock
		{ "$dir/msmock.sh" "$env" up && "$dir/run-e2e.sh" "$env" "${one[@]}"; } >> "$logs/$env.log" 2>&1 || code=$?
		"$dir/msmock.sh" "$env" down >> "$logs/$env.log" 2>&1 || true
		mock_unlock
		grep '^run-e2e ' "$logs/$env.log" | tail -n 1 || true
	fi
	if want logscan; then check_log "$env" >> "$logs/$env.log" 2>&1 || code=$?; fi
	return "$code"
}
e2e_envs=()
for env in "${envs[@]}"; do
	if want e2e && [[ ${rc[$env]} -eq 0 && ( "$env" == sqlite || "$env" == nginx ) ]]; then
		e2e_envs+=("$env")
	elif want upgrade || [[ "${KEEP:-0}" == 1 && ${rc[$env]} -ne 0 ]]; then
		: # upgrade-path baut die Umgebung selbst neu auf; rote bleiben mit KEEP=1 stehen
	else
		"$dir/down.sh" "$env" >/dev/null 2>&1 || true
	fi
done
# E2E_PARALLEL=1: sqlite und nginx gleichzeitig (schneller, Zeitmessungen wie anti-flicker/budget dann weniger belastbar)
declare -A epid=()
for env in "${e2e_envs[@]}"; do
	if [[ "${E2E_PARALLEL:-0}" == 1 ]]; then
		e2e "$env" & epid[$env]=$!
	else
		code=0; e2e "$env" || code=$?
		finish "$env" "$code" "E2E"
	fi
done
for env in "${!epid[@]}"; do
	code=0; wait "${epid[$env]}" || code=$?
	finish "$env" "$code" "E2E"
done

# --- Phase 3: Upgrade-Pfad -----------------------------------------------------------------------------
# upgrade-path.sh baut die Umgebung je Startpunkt frisch auf und danach ab (KEEP=1: bleibt stehen)
upgrade_env() {
	local env="$1" ref code=0 bad=()
	for ref in $upgrade_refs; do
		echo "--- upgrade $env $ref"
		if ! "$dir/upgrade-path.sh" "$env" "$ref"; then bad+=("$ref"); code=1; fi
	done
	[[ $code -eq 0 ]] || echo "upgrade $env: rot ab ${bad[*]}" >&2
	return "$code"
}
if want upgrade; then
	declare -A upid=()
	for env in "${envs[@]}"; do
		case "$env" in sqlite|mariadb|pgsql) ;; *) continue ;; esac
		if [[ ${rc[$env]} -ne 0 && "${KEEP:-0}" == 1 ]]; then
			echo "=== $env: Phase 3 übersprungen (rot, KEEP=1 lässt die Umgebung stehen)"; continue
		fi
		echo "=== $env: Phase 3 Upgrade $upgrade_refs (Log $logs/$env-upgrade.log)"
		if [[ "${UPGRADE_PARALLEL:-1}" == 1 ]]; then
			upgrade_env "$env" > "$logs/$env-upgrade.log" 2>&1 & upid[$env]=$!
		else
			code=0; upgrade_env "$env" > "$logs/$env-upgrade.log" 2>&1 || code=$?
			grep -E '^upgrade-path .*: (grün|ROT)' "$logs/$env-upgrade.log" || true
			finish "$env" "$code" "Upgrade"
		fi
	done
	for env in "${!upid[@]}"; do
		code=0; wait "${upid[$env]}" || code=$?
		grep -E '^upgrade-path .*: (grün|ROT)' "$logs/$env-upgrade.log" || true
		finish "$env" "$code" "Upgrade"
	done
fi

for env in "${envs[@]}"; do
	[[ "${KEEP:-0}" == 1 && ${rc[$env]} -ne 0 ]] || "$dir/down.sh" "$env" >/dev/null 2>&1 || true
done
failed=()
for env in "${envs[@]}"; do [[ ${rc[$env]} -eq 0 ]] || failed+=("$env"); done
echo "Dauer gesamt: $(( (SECONDS - start) / 60 )) min; Paket $(basename "$pkg") $(cut -c1-16 "$pkg.sha256"); Logs: $logs"
# Netz des Projekts entfernen, sofern nichts stehen bleiben soll
[[ "${KEEP:-0}" == 1 ]] || "$dir/down.sh" all >/dev/null 2>&1 || true
if [[ ${#failed[@]} -gt 0 ]]; then
	echo "run-m7: rot in ${failed[*]} (grün: $(( ${#envs[@]} - ${#failed[@]} ))/${#envs[@]}; Schritte: $only)" >&2
	exit 1
fi
echo "run-m7: alle Umgebungen grün (${envs[*]}; Schritte: $only)"
