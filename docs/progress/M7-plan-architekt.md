# M7-plan – Architekt

Status: fertig (r2)

## Auftrag
Plan `docs/slices/M7-plan.md` (Paketierung): Slices M7a… mit Scope, Dateien, Done, Testnachweis; [THOMAS]-Punkte trennen. Nur lesen, lokal bauen/prüfen.

## Erledigt
- Gelesen: STATUS.md (M7-Bezüge Z. 62, 135, 235), SPEC §1.1–1.3, §8, §13 M7, ADR 0001, 0007, 0008; M2-plan O10, M6-plan §2.8
- Bestand: `nextnotes/Makefile` (build, check-upstream, clean; kein dist), `dev/nextcloud/*` (install-app, lifecycle, validate-info, run-m6, run-e2e, check-forbidden-sql), `dev/demo/update.sh` (nur gelesen)
- Befund: occ `export-user`/`delete-user-data`/`purge` und `NextNotesMigrator` fehlen (SPEC §8/ADR 0008 → M7)
- App-Store-Doku gelesen (Zertifikat, Signatur, Archiv, info.xml, CHANGELOG-Format)

- tar-Probelauf im Scratchpad: 2× gleiche sha256, 7,9 MB, 418 Einträge (rc 0)
- `.ref` Checker: sign-app nutzt RSA-PSS → signature.json nicht reproduzierbar
- Plan geschrieben: `docs/slices/M7-plan.md` r1 (M7a–M7g, Q1–Q5, [THOMAS]-Liste)

## r2 (Review docs/reviews/M7-plan-r1.md)
- Review gelesen; Vorgaben Lead: B1 nur deaktiviert/gelöscht, B2 Q2, H1/3/5/6/7

- Plan r2 geschrieben: M7a (node_modules/Lockfile, Versionen, Done 5), M7b neu (delete-user-data nur deaktiviert/gelöscht, synchron, Done 1–9; purge nur bei Q2; Migrator canImport=true), M7c/M7d offline-URLs, M7e +0.1.2 + Jobprüfung, Q2 neu, Q6 neu, Risiken
- OCP geprüft: IUserConfig getKeys/deleteUserConfig/deleteApp (@since 32), IUser::isEnabled

## Nächster Schritt
keiner (Review r2 durch speccritic)

## Offen/Annahmen
- siehe Plan §Fragen
