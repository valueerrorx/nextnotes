#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# check-upstream (ADR 0007, M3-Teil): (a) src/-Diff = upstream-patches.txt, (b) Bundles = Upstream-Listen,
# (c) Kopfkommentar der Templates, (d) Adapter-Namen in src/. Voraussetzung: git, Host-php, node. SRC= prüft (b)/(d) in einer Kopie.
set -euo pipefail
app="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
repo="$(cd "$app/.." && pwd)"
src="${SRC:-$repo/src}"
fail=0
err() { echo "FEHLER: $*" >&2; fail=1; }

command -v php >/dev/null || { echo "FEHLER: Host-php fehlt (siehe dev/nextcloud/README.md)" >&2; exit 2; }
command -v node >/dev/null || { echo "FEHLER: node fehlt" >&2; exit 2; }

# (a) git diff gegen die Upstream-Basis (inkl. neuer, nicht verfolgter Dateien) = upstream-patches.txt
base="$(sed -n 's/^- Basis-Commit: `\([0-9a-f]\{40\}\)`.*/\1/p' "$repo/UPSTREAM.md")"
[[ -n "$base" ]] || { echo "FEHLER: Basis-Commit in UPSTREAM.md nicht gefunden" >&2; exit 2; }
git -C "$repo" cat-file -e "$base^{commit}" || { echo "FEHLER: Basis-Commit $base fehlt im Repo" >&2; exit 2; }
changed="$( { git -C "$repo" diff --name-only "$base" -- src/; git -C "$repo" ls-files --others --exclude-standard -- src/; } | sort -u)"
listed="$( { grep -v '^[[:space:]]*#' "$app/build/upstream-patches.txt" || true; } | sed '/^[[:space:]]*$/d' | sort -u)"
while IFS= read -r f; do if [[ -n "$f" ]]; then err "$f geändert, aber nicht in build/upstream-patches.txt (SPEC §11.1)"; fi; done \
	< <(comm -23 <(echo "$changed") <(echo "$listed"))
while IFS= read -r f; do if [[ -n "$f" ]]; then err "$f in build/upstream-patches.txt, aber nicht geändert"; fi; done \
	< <(comm -13 <(echo "$changed") <(echo "$listed"))
if [[ $fail -eq 0 ]]; then echo "check-upstream (a): src/-Änderungen = upstream-patches.txt ($(grep -c . <<<"$listed" || true) Dateien)"; fi

# (b) und (d)
php "$app/build/upstream-lists.php" "$src" | node "$app/build/check-upstream.mjs" "$src" || fail=1

# (c) Templates: "abgeleitet von <pfad> @ <commit>" (Pfad existiert) oder "eigene Vorlage"
while IFS= read -r t; do
	head="$(head -n 12 "$t")"
	if [[ "$head" =~ abgeleitet\ von\ ([^[:space:]]+)\ @\ ([0-9a-f]{7,40}) ]]; then
		[[ -f "$repo/${BASH_REMATCH[1]}" ]] || err "${t#"$app/"}: Quelle ${BASH_REMATCH[1]} fehlt"
		git -C "$repo" cat-file -e "${BASH_REMATCH[2]}^{commit}" 2>/dev/null || err "${t#"$app/"}: Commit ${BASH_REMATCH[2]} unbekannt"
	elif ! grep -q 'eigene Vorlage' <<<"$head"; then
		err "${t#"$app/"}: Kopfkommentar 'abgeleitet von <pfad> @ <commit>' bzw. 'eigene Vorlage' fehlt"
	fi
done < <(find "$app/templates" -type f -name '*.php' | sort)
if [[ $fail -eq 0 ]]; then echo "check-upstream (c): Template-Kopfkommentare vorhanden"; fi

if [[ $fail -ne 0 ]]; then
	echo "check-upstream: ROT" >&2
	exit 1
fi
echo "check-upstream: grün"
