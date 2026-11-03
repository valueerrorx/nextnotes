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

## Nachtrag M3-fix4 (2026-11-03)
Nur `POST /notes/{id}/lock` legt eine Sperre an. Heartbeat verlängert ausschließlich die gültige Sperre derselben Sitzung desselben Kontos; fremde Sitzung oder fremdes Konto → 409 mit Halter, keine Sperre → 200 `lock: null` (Upstream-Client bleibt bearbeitbar). Speichern (PATCH, Beacon) legt nie an; fremde gültige Sperre → 423 wie bisher. Grund: späte Heartbeats/Notfall-PATCHs einer verlassenen Seite holten die Sperre nach der Freigabe zurück (SPEC B53).

**Nachtrag M3-fix4 (Web-Lock-Frist):** Chromium sagt `navigator.locks.request(…, {ifAvailable:true})` während des Seitenladens erst spät zu (gemessen 2,2–2,8 s, etwa beim `load`). Die 2-s-Frist läuft deshalb erst ab `load`; eine nach Fristablauf eintreffende Zusage wird sofort freigegeben, damit kein verwaister Lock bleibt. Vorher bekam jeder Tab bei jedem Laden eine neue Sitzungs-ID (Ursache der gemeldeten „anderer Tab bearbeitet“-Meldung nach Reload).
