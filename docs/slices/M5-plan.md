# M5 – Teams: Plan

Stand: 2026-11-07, Architekt, r3 (Review `M5-plan-r1`: 4 BLOCKER, H1–H7; `M5-plan-r2`: 2 BLOCKER, H1–H5; Entscheidungen Thomas Q1–Q4, Verschieben, `make_private`, O5; Übersicht §7). Grundlage: SPEC §2.1 (`workspaces`, `teams`, `ws_shares`), §3 (Rechte, Team-Zustand, Rollen, ohne Teams-App), §5 (`CleanupJob`), §6, §8, §12 R1/R2/R15/R16, §13 M5 (Krit. 1–7); ADR 0003, 0008, 0014; STATUS „Nacharbeit“ (B17 `moveNotes`/`reorder`, CleanupJob Excalidraw-Vorschauen, `filterReadable`/`noteLevel`/`visibleWorkspaceIds`), „Vorläufig M3c2“ (Teilen → 501), „Nächster Schritt“ (Erstellen-Menü Escape).
Nicht in M5: Aufgaben/Abgaben (M6; nur Schnittstellen, §1.6), `IMigrator`/`occ`-Export (M7), Gruppen-Freigaben (ADR 0014 Option 3).
Regeln wie M4: Daten nur über `AccessService`; Seiten-GET ohne Seiteneffekt; jede `src/`-Änderung in §11.1 + `upstream-patches.txt`; Circles nur im Adapter `TeamService`.

## 0. Befund Test-Setup (Probe 2026-11-06, Wegwerf-Container `nextcloud:35.0.2-apache`, gelöscht)
- Circles 35.0.0 ist im Image enthalten, `shippedApps` + `defaultEnabled`, **nicht** `alwaysEnabled` → `occ app:disable circles` geht. Alle bisherigen Testinstanzen liefen also schon mit aktivem Circles.
- Die Contacts-App (dort liegt die NC-Oberfläche „Teams“) fehlt im Image. Tests legen Teams per `CirclesManager` im PHPUnit-Prozess an (Bootstrap lädt NC, HTTP-Aufrufe laufen getrennt).
- `occ circles:check`: Loopback `http://localhost` auf Apache ok. nginx/fpm: Loopback muss auf den nginx-Dienst zeigen (`circles` App-Config `loopback_tmp_scheme`/`loopback_tmp_id`) – im Setup setzen.
- Gemessen: Mitglied über Gruppe → `getLink()->getLevel()` = 1 (effektiv). Kreis mit `CFG_INVITE` (32): Status `Invited`, `getLink` wirft `MembershipNotFoundException`, nicht in `getTeamsForUser`. `removeMember` → `MembershipsRemovedEvent` **synchron im selben Prozess** (enthält Circle-ID + `singleId`, nicht die UID). `CircleDestroyedEvent`/`CircleMemberRemovedEvent` kamen **nicht** im selben Prozess an (Circles verteilt sie über den Loopback-Request `asyncBroadcast`). `app:disable circles` → `hasTeamSupport()` false, `getTeam()` null, `class_exists(CirclesManager)` false. `getMembersOfTeam()` liefert auch die Gruppe als Schlüssel (R2 bestätigt).
- Folge: Lösch-Erkennung darf nicht allein am Event hängen (SPEC hat den Rückfall `orphan-check` schon); M5b prüft zuerst per Test, ob der Listener im Loopback-Request läuft.

## 1. Slice-Teilung

| Slice | Inhalt | Testzeit (Schätzung) | Review |
|---|---|---|---|
| **M5a** | `TeamService` (Adapter), `TeamStateService`, Rechte für Team-Workspaces in `AccessService` (Level, Sichtbarkeit, `filterReadable`), Team-Workspace anlegen/umbenennen/löschen über API, `nextnotes_teams`-Zeile, `ITeamResourceProvider`, Probe-Regel, Verlassen-Regel für alle Verschiebe-/Kopierwege (B17), Freigaben nur Benutzer-Workspaces, `reorder` je Benutzer, Quote je Team, Test-Helfer Circles | ≈ 30 min | ja (Rechte, Daten) |
| **M5b** | Lebenszyklus: Events (Löschung, Austritt), eingefroren/verwaist, Umwandeln in privat, Benachrichtigungen `team_orphaned`/`team_deletion_soon`, Austritts-Aufräumen (Erinnerungen, Erwähnungs-Status, Hintergründe), Kontolöschung um Team-Workspaces (R15, `created_by`) | ≈ 25 min | ja (Rechte, Daten, Datenschutz) |
| **M5c** | `CleanupJob` (Existenzprüfung, Vorab-Benachrichtigung, Purge nach 30 Tagen über `TeamCleanupService`), `occ nextnotes:team:orphan-check [--dry-run]`, verwaiste Anhangsdateien und Excalidraw-Vorschauen | ≈ 20 min | ja (Löschen) |
| **M5d** | Oberfläche: Freigabe-Dialog (`share_workspace`/`unshare_workspace`, NC-Freigaberichtlinie serverseitig, Benutzersuche statt Liste aller Konten, „nur lesen“), „Neuer Workspace“ mit Team-Auswahl, Team-Kennzeichen in Workspace-Menü und Einstellungen, Banner eingefroren/verwaist, Erwähnungen in Team-Workspaces, Notizfarben in fremden Workspaces (R16), Erstellen-Menü Escape | ≈ 30 min | ja (neue Endpunkte, Erwähnungs-Empfänger) |
| **M5e** | Abschluss: Krit. 1–7 vollständig, Isolation/IndirectRead über Team-Pfade, Budget mit Teams, `run-m5.sh`, volle Matrix | Meilenstein-Abschluss | ja |

Reihenfolge = Tabelle. M5b braucht M5a (Zustand, Adapter); M5c braucht M5b (Zustand `orphaned`, Benachrichtigungen); M5d braucht M5a (API) und M5b (Banner, Umwandeln). Jede Slice einzeln lauffähig und committet.

**Zuordnung SPEC §13 M5 → Slice**
| Krit. | Inhalt | Slice |
|---|---|---|
| 1 | Team-Workspace sichtbar für Mitglied, `getSharedWith` enthält Ressource | M5a (API, Provider), M5d (UI) |
| 2 | Entfernen → 404 ohne Job | M5a |
| 3 | Circles aus → eingefroren, Zustand bleibt `active`; wieder an → normal | M5b |
| 4 | Team gelöscht → verwaist, Benachrichtigung, Umwandeln, Purge nach 30 Tagen, 7-Tage-Hinweis; Fehler beim Zugriff ändert nichts | M5b (Event, Umwandeln, Benachrichtigung), M5c (Frist, Purge) |
| 5 | Freigabe an C → lesen/schreiben; entfernen → 404 | M5d (UI-Weg; Backend existiert seit M2a) |
| 6 | über Gruppe sichtbar; Eingeladener nicht | M5a |
| 7 | `orphan-check --dry-run`, zwei fehlerfreie Läufe | M5c |

## 2. Gemeinsame Architektur

