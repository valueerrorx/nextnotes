#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# sign-release.sh <tarball> <key> <crt>: signiert das Paket aus make dist (appinfo/signature.json + Release-Signatur) in einer Wegwerf-Umgebung.
# sign-release.sh --verify <signierter-tarball> <crt> [<sig>]: prüft ein signiertes Paket (integrity:check-app, openssl dgst -verify).
# Umgebung: NN_PROJECT (Standard nextnotes-sign), NN_PORT_BASE (Standard 9300), sqlite; läuft sie schon, wird sie genutzt und bleibt stehen, sonst gestartet und danach entfernt.
# Nur Probelauf: NN_SIGN_TEST_CA=<crt> vertraut zusätzlich diesem Zertifikat (nur im Wegwerf-Container), damit check-app auch den Dateivergleich zeigt.
set -euo pipefail
export NN_PROJECT="${NN_PROJECT:-nextnotes-sign}" NN_PORT_BASE="${NN_PORT_BASE:-9300}"
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"
export LC_ALL=C TZ=UTC
[[ "${1:-}" == --verify ]] || { nn_need_gnu_tar; nn_need_gnu_gzip; }
env=sqlite
svc="$(svc_for "$env")"

usage() { die "Aufruf: sign-release.sh <tarball> <key> <crt> | sign-release.sh --verify <signierter-tarball> <crt> [<sig>]"; }
mode=sign
if [[ "${1:-}" == --verify ]]; then mode=verify; shift; fi
if [[ $mode == sign ]]; then
	[[ $# -eq 3 ]] || usage
	tarball="$1" key="$2" crt="$3"
else
	[[ $# -ge 2 && $# -le 3 ]] || usage
	signed="$1" crt="$2" sig="${3:-$1.sig}"
fi
test_ca="${NN_SIGN_TEST_CA:-}"
for f in "${tarball:-}" "${key:-}" "${signed:-}" "$crt" "${sig:-}" "$test_ca"; do
	[[ -z "$f" || -f "$f" ]] || die "$f fehlt"
done

# Zertifikat: CN nextnotes, gültig
cn="$(openssl x509 -in "$crt" -noout -subject -nameopt multiline | sed -n 's/^ *commonName *= *//p')"
[[ "$cn" == nextnotes ]] || die "Zertifikat CN '$cn' statt nextnotes"
openssl x509 -in "$crt" -noout -checkend 0 >/dev/null || die "Zertifikat abgelaufen"

work="$(mktemp -d)"
in_ctr() { dc "$env" exec -T -u www-data "$svc" sh -c "$1"; }
started=0
cleanup() {
	if [[ $started -ge 1 ]]; then
		in_ctr 'rm -f /dev/shm/nn-sign.key; rm -rf /tmp/nnsign /tmp/nncheck /tmp/nnsign.crt' 2>/dev/null || true
		[[ -n "$test_ca" ]] && dc "$env" exec -T -u root "$svc" sh -c \
			'f=/var/www/html/resources/codesigning/root.crt; [ -f "$f.nnbak" ] && mv "$f.nnbak" "$f"' 2>/dev/null || true
	fi
	# Projekt war vorher leer: alles samt Netz und Volumes entfernen
	[[ $started -eq 2 ]] && "$NN_DEV_DIR/down.sh" all >/dev/null 2>&1 || true
	rm -rf "$work"
}
trap cleanup EXIT

if [[ $mode == sign ]]; then
	# Schlüssel nie im Repo (nur in ignorierten Pfaden geduldet)
	keyabs="$(nn_abspath "$key")"
	if [[ "$keyabs" == "$NN_REPO_ROOT"/* ]] && ! git -C "$NN_REPO_ROOT" check-ignore -q "$keyabs"; then
		die "Schlüssel liegt im Repo und ist nicht ignoriert: $keyabs"
	fi
	[[ "$(openssl pkey -in "$key" -pubout)" == "$(openssl x509 -in "$crt" -noout -pubkey)" ]] || die "Schlüssel passt nicht zum Zertifikat"
	"$NN_DEV_DIR/check-dist.sh" "$tarball" >/dev/null
	grep -qx 'nextnotes/appinfo/signature.json' <<<"$(tar -tzf "$tarball")" && die "Paket enthält schon appinfo/signature.json"
fi

# Wegwerf-Umgebung
if [[ -n "$(dc "$env" ps -q --status running "$svc" 2>/dev/null)" ]]; then
	echo "sign: nutze laufende Umgebung $NN_PROJECT/$env"
	started=1
else
	[[ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$NN_PROJECT")" ]] || die "Projekt $NN_PROJECT hat gestoppte Container, erst down.sh"
	started=2
	"$NN_DEV_DIR/up.sh" "$env" >/dev/null
fi
if [[ -n "$test_ca" ]]; then
	echo "sign: PROBELAUF, vertraue zusätzlich $(basename "$test_ca") (nur in diesem Container)"
	dc "$env" exec -T -u root "$svc" sh -c \
		'f=/var/www/html/resources/codesigning/root.crt; cp -p "$f" "$f.nnbak" && { echo; cat; } >> "$f"' < "$test_ca"
fi

# check-app auf dem entpackten Paket; Ausgabe leer = gültig
verify_pkg() {
	local pkg="$1" pubkey="$work/pub.pem" out rc=0
	"$NN_DEV_DIR/check-dist.sh" "$pkg"
	in_ctr 'rm -rf /tmp/nncheck && mkdir /tmp/nncheck && tar -xzf - -C /tmp/nncheck' < "$pkg"
	out="$(in_ctr 'php occ integrity:check-app nextnotes --path=/tmp/nncheck/nextnotes --output=json' 2>&1)" || rc=$?
	if [[ $rc -eq 0 ]]; then
		echo "check-app: OK (Zertifikatskette, Signatur und alle Dateien)"
	else
		echo "check-app: FEHLER"; echo "$out"
	fi
	openssl x509 -in "$crt" -noout -pubkey > "$pubkey"
	if tr -d '\r\n' < "$sig" | openssl base64 -d -A | openssl dgst -sha512 -verify "$pubkey" -signature /dev/stdin "$pkg" >/dev/null 2>&1; then
		echo "Release-Signatur: OK ($(basename "$sig"))"
	else
		echo "Release-Signatur: FEHLER ($(basename "$sig"))"; rc=1
	fi
	return $rc
}

if [[ $mode == verify ]]; then
	verify_pkg "$signed"
	exit
fi

# entpacken (Zeitstempel bleiben), signature.json im Container erzeugen
mkdir -p "$work/x"
nn_tar -xzf "$tarball" -C "$work/x"
epoch="$(nn_mtime "$work/x/nextnotes/appinfo/info.xml")"
version="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$work/x/nextnotes/appinfo/info.xml" | head -n1)"
in_ctr 'rm -rf /tmp/nnsign && mkdir /tmp/nnsign && tar -xzf - -C /tmp/nnsign' < "$tarball"
in_ctr 'cat > /tmp/nnsign.crt' < "$crt"
in_ctr 'umask 077; cat > /dev/shm/nn-sign.key' < "$key"
rc=0
in_ctr 'php occ integrity:sign-app --path=/tmp/nnsign/nextnotes --privateKey=/dev/shm/nn-sign.key --certificate=/tmp/nnsign.crt' || rc=$?
in_ctr 'rm -f /dev/shm/nn-sign.key'
[[ $rc -eq 0 ]] || die "integrity:sign-app fehlgeschlagen"
in_ctr 'cat /tmp/nnsign/nextnotes/appinfo/signature.json' > "$work/x/nextnotes/appinfo/signature.json"
jq -e '.hashes and .signature and .certificate' "$work/x/nextnotes/appinfo/signature.json" >/dev/null || die "signature.json unvollständig"
# signierter Inhalt = Paketinhalt (keine fehlenden, keine zusätzlichen Dateien)
diff <(jq -r '.hashes | keys[]' "$work/x/nextnotes/appinfo/signature.json" | sort) \
	<(cd "$work/x/nextnotes" && find . -type f ! -path ./appinfo/signature.json | sed 's:^\./::' | sort) \
	|| die "signature.json deckt den Paketinhalt nicht ab"

# neu packen mit denselben Flags wie make dist
out="${NN_SIGN_OUT:-$(dirname "$(nn_abspath "$tarball")")/signed}"
name="nextnotes-$version.tar.gz"
mkdir -p "$out"
nn_tar -C "$work/x" --format=gnu --sort=name --mtime="@$epoch" --owner=0 --group=0 --numeric-owner \
	--mode='u=rwX,go=rX' -cf - nextnotes | gzip -n -9 > "$out/$name"
(cd "$out" && nn_sha256 "$name" > "$name.sha256")
# Release-Signatur für den App Store
sig="$out/$name.sig"
openssl dgst -sha512 -sign "$key" "$out/$name" | openssl base64 > "$sig"

verify_pkg "$out/$name" || { echo "sign: Prüfung rot (bei Wegwerf-Zertifikat ohne NN_SIGN_TEST_CA erwartet: 'Certificate is not valid.')" >&2; exit 1; }
echo "sign: $out/$name"
cat "$out/$name.sha256"
echo "Release-Signatur ($sig):"
cat "$sig"
