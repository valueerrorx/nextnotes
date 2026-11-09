# M9a Architekt – Fortschritt

Status: in Arbeit

## Auftrag
OneNote-Import Grundlage (Plan M9-plan.md r3 §M9a): Admin-Einstellungen, OAuth PKCE, Token-Vault, GraphClient, Migration 2 Tabellen, Lebenszyklus (purge/delete/export/migrator), Bestandsprüfungen, Mock msmock.

## Erledigt
- Plan, ADR 0020, STATUS gelesen

## Nächster Schritt
- Bestand sichten (Migrationen, AdminSettings, UserDataService, compose)

## Offene Punkte / Annahmen
- Bestand gesichtet: Migrationen bis 000600, AdminSettings(+Controller, Template, JS), UserDataService (UID_COLUMNS, neutralize), Purge::TABLES, UserExportService/ExportUser/Migrator, compose/env/run-m1/upgrade-path/check-purge, expected-m1.json, OCP (IClient, ICrypto, IAppConfig sensitiv, ITimeFactory)
- Entscheidungen: Migration `Version000700Date20261111000000`, App-Version 0.9.0 → 0.9.1 (sonst läuft die Migration beim Upgrade nicht); Routen unter `/api/v1/onenote/…` (einheitlich mit übriger API); Callback `GET /onenote/callback`, Ansicht `GET /onenote.php` (M9a minimal, M9b baut aus); connect: `auth`-Zeile jünger 5 s wird nicht ersetzt (409), damit Done 14 deterministisch ist
- Code geschrieben (php -l ok): Migration `Version000700Date20261111000000`, `lib/Db/OneNoteImport{,Mapper}.php`, `lib/Db/OneNoteItem{,Mapper}.php`, `lib/OneNote/{OneNoteConfig,TokenVault,HttpGate,HttpResult,GateException,OAuthService,OAuthException,TokenSet,RateBudget,GraphClient,GraphException,ConnectService,CallbackResult,TokenException,OneNoteNotifications,OneNoteMessages}.php`, `lib/Controller/OneNoteApiController.php`, Routen; info.xml 0.9.1
- Admin: AdminSettings/Controller/Template/JS um OneNote (Secret nie zurück, Wechsel → reconcile); Ansicht `templates/onenote.php` + `js-src/nextnotes-onenote.js`; l10n de/de_DE; UserDataService (UID_COLUMNS, neutralize), Purge::TABLES, ExportUser (onenote-imports.json), Notifier (onenote_failed/reauth)
- SPEC §8 (15 Tabellen + OneNote-Absatz), check-purge.sh 15, expected-m1.json (+2 Tabellen)
- Tests begonnen: tests/nextnotes/php/OneNote/{FakeHttp,FakeClient,FakeResponse,OneNoteTestCase}.php
- Tests geschrieben: OneNote/{OAuthTest,GraphClientTest,TokenVaultTest,LifecycleTest,ImportRightsTest}.php; PurgeTest (OneNote-Seed + appconfig-Prüfung), AdminTestCase UID_COLUMNS, RouteDiffTest PORT_ONLY
- Entscheidung Mock: kein node-Image lokal → Mock als PHP unter Apache+mod_ssl im vorhandenen Image nextcloud:35.0.2-apache (Abweichung vom Plan „Node“), Steuerung http://ms-mock/__ctl
- Mock: compose-Dienst `ms-mock` (Profil msmock), `dev/nextcloud/msmock.sh` (up/down/ctl), `dev/nextcloud/scripts/msmock/{index.php,start.sh}`; down.sh all inkl. msmock; lifecycle.sh 15 Tabellen; run-m1.sh prüft allow_local_remote_servers
- Tests: OneNote/MockIntegrationTest (Gruppe msmock, phpunit.xml exclude), E2E `tests/nextnotes/e2e/onenote-connect.mjs`
- Instanz nextnotes-m9a sqlite hoch (10801), App 0.9.1 aktiviert; build rc 0, check-upstream rc 0, check-forbidden-sql 0
- sqlite PHPUnit OneNote: 27 Tests grün (Fixes: Helfernamen callback/count kollidierten mit PHPUnit-final; notifications-App in setUp laden; Testreihenfolge common; GraphClientTest zweiter Nutzer)
- läuft: sqlite Filter OneNote|Purge|Export|Delete|Schema|Migrat|RouteDiff|AdminSettings
- Arbeitsbaum-Paket (make dist nimmt nur HEAD) im Scratchpad gebaut, check-dist OK; upgrade-path mit NN_APP_TARBALL geplant (Ref HEAD = 0.9.0)
- sqlite PHPUnit Filter OneNote|Purge|Export|Delete|Schema|Migrat|RouteDiff|AdminSettings: OK 142 Tests (Exit 0); mariadb/pgsql hoch (10802/10803), App 0.9.1
- msmock up (sqlite) ok; PHPUnit --group msmock: OK 3 Tests
- E2E onenote-connect hell sqlite: grün (42 Prüfungen, inkl. Log-Suche Done 6)
- msmock down: allow_local_remote_servers weg, CA entfernt; check-purge.sh sqlite rc 0 (inkl. onenote_*-Config-Prüfung)
- CHANGELOG 0.9.1 (M8-Einträge aus [Unreleased] mit in 0.9.1, OneNote-Grundlage ergänzt); PHPUnit mariadb/pgsql wurden vom 10-min-Hintergrund-Timeout abgebrochen (143) → neu starten detached
- SPEC §(Admin-Formular) OneNote-Absatz, ADR-0020-Nachtrag, README msmock, upgrade-path Zeilenzählung vorher/nachher
- run-m1.sh sqlite grün (Schema OK 15 Tabellen, Done 9 geprüft). Achtung: run-m1 baut per Trap ALLE Umgebungen ab → mariadb/pgsql-PHPUnit abgebrochen; Reihenfolge jetzt: run-m1 mariadb pgsql, danach PHPUnit
- run-m1.sh mariadb pgsql grün (Schema OK 15 Tabellen mysql/pgsql), DONE-0
- upgrade-path.sh sqlite HEAD (0.9.0) → 0.9.1 (Arbeitsbaum-Paket): grün, Zeilen 13→15 Tabellen unverändert, Schema = frisch, 7 Migrationen, smoke-m6 hell grün
- mariadb PHPUnit Filter: OK 142 Tests
- pgsql PHPUnit Filter: OK 142 Tests; mariadb --group msmock OK 3
- pgsql --group msmock OK 3; check-purge.sh pgsql grün; alle Umgebungen nextnotes-m9a abgebaut (0 Container, 0 Volumes, Netz entfernt)
- php -l aller neuen/geänderten PHP-Dateien ok, bash -n Skripte ok, node --check E2E ok

## Status: fertig
## Offene Punkte
- upgrade-path mit Arbeitsbaum-Paket (make dist nimmt nur HEAD); nach dem Commit ggf. mit make dist wiederholen
- CHANGELOG: M8-Einträge aus [Unreleased] stehen jetzt unter 0.9.1 (Versionssprung für Migration nötig)
- E2E nur hell (keine CSS-Änderung)
