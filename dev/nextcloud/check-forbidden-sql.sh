#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# check-forbidden-sql.sh [dir]: verbotene SQL-/Sitzungsmuster in nextnotes/lib (SPEC §13 M2.5); Exit 1 bei Treffer. SELFTEST=1 prüft den Detektor.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

scan() {
	local dir="$1" rc=0
	grep -rnE 'datetime\(|COLLATE|INSERT OR|REPLACE INTO|PRAGMA|ON CONFLICT|\$_SESSION|X-User-ID' "$dir" && rc=1
	grep -rnE -- '->(prepare|executeQuery|executeStatement)\(\s*['"'"'"]' "$dir" && rc=1
	# IFunctionBuilder::md5 ist seit NC 35.0.0 deprecated
	grep -rnE -- '->md5\(' "$dir" && rc=1
	return $rc
}

if [[ "${SELFTEST:-0}" == 1 ]]; then
	tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
	echo '<?php $db->executeQuery("SELECT 1");' > "$tmp/probe.php"
	scan "$tmp" >/dev/null && die "Selbsttest: Roh-SQL nicht erkannt"
	echo '<?php $x = $_SESSION["a"];' > "$tmp/probe.php"
	scan "$tmp" >/dev/null && die "Selbsttest: \$_SESSION nicht erkannt"
	echo '<?php $qb->func()->md5("content");' > "$tmp/probe.php"
	scan "$tmp" >/dev/null && die "Selbsttest: md5 im QueryBuilder nicht erkannt"
	echo '<?php $qb->executeQuery();' > "$tmp/probe.php"
	scan "$tmp" >/dev/null || die "Selbsttest: Fehlalarm bei QueryBuilder"
	echo "check-forbidden-sql: Selbsttest OK"
fi

if scan "${1:-$NN_REPO_ROOT/nextnotes/lib}"; then
	echo "check-forbidden-sql: 0 Treffer"
else
	die "verbotene Muster gefunden (siehe oben)"
fi
