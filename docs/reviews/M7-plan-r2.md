VERDICT: FREIGABE

Review M7-plan r2 (Basis 1378e5d8) gegen r1-Befunde, SPEC §8/§13 M7, ADR 0008, .ref (OCP 35.0.2). Q1–Q6 als [THOMAS] nicht bewertet.

## r1-Befunde
- B1 behoben: kein `--force`, nur deaktiviert/gelöscht, aktives Konto → Abbruch ohne Änderung (Done 2). a (Done 3 + Risiko Nachzügler-Austeilen mit Lead-Entscheid), b (Done 4, Vergleich beider Wege), c (Ablauf 1–4, Done 5), d (durch Deaktivierung ausgeschlossen). SPEC §8/ADR-0008-Nachtrag in M7b-Dateien.
- B2 behoben: purge nur bei Q2 = ja, Q2 nennt Abgaben trotz Aufbewahrungsfrist und dritten Löschweg, ADR-Nachtrag bedingt in M7b.
- H1–H7 umgesetzt (canImport true + No-op, Reihenfolge purge vor app:remove + IUserConfig/Notifications/Wartungsmodus, kein Export über student_uid_former, synchron + UID-Warnung, Startpunkt 0.1.2 + Jobprüfung, node_modules mit Lockfile-Prüfung + Versionsausgabe, offline-Validierung).
- OCP stichprobenartig bestätigt: `IUserConfig::getKeys/deleteUserConfig/deleteApp` (@since 32), `IAppConfig::deleteApp`, `IJobList::remove`, `registerUserMigrator`.

## Hinweise (in der Umsetzung M7b beachten)
1. purge und `IAppConfig`: `deleteApp('nextnotes')` löscht auch die vom Kern verwalteten Schlüssel `enabled`, `installed_version`, `types`. `AppManager::isInstalled()` liest `enabled` aus der appconfig (`lib/private/App/AppManager.php` Z. 432–439). Danach gilt die App als nicht installiert, `app:remove` in Done 7 schlägt voraussichtlich fehl, der Zustand ist inkonsistent (Tabellen und `oc_migrations` bleiben). Festlegen: nur App-eigene Schlüssel löschen (Kern-Schlüssel ausnehmen), Done 7 prüft `app:remove` Exit 0.
2. delete-user-data, „Konto nicht vorhanden“: `IUserManager::get()` = null gilt auch bei vorübergehend nicht erreichbarem Benutzer-Backend (LDAP/SAML, in Schulen üblich). Dann würden Daten eines aktiven Kontos gelöscht. Empfehlung: Bei nicht gefundener UID mit vorhandenen Daten eine ausdrückliche Bestätigung verlangen (interaktiv oder Option wie `--user-deleted`), dazu ein Done-Fall und eine Doku-Warnung.
3. Reaktivierung nach delete-user-data: Das Konto bleibt Circles-Mitglied. Nach der Reaktivierung erhält es wieder Team-Zugriff und neue Nachzügler-Kopien offener Aufgaben. Q6 nennt nur den Verlust der alten Abgaben. Ein Satz in Q6 und der Admin-Doku genügt.