### 2.1 `TeamService` (einziger Circles-Zugriff, ADR 0003)
- `isAvailable(): bool` = `ITeamManager::hasTeamSupport()` ∧ `class_exists(CirclesManager::class)`.
- **Eine Probe-Regel** (BLOCKER 3, Lead): Ein Team zählt für Next Notes nur, wenn es ein normales Team ist – Kreise mit `CFG_HIDDEN`, `CFG_BACKEND`, `CFG_PERSONAL` oder `CFG_SINGLE` zählen nie. Die Regel steckt in genau einer privaten Methode `TeamService::probe()` (eigene `CircleProbe` mit `mustBeMember()` + diesen Filtern, Konfiguration zusätzlich am geladenen `Circle` geprüft) und gilt für `memberTeams`, `getLevel`, `members`, `GET /api/v1/teams`, Anlegen und `ITeamResourceProvider` – also nur für Sichtbarkeit und Rechte. `getTeamsForUser` des Kerns wird nicht genutzt (eigene Standard-Probe). Anlegen in einem ausgeschlossenen Kreis → 403; ein Team, das nachträglich versteckt wird, verhält sich für Rechte wie „nicht Mitglied“ (404), sein Zustand bleibt `active`.
- `memberTeams(uid): array<teamId, displayName>` – über `probe()`; nur Status `Member` (Probe-Befund: `Invited` hat keine Mitgliedschaft; `Requesting`/`Blocked` per Test belegen). Ein Aufruf je Request (Cache je uid).
- `getLevel(teamId, uid): int` – `CirclesManager::getLink(teamId, singleId)->getLevel()` nur für Teams, die `probe()` zulässt (sonst 0), `MembershipNotFoundException` → 0; nur nötig für `manage` (≥ 4) und für M6; Request-Cache.
- **Cache zurücksetzbar** (H7): `TeamService::resetCache()` und `AccessService::resetCache()` gemeinsam; Jobs (`ReminderJob`, `CleanupJob`, M6 `AssignmentDeadlineJob`) und `occ`-Befehle setzen je Benutzer bzw. je Team zurück, PHPUnit-Helfer nach jeder Mitgliedsänderung. Kein Cache über den Request hinaus. Ein Circles-Fehler (jede andere Ausnahme) → `TeamUnavailableException` → für diesen Request 0/404, nie Zustandsänderung, Log `warning`.
- **Existenzprüfung getrennt von `probe()`** (r2 BLOCKER 1): `exists(teamId): ?array{id,name}` = ausschließlich `ITeamManager::getTeam(teamId)` ohne Benutzer (Super-Session, **ohne** Konfigurationsfilter: versteckte, Backend- und alle übrigen Kreise gelten als vorhanden). Einzige Quelle für `CleanupJob` Schritt 1, Rückweg `orphaned → active`, Vorprüfung vor dem Purge und `orphan-check`. `null` nur dann als „fehlt“ werten, wenn `isAvailable()` true und kein Fehler; Ausnahme → Fehler, nie „fehlt“.
- `members(teamId): array<uid, displayName>` – `Circle::getInheritedMembers()` gefiltert auf lokale Benutzer (`TYPE_USER`), Status `Member`; optional Level-Filter (M6: = 1). Nie `getMembersOfTeam` ungefiltert (enthält Gruppen, R2).
- `uidForSingleId(singleId): ?string` – für `MembershipsRemovedEvent` (M5b).
- Circles-Klassen nur hier; Events über Klassennamen-Strings registriert, Listener prüfen `instanceof` (App läuft ohne Circles).
- Session-Handhabung: `startSession`/`stopSession` je Aufruf in `try/finally` (der Kern-`TeamManager` lässt Sessions offen; eine fremde offene Session darf unsere Prüfung nicht beeinflussen).

### 2.2 Rechte (`AccessService`, SPEC §3)
- `teamLevel(uid, ws)` nach Zustand aus `TeamStateService::stateOf(teamId)`:
  - `frozen` (`!isAvailable()`): `created_by == uid` → READ, sonst NONE.
  - `orphaned`: `created_by == uid` → READ (Umwandeln/Export über eigene Aktionen, nicht `manage`), sonst NONE.
  - `active`: Mitglied → WRITE; `manage` nur bei `getLevel ≥ 4` (lazy, erst wenn `manage` gefragt ist). Nicht Mitglied → NONE.
- `visibleWorkspaceIds(uid)` = eigene ∪ Freigaben ∪ Team-Workspaces der `memberTeams(uid)` (eine SQL-Abfrage `owner_type=team AND owner_id IN (…)`, ohne `orphaned`-Teams per Join/Unterabfrage auf `nextnotes_teams`) ∪ (bei `frozen` oder `orphaned`) Team-Workspaces mit `created_by = uid`. `writableWorkspaceIds` analog ohne die READ-Fälle. `filterReadable` bleibt auf `visibleWorkspaceIds` (gleiche Regel wie `noteLevel`; `FilterReadableTest` wird um Team-Fälle erweitert, Äquivalenz per Test).
- **Freigaben nur für Benutzer-Workspaces** (BLOCKER 2, Lead, ADR 0014): `computeLevel` und `findSharedWith`/`visibleWorkspaceIds` werten `ws_shares` nur bei `owner_type=user` aus (Mapper filtert schon auf `w.owner_type='user'`, per Test festschreiben); eine vorhandene Freigabezeile auf einem Team-Workspace (Altbestand, Umwandeln zurück) verleiht nichts. `share()` → 403 bei `owner_type=team` (auch für Level 9). SPEC §3: Spalte „verwalten“ für Team-Workspaces ohne „teilen“.
- **Verlassen eines Team-Workspace** (BLOCKER 4, Thomas 2026-11-07): Notizen und Ordner verlassen einen Team-Workspace nur in einen Workspace **desselben Teams**. Einzige Ausnahme (O5, entschieden Lead + Thomas): eine Notiz mit `created_by` = Akteur darf in einen eigenen privaten Workspace (`owner_type=user`, `owner_id` = Akteur); dafür genügt `write` an der Quelle, auch für Schüler. Ein Ordner nur mit `manage` und nur, wenn alle enthaltenen Notizen (auch Papierkorb) die Bedingung erfüllen. Beim Anhang-Verschieben zählt `created_by` der **Quellnotiz**, Ziel muss eine Notiz im eigenen privaten Workspace sein (r2 H3). Sonst 403. `created_by` ist nach Kontolöschung NULL (bestehende Neutralisierung M2d, gilt auch in Team-Workspaces), eine neu vergebene UID erbt die Ausnahme also nicht (r2 H2). Eine Prüfstelle `AccessService::requireLeave(uid, LeaveRequest)` (Quelle, Ziel, betroffene Notizen, Art; Objekt statt fester Parameter, damit M6 erweitern kann); bei Quelle `owner_type=team` **ersetzt** sie die bisherige `manage`-Prüfung der Quelle in den einzelnen Wegen (B17; auch die eigene Prüfung in `AttachmentService::move`), `write` am Ziel bleibt; bei Benutzer-Quellen gilt B17 unverändert (`manage` an der Quelle). Wege: `NoteService::update` (Workspace-Wechsel per PATCH), `moveToFolder`, `NoteService::archive`/`FolderService::archive` (Archiv liegt beim Besitzer → bei Team-Quelle nur mit Archiv im selben Team bzw. 403), `FolderService::move`, `moveFiles`, `WorkspaceService::moveNotes` und `delete`, `DuplicateService::duplicateNote`/`duplicateFolder` (Kopie in fremden Workspace ist auch ein Verlassen), `AttachmentService::move`. Import und Konvertierung schreiben nur in den Ziel-Workspace selbst (kein Verlassen, keine Regel nötig; im Slice per `grep` auf alle `workspace_id`-Schreiber belegen). **Ausnahme `make_private`** (§2.4): bewusst alle Notizen.
- `ownWorkspaceIds` bleibt nur `owner_type=user` (ADR 0008, „alle“-Exporte).
- `kind=assignments`-Workspaces (M6) sind nie `owner_type=team`; die Team-Regeln gelten nur für `kind=normal`.

