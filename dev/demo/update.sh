#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# update.sh: startet die Demo-Instanz (http://localhost:8090, admin/admin) und spielt den gebauten App-Stand ein.
# Daten bleiben erhalten. Nur nach einem Commit ausführen (Lead).
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
dc() { docker compose -f "$here/compose.yaml" "$@"; }
occ() { dc exec -T -u www-data nc php occ "$@"; }

make -C "$repo/nextnotes" build >/dev/null
dc up -d
for i in $(seq 1 150); do
	occ status --output=json 2>/dev/null | grep -q '"installed":true' && break
	[[ $i -eq 150 ]] && { echo "Nextcloud nicht bereit" >&2; exit 1; }
	sleep 2
done
dest=/var/www/html/custom_apps/nextnotes
tar -C "$repo/nextnotes" --exclude=.gitignore --exclude=./build --exclude=./Makefile --exclude=./js-src --exclude=./css-src -cf - . \
	| dc exec -T -u root nc sh -c "rm -rf '$dest' && mkdir -p '$dest' && tar -xf - -C '$dest' && chown -R www-data:www-data '$dest'"
occ app:enable nextnotes >/dev/null || true
occ upgrade >/dev/null || true
occ maintenance:repair >/dev/null 2>&1 || true
if ! occ user:info thomas >/dev/null 2>&1; then
	dc exec -T -u www-data -e OC_PASS=thomas-demo-2026 nc php occ user:add --password-from-env --display-name "Thomas" thomas >/dev/null
fi
echo "Demo bereit: http://localhost:8090  (admin/admin, thomas/thomas-demo-2026), Stand $(git -C "$repo" log --oneline -1)"
