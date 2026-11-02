# ADR 0002: Speicherort von Notizen, Anhängen und Snapshots (Auftragsfrage 1)

Status: vorgeschlagen · [THOMAS] (Nutzerwirkung: Notizen nicht in Nextcloud Files, kein Desktop-Sync)

## Kontext
Upstream speichert Notizinhalt doppelt (Datei `entries/{id}.html|md` + Spalte `entries.entry`), Anhänge als Dateien mit JSON-Metadaten in `entries.attachments`, Snapshots als Dateien (Inventur A: F1, F2, F4, T1). Notizen können einem Benutzer oder einem Team gehören (ADR 0003). Kriterien: Quota, Versionen, Sync, Suche, Team-Eigentum, Upstream-Kompatibilität (API liefert Inhalt als String, Anhänge über `/api/v1/notes/{id}/attachments/{aid}`).

## Optionen
1. **Datenbank** für Inhalt, **`IAppData`** (`OCP\Files\IAppData`, @since 11) für Anhänge, **Datenbank** für Snapshots.
2. **Nextcloud Files** (`IRootFolder`, @since 8): jede Notiz eine Datei im Home des Besitzers; Team-Workspaces in einem Team-Ordner (`ITeamFolderProvider`, erst @since 35.0.0).
3. Alles in `IAppData`, DB nur Metadaten.

| Kriterium | 1 DB + IAppData | 2 Files | 3 IAppData |
|---|---|---|---|
| Quota | nicht auf Benutzer-Quota; eigene App-Grenze nötig | Benutzer-Quota greift | wie 1 |
| Versionen | eigene Snapshots (Upstream-Feature bleibt) | NC-Versionen, Snapshots doppelt | eigene |
| Sync/Desktop-Client | nein | ja, aber Fremdänderungen umgehen Sperre, Ordner-/Tag-Modell und Rechteprüfung | nein |
| Suche | SQL über normalisierte Spalte, Unified Search eigener Provider | Volltext nur mit fulltextsearch-App | Datei-Scan nötig |
| Team-Eigentum | Spalte am Workspace, Rechte live (ADR 0003) | Team-Ordner (`ITeamFolderProvider`, 35) möglich, aber Rechte/Sperren/Abgaben liefen am Datei-Modell vorbei | wie 1 |
| Transaktionen, Löschung, Export | einfach, eine Quelle | verteilt, Kollisionen mit Dateinamen | Datei+DB-Konsistenz |

## Empfehlung
Option 1:
- Notizinhalt nur in `nextnotes_notes.content` (`Types::TEXT`), dazu `search_text` (normalisiert) für Suche. Keine Dateikopie.
- Anhänge als Dateien in `IAppData` (`attachments/<note_id>/<attachment_id>`), Metadaten in `nextnotes_attachments`. Kopie beim Austeilen ist eine physische Kopie.
- Snapshots in `nextnotes_snapshots` (Text), begrenzt je Notiz (Upstream-Regel `purgeOldSnapshots` + Aufbewahrung per `TimedJob`). Weicht von Inventur A (#50–54 „→ IAppData“) ab: Tabelle ist einfacher zu exportieren und zu löschen.
- Quota: App-Einstellung (Admin) „max. Anhangsgröße“ und „max. Speicher je Benutzer“ (Summe `size` aus `nextnotes_attachments` der eigenen Workspaces). Standardwerte: 20 MB/Datei, 500 MB/Benutzer, Team-Workspaces zählen beim Ersteller; vom Admin änderbar.
- Hintergrundbilder je Workspace ebenfalls in `IAppData` (`backgrounds/<workspace_id>`).

## Konsequenzen
- Kein Desktop-Sync von Notizen. Export (ADR 0008) ersetzt Datei-Zugriff.
- Benutzer-Quota und Speicherstatistik von NC zeigen den App-Speicher nicht; deshalb eigene Anzeige (Inventur B #23 „ersetzen“).
- `IAppData` liegt in `appdata_<instanceid>/nextnotes/`; Backups der Instanz müssen Datenverzeichnis + DB umfassen (Standard bei NC).
- Später möglich: Option 2 als Export-Ziel („in Dateien speichern“), ohne Datenmodell zu ändern.