### 2.3 Team-Workspaces (API)
- Anlegen: `POST /api/v1/workspaces` mit optionalem `team` (singleId). Erlaubt nur bei `active` und Level ≥ 4 (Q1, Thomas 2026-11-07), Team muss `probe()` passieren. Legt `nextnotes_teams`-Zeile per `insertIgnoreConflict` an (Name-Snapshot), `owner_type=team`, `owner_id=teamId`, `created_by=uid`. Name je Team eindeutig (wie je Benutzer).
- Umbenennen, Farbe, Tags: `manage` (≥ 4). Löschen (Q2, Thomas 2026-11-07): `manage`; Inhalte werden in einen anderen Workspace **desselben Teams** verschoben (Ziel wählbar, Standard: kleinste ID); gibt es keinen, nur mit `confirm: true` (Dialog: Name eintippen) endgültig löschen (gleicher Löschpfad wie `purge`, ohne `confirm` → 400 `confirmation_required`); nie in einen privaten Workspace. Gilt für `DELETE /workspaces/{name}` und `workspaces.php` `delete`.
- Workspace-Schlüssel (§4.4): Kollisionsname „Name (Teamname)“; Teamname aus `memberTeams` (Request), sonst `team_name_snapshot`. Bleibt er doppelt (zwei Teams gleichen Namens, oder Team- und Benutzername gleich), greift die vorhandene Langform „Name (Teamname, #id)“ für alle Beteiligten (H6); `resolveKey` akzeptiert sie immer. Test mit zwei gleichnamigen Teams. Kein Schreiben des Snapshots bei GET (§4.5); aktualisiert wird er beim Anlegen, im `CleanupJob` und beim Umbenennen (SPEC §2.1 entsprechend ändern).
- **B17**: `moveNotes` Quelle `manage`, Ziel `write`, dazu `requireLeave` (§2.2), Quote beim Ziel (Team: Team-Quote). `reorder`: Reihenfolge je Benutzer – eigene über `display_order`, fremde (geteilt/Team) über `IUserConfig` (interner Schlüssel, nicht über Settings-API schreibbar); kein 403 für Team-Mitglieder, ein Benutzer ändert nie die Reihenfolge anderer (R8 für Workspaces erledigt).
- Quote (SPEC §2.2): Team-Workspaces zählen gegen eine eigene Team-Quote `attachment_quota_mb` (Summe aller Workspaces des Teams, Sperre aller Workspace-Zeilen des Teams) – Lead-Vorschlag, da kein einzelner Besitzer.
- `ITeamResourceProvider` (`Teams/WorkspaceResourceProvider`, `id=nextnotes`): `getSharedWith(teamId)` = Team-Workspaces dieses Teams (Label = Name, URL `index.php?workspace=<id-Langform>`), `isSharedWithTeam`, `getTeamsForResource`. Registriert per `registerTeamResourceProvider`. Der Kern prüft die Mitgliedschaft selbst (`getSharedWith(teamId, userId)` mit `mustBeMember`).
- InitialState `teamsAvailable` = `isAvailable()` (bisher fest `false`).

### 2.4 Lebenszyklus (SPEC §8)
- Team gelöscht: `CircleDestroyedEvent` → `TeamStateService::markOrphaned(teamId)` (bedingtes UPDATE `state=active`), danach Benachrichtigung `team_orphaned` an `created_by` aller Team-Workspaces (M6 ergänzt `owner_uid`). Rückfall `CleanupJob` (§2.5).
- Listener (H1): fangen jede Ausnahme selbst (Log `error`, nie weiterwerfen – sie laufen im Pfad der Circles-Verwaltung bzw. im Loopback-Request); der Rückfall bleibt der `CleanupJob`.
- Austritt: `MembershipsRemovedEvent` (direkt und geerbt, synchron) → je (Team, uid) erst `getLevel(team, uid) == 0` erneut prüfen (nach `resetCache`; Wiedereintritt oder zweiter Mitgliedschaftsweg → nichts tun), dann nach dem Commit: Erinnerungen und Erwähnungs-Status der uid in Team-Workspaces löschen, Hintergrundbilder der uid dort entfernen (R15). Rechte hängen nicht daran (Live-Abfrage). M6 hängt `left_team_at` an denselben Listener (Schnittstelle `TeamMembershipListener::onLeft(teamId, uid)`).
- Kontolöschung (`UserDeletedListener`, synchron): `workspaces.created_by` → NULL für Team-Workspaces (rechtetragend bei verwaist/eingefroren, §2 UID-Regel); Hintergrundbilder der uid in allen Workspaces (nicht nur Freigaben) – `BackgroundFiles::queueUserEverywhere(uid)` über die Ordner `backgrounds/*/<uid>`; Erinnerungen/Erwähnungen laufen schon über alle Notizen; `notes.created_by`/`updated_by` werden wie seit M2d überall NULL, auch in Team-Workspaces (Anzeige „Nicht verfügbar“, keine Eigene-Notiz-Ausnahme für eine neu vergebene UID). Team-Workspaces und von der uid geschriebene Notizen darin bleiben (gehören dem Team, ADR 0003).
- Umwandeln in privat (`orphaned`, nur `created_by`) – **bewusste Ausnahme von der Verlassen-Regel §2.2** (ADR 0003, SPEC §3, bestätigt Thomas 2026-11-07): der ganze Workspace samt **allen** Notizen aller früheren Mitglieder geht an `created_by`; sonst würden sie nach 30 Tagen gelöscht. In SPEC §3/§8 und ADR 0003 als ausdrückliche Ausnahme vermerken, Dialog nennt es („Alle Notizen des Workspace, auch die anderer Mitglieder, gehören danach dir“). neue Aktion `workspaces.php` `action=make_private` + `POST /api/v1/workspaces/{name}/make-private` (eine davon, im Slice festlegen; Vorschlag API-Route): `owner_type=user`, `owner_id=created_by`, Namenskollision mit eigenem → Suffix „(Team)“/Zähler. Eingefroren: keine Umwandlung (SPEC §3).
- Abgaben (M6): Austritt und Kontolöschung löschen nie etwas aus `kind=assignments`-Workspaces oder `role=submission`-Notizen; die Aufräum-Pfade von M5b filtern auf `owner_type=team` ∧ `kind=normal`.

### 2.5 `CleanupJob` und `TeamCleanupService` (SPEC §5)
- `TimedJob` 24 h. Schritt 1 (nur bei `isAvailable()` und fehlerfreiem Circles): je `active`-Zeile `getTeam()`; „fehlt“ → `missing_count+1`, bei 2 → `orphaned` (wie Event-Pfad); „vorhanden“ → 0 + Snapshot aktualisieren; Fehler → Lauf abbrechen, nichts zählen.
- **Massenschutz** (H2, Lead; r2 H4): verdächtig ist ein Lauf, wenn (a) mehr als 10 Teams **und** mehr als 50 % der geprüften `active`-Teams „fehlen“ oder (b) **alle** geprüften Teams fehlen und es mindestens 2 sind (kleine Instanzen, Circles neu installiert/DB zurückgesetzt). Dann wird **nur Schritt 1** verworfen (kein Zähler erhöht, nichts verwaist); Schritte 2–4 für schon verwaiste Teams laufen normal. Log `error` und NC-Benachrichtigung `cleanup_suspicious` an die Admins (Hinweis auf `orphan-check --dry-run`, `circles:check`). Gleiche Schwelle in `orphan-check`; `--force` übergeht sie für ein bewusst gewolltes Massenlöschen (z. B. Schuljahresende ohne angekommene Events). README „Betrieb“ nennt `circles:check`, `orphan-check --dry-run` und `--force`.
- **Rückweg `orphaned → active`** (H2): findet Schritt 1 (bzw. `orphan-check`) ein `orphaned`-Team fehlerfrei als vorhanden, setzt er `state=active`, `orphaned_at`/`missing_count`/`deletion_notified_at` zurück (bedingtes UPDATE), Log `warning`; Team-Workspaces sind danach wieder normal. Gilt nicht für Teams, deren Workspaces schon umgewandelt wurden (die bleiben privat). Rückweg und Vorprüfung nutzen nur `TeamService::exists()` (§2.1), nie `probe()`. Schritt 2: `orphaned` mit `orphaned_at + 23 Tage ≤ now` und `deletion_notified_at IS NULL` → `team_deletion_soon`. Schritt 3: `orphaned_at + 30 Tage ≤ now` → unmittelbar vorher `getTeam()` erneut (H2; nur bei verfügbarem, fehlerfreiem Circles, sonst Team überspringen); existiert es → Rückweg statt Löschen, Log; sonst `TeamCleanupService::purge(teamId)`. Schritt 4: verwaiste Dateien (§2.6). Zeitbudget je Lauf (300 s), Abbruch nur zwischen Workspaces; je Workspace eine Transaktion (H4, wie `DeletedWorkspaceSweepJob`), Team-Zeile erst, wenn kein Workspace des Teams mehr übrig ist; Dateien nach Commit (`FileCleanupQueue`). Ein abgebrochener Purge setzt im nächsten Lauf fort.
- `purge(teamId)`: Hook `beforePurge(teamId)` für M6 (Aufgaben, Vorlagen, Abgaben, Schüler-Workspaces), dann Team-Workspaces über denselben Löschpfad wie Workspace-Löschung (Notizen, Ordner, Tags, Anhänge, Snapshots, Erinnerungen, Sperren, Erwähnungen, Hintergründe, `markProcessed`), dann Team-Zeile. Idempotent.
- `occ nextnotes:team:orphan-check [--dry-run]`: Schritt 1 als Befehl; `--dry-run` listet nur.
- Zeit über `ITimeFactory` (Tests mocken über Zeit-Override wie bei bestehenden Job-Tests).

### 2.6 Verwaiste Dateien (STATUS-Nacharbeit)
- Anhangsdateien in `IAppData` `attachments/<note_id>/<id>` ohne DB-Zeile (Rest nach `FileCleanupQueue`-Fehler) → löschen, nur wenn älter als 24 h (kein Rennen mit laufendem Upload).
- Excalidraw-Vorschauen: Anhang-Zeilen, die als Excalidraw-Vorschau erkennbar sind (Erkennung im Slice belegen, z. B. Markierung beim Anlegen durch `ExcalidrawService`), weder im aktuellen Inhalt noch in einem Snapshot referenziert → löschen. Kein Löschen ohne sichere Erkennung (lieber liegen lassen); Budget je Lauf.

### 2.7 Oberfläche (M5d)
- **Freigabe-Richtlinie serverseitig** (BLOCKER 1, Lead): `WorkspaceService::share()` und die Suche nutzen eine Prüfung `ShareTargetPolicy::allowed(actor, target)`: `IShareManager::shareApiEnabled()`, `sharingDisabledForUser(actor)` (umfasst `shareapi_exclude_groups`), bei `shareWithGroupMembersOnly()` gemeinsame Gruppe außer `shareWithGroupMembersOnlyExcludeGroupsList()` (`IGroupManager`), `currentUserCanEnumerateTargetUser` (Enumeration-Einstellungen; Treffer per vollständiger UID/Name nur bei `allowEnumerationFullMatch`, wie die NC-Suche). Ziel unbekannt, deaktiviert oder nicht erlaubt → **einheitlich** 400 `Invalid share target` (gleicher Text, gleicher Status). Die Richtlinie gilt nur für **neue** Empfänger: Rechteänderung („bearbeiten“ ↔ „lesen“, per Update der vorhandenen Zeile statt `insertIgnore`) und `unshare` bestehender Empfänger scheitern nie an ihr (r2 H5). Bestehende Freigaben, die die Richtlinie später verletzen, bleiben (NC-Verhalten bei Dateifreigaben); Entzug über den Dialog. Laufzeitunterschiede zwischen „gibt es nicht“ und „nicht erlaubt“ sind als Restgröße akzeptiert (NC verhält sich bei Dateifreigaben genauso).
- Freigabe: `workspaces.php` `share_workspace`/`unshare_workspace` statt 501. Upstream-Dialog (`workspaces-share.js`) listet alle Konten über `/users/profiles` (numerische IDs) – im Port nicht zulässig (Sichtbarkeit). Patch **P30**: Suchfeld mit Treffern aus neuer Route `GET /api/v1/users/search?q=` (`ISearch::filteredSearch`, Typ Benutzer, ≥ 2 Zeichen, ≤ 20 Treffer, respektiert NC-Freigabe-Einstellungen), gewählte Konten als Liste, UIDs als Strings; Recht „bearbeiten“/„lesen“ je Empfänger, Standard „bearbeiten“ (Q3, Thomas 2026-11-07). `GET /api/v1/users/search`: `#[UserRateLimit(limit: 30, period: 60)]`, Antwort nur `{uid, displayName}` (keine E-Mail, keine weiteren Profilfelder), Ergebnis zusätzlich durch `ShareTargetPolicy` gefiltert (H5). Nur Besitzer privater Workspaces teilen (ADR 0014); Team-Workspaces haben keinen Teilen-Knopf.
- „Neuer Workspace“: Auswahl „Privat / Team …“ (Teams mit Level ≥ 4, lazy beim Öffnen über neue Route `GET /api/v1/teams` → `[{id, name}]`); nur sichtbar bei `teamsAvailable`. `workspaces-create.js` sendet `team` mit (Patch **P31**, eine Zeile) oder Feld im eigenen Template, das der Adapter beim Absenden ergänzt – im Slice den kleineren Weg wählen.
- Workspace-Menü und Einstellungen → Workspaces: Team-Workspaces mit Team-Symbol und Teamname; Verwalten-Aktionen nur bei `manage`; ohne Teams-App kein Team-Element.
- Banner in der Hauptansicht (Insel `nextnotes-body`): eingefroren „Teams-App nicht verfügbar – nur lesbar“; verwaist „Team gelöscht – Workspace wird am <Datum> gelöscht“ mit Knöpfen „In privaten Workspace umwandeln“, „Exportieren“ (für `created_by`). Nur-Lesen über den vorhandenen Weg (P11).
- Erwähnungen in Team-Workspaces: `/mentions/members` = `TeamService::members(teamId)` (bei großen Teams ≤ 500, Suche clientseitig wie Upstream); `notifyNew` Empfänger = genannte uid ∈ Mitglieder mit Leserecht (`canRead`), ≤ 20; Icon-Leiste „Erwähnungen“ auch bei Team-Workspaces; `railCount` unverändert über `visibleWorkspaceIds`.
- R16 Notizfarben: in fremden Workspaces (geteilt oder Team) wird eine gespeicherte Farb-ID mit der Palette des Besitzers aufgelöst, in Team-Workspaces mit der Standardpalette; die Farbwahl zeigt dort dieselbe Palette (Lead-Vorschlag, vorläufig).
- Erstellen-Menü (STATUS „Nächster Schritt“): Escape und Klick außerhalb direkt nach dem Öffnen schließen das Menü (Ursache im Slice; Upstream-Bug → B-Nummer, ggf. Patch).
- Teams verwalten: NC-Oberfläche liegt in der Contacts-App. Next Notes legt keine Teams an; Link „Teams verwalten“ nur, wenn Contacts aktiv ist (`IAppManager::isEnabledForUser`), sonst nichts (Q4, Thomas 2026-11-07).

### 2.8 Schnittstellen für M6 (jetzt anlegen, nicht nutzen)
- `TeamService::getLevel`, `members(teamId, level)`, `TeamStateService::stateOf` – von `AccessService::requireAssignment`/`visibleAssignmentIds` nutzbar.
- `TeamCleanupService::purge` mit Hook vor dem Workspace-Löschen; `CleanupJob` Schritt „Übergang `owner_uid`“ als leere Stelle.
- Austritts-Listener mit `onLeft(teamId, uid)`; Kontolöschung belässt `kind=assignments` und `role=submission` unberührt (Abgaben bleiben, Thomas).
- **`requireLeave` ist auch die Prüfstelle für M6** (r2 BLOCKER 2): Notizen mit `role≠note` (Vorlage, Abgabe) und alle Inhalte von `kind=assignments`-Workspaces bzw. Ordnern mit `assignment_id` verlassen ihren Workspace über **keinen** der §2.2-Wege – auch nicht über die Eigene-Notiz-Ausnahme – und werden nicht dupliziert oder per Anhang-Verschieben entleert (403). Schon in M5a umgesetzt (deny by default, heute erreicht ohnehin niemand solche Notizen), Ausnahmen regelt allein M6 durch Erweiterung von `LeaveRequest`. Test in M5a mit per DB angelegter `role=submission`-Notiz im eigenen `kind=assignments`-Workspace: alle Wege → 403.
- Benachrichtigungen `team_orphaned`/`team_deletion_soon` mit Parameter für Aufgabenanzahl; der 7-Tage-Hinweis enthält schon in M5 den Satz zum Export (Workspace-Export; Abgaben-Export mit Link „Meine Aufgaben“ erst ab M6, Krit. 4 entsprechend angepasst, §4).

## 3. Slices

### M5a – Team-Rechte und Team-Workspaces (Backend)
**App**: `Service/TeamService.php`, `Service/TeamStateService.php`, `Db/{Team,TeamMapper}.php`, `AccessService` (§2.2), `WorkspaceService` (create mit `team`, `listVisible`, `keys`, rename/delete/`moveNotes`/`reorder` §2.3), `WorkspaceMapper` (Team-Abfragen), `AttachmentService` (Team-Quote), `Teams/WorkspaceResourceProvider.php`, `AppInfo/Application.php`, `PageController` (`teamsAvailable`), `GET /api/v1/teams` (`TeamsApiController`, nur Teams mit Level ≥ 4 bzw. `?all=1` Mitgliedschaft). Test-Helfer (tester): `tests/nextnotes/php/Support/Teams.php` (Kreis anlegen, Mitglied/Gruppe/Level, Invite-Kreis, entfernen, zerstören) über `CirclesManager` im Testprozess.
**Done M5a**
1. A (Owner) legt Team T mit B an, A legt Team-Workspace an → B: `GET /workspaces` enthält ihn, B legt Notiz an, liest/schreibt; `ITeamManager::getSharedWith(T, B)` enthält die Ressource; für Nicht-Mitglied E leer (Krit. 1).
2. B aus T entfernen → nächster Request von B auf Notiz/Ordner/Anhang/Workspace → 404, Listen/Suche/Graph/`/changes`/Unified Search ohne Inhalte, ohne Job (Krit. 2).
3. C über Gruppe in T → sieht und schreibt; D eingeladen (Invite-Kreis, Status `Invited`) → 404 (Krit. 6).
4. B (Level 1) umbenennen/löschen/Farbe → 403; Moderator → 200. B legt Team-Workspace an → 403 (Q1). Nicht-Mitglied mit `team=T` → 404.
5. Verlassen (BLOCKER 4), je Weg (PATCH `workspace`, `moveToFolder`, Notiz-/Ordner-Archiv, `FolderService::move`, `moveFiles`, `moveNotes`, Duplikat Notiz/Ordner, Anhang verschieben): A (Level 9) verschiebt B-Notiz aus Team-Workspace W1 in eigenen privaten → 403, Notiz unverändert; in W2 desselben Teams → 200; in Workspace eines anderen Teams T2 (A dort Moderator) → 403; B (Level 1) verschiebt **eigene** Notiz (`created_by`=B) in eigenen privaten → 200, fremde → 403; A mit eigener Notiz → 200; Ordner mit gemischten Autoren → 403, nur eigene Notizen → 200 (für A; B braucht für Ordner weiter `manage`). `reorder` mit Team-Workspace durch B → 200, Reihenfolge bei A unverändert.
6a. Löschen (Q2): Team mit W1+W2, A löscht W1 → Inhalte in W2; nur W1 übrig: ohne `confirm` → 400, mit → Notizen/Anhänge weg; Ziel privat → 400/403.
6b. Freigaben (BLOCKER 2): A (Level 9) `share()` auf Team-Workspace → 403; per DB eingefügte `ws_shares`-Zeile auf Team-Workspace für E → E 404 auf allen Pfaden (aktiv, eingefroren, verwaist).
6c. Probe-Regel (BLOCKER 3): versteckter Kreis (`CFG_HIDDEN`) und Backend-Kreis: Owner legt Team-Workspace an → 403, `GET /teams` ohne ihn; per DB angelegter Team-Workspace dieses Kreises → für Mitglieder 404 auf allen Pfaden und `filterReadable` ≡ `noteLevel`; nachträglich versteckt → 404, `nextnotes_teams.state` bleibt `active`.
6d. Schlüssel (H6): zwei Teams „7a“ mit je Workspace „Mathe“ für B → zwei verschiedene Schlüssel, beide auflösbar.
6. `FilterReadableTest` um Team-Fälle (Mitglied, Ex-Mitglied, Gruppe, eingeladen) – Äquivalenz `filterReadable` ≡ `noteLevel ≥ READ`.
7. Quote: Uploads mehrerer Mitglieder zählen gegen die Team-Summe; parallele Uploads überschreiten sie nicht.
8. Abfragen: `visibleWorkspaceIds` mit 20 Teams = 1 Circles-Abfrage + 1 SQL (Zähler im Test); Hauptansicht TTFB mit 20 Teams ≤ +30 ms gegenüber ohne Teams.
**Testart**: PHPUnit neu (`Teams/TeamAccessTest`, `TeamWorkspaceTest`, `TeamLeaveTest`, Filter) + Access/Isolation gefiltert, sqlite; Team-Abfragen in `WorkspaceMapper` → `TeamAccessTest` auch mariadb/pgsql. Kein E2E.

### M5b – Lebenszyklus, Zustände, Austritt, Kontolöschung
**App**: `Listener/{CircleDestroyedListener,MembershipsRemovedListener}.php`, `TeamStateService::markOrphaned`, Umwandeln (§2.4), `Notification/Notifier` (`team_orphaned`, `team_deletion_soon`; Objekt `team`), `UserDataService`/`UserDeletedListener` (§2.4), `BackgroundFiles::queueUserEverywhere`, ggf. Migration (Index `nn_ws_cby` auf `workspaces.created_by` für Kontolöschung/eingefroren; App-Version 0.2.0), Test-Setup: `dev/nextcloud` setzt Circles-Loopback (Apache `localhost`, nginx Dienstname) und prüft `circles:check`.
**Done M5b**
1. Circles deaktivieren (`occ app:disable circles`) → private Workspaces 200, keine 500 auf allen Hauptrouten; Team-Workspace: `created_by` liest, B 404, Schreiben 403/404; `nextnotes_teams.state` bleibt `active`; aktivieren → ohne Eingriff normal (Krit. 3).
2. T zerstören → `state=orphaned`, `orphaned_at` gesetzt; nur `created_by` liest; Benachrichtigung an `created_by` (Krit. 4 Teil). Kommt das Event im Test nicht an (Loopback), meldet der Architekt es; Krit. 4 gilt dann über M5c.
3. Simulierter Circles-Fehler (Adapter wirft) und „nicht gefunden“ beim Zugriff → 404 für den Request, Zustand unverändert.
4. Umwandeln durch `created_by` → privater Workspace mit **allen** Notizen (auch von B geschrieben), Team-Zeile bleibt; durch andere → 404/403; bei eingefroren → 403.
5. Austritt (direkt und über Gruppe): Erinnerungen/Erwähnungs-Status/Hintergrund der uid in Team-Workspaces weg, andere Workspaces unberührt; nichts in `kind=assignments`. Austritt über Gruppe bei weiter bestehender Direktmitgliedschaft → nichts gelöscht (H1).
5a. Listener mit erzwungenem Fehler (Adapter wirft) → `removeMember`/`destroyCircle` gelingen trotzdem, Log-Eintrag (H1).
6. Kontolöschung eines `created_by`: `workspaces.created_by` und `notes.created_by` seiner Notizen im Team-Workspace NULL, Team-Workspace bleibt für Mitglieder, Hintergrundbilder der uid in Team-Workspaces weg (R15); neu angelegte gleiche UID liest den Workspace bei eingefrorenem Team nicht und darf die alten Notizen nicht in einen privaten Workspace verschieben (403).
**Testart**: PHPUnit `TeamLifecycleTest`, `UserDeletionTest` (erweitert) sqlite; Migration → mariadb/pgsql Schema + diese Tests; eine Probe nginx für den Loopback-Event.

### M5c – CleanupJob, orphan-check, verwaiste Dateien
**App**: `BackgroundJob/CleanupJob.php` (`info.xml`), `Service/TeamCleanupService.php`, `Command/TeamOrphanCheck.php` (`info.xml` `<commands>`), Schritt verwaiste Dateien (§2.6).
**Done M5c**
1. Team ohne Event gelöscht (Circles währenddessen deaktiviert, dann aktiviert): `orphan-check --dry-run` listet es, ändert nichts; erster Joblauf `missing_count=1`, zweiter `orphaned`; Lauf mit deaktiviertem Circles bzw. Fehler zählt nicht hoch; „vorhanden“ setzt 0 (Krit. 7).
2. Gemockte Zeit: Tag 23 → genau eine `team_deletion_soon`; Tag 30 → Team-Workspaces samt Notizen, Anhängen (DB + `IAppData`), Snapshots, Erinnerungen, Hintergründen, Team-Zeile weg; vorher umgewandelter Workspace bleibt (Krit. 4 Rest). Zweiter Lauf ohne Wirkung.
3. Verwaiste Datei > 24 h ohne Zeile → gelöscht; jünger oder mit Zeile → bleibt. Ersetzte Excalidraw-Vorschau ohne Verweis → gelöscht; mit Snapshot-Verweis → bleibt.
4. Budget: 1 000 Teams Schritt 1 bricht nach Budget sauber ab und setzt im nächsten Lauf fort; Purge eines Teams mit 3 Workspaces bricht nach dem ersten ab (Budget 0 simuliert) und endet im nächsten Lauf, Team-Zeile erst zuletzt (H4).
5. Massenschutz (H2): 12 von 20 Teams „fehlen“ → Lauf verworfen, kein `missing_count` geändert, Admin-Benachrichtigung `cleanup_suspicious`; 2 von 20 → normal.
6. Rückweg (H2): `orphaned`-Team, das `getTeam()` fehlerfrei findet → `active`, Mitglieder lesen wieder; Tag 30 mit existierendem Team → kein Purge.
7. Versteckter Kreis (r2 BLOCKER 1): Team mit Team-Workspace wird nachträglich auf `CFG_HIDDEN` bzw. `CFG_BACKEND` gesetzt → nach zwei Jobläufen und `orphan-check` weiter `active`, `missing_count` 0, kein Purge (auch mit gemockter Zeit + 30 Tage). Ein per DB auf `orphaned` gesetzter versteckter Kreis geht über den Rückweg auf `active`.
8. Massenschutz (b): 2 von 2 Teams fehlen → Schritt 1 verworfen, Schritt 3 für ein schon länger verwaistes drittes Team läuft; `orphan-check --force` verwaist trotzdem.
**Testart**: PHPUnit `CleanupJobTest`, `OrphanCheckCommandTest` sqlite + mariadb/pgsql (Löschpfade); kein E2E.

### M5d – Oberfläche: Freigaben, Team-Workspaces, Erwähnungen, Farben
**App**: `CompatController` (share/unshare, `make_private` falls dort), `UsersApiController::search` (`GET /api/v1/users/search`), `templates/partials/{modals,settings_modals}.php`, `IndexViewService`/`SettingsViewService` (Team-Kennzeichen, Banner), `MentionService` (§2.7), `NotePalette` (R16), Adapter (`nextnotes-late.js`) falls nötig; `src/`: P30 (`workspaces-share.js`), ggf. P31 (`workspaces-create.js`), ggf. Erstellen-Menü-Fix.
**Done M5d**
0. Richtlinie (PHPUnit, BLOCKER 1): `share_workspace` per Hand-Request mit (a) unbekannter UID, (b) UID außerhalb der Gruppe bei `shareapi_only_share_with_group_members=yes`, (c) Akteur in `shareapi_exclude_groups`, (d) `shareapi_enabled=no` → jeweils gleiche Antwort 400 `Invalid share target`, keine Zeile; erlaubtes Ziel → 200. Bestehender Empfänger nach Wechsel auf (b): Rechteänderung und `unshare` → 200. Suche liefert in (b)–(d) und (e) Enumeration aus bei unvollständigem Namen keine Treffer ((e) nur Suche, `share()` nennt immer die volle UID); Antwort ohne E-Mail; 31. Suche in 60 s → 429 (H5).
1. Freigabe (E2E Hell/Dunkel): A sucht „car“ → Treffer carol; teilt mit C (bearbeiten) → C sieht/schreibt; ändert auf lesen → C Nur-Lesen; Freigabe aufheben → C 404 (Krit. 5). Kein Request auf `/users/profiles`.
2. Neuer Team-Workspace über Dialog (Moderator) → erscheint mit Teamname bei B; B sieht keinen Verwalten-Eintrag.
3. Banner: eingefroren (Circles aus) und verwaist (Zustand per DB) sichtbar, Umwandeln-Knopf wirkt; Editor Nur-Lesen ohne Sperr-Request.
4. Erwähnung in Team-Workspace: `@B` → Benachrichtigung an B; `@E` (kein Mitglied) → keine; nach Austritt von B zeigt `prepare` nichts (`AlreadyProcessed`).
5. R16: A setzt Farb-ID in geteiltem Workspace → C sieht dieselbe Farbe (Palette des Besitzers); Team-Workspace → Standardfarbe für alle.
6. Erstellen-Menü schließt per Escape und per Klick außerhalb direkt nach dem Öffnen.
7. 0 CSP/Konsolenfehler, `menu-501` ohne 501 (Teilen nicht mehr 501), `check-upstream` grün mit P30/P31.
**Testart**: PHPUnit Share/UsersSearch/Mention gefiltert sqlite; E2E neu `teams-share` Hell+Dunkel nginx; `menu-501`, `settings-m4h`, `workspace-create` Hell.

### M5e – Abschluss
- Krit. 1–7 vollständig belegt; `PageIsolationTest`/`IndirectReadTest` um Team-Fälle (Ex-Mitglied, eingeladen, eingefroren) über alle Seiten-, Export- und Kompatibilitätsrouten; `smoke-m5.mjs` (Team-Workspace anlegen, Notiz, zweites Konto liest, Austritt → weg).
- `run-m5.sh` = `run-m4.sh` + Circles-Setup je Umgebung (Loopback, `circles:check`) + Circles-Aus-Lauf (Krit. 3 über HTTP); volle Matrix einmal (≈ 2 h wie M4).
- Budget: Hauptansicht mit 20 Teams/100 Team-Workspaces: API-Requests unverändert, TTFB ≤ +30 ms.

## 4. SPEC-/ADR-Änderungen (mit dem jeweiligen Slice)
| Stelle | Änderung | Slice |
|---|---|---|
| §2.1 `teams` | Snapshot nicht bei jedem `getTeam()`, sondern Anlegen/Job/Umbenennen (GET ohne Schreiben) | M5a |
| §2.2 | Quote für Team-Workspaces je Team | M5a |
| §3 | `visibleWorkspaceIds`-Regel inkl. `created_by` bei eingefroren/verwaist; `manage` lazy; Reihenfolge je Benutzer; Probe-Regel (versteckte/Backend-Kreise zählen nicht); Tabelle: „teilen“ nur bei Benutzer-Workspaces, Freigabezeilen auf Team-Workspaces wirkungslos; Verlassen-Regel für Team-Workspaces (Ausnahme eigene Notizen, Ausnahme `make_private`); Anlegen Level ≥ 4; Löschen nur in Workspace desselben Teams oder mit `confirm` | M5a, M5b |
| §11.2 | B17 erweitert (alle Verlassen-Wege, Team-Regel); neuer Eintrag Freigabe ohne Richtlinie (Upstream teilt mit jedem Konto der Instanz) | M5a, M5d |
| §4.2 | `GET /api/v1/teams`, `GET /api/v1/users/search`, `POST /workspaces` `team`, Umwandeln-Route, `workspaces.php` share/unshare umgesetzt | M5a, M5b, M5d |
| §5 | `CleanupJob` Schritte, Budget, Transaktion je Workspace, Massenschutz, Rückweg `orphaned → active`, `getTeam()` vor Purge, verwaiste Dateien | M5c |
| §6 | `team_orphaned`, `team_deletion_soon` (Export-Hinweis; Abgaben-Satz ab M6), `cleanup_suspicious` (Admins), Erwähnungen in Team-Workspaces | M5b, M5c, M5d |
| §13 M5 Krit. 4 | „mit Hinweis auf Export der Abgaben“ → „mit Hinweis auf den Export (Abgaben ab M6)“ (H3) | M5c |
| §4.5 | Freigabe-Richtlinie (`ShareTargetPolicy`), einheitliche 400; `users/search` 30/min, ohne E-Mail | M5d |
| §8 | Austritt räumt Erinnerungen/Erwähnungs-Status/Hintergründe; Kontolöschung Hintergründe überall, `created_by` Team-Workspaces | M5b |
| §11.1 | P30, ggf. P31 | M5d |
| §12 | R2 erledigt (Filter belegt), R15 erledigt, R16 erledigt; neu R17 Circles-Loopback | M5a–M5d |
| ADR 0003 | Nachtrag: Probe-Befund Events/Loopback, Session-Handhabung, Probe-Regel, Verlassen-Regel, `make_private` nimmt alle Notizen (Thomas 2026-11-07) | M5a, M5b |
| ADR 0014 | Nachtrag: Teilen nur Benutzer-Workspaces, serverseitige NC-Freigaberichtlinie, „nur lesen“ im Dialog | M5d |

## 5. Risiken
- **R1 Circles-Interna**: `getLink`, `getInheritedMembers`, Events nicht OCP. Gegenmaßnahme: nur `TeamService`, Integrationstests gegen echtes Circles 35.0.0, `class_exists`.
- **R2 Loopback-Events (neu R17)**: `CircleDestroyedEvent` läuft im Circles-Loopback-Request; falsch konfiguriertes Loopback (nginx, Proxy) → kein Event. Gegenmaßnahme: Rückfall `CleanupJob`/`orphan-check` (zweifach bestätigt), Admin-Hinweis `occ circles:check` in README „Betrieb“, Test auf nginx.
- **R3 Leistung**: jede Seite fragt Circles (`memberTeams`). Gegenmaßnahme: ein Aufruf je Request, `getLevel` nur für `manage`; Messung M5a.8/M5e. Rückfall: verteilter Cache 30 s für `memberTeams` – nur mit Lead-Freigabe, weil er Krit. 2 („ohne Verzögerung“) verletzt.
- **R4 Session-Zustand im Kern-`TeamManager`** (lässt `startSession` offen): eigene Aufrufe in `try/finally`, Tests mit wechselnden Benutzern im selben Prozess (Provider-Aufruf durch den Kern).
- **R5 Löschen im `CleanupJob`**: Datenverlust bei Fehlklassifikation. Gegenmaßnahme: nur zweifach bestätigt verwaist + 30 Tage, Massenschutz, `getTeam()` direkt vor dem Purge, Rückweg `orphaned → active`; Dateien nur ohne Zeile und > 24 h; Excalidraw nur bei sicherer Erkennung.
- **R7 Verlassen-Regel**: viele Schreibwege (§2.2). Gegenmaßnahme: eine Prüfstelle `requireLeave`, Test je Weg (M5a Done 5), `grep` auf alle Schreiber von `workspace_id` im Slice.
- **R6 Umfang M5d** (UI + Patches). Teilt der Architekt bei > 1 500 Zeilen in M5d1 (Freigaben, Suche) / M5d2 (Team-UI, Banner, Erwähnungen, Farben, Menü).

## 6. Offene Punkte
| # | Punkt | Vorschlag | Wer |
|---|---|---|---|
| O1 | Event vs. Rückfall bei Team-Löschung | beides; Rückfall maßgeblich (zweifach) | Lead, entschieden durch SPEC |
| O2 | Quote Team-Workspaces | je Team, gleicher Grenzwert `attachment_quota_mb` | Lead |
| O3 | Reihenfolge fremder Workspaces | je Benutzer in `IUserConfig` | Lead |
| O4 | Umwandeln als API-Route oder `workspaces.php`-Aktion | API-Route (keine Upstream-Aktion vorhanden) | Lead |
| O5 | Eigene Notiz eines Schülers in privaten Workspace | **entschieden** (Lead + Thomas): `write` an der Quelle genügt (§2.2) | – |
| Q1 | Team-Workspaces anlegen | **entschieden** (Thomas 2026-11-07): nur Level ≥ 4 | – |
| Q2 | Team-Workspace löschen | **entschieden** (Thomas 2026-11-07): nur in Workspace desselben Teams verschieben, sonst nach Bestätigung endgültig löschen; nie privat | – |
| Q3 | „nur lesen“ im Freigabe-Dialog | **entschieden** (Thomas 2026-11-07): ja, Standard „bearbeiten“ | – |
| Q4 | Teams anlegen/verwalten | **entschieden** (Thomas 2026-11-07): Next Notes legt keine Teams an; Link nur bei aktiver Contacts-App | – |
| Q5 | R16 Notizfarben: Palette des Besitzers in geteilten, Standardpalette in Team-Workspaces (vorläufig Lead) | Produkt (gering) | Lead, zur Durchsicht |

## 7. Änderungen r2
| Befund | Umsetzung im Plan |
|---|---|
| BLOCKER 1 Freigabe-Richtlinie | §2.7 `ShareTargetPolicy` für `share()` und Suche, einheitliche 400; M5d Done 0; §4 (§4.5, §11.2, ADR 0014) |
| BLOCKER 2 Team-Workspaces teilbar | §2.2 Freigaben nur `owner_type=user`, `share()` 403 bei Team; M5a Done 6b; §4 (§3) |
| BLOCKER 3 zwei Mitgliedschaftsquellen | §2.1 eine `probe()`-Regel für alle Pfade, versteckte/Backend/persönliche Kreise ausgeschlossen, Anlegen 403; M5a Done 6c |
| BLOCKER 4 Verlassen | §2.2 Verlassen-Regel mit Prüfstelle `requireLeave` für alle Wege, Ausnahme eigene Notizen (O5); §2.3 Löschen (Q2); §2.4 `make_private` als ausdrückliche Ausnahme; M5a Done 5/6a, M5b Done 4; R7 |
| H1 Listener | §2.4 nie werfen, Level erneut prüfen; M5b Done 5/5a |
| H2 CleanupJob konservativ | §2.5 Massenschutz (> 10 und > 50 %), Rückweg, `getTeam()` vor Purge; M5c Done 5/6 |
| H3 Export-Hinweis | §2.8, §4 Zeile §13 Krit. 4 |
| H4 Transaktion | §2.5 je Workspace, Team-Zeile zuletzt; M5c Done 4 |
| H5 `users/search` | §2.7 30/min, nur uid/Anzeigename, Richtlinienfilter; M5d Done 0 |
| H6 Schlüssel | §2.3 Langform bei gleichnamigen Teams; M5a Done 6d |
| H7 Cache | §2.1 `resetCache` in Jobs/CLI/PHPUnit |
| Thomas Q1–Q4 | §2.3, §2.7, §6 entschieden |
| r2 BLOCKER 1 Existenzprüfung | §2.1 `TeamService::exists()` nur `ITeamManager::getTeam` (Super-Session, ohne Filter) für Schritt 1, Rückweg, Vorprüfung, `orphan-check`; `probe()` nur Sichtbarkeit/Rechte; §2.5; M5c Done 7 |
| r2 BLOCKER 2 Abgaben | §2.8 `requireLeave(uid, LeaveRequest)` auch für M6: `role≠note` und `kind=assignments`/`assignment_id`-Inhalte verlassen nie ihren Workspace (keine Ausnahme), schon in M5a, Test |
| r2 H1 | O5 entschieden; §2.2 `requireLeave` ersetzt bei Team-Quellen die `manage`-Prüfung der Wege (inkl. `AttachmentService::move`) |
| r2 H2 | §2.2/§2.4 `notes.created_by` wird bei Kontolöschung überall NULL (seit M2d, `UserDataService`); M5b Done 6 ergänzt |
| r2 H3 | §2.2 Anhang: `created_by` der Quellnotiz, Ziel im eigenen privaten Workspace |
| r2 H4 | §2.5 Massenschutz (b) „alle fehlen, ≥ 2“, nur Schritt 1 verworfen, `--force` + README „Betrieb“; M5c Done 8 |
| r2 H5 | §2.7 Richtlinie nur für neue Empfänger, Rechteänderung per Update, `unshare` nie blockiert, Timing als Restgröße; M5d Done 0 ohne (e) für `share()` |
