# M4f – Diff-Review r1 (speccritic)

VERDICT: FREIGABE

Keine Blocker. Geprüft: Rechte aller sechs Kalender-/Tagebuch-Routen und der Seiten diary.php/tasks.php, die Tagebuch-Automatik in NoteService, XSS, Eingaben/Budget, CSP-Nachtrag.
- Rechte: `DiaryService::scope`/`workspace` (DiaryService.php:266-274) laufen über `resolveOptional` → `requireByKey` (fremd → 404); ohne Workspace `visibleWorkspaceIds`. `calendarStamps`/`findList` filtern `role=note` + `trashed_at IS NULL`, Abgaben sind also ausgeschlossen. `entries` prüft Workspace, `role`, Papierkorb und Tagebuch-Teilbaum (:329); verknüpfte Ziele laufen über `filterReadable` (:336). POST diaries.php hat `write` (:286) und CSRF (kein `NoCSRFRequired`). Tests: DiaryTest::testOtherUserSeesNothing.
- Automatik: Vorlage nur aus demselben Workspace, nur `role=note`, nicht im Papierkorb (DiaryService.php:468), nur bei leerem Inhalt (NoteService.php create). Verschieben nur ohne Ordner-/Workspace-Wechsel, unter Zeilensperre nach Versionsprüfung, Workspace mit `write` (NoteService.php updateLocked).
- XSS: Journal-Markdown über `renderSnapshot`; HTML ist beim Speichern bereinigt; Aufgaben werden escaped (:402-407). Templates nutzen `p()` und JSON-Inseln mit `JSON_HEX_*`. Die P25/P26-Patches führen kein neues `innerHTML` ein; Upstream-Rendering escaped Titel, URL, Bild und Farbe.
- Eingaben: `InputValidator::date` lehnt 2026-02-30 ab (Test vorhanden), höchstens 50 IDs. Budget 1 MiB wird vorab über `contentBytes` geprüft; Einträge über 384 KiB bekommen einen Hinweis-Link. Seitengröße ≈ 1,7 KB/Karte (Suchtext ≤ 1 000 Zeichen) entspricht SPEC §4.5 (≤ 2 MiB) und ist vertretbar.
- CSP: `diary` in `NOTE_VIEWS` = gleiche Policy wie `index`, ADR-0012-Nachtrag vorhanden. `tasks` bleibt beim Standard.

## Hinweise (nicht blockierend)
1. DiaryService.php:420 `tooLarge`: Der Link nutzt `$ws->getName()` statt `workspaces->keyFor($uid, …)`. Bei doppelten Namen in geteilten Workspaces (§4.4) öffnet er den falschen bzw. den Standard-Workspace. Fix: den Schlüssel übergeben.
2. DiaryService.php:476-477: Bei gleichem Typ geht die Vorlage im Speicherformat ohne `toClient` in `applyContent` → `toServer`. Das ist nur korrekt, solange `toServer` idempotent ist; zur Symmetrie wie im Umwandlungszweig erst `toClient`.
3. DiaryService.php:496 `resolvePath(..., true)`: Ohne Unique-Index auf Ordner (parent, name) können zwei gleichzeitige Anfragen doppelte `JJJJ/MM`-Ordner anlegen. Es gehen keine Daten verloren, das Muster bestand schon vorher. Für M4i/Härtung vormerken.
4. Testlücke: Kein Test für Leser mit Nur-Lese-Freigabe (POST diaries.php → 403; PATCH mit Datumstitel → 403 ohne Ordneranlage; diary.php ohne Anlege-Knöpfe) und keiner für eine Vorlage aus einem fremden Workspace in `diary_templates` (→ keine Übernahme). Empfohlen.
5. `notes-on-date?mode=modified` filtert auf `updated_at`, die Zähler nutzen `updated_at ?: created_at`. Das ist nur dann inkonsistent, wenn `updated_at` 0 bzw. NULL sein kann.
