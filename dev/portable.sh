# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# Linux/macOS-Helfer für dev/ und nextnotes/build/; per source geladen. macOS-Voraussetzungen: dev/nextcloud/README.md „macOS“.

# bash >= 4.4 (inherit_errexit, declare -A, set -u mit leeren Arrays); macOS bringt nur 3.2 mit
if [ -z "${BASH_VERSINFO:-}" ] || [ "${BASH_VERSINFO[0]}" -lt 4 ] || { [ "${BASH_VERSINFO[0]}" -eq 4 ] && [ "${BASH_VERSINFO[1]}" -lt 4 ]; }; then
	echo "FEHLER: bash >= 4.4 nötig, läuft ${BASH_VERSION:-?} (macOS: brew install bash, /opt/homebrew/bin vor /bin im PATH)" >&2
	exit 2
fi

# sha256 wie sha256sum ("<hash>  <datei>"); als Array auch für xargs
if command -v sha256sum >/dev/null 2>&1; then NN_SHA256=(sha256sum); else NN_SHA256=(shasum -a 256); fi
nn_sha256() { "${NN_SHA256[@]}" "$@"; }

# Dateigröße in Bytes, mtime in Sekunden
nn_size() { local n; n="$(wc -c < "$1")"; echo "${n//[[:space:]]/}"; }
nn_mtime() { stat -c %Y "$1" 2>/dev/null || stat -f %m "$1"; }

# absoluter Pfad (Verzeichnis aufgelöst), ohne realpath/readlink -f
nn_abspath() { local d; d="$(cd "$(dirname "$1")" && pwd -P)" || return 1; printf '%s/%s\n' "$d" "$(basename "$1")"; }

# nn_timeout <sek> <befehl…>: timeout, gtimeout (coreutils) oder Watchdog im Hintergrund; Exit 124 bei Ablauf wie timeout
nn_timeout() {
	local s="$1"; shift
	if command -v timeout >/dev/null 2>&1; then timeout "$s" "$@"; return; fi
	if command -v gtimeout >/dev/null 2>&1; then gtimeout "$s" "$@"; return; fi
	local pid wd rc=0 flag
	flag="$(mktemp)"; rm -f "$flag"
	"$@" & pid=$!
	( sleep "$s"; touch "$flag"; kill -TERM "$pid" 2>/dev/null ) & wd=$!
	wait "$pid" || rc=$?
	kill "$wd" 2>/dev/null || true; wait "$wd" 2>/dev/null || true
	if [[ -e "$flag" ]]; then rm -f "$flag"; return 124; fi
	return "$rc"
}

# nn_offline <befehl…>: ohne Netz (Linux: unshare -rn); auf macOS keine Sperre möglich, nur Warnung
nn_offline() {
	if [[ "$(uname -s)" == Linux ]]; then unshare -rn "$@"; return; fi
	echo "WARNUNG: keine Netz-Sperre auf $(uname -s) (unshare fehlt), läuft mit Netz: $*" >&2
	"$@"
}

# GNU tar (--anchored, --sort, --mtime, --owner; keine macOS-xattrs im Archiv); macOS: gtar
nn_need_gnu_tar() {
	[[ -n "${NN_TAR:-}" ]] && return 0
	local t v
	for t in tar gtar; do
		command -v "$t" >/dev/null 2>&1 || continue
		v="$("$t" --version 2>/dev/null || true)"
		if [[ "$v" == *"GNU tar"* ]]; then NN_TAR="$t"; return 0; fi
	done
	echo "FEHLER: GNU tar fehlt (macOS: brew install gnu-tar)" >&2
	exit 2
}
nn_tar() { nn_need_gnu_tar; "$NN_TAR" "$@"; }

# GNU gzip für byte-gleiche Pakete auf Linux und macOS (Apple-gzip komprimiert anders)
nn_need_gnu_gzip() {
	local v; v="$(gzip --version 2>&1 || true)"
	[[ "$v" =~ ^gzip\ [0-9] ]] && return 0
	echo "FEHLER: GNU gzip nötig für reproduzierbare Pakete, gefunden: ${v%%$'\n'*} (macOS: brew install gzip)" >&2
	exit 2
}

# CHROMIUM setzen, falls leer: System-Chromium, Playwright-Cache (neueste zuerst), /Applications
nn_chromium() {
	[[ -n "${CHROMIUM:-}" ]] && return 0
	local c i ng pw=() cands
	ng="$(shopt -p nullglob || true)"; shopt -s nullglob
	pw=("$HOME"/Library/Caches/ms-playwright/chromium-*/chrome-mac*/Chromium.app/Contents/MacOS/Chromium
		"$HOME"/Library/Caches/ms-playwright/chromium-*/chrome-mac*/"Google Chrome for Testing.app"/Contents/MacOS/"Google Chrome for Testing"
		"$HOME"/.cache/ms-playwright/chromium-*/chrome-linux*/chrome)
	eval "$ng"
	cands=(/usr/bin/chromium /usr/bin/chromium-browser)
	for ((i = ${#pw[@]} - 1; i >= 0; i--)); do cands+=("${pw[i]}"); done
	cands+=(/Applications/Chromium.app/Contents/MacOS/Chromium "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome")
	for c in "${cands[@]}"; do
		if [[ -x "$c" ]]; then export CHROMIUM="$c"; return 0; fi
	done
	return 0
}
