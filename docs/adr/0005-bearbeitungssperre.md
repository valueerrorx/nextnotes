# ADR 0005: Bearbeitungssperre (Auftragsfrage 4)

Status: entschieden (Lead, 2026-11-02)

## Kontext
Upstream: Tabelle `master.note_edit_locks` (T15), TTL 90 s (15–300), Heartbeat, Takeover, Kennung `X-Editor-Session-ID` je Editor-Tab, Aufräumen bei jedem Sperrzugriff (J17). Endpunkte A #28–31. Abstürze (Tab geschlossen, Browser abgestürzt) dürfen keine Dauer-Sperre hinterlassen. Sperren sind nur in geteilten/Team-Workspaces relevant, die API wird aber immer aufgerufen.

## Optionen
1. **DB-Tabelle mit Ablaufzeit** (`nextnotes_locks`), Ablauf beim Lesen ausgewertet.
2. `OCP\Lock\ILockingProvider` (@since 8.1): für Datei-Locks gedacht, ohne Halter-Info (Name, Tab), TTL hängt am Memcache-Backend.
3. Nur optimistische Konkurrenz (Version/ETag), keine Sperre.

## Empfehlung
Option 1 plus optimistische Prüfung:
- `nextnotes_locks` (Primärschlüssel `note_id`): `holder_uid`, `holder_session`, `holder_kind`, `created_at`, `last_seen_at`, `expires_at` (Unix-Sekunden, `ITimeFactory`).
- Eine Sperre gilt nur, wenn `expires_at > now`; abgelaufene Zeilen werden beim Erwerb überschrieben (bedingtes `UPDATE … WHERE expires_at <= now OR holder_session = ?`, sonst `INSERT`, Unique-Verletzung → 409). Kein DB-spezifisches Upsert.
- TTL 90 s, Heartbeat vom Upstream-JS unverändert; `releaseLock` per `fetch(keepalive)`/Beacon beim Verlassen.
- Absturz: Sperre läuft spätestens nach TTL ab. `TimedJob` (stündlich) löscht nur Reste.
- Speichern (`PATCH /notes/{id}`, Beacon) prüft: fremde gültige Sperre → 423; dazu Versionsvergleich (`updated_at`/ETag wie Upstream) → 409 bei Konflikt.
- Abgaben (SPEC §9) nutzen keine Sperre, sondern Statusprüfung.

## Konsequenzen
- Gleiches API-Verhalten wie Upstream, Frontend unverändert.
- Uhrzeit nur aus PHP (`ITimeFactory`), DB-Uhr wird nie verwendet.
