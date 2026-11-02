# ADR 0009: Import aus einer bestehenden Poznote-Instanz (Auftragsfrage 8)

Status: vorgeschlagen · [THOMAS] (Produkt: wird Import in v1 gebraucht?)

## Kontext
Bestehende Poznote-Nutzer haben Daten in `data/users/{id}/database/poznote.db` + Dateien. IDs sind je Benutzer lokal, Inhalte enthalten URLs (`/api/v1/notes/<id>/attachments/<aid>`, `index.php?note=N`, `/audio_player.php`, `api_attachments.php?…`). Upstream kann einzelne Notizen per Drag&Drop importieren (A #199, bleibt) und exportiert ZIPs (ADR 0008).

## Optionen
1. Kein Import.
2. **Import der Upstream-Exportformate (ZIP „strukturiert“) über die UI**, als `QueuedJob` bei großen Dateien.
3. `occ`-Befehl, der die Upstream-SQLite und das Datenverzeichnis direkt liest (braucht `pdo_sqlite` auf dem NC-Server).

## Empfehlung
v1: Option 2 (M4, optional bis M7), Option 3 nicht in v1.
- Import legt einen neuen privaten Workspace je importiertem Workspace an, vergibt neue IDs und schreibt Inhalte um (ID-Map alt→neu, URL-Migration SPEC §10).
- Mehrbenutzer-Instanzen: jeder Benutzer exportiert in Poznote und importiert in NC selbst. Admin-Massenimport später per Option 3.

## Konsequenzen
- Exportformat von Upstream ist Vertrag; `check-upstream` (ADR 0007) meldet Änderungen an `export_helpers.php`/`import_helpers.php`.
- Freigaben, öffentliche Links, Benutzer und AI-Verläufe werden nicht importiert.
