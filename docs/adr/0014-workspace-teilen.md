# ADR 0014: Workspace-Teilen (Benutzer, Gruppen, Teams)

Status: entschieden (Thomas, 2026-11-02): wie vorgeschlagen angenommen.

## Kontext
Upstream teilt einen Workspace mit einzelnen Benutzern (`master.workspace_shares`, T14; `workspaces.php` share/unshare, A #215; Erwähnungen A #8–11; Benutzersuche A #160). In NC gibt es Benutzer, Gruppen (`IGroupManager`, @since 8) und Teams (ADR 0003). Benutzersuche: `OCP\Collaboration\Collaborators\ISearch` (@since 13).

## Optionen
1. Nur Teams (Freigabe = Workspace gehört Team).
2. **Team-Eigentum + Freigabe an einzelne Benutzer** (Upstream-Parität).
3. Zusätzlich Freigabe an NC-Gruppen.

## Entscheidung
Option 2 in v1, Option 3 vorbereitet (Spalte `share_type`), aber nicht freigeschaltet.
- Tabelle `nextnotes_ws_shares` (`workspace_id`, `share_type` = `user` [später `group`], `share_with`, `permission` = `write`).
- Nur der Besitzer eines privaten Workspace teilt. Team-Workspaces werden nicht zusätzlich an Einzelne geteilt (Mitgliedschaft regelt das).
- Benutzersuche über `ISearch` (respektiert NC-Einstellungen zur Sichtbarkeit von Benutzern).
- Erwähnbare Mitglieder = Besitzer + Freigabeempfänger bzw. Team-Mitglieder.
- `UserDeletedEvent` entfernt Freigaben an/vom Benutzer; `GroupDeletedEvent` erst mit Option 3.

## Konsequenzen
- Upstream-Endpunkte in `workspaces.php` (POST JSON) werden als Kompatibilitätsroute mit gleichem Format nachgebaut.
- Rechteprüfung kennt drei Wege: Besitzer, Freigabe, Team (SPEC §3).

## Nachtrag M5d
- Freigaben nur für Benutzer-Workspaces; Team-Workspaces erhalten Zugriff ausschließlich über das Team (403).
- `share_workspace` setzt die vollständige Liste; `allowed_users` ist Pflicht (fehlend → 400, `[]` entfernt alle), damit ein fehlender Parameter nicht still alle Empfänger entzieht.
- Neue Empfänger prüft der Server gegen die NC-Freigaberegeln (`ShareTargetPolicy`, SPEC §3), einheitlich 400 `Invalid share target`. Bestehende Empfänger bleiben änderbar und entfernbar.
- Der Dialog sucht Personen (`GET /users/search`, 30/min, nur UID und Anzeigename) statt alle Konten zu listen und bietet „bearbeiten“ (Standard) oder „nur lesen“.
