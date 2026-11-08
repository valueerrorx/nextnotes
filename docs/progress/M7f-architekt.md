# M7f Architekt – Signatur und Release-Checkliste
Status: fertig
Auftrag: `dev/nextcloud/sign-release.sh`, `docs/app/release-checklist.md`, ADR 0001 Nachtrag (App-ID-Prüfung). Probelauf mit Wegwerf-Schlüssel, Instanz `nextnotes-m7f` (Port 9300, sqlite), danach abbauen.

## Erledigt
- Plan M7f gelesen.


## Offen/Annahmen
- up.sh sqlite (nextnotes-m7f, 9300) exit 0
- `dev/nextcloud/sign-release.sh` geschrieben (sign + --verify, NN_SIGN_TEST_CA nur Probelauf), bash -n ok
- Wegwerf-Schlüssel/Zertifikat (CN=nextnotes, 2 Tage) im Scratchpad `keys/`; `make -C nextnotes dist` exit 0, sha256 9d2c697e…, Kopie nach Scratchpad `pkg/`
- Probelauf 1 ohne Test-CA: exit 1, check-app nur EXCEPTION "Certificate is not valid.", Release-Signatur OK
- Fix: root.crt endet ohne Zeilenumbruch → Test-CA mit vorangestellter Leerzeile anhängen
- Probelauf 2 mit NN_SIGN_TEST_CA: exit 0, check-app OK (Dateivergleich), Release-Signatur OK, Ausgabe Scratchpad `out2/`
- Gegenproben --verify (Test-CA): geändert → INVALID_HASH, Zusatzdatei → EXTRA_FILE, entfernt → FILE_MISSING, Release-Signatur je FEHLER, exit 1; gutes Paket exit 0; ohne Test-CA nur Kettenfehler
- Inhalt: unsigniert + signature.json = signiert (Liste und `diff -r`)
- Guards: CN falsch, Schlüssel≠Zertifikat, Schlüssel im Repo nicht ignoriert, Paket schon signiert → exit 1 (Fix SIGPIPE/pipefail bei letzterem)
- Eigener Lebenszyklus (Projekt leer → up, danach `down.sh all`): 36 s, 0 Container/Volumes/Netze übrig. Signiertes Paket + .sig bytegleich zu Lauf 2 (PSS mit Salt 0 deterministisch; Plan-Annahme „je Lauf anders“ falsch)
- `.gitignore` (dev/nextcloud, nextnotes): `*.key`, `*.csr` ergänzt; `.cache/` war schon ignoriert
- App-ID: GET apps.json 200 (942 Apps), keine ID/Name nextnotes; /apps/nextnotes 404 → ADR 0001 Nachtrag
- `docs/app/release-checklist.md` geschrieben; M7c/M7d/M7e/M7g-Punkte offen (nicht abhakbar)
- Instanz nextnotes-m7f abgebaut (0 Container/Volumes/Netze)

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen/Annahmen
- `dev/nextcloud/README.md`-Zeile für sign-release.sh nicht ergänzt (M7e bearbeitet die Datei parallel)
- Plan-Risiko „signiertes Paket nicht reproduzierbar“ widerlegt (deterministisch)
