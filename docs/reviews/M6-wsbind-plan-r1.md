VERDICT: BLOCKER

Review M6-wsbind Plan r2 (speccritic, Runde 1), Basis e258fcd5.

1. BLOCKER – `make-private` 409 `has_assignments` (Plan §3, §8; SPEC §3 Zeile „Team orphaned“, §8 „Team gelöscht“; Code `TeamLifecycleService::makePrivate`). In verwaisten Teams darf nur `owner_uid` Aufgaben löschen, `created_by` des Workspace nicht. Häufiger Fall: Lehrkraft A hat den Workspace angelegt, Lehrkraft B die Aufgabe. Dann kann A erst umwandeln, wenn B löscht. Bei `owner_uid` NULL (gesperrt, ADR 0008 c) kann das niemand. Folge: A verliert nach Fristende alle Notizen des Workspace. Das widerspricht SPEC §8 („created_by … kann ihn bis Fristende in privat umwandeln … bleibt erhalten“) und Thomas 2026-11-07 („mit allen Notizen“). Das ist eine Produkt- bzw. Datenverlust-Frage, also Thomas fragen. Der Plan muss den Fall auflösen, zum Beispiel so:
   (a) Umwandeln bleibt erlaubt; die Invariante gilt nur für aktive Teams. Die Listenregel (L1) richtet sich nach dem Team-Zustand (Aufgaben nicht aktiver Teams), nicht nach der Lesbarkeit. Aufgaben gehen mit der Team-Frist.
   (b) 409 nur bei Aufgaben mit `owner_uid` = uid; die übrigen nach Variante (a).
   Außerdem fehlt: Der 409-Check muss in der Transaktion unter der Team-Sperre laufen (nach `teamRows->lockRow`).

2. MINOR – Löschen ohne Ziel (Plan §3):
   - Zu ergänzen: `purge` läuft erst nach `confirm` und nach der Vorprüfung „kein Kandidat“.
   - Rennen: Entsteht nach dem Purge parallel ein zweiter Team-Workspace, verschiebt die Transaktion die Inhalte dorthin, die Aufgaben sind aber schon gelöscht. Unter `lockOwner` gilt: Lief der Weg „ohne Ziel“ und gibt es jetzt doch einen Kandidaten, dann 409.
   - Teilabbruch (einige Aufgaben gelöscht, Workspace bleibt) ausdrücklich als zulässig festhalten (Wiederholung setzt fort).
   - Die Team-Sperre gilt für beide Zweige derselben Transaktion; heute steht sie nur bei „mit Ziel“. Gibt es keine `nextnotes_teams`-Zeile, sperrt `lockRow` nichts. Das ist hier unkritisch, weil `create` per `lockRows` erneut prüft. Bitte so festhalten.

3. MINOR – `create` (Plan §2): Die Nachprüfung muss die Rückgabe von `workspaces->lockRows([wsId])` auswerten (0 → 404). NC setzt READ COMMITTED (`.ref/.../DB/SetTransactionIsolationLevel.php`), ein erneutes Lesen geht also auch. Die Rückgabe auszuwerten ist trotzdem die DB-neutrale Form. Die Workspace-Sperre gehört vor `copyAsTemplate`.

4. MINOR – Sichtbarkeit (Plan §2 `view`, §4 `inScope`): `workspace_id` geht „immer“ auch an Benutzer, die den Workspace nicht lesen können (Schüler und `owner_uid` verwaister Teams). Das ist eine ID-Preisgabe entgegen SPEC §3 („keine ID-Aufzählung“). Vorschlag: `workspace_id` und `workspace` nur, wenn lesbar, sonst null; `inScope` (L1) prüft auf null statt `reachable`.

5. MINOR – Done-Kriterien (§7) unvollständig:
   - L1 testen: `owner_uid` und Schüler eines verwaisten bzw. eingefrorenen Teams sehen die Aufgabe im eigenen Workspace, nicht in fremden Team-Workspaces; `?assignment=` dito; `workspace` ist null, wenn nicht lesbar.
   - Migration: Dateien und AppData der Abgaben sind wirklich weg (`upgrade-path.sh` prüft nur 404). Ein PHPUnit-Test ruft `preSchemaChange` direkt auf.
   - Nachweis, dass der 409-Check von `make-private` unter der Sperre läuft.
   - Teams-Ressource: alte ID `assignments:<teamId>` wird nicht mehr aufgelöst; `isSharedWithTeam` gilt für Workspaces fremder Teams nicht.

6. HINWEIS – Migration (Plan §1, §8): Gegen `.ref` geprüft. `MigrationService::createInstance` nutzt `Server::get`, die App-Container-Auflösung für `OCA\…` funktioniert, Konstruktor-Injektion geht. `executeStep` ruft `preSchemaChange` vor `changeSchema`. Neuinstallation (`migrateSchemaOnly`) überspringt `preSchemaChange`, das passt. SQLite: Neue Spalte plus Index führen in DBAL `getSimpleAlterTableSQL` zum Neuaufbau der Tabelle, NOT NULL ist auf der leeren Tabelle unkritisch. Restrisiken:
   - Spätere Migrationen ändern Mapper und Services, die `purge` nutzt. Ein Upgrade 0.9.2 → ≥ 0.9.4 läuft dann neuen Code gegen das alte Schema. Bitte einen Kommentar in die Migration und nach `changeSchema` eine Leerprüfung mit klarer Ausnahme.
   - `markProcessed` wirkt während `occ upgrade` womöglich nicht. Das ist unkritisch, weil `Notifier` bei fehlender Aufgabe `AlreadyProcessedException` wirft. Das Done-Kriterium „Benachrichtigungen verarbeitet“ deshalb nicht für die Migration fordern.

7. HINWEIS – Scope: §4.1 (Lupe `sort=recent`, `sourceMoreFiltered`) gehört zu UI-tplpick, nicht zur Workspace-Bindung. Der Lead entscheidet, ob das hier mitläuft.

8. HINWEIS – §8 L1 ist laut Auftrag vom Lead entschieden; den Punkt im Plan schließen. Die Prüfnotiz zu `.ref` ist mit Punkt 6 erledigt. SPEC §8 „auch Teams nur mit Aufgaben“ kann mit der Invariante entfallen, sofern Punkt 1 nicht Variante (a) wird.
