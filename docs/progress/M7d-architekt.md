# M7d Architekt – Doku
Auftrag: README (EN), docs/app/admin.md, user.md, upgrade.md (DE) nach M7-plan r2 M7d; Datenschutz für Q3 markieren. Nicht anfassen: info.xml, CHANGELOG, THIRD-PARTY-NOTICES, l10n, Screenshot-Skript.

Status: fertig

## Erledigt
- Plan, README, ADR 0008, release-checklist gelesen
- Abgleich gelesen: SPEC §1.3/1.4/2.2/3/5/8/9.7, info.xml (5 commands), AdminSettings (Embed-Domains ≤100, max. Datei 20 MiB 1–2048, Quote 500 MiB 1–1048576), Command-Optionen, nginx-Conf, .ref (OC.php, SecurityMiddleware, Updater, Installer, AppManager::isUpgradeRequired), Rail/UI-Namen (de.json), STATUS M7e (OPcache)
- Befund: Gruppenbeschränkung nur für App-Zugriff (SecurityMiddleware); Austeilen/Teilen prüfen sie nicht → admin.md §3
- Befund: nginx doppelt nosniff = server add_header + App-Header (core sendet wegen modHeadersAvailable nicht) → admin.md §4, Abhilfe fastcgi_hide_header (ungetestet)
- Befund: Downgrade verweigert nur der App-Store-Installer; manuell älteres Paket + occ upgrade läuft (isUpgradeRequired prüft !=) → upgrade.md warnt
- Befund: occ upgrade lässt vorher aktiven Wartungsmodus an (Updater) → upgrade.md
- nextnotes/README.md (EN) neu, Betrieb-Abschnitt nach admin.md verschoben
- docs/app/admin.md (12 Abschnitte, §10 Datenschutz mit Q3-Kommentar), docs/app/upgrade.md, docs/app/user.md
- Link-Check relativ (bash, alle 11 Links OK, Exit 0)

## Nächster Schritt
- keiner (Tester: occ --help-Liste, Lead: Review)

## Offen
- info.xml <documentation> zeigt auf blob/main (Branch nextcloud-port) – M7c/Thomas
- Ex-Mitglied/Schüler ohne Team-Workspace: kein Rail-Eintrag „Aufgaben“ → Weg zur eigenen Abgabe nur per Benachrichtigungslink/URL
