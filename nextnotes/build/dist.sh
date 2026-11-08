#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# dist.sh: baut nextnotes/dist/nextnotes-<version>.tar.gz reproduzierbar aus einem sauberen worktree von HEAD (Aufruf über make dist).
set -euo pipefail
export LC_ALL=C TZ=UTC
app="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
repo="$(git -C "$app" rev-parse --show-toplevel)"
die() { echo "FEHLER: $*" >&2; exit 1; }

# node_modules nur aus dem Hauptbaum (kein Netz, kein npm install)
mods="$app/build/node_modules"
[[ -x "$mods/.bin/esbuild" ]] || die "build/node_modules fehlt im Hauptbaum (einmalig mit Netz: npm ci --prefix nextnotes/build)"

work="$(mktemp -d)"
wt="$work/src"
cleanup() { git -C "$repo" worktree remove --force "$wt" >/dev/null 2>&1 || true; git -C "$repo" worktree prune; rm -rf "$work"; }
trap cleanup EXIT
git -C "$repo" worktree add --detach --force "$wt" HEAD >/dev/null 2>&1 || die "git worktree add fehlgeschlagen"
commit="$(git -C "$wt" rev-parse --short HEAD)"
export SOURCE_DATE_EPOCH="$(git -C "$wt" log -1 --format=%ct HEAD)"

cmp -s "$wt/nextnotes/build/package-lock.json" "$app/build/package-lock.json" \
	|| die "build/package-lock.json im Hauptbaum weicht von HEAD ab; erst npm ci --prefix nextnotes/build mit der Lockfile aus HEAD, kein Paket gebaut"
[[ -e "$wt/nextnotes/build/node_modules" ]] && die "worktree hat eigenes node_modules (unerwartet)"
ln -s "$mods" "$wt/nextnotes/build/node_modules"

version="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$wt/nextnotes/appinfo/info.xml" | head -n1)"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]] || die "Version aus appinfo/info.xml unbrauchbar: '$version'"

echo "dist: Commit $commit, Version $version, SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH"
echo "dist: $(tar --version | head -n1); $(gzip --version | head -n1); esbuild $("$mods/.bin/esbuild" --version); node $(node --version)"

make -C "$wt/nextnotes" build >/dev/null

# Ausschlüsse aus der gemeinsamen Liste (gleiche Quelle wie install-app.sh)
excl=()
while IFS= read -r p; do [[ -z "$p" || "$p" == \#* ]] || excl+=("--exclude=$p"); done < "$app/build/dist-exclude.txt"
stage="$work/stage"
mkdir -p "$stage/nextnotes"
tar -C "$wt/nextnotes" --anchored "${excl[@]}" -cf - . | tar -xf - -C "$stage/nextnotes"

out="$app/dist"
name="nextnotes-$version.tar.gz"
mkdir -p "$out"
tar -C "$stage" --format=gnu --sort=name --mtime="@$SOURCE_DATE_EPOCH" --owner=0 --group=0 --numeric-owner \
	--mode='u=rwX,go=rX' -cf - nextnotes | gzip -n -9 > "$out/$name.tmp"
mv "$out/$name.tmp" "$out/$name"
(cd "$out" && sha256sum "$name" > "$name.sha256")

check="$repo/dev/nextcloud/check-dist.sh"
[[ -x "$check" ]] && "$check" "$out/$name"
echo "dist: $out/$name"
cat "$out/$name.sha256"
