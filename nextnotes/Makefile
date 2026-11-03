# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# make build | check-upstream | clean (dist folgt in M7). Netz nur einmalig: npm ci --prefix build
.PHONY: build check-upstream clean

build:
	node build/build.mjs

check-upstream:
	build/check-upstream.sh

clean:
	rm -rf js css i18n
