# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# make build | check-upstream | clean | dist. Netz nur einmalig: npm ci --prefix build
# dist: Paket aus sauberem worktree von HEAD nach dist/ (Ausschlüsse: build/dist-exclude.txt)
.PHONY: build check-upstream clean dist

build:
	node build/build.mjs

check-upstream:
	build/check-upstream.sh

clean:
	rm -rf js css i18n

dist:
	build/dist.sh
