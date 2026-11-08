# M7-mac – architekt

Status: fertig

Auftrag: dev/-, build- und Test-Skripte macOS-fähig (bash 3.2, BSD-Tools, Docker Desktop arm64), README-Abschnitt macOS. Nicht anfassen: build.mjs, check-notices.mjs, bundles.json, package*.json, tests/.

## Erledigt
- grep-Inventur (bash4, GNU-Tools, Chromium, unshare, timeout, tar --anchored/--sort, sha256sum, stat -c, realpath, xargs -r, ss, base64 -d, date %N)
- Baseline dist: Hauptbaum blockiert (package-lock von M8a weicht von HEAD ab). Ersatz: worktree HEAD c881eed9 in scratchpad/w mit Symlink node_modules → make dist Exit 0, sha256 e7b2320c186e7f75793799f5114143fc86359d33c13a9e9440871c7fbca7f77e

## Entscheidungen
- bash >= 4.4 Pflicht (inherit_errexit, declare -A, set -u mit leeren Arrays); Guard in dev/portable.sh; macOS: brew install bash
- GNU tar (gtar) Pflicht für Host-tar mit --anchored/--sort; GNU gzip für dist/sign (gleicher Hash Linux/macOS)
- Helfer in dev/portable.sh (sha256, size, mtime, abspath, timeout, offline, tar, chromium)

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen
- Mac-Pfad ungetestet (Liste im Bericht); main-tree make dist blockiert durch M8a-Lockfile, nicht Slice-bedingt
- dev/portable.sh angelegt; umgestellt: env.sh (source, xargs -r weg, lsof-Fallback, nn_chromium), run-m5/6/7 (sha256, nn_offline), run-e2e, check-csp (nn_timeout), check-dist (wc -c), validate-info (nn_size), upgrade-path, install-app, run-phpunit (nn_tar, sha), sign-release (gtar/gzip, nn_abspath, nn_mtime, openssl base64, touch -d entfernt), probe-grenzen, probe-m3c/m3c2 (date %N), dist.sh, check-upstream.sh (nn_sha256, nn_chromium, \s→[[:space:]], grep-Optionen vor Pfad), demo/update.sh (nn_tar)
- bash -n alle geänderten: ok; shellcheck (Docker koalaman/shellcheck, -S warning): nur Altbefunde, nichts aus diesem Slice
- bash:3.2-Container: Guard greift (Exit 2, klare Meldung) für portable.sh und env.sh
- Helfer-Tests: nn_timeout-Fallback (124/7/0), shasum=sha256sum, nn_size, nn_mtime, nn_abspath, nn_chromium, GNU-tar-Erkennung (bsdtar → Fehler; gtar gewählt), Apple-gzip → Fehler
- make build Exit 0, make check-upstream Exit 0 (grün)
- dist im worktree 46f575e4: vorher e7b2320c…f77e, nachher gleich; auch mit tar=bsdtar + gtar=GNU gleich
- Smoke nextnotes-mac/10200: up.sh sqlite 0, install-app 0, app:enable 0, run-phpunit --filter RouteDiffTest 0 (3 Tests), check-csp 0, down.sh sqlite + all 0, nichts übrig; Demo/gftest unberührt
- Doku: dev/nextcloud/README.md (Voraussetzungen + Abschnitt macOS), dev/demo/README.md (Abschnitt macOS)
- validate-info.sh (nn_size) Exit 0; scratch-worktree entfernt
