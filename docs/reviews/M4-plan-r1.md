VERDICT: BLOCKER

Review M4-plan r1 (speccritic, 2026-11-03) – Grundlage: docs/slices/M4-plan.md, SPEC §1.2, §3, §4.5, §4.6, §6, §13 M4, ADR 0008/0012, STATUS Nacharbeit.

## Blocker
1. **Export: im Inhalt referenzierte Objekte ohne Rechteprüfung** (Plan §1.3, M4c, M4d). Upstream löst beim Export Fremdreferenzen per ID auf: `html_embedded` bettet `/api/v1/notes/{note_id}/attachments/{id}` und `api_attachments.php?...note_id=` mit beliebiger `note_id` aus dem Inhalt als `data:` ein (`src/public/api_export_note.php:265–290`); `[[<id>|Titel]]` wird per `buildExportNoteReferenceResolver` (`:709`) zu Titel+Link aufgelöst; ZIP-Exporte sammeln Anhänge analog. A kann so Bilder/Titel von C exfiltrieren (SPEC §3: jeder indirekte Lesepfad über `AccessService`). Plan muss festlegen: eingebettete/gepackte Anhänge nur aus Notizen mit Leserecht (Vorschlag: nur Anhänge der exportierten Notiz selbst), `[[…]]`-Auflösung über `filterReadable` (ein Batch, kein N+1), verknüpfte Notizen (`type=linked`) in Ordner-/Gesamt-/strukturierten ZIPs nur mit Leserecht aufs Ziel, sonst leere Hülle. Done M4c.3/M4c.4/M4d.4 um je einen Fall „Referenz auf C-Anhang/C-Notiz → nicht enthalten“ ergänzen; M4i Krit. 5 entsprechend.
2. **ZIP-Export: Speicher/Platte nicht beherrscht** (Plan §1.3, M4d.5, R3). (a) `ZipArchive` nimmt keine Streams; „per `ISimpleFile::read()` gestreamt“ ist so nicht umsetzbar, `addFromString(getContent())` lädt ganze Dateien (Max-Datei bis 2048 MiB laut M4a) → Speicherüberlauf. Festlegen: Stream → Temp-Datei → `addFile`, Temp-Kopien nach `close()` löschen. (b) `ITempManager::clean()`/`cleanOld()` werden in NC 35.0.2 vom Kern nicht aufgerufen (nur Interface/Implementierung, kein Aufrufer in `lib/`, `core/`) → Temp-ZIPs und -Kopien bleiben liegen; bei 10 Exporten/min Plattenfüllung. Explizites Löschen nach Auslieferung (auch bei Abbruch) und Obergrenze (Gesamtgröße oder Einträge → 413) nötig. (c) M4d.5 prüft nur kleine Anhänge: Lastfall mit einem Anhang nahe `attachment_max_file_mb` (z. B. 200 MiB) ergänzen, RSS ≤ 64 MB, Temp-Verzeichnis danach leer.
3. **Parser-DoS nur bei 1 MiB belegt** (M4b.2, R2). `/convert-*` und `notes/{id}/convert` nehmen bis 16 MiB; Laufzeit dort unbegrenzt (PHP-Schleifen sind nicht über `Pcre` abgesichert). Done-Kriterium für maximale Eingabegröße (Zeitgrenze oder niedrigere Eingabegrenze → 413) ergänzen; ebenso Mehrfach-Render pro Request (Kanban-Karten #1672, `diary/entries.php`, Snapshot `render=1`): Obergrenze bzw. Messung im Seed 1 000.

## Hinweise
- Q2 ist offen [THOMAS], aber M4c.4 („Lesefreigabe → 200“) und M4d.3 setzen den Vorschlag voraus; Kriterien als bedingt markieren.
- `disposition=inline` nur für `format=html` (Druck); andere Formate immer `attachment` + `nosniff` (Upstream erlaubt inline für alle).
- Druckansicht NC-Standard-CSP: Notiz mit erlaubtem Embed erzeugt `frame-src`-Verletzung → M4c.2 „0 CSP“ scheitert; iframes im Druck entfernen oder `NOTE_VIEWS`.
- Embed-Normalisierung: einlabelige Hosts/öffentliche Suffixe (`com`, `co.uk`) ablehnen, sonst `https://*.com` in `frame-src`.
- Hintergrund: Ablage `backgrounds/<uid>/<wsId>` macht Workspace-Löschung zu einer Suche über alle Benutzer; Leser-Uploads (2×5 MiB je lesbarem Workspace) außerhalb der Quote.
- Base64→Anhang „keine Teiländerung“: bereits geschriebene IAppData-Dateien bei Rollback löschen.
- `index.php?note=<linked>`: Bearbeiten des Ziels nur mit dessen Schreibrecht (sonst Nur-Lesen), festhalten.
- Mention: Obergrenze Empfänger je Speichern; Import: Gesamtgröße je Request (50×16 MiB).
- SPEC §1.2 Nr. 5 nennt noch league/Composer; Änderung ist in Plan §6 vorgesehen (ok).
