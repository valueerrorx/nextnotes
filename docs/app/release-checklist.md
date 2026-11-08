<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Release-Checkliste Next Notes

Reihenfolge von oben nach unten. Alles mit **[THOMAS]** geht nach außen (Konto, GitHub, App Store) und wird nur von Thomas ausgeführt; Agenten führen diese Schritte nie aus.
Schlüssel und CSR liegen nur bei Thomas, außerhalb des Repos (Vorschlag `~/.nextcloud/certificates/`); `*.key`/`*.csr` sind zusätzlich in `nextnotes/.gitignore` und `dev/nextcloud/.gitignore` gesperrt.

## 1. Vorbereitung im Repo
- [x] Reproduzierbares Paket: `make -C nextnotes dist` → `nextnotes/dist/nextnotes-<version>.tar.gz` + `.sha256`, `dev/nextcloud/check-dist.sh` grün (M7a).
- [x] Signier-Skript `dev/nextcloud/sign-release.sh` mit Probelauf (Wegwerf-Schlüssel, selbst signiert): `signature.json` deckt genau den Paketinhalt ab, Gegenproben geändert/zusätzlich/fehlend rot (M7f).
- [x] App-ID `nextnotes` im App Store frei (Lesezugriff 2026-11-08, ADR 0001 Nachtrag).
- [ ] `info.xml`: Release-Version (Q1), `summary`, `description` EN + DE, `<website>`, `<documentation>`, `<screenshot>` (HTTPS, ≤ 2 MiB), `validate-info.sh` grün (M7c).
- [ ] `nextnotes/CHANGELOG.md`: Abschnitt `## <version> – <Datum>` = `info.xml`-Version (M7c).
- [ ] `THIRD-PARTY-NOTICES.md`, `COPYING`, `LICENCE` im Paket (M7c).
- [ ] Doku `docs/app/admin.md`, `user.md`, `upgrade.md` (M7d); Upgrade-Pfad von 0.x getestet (M7e).
- [ ] Volle Matrix grün, App aus dem Paket installiert (M7g).
- [ ] [THOMAS] Freigaben Q1 (Version), Q3 (Datenschutzabschnitt), Q5 (Screenshots), Namensprüfung „Next Notes“ (ADR 0001).

## 2. Zertifikat (einmalig)
- [ ] [THOMAS] Schlüssel + CSR erzeugen: `openssl req -nodes -newkey rsa:4096 -keyout nextnotes.key -out nextnotes.csr -subj "/CN=nextnotes"`.
- [ ] [THOMAS] PR an `nextcloud/app-certificate-requests` mit `nextnotes/nextnotes.csr` (GitHub-Profil mit sichtbarer Mail, Quellcode öffentlich).
- [ ] [THOMAS] Signiertes Zertifikat `nextnotes.crt` aus dem PR speichern; CN muss `nextnotes` sein.

## 3. App Store (einmalig)
- [ ] [THOMAS] Konto auf apps.nextcloud.com anlegen.
- [ ] [THOMAS] App registrieren: Zertifikat einfügen und Registrierungssignatur über die App-ID: `echo -n nextnotes | openssl dgst -sha512 -sign nextnotes.key | openssl base64`.

## 4. Je Release
- [ ] [THOMAS] Repo `valueerrorx/nextnotes` öffentlich, Branch pushen, Tag `v<version>` setzen und pushen (erst danach gelten `<bugs>`, `<website>`, `<documentation>`, Screenshot-URLs).
- [ ] [THOMAS] Screenshot- und Doku-URLs aus `info.xml` im Browser erreichbar.
- [ ] Paket aus dem Tag bauen: `make -C nextnotes dist` (zweimal, gleiche sha256).
- [ ] [THOMAS] Signieren mit echtem Schlüssel: `dev/nextcloud/sign-release.sh nextnotes/dist/nextnotes-<version>.tar.gz <key> <crt>` → `nextnotes/dist/signed/nextnotes-<version>.tar.gz` + `.sha256` + `.sig`. Das Skript startet eine eigene Wegwerf-Umgebung (`NN_PROJECT`, Standard `nextnotes-sign`, Ports ab 9301), legt den Schlüssel nur kurz in `/dev/shm` des Containers und entfernt die Umgebung danach. Erwartet: `check-app: OK` und `Release-Signatur: OK`.
- [ ] [THOMAS] GitHub-Release zum Tag anlegen, signiertes `nextnotes-<version>.tar.gz` hochladen; Download-URL (HTTPS) notieren.
- [ ] [THOMAS] Release im App Store hochladen: Download-URL, Inhalt der `.sig`-Datei als Signatur, **Nightly: nein**.
- [ ] [THOMAS] Store-Seite prüfen (Version, Beschreibung, Screenshots, Kompatibilität NC 35).

## 5. Nach dem Release
- [ ] Demo-Update durch den Lead (`dev/demo/update.sh`), nur nach Freigabe von Thomas; Agenten fassen die Demo nicht an.
- [ ] `CHANGELOG.md`: neuen Abschnitt `## [Unreleased]` anlegen.
- [ ] [THOMAS] Optional: Upstream-PRs an Poznote (SPEC §11.1, „Upstream-PR-Kandidat“).

## Hinweise
- Probelauf ohne echtes Zertifikat: `NN_SIGN_TEST_CA=<wegwerf.crt>` lässt den Wegwerf-Container zusätzlich diesem Zertifikat vertrauen, damit `integrity:check-app` auch den Dateivergleich zeigt. Ohne diese Variable meldet ein selbst signiertes Zertifikat nur `Certificate is not valid.`
- Prüfen eines signierten Pakets: `dev/nextcloud/sign-release.sh --verify <paket> <crt> [<sig>]`.
- Signatur ist deterministisch (RSA-PSS mit Salt-Länge 0, `openssl dgst` PKCS#1 v1.5): gleicher Schlüssel + gleiches Paket → bytegleiches signiertes Paket und `.sig`.
