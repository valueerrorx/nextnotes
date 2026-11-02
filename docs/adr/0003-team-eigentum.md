# ADR 0003: Team-Eigentum und Rechteprüfung (Auftragsfrage 2)

Status: entschieden (Thomas, 2026-11-02): wie vorgeschlagen angenommen. Teilentscheidungen Lead (2026-11-02): Auslösung nur per Event, Einfrieren, Übernahmefrist, effektive Mitgliedschaft.

## Kontext
Workspaces können privat bleiben oder einem Nextcloud-Team gehören. Mitgliederänderungen sollen ohne Sync wirken; die App muss ohne Teams-App (Circles) privat weiterlaufen. Geprüft in `.ref` (v35.0.2):
- `OCP\Teams\ITeamManager` (@since 29): `getProviders`, `getProvider`, `getSharedWith`, `getTeamsForResource` (29); `getTeamsForUser` (33.0.0); `getSharedWithList` (33.0.0, Parameter `$resourceId` ab 34.0.0); **`getMembersOfTeam` (@since 34.0.0)**, **`hasTeamSupport` (@since 34.0.0)**, **`getTeam` (@since 35.0.0)**, **`getTeamFolderProvider` (@since 35.0.0)**.
- `OCP\Teams\ITeamResourceProvider` (@since 29), Registrierung `IRegistrationContext::registerTeamResourceProvider` (@since 29). `ITeamFolderProvider`, `TeamFolder` (@since 35.0.0).
- `OCP\Teams` liefert **keine Rollen**. Rollen gibt es nur in der Circles-App (nicht OCP): `OCA\Circles\CirclesManager`, `OCA\Circles\Model\Member::LEVEL_MEMBER=1, LEVEL_MODERATOR=4, LEVEL_ADMIN=8, LEVEL_OWNER=9` (geprüft gegen github.com/nextcloud/circles `stable35`; Circles liegt nicht in `.ref`). Zielversion ist nur NC 35 (Thomas, 2026-11-02), daher sind alle `ITeamManager`-Methoden bis @since 35 nutzbar. Die private `OC\Teams\TeamManager` nutzt selbst `CirclesManager::getFederatedUser/startSession/getCircle/probeCircles`.
- Events (Circles, nicht OCP): `CircleDestroyedEvent`, `CircleMemberAddedEvent`, `CircleMemberRemovedEvent`, `CircleMemberEditedEvent`.

## Optionen
1. Mitglieder in eigene Tabelle spiegeln (Sync per Events).
2. **Eigentum als Spalte am Workspace, Mitgliedschaft und Rolle bei jedem Zugriff live abfragen** (mit Request-Cache).
3. Team-Ordner (`ITeamFolderProvider`) als Eigentumsträger – erst ab NC 35, passt nicht zu ADR 0002.

## Entscheidung
Option 2:
- `nextnotes_workspaces.owner_type` = `user` | `team`, `owner_id` = UID bzw. Circle-`singleId`; `created_by` = UID.
- Ein Adapter `TeamService` kapselt alles: `isAvailable()`, `getTeamsForUser(uid)`, `getLevel(teamId, uid): int` (0 = kein Mitglied), `getMembers(teamId)`. OCP, wo es reicht: `ITeamManager::hasTeamSupport()` (34) für `isAvailable()`, `getTeam()` (35) für Existenz und Namen, `getTeamsForUser()` (33), `getMembersOfTeam()` (34) für Mitgliederlisten. Circles-API nur für Rollen-Level und Mitgliedsstatus (`Membership::getLevel`, `Member::STATUS_*`) sowie für Events. Es zählt die **effektive** Mitgliedschaft (inkl. Gruppen und verschachtelter Teams) mit Status `Member`; `Invited`/`Requesting` zählen nicht. Ohne Circles (`hasTeamSupport()` false oder Circles-Klassen fehlen) meldet der Adapter `isAvailable() = false`.
- Rechte: Mitglied (Level ≥ 1) = lesen+schreiben im Team-Workspace; Löschen/Umbenennen des Workspace ab Moderator (≥ 4). Prüfpunkt zentral in `AccessService` (SPEC §3).
- `ITeamResourceProvider` (`id = nextnotes`) meldet Team-Workspaces, damit sie im Team-Arbeitsbereich erscheinen. `ITeamFolderProvider` (35) wird nicht genutzt: Team-Ordner betreffen Files, Notizen liegen in der DB (ADR 0002).
- Team-Zustand (Lead, 2026-11-02, nach Review M0-Nachtrag r1): Tabelle `nextnotes_teams` (`team_id` PK, `state` active|orphaned, `orphaned_at`, `missing_count`, `team_name_snapshot`) ist die einzige Quelle für „verwaist“ und die Frist. Sie wird angelegt, sobald ein Team in Next Notes zum ersten Mal genutzt wird (Team-Workspace oder Aufgabe). Workspaces speichern keinen Zustand; er wird aus dem Team abgeleitet.
- Team gelöscht: `CircleDestroyedEvent` setzt `nextnotes_teams.state = orphaned`, `orphaned_at = now`; nie ein „nicht gefunden“ oder Circles-Fehler beim Zugriff (dann nur 404 für diesen Request). Rückfallweg für verpasste Events (z. B. Circles war bei der Löschung deaktiviert): Der `CleanupJob` prüft täglich jede aktive Team-Zeile per `ITeamManager::getTeam()` (auch Teams ohne Workspace), nur wenn Circles aktiv ist und fehlerfrei antwortet; zweimal hintereinander „Team fehlt“ (`missing_count`) → `orphaned`. Manuell: `occ nextnotes:team:orphan-check [--dry-run]`. Team-Workspaces: Zugriff nur noch für `created_by` (lesen, exportieren); er kann den Workspace bis Fristende in einen privaten umwandeln und löst ihn damit vom Team. Aufgaben bleiben am Team-Datensatz und an dessen Frist (Zugriff für `owner_uid`, ADR 0008). Benachrichtigung beim Verwaisen und 7 Tage vor Fristende (mit Hinweis auf ZIP-Export der Abgaben); nach 30 Tagen löscht der `CleanupJob` alle Aufgaben, Abgaben, Abgabe-Kopien und verbliebenen Team-Workspaces des Teams sowie die Team-Zeile (ADR 0008 b).
- Teams-App deaktiviert oder fehlt: Team-Workspaces sind **eingefroren** (berechnet, nicht gespeichert): lesbar für `created_by`, Schüler lesen nur eigene Abgaben, keine Schreibzugriffe, keine Löschfrist; nach Reaktivierung automatisch wieder normal.
- Aufgaben/Abgaben verwaister oder eingefrorener Teams: Rechte laut SPEC §9.7 (nur `owner_uid`, nie `created_by`); manuelle Aktionen sind gesperrt, der berechnete Fristablauf → `missed` läuft weiter.
- Rechte folgen immer der aktuellen Rolle; gespeicherte UIDs verleihen nur dort Rechte, wo die SPEC (§2 UID-Regel) es nennt, und werden bei Kontolöschung entfernt.

## Konsequenzen
- Abhängigkeit auf Circles-Klassen außerhalb OCP; alle Aufrufe nur im Adapter, mit `class_exists`-Prüfung. Bricht Circles die API, ist nur eine Datei betroffen.
- Jeder Request mit Team-Workspaces fragt Circles (gecacht pro Request). Leistung bei vielen Teams in M5 messen.
- Austeilen/Einsammeln (SPEC §9) nutzt dieselben Levels: ≥ 4 Lehrkraft, = 1 Schüler.
