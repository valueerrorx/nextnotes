#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# run-phpunit.sh <env> [phpunit-args…]: PHPUnit aus tests/nextnotes im Container (www-data) gegen die Instanz-DB; Exit-Code von PHPUnit.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

env="${1:?Aufruf: run-phpunit.sh <env> [phpunit-args…]}"; shift
check_env "$env"
case "$env" in sqlite|mariadb|pgsql) ;; *) die "PHPUnit nur in Apache-Umgebungen (sqlite, mariadb, pgsql)" ;; esac
svc="$(svc_for "$env")"
tests="$NN_REPO_ROOT/tests/nextnotes"
[[ -x "$tests/vendor/bin/phpunit" ]] || die "PHPUnit fehlt: composer install -d tests/nextnotes"
dest=/tmp/nn-tests

# Tests + Referenzdateien (Routen-Diff) in den Container
stage="$(mktemp -d)"; trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/ref"
cp "$NN_REPO_ROOT/src/public/api/v1/index.php" "$stage/ref/index.php"
cp "$NN_REPO_ROOT/docs/inventory/A-backend.md" "$stage/ref/A-backend.md"
{ tar -C "$tests" --exclude=.phpunit.cache -cf - . ; } \
	| dc "$env" exec -T -u root "$svc" sh -c "rm -rf '$dest' && mkdir -p '$dest' && tar -xf - -C '$dest'"
tar -C "$stage" -cf - ref | dc "$env" exec -T -u root "$svc" sh -c "tar -xf - -C '$dest' && chown -R www-data:www-data '$dest'"

occ "$env" app:enable notifications >/dev/null

# APCu im Webserver kennt die per occ aktivierte App ggf. erst nach kurzer Zeit (404)
url="$(base_url "$env")/index.php/apps/nextnotes/"
for i in $(seq 1 30); do
	code="$("${NN_CURL[@]}" -sS -u "$NN_TEST_USER:$NN_TEST_PASS" -o /dev/null -w '%{http_code}' "$url")"
	[[ "$code" == 404 ]] || break
	sleep 1
done
[[ "$code" == 200 ]] || die "$env: App-Seite HTTP $code statt 200"

set +e
dc "$env" exec -T -u www-data -e NN_REF_DIR="$dest/ref" -e NN_BASE_URL=http://localhost "$svc" \
	php "$dest/vendor/bin/phpunit" -c "$dest/phpunit.xml" "$@"
rc=$?
set -e
echo "$env: PHPUnit Exit $rc"
exit $rc
