#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# check-dist.sh <tarball>: prüft ein App-Paket (eine Wurzel nextnotes/, info.xml, Version = Dateiname, keine verbotenen Pfade).
set -euo pipefail
export LC_ALL=C
tarball="${1:?Aufruf: check-dist.sh <nextnotes-<version>.tar.gz>}"
[[ -f "$tarball" ]] || { echo "FEHLER: $tarball fehlt" >&2; exit 1; }
fail=0
bad() { echo "FEHLER: $*" >&2; fail=1; }

list="$(tar -tzf "$tarball")" || { echo "FEHLER: $tarball ist kein lesbares tar.gz" >&2; exit 1; }
# eine Wurzel nextnotes/, keine absoluten Pfade oder ..
roots="$(sed -e 's:^\./::' -e 's:/.*::' <<<"$list" | sort -u)"
[[ "$roots" == nextnotes ]] || bad "Wurzel nicht genau nextnotes/: $(tr '\n' ' ' <<<"$roots")"
grep -qE '^/|(^|/)\.\.(/|$)' <<<"$list" && bad "absolute Pfade oder .. im Paket"

# info.xml und Version = Dateiname
info="$(tar -xzOf "$tarball" nextnotes/appinfo/info.xml 2>/dev/null)" || bad "nextnotes/appinfo/info.xml fehlt"
version="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' <<<"$info" | head -n1)"
[[ "$(basename "$tarball")" == "nextnotes-$version.tar.gz" ]] || bad "Dateiname $(basename "$tarball") passt nicht zu Version '$version'"
grep -q '<id>nextnotes</id>' <<<"$info" || bad "info.xml: <id>nextnotes</id> fehlt"

# verbotene Pfade
# überall: .git, node_modules, tests, .ref; in der Wurzel: Ausschlüsse aus nextnotes/build/dist-exclude.txt
forbidden="$(grep -E '(^|/)(\.git|node_modules|tests|\.ref)(/|$)|^nextnotes/(build|dist|screenshots|[^/]*-src)(/|$)|^nextnotes/(Makefile|\.gitignore)$' <<<"$list" || true)"
[[ -z "$forbidden" ]] || bad "verbotene Pfade: $(head -n5 <<<"$forbidden" | tr '\n' ' ')"
# PHP nur aus der App (appinfo/, lib/, templates/), kein Upstream-PHP aus src/ (ADR 0007)
php="$(grep -E '\.php$' <<<"$list" | grep -vE '^nextnotes/(appinfo|lib|templates)/' || true)"
[[ -z "$php" ]] || bad "PHP außerhalb appinfo/lib/templates: $(head -n5 <<<"$php" | tr '\n' ' ')"
# Build-Ausgabe vorhanden
grep -qx 'nextnotes/js/nextnotes-app.js' <<<"$list" || bad "Build-Ausgabe js/nextnotes-app.js fehlt"
# Lizenz- und Begleitdateien (M7c)
for f in CHANGELOG.md COPYING LICENCE THIRD-PARTY-NOTICES.md LICENSES/MIT.txt; do
	grep -qx "nextnotes/$f" <<<"$list" || bad "$f fehlt im Paket"
done

[[ $fail -eq 0 ]] || exit 1
echo "check-dist: OK $(basename "$tarball") (Version $version, $(wc -l <<<"$list") Einträge, $(wc -c < "$tarball" | tr -d " ") Bytes, $(du -h "$tarball" | cut -f1))"
