# M7e – Architekt (Upgrade-Pfad)
Status: fertig
Auftrag: `docs/slices/M7-plan.md` r2 §M7e: `dev/nextcloud/upgrade-path.sh <env> <from-ref>`, Startpunkte 0.1.0/0.1.2/0.3.3 × sqlite/mariadb/pgsql; Schema=frisch, Daten lesbar, Jobs=info.xml (+Gegenproben), smoke-m6. Projekt nextnotes-m7e, NN_PORT_BASE=9200.

## Erledigt
- Plan + M7a-Progress gelesen; Werkzeuge gelesen (env/up/down/install-app/lifecycle/check-schema/run-e2e/demo update.sh)
- Refs: 0.1.0 = fe470b2c (M1b, nur Migration 0001, kein Build/Makefile), 0.1.2 = 86e514e6 (M4d, Lockfile = Hauptbaum), 0.3.3 = 1378e5d8 (Lockfile = Hauptbaum)
- Migrationen nie nachträglich geändert (git log je Datei); HEAD-Version 0.3.3 → Upgrade 0.3.3→0.3.3 ist für die App ein No-op
- `make -C nextnotes dist` Exit 0, HEAD e1379f85, sha256 93cca7c4…17f5

- neu dev/nextcloud/upgrade-path.sh, neu dev/nextcloud/scripts/upgrade-probe.php (bash -n / php -l OK)
- Logs: dev/nextcloud/.cache/m7e/<env>-<ver>.log
- Paket wurde parallel (andere Slices) neu gebaut → Kopie im Scratchpad `pkg/nextnotes-0.3.3.tar.gz` (sha 9d2c697e…, HEAD f2d6b00e), Läufe mit NN_APP_TARBALL darauf
- Lauf 1 sqlite 0.3.3: alles grün außer eigener Prüfung Team-Workspace (Feld `team` = Teamname, nicht ID) → Skript korrigiert
- README dev/nextcloud: Zeile upgrade-path.sh ergänzt
- Runner im Scratchpad run.sh
- sqlite Lauf A: 0.3.3 grün, 0.1.0 grün, 0.1.2 ROT: REST 500 nach Upgrade („material is not a valid attribute“) = OPcache des Webservers hält alte Entity-Klassen (revalidate_freq 60, occ leert nur CLI) → kein App-Bug
- Fix im Skript: nach occ upgrade Dienst neu starten (wie NC-Handbuch); sqlite 0.1.2 danach grün (83 s)
- Befund für M7d-Doku: nach Austausch der App-Dateien PHP/Webserver neu starten bzw. Wartungsmodus+Webanfrage

- Lauf B (Skript final): mariadb 0.3.3 grün 190 s, 0.1.2 grün 137 s; pgsql 0.3.3 grün 171 s, 0.1.2 grün 121 s; sqlite 0.3.3 grün 99 s
- Gegenproben sqlite: NN_UPGRADE_BREAK=schema (ab 0.1.2) Exit 1 (check-schema „unerwartete Spalte“ + Diff; REST 500 wegen Fremdspalte), NN_UPGRADE_BREAK=jobs (ab 0.1.0) Exit 1 („Job fehlt: LockCleanupJob“)

- 0.1.0: mariadb grün 127 s, pgsql grün 113 s, sqlite grün 83 s → alle 9 Läufe grün (Exit 0)
- Abbau: down.sh all Exit 0, Netz entfernt, keine Container/Volumes/Netze nextnotes-m7e, worktree list nur Hauptbaum

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen/Annahmen
- Jobprüfung: fehlend = info.xml-Job nicht in oc_jobs; verwaist = App-Klasse in oc_jobs ohne Klasse oder TimedJob nicht in info.xml; QueuedJob (UserDataDeletionJob) erlaubt
- Schema: check-schema.php (SPEC) + jq-Diff gegen frische Paket-Installation derselben DB (Cache je Paket-sha)
- occ upgrade meldet NC-Kern-Hinweis (oc_job_classes_registry/oc_federated_invites fehlender Index), nicht App
- Upgrade 0.3.3→0.3.3 ist für die App ein No-op (keine neue Version); echter Versionssprung erst nach Bump (M7b/Q1 0.9.0) → in M7g wiederholen
