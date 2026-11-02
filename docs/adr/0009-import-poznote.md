# ADR 0009: Import aus einer bestehenden Poznote-Instanz (Auftragsfrage 8)

Status: entschieden (Thomas, 2026-11-02): gestrichen, kein Import in v1 (Option 1).

## Kontext
Bestehende Poznote-Nutzer haben Daten in `data/users/{id}/database/poznote.db` + Dateien. IDs sind je Benutzer lokal, Inhalte enthalten URLs (`/api/v1/notes/<id>/attachments/<aid>`, `index.php?note=N`, `/audio_player.php`, `api_attachments.php?…`). Upstream kann einzelne Notizdateien per Drag&Drop importieren (A #199) und exportiert ZIPs (ADR 0008).

## Optionen
1. **Kein Import.**
2. Import der Upstream-Exportformate (ZIP „strukturiert“) über die UI, als `QueuedJob` bei großen Dateien.
3. `occ`-Befehl, der die Upstream-SQLite und das Datenverzeichnis direkt liest (braucht `pdo_sqlite` auf dem NC-Server).

## Entscheidung
Option 1: kein Import aus Poznote-Instanzen oder Poznote-ZIPs in v1. Kein `ImportService`, kein `ImportJob`, keine ID-Map.
- Nicht betroffen: Drag&Drop einzelner Notizdateien (`.md`/`.html`/`.txt`, A #199, B #69, B 2.1 #5) bleibt als normale Notiz-Anlage.
- Optionen 2/3 sind für später möglich, ohne Datenmodell-Änderung.

## Konsequenzen
- Inventur: J9 (ZIP-Import) → streichen; J12 bleibt gestrichen.
- `check-upstream` (ADR 0007) muss `import_helpers.php` nicht beobachten; Exportformat bleibt nur für den Export relevant.
- SPEC §10 braucht keine Import-Umschreibung; Alt-URLs werden beim ersten Speichern normalisiert.
