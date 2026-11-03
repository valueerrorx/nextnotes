# M3c1 Diff-Review r1 (speccritic)

VERDICT: BLOCKER

1. CSP-Erweiterung ohne ADR-Deckung – `nextnotes/lib/Http/CspFactory.php:21` nimmt `attachments` in `NOTE_VIEWS` auf. Damit erhält die Anhang-Seite nicht nur `frame-src 'self'` (für P13 nötig), sondern auch alle Admin-Embed-Domains, die dort nie eingebettet werden. ADR 0012 (Tabelle Z. 16–17) deckt `frame-src` nur für „Ansichten mit Editor/Notizanzeige“ (Audio-Player, Video-Embeds); die PDF-Vorschau auf `attachments.php` ist ein neuer Zweck. Nötig: eigene Liste nur für `'self'` für `attachments` (ohne Embed-Domains) und ein Nachtrag in ADR 0012 (Ansicht `attachments`, Zweck PDF-iframe P13). Den CSP-Header je Ansicht prüft `PageRoutesTest` (Plan-Tests).
2. Done Krit. 1/1b sind für M3c1 nicht vollständig nachgewiesen. Im Diff fehlen `tests/nextnotes/php/Access/PageIsolationTest.php` (Plan „Tests“, Done M3c Krit. 1) und eine XSS-Prüfung (Krit. 1b). `dev/nextcloud/scripts/probe-m3c.sh` deckt Folgendes nicht ab: (a) A-Zustand vorher = nachher, (b) Selbsttest des Detektors, (c) die ID-Stelle `workspace` allein (B mit eigener ID + A-Workspace-Schlüssel) für `kanban_content.php`, `attachments.php`, `api_download_note.php`, `api_export_*`, `api_attachments.php` (nur bei `revisions.php`/`api_note_info.php` geprüft), (d) Krit. 1b: Namen aus M3a2.4 (Ordner, Tags, Titel) auf `revisions.php`, `attachments.php`, `kanban_content.php` → kein zusätzliches Element bzw. Attribut. `probe-m3c-pages.mjs` prüft nur CSP, Konsole und ≥ 400. Der Code selbst ist für (c) korrekt (`resolveOptional` → `requireByKey` wirft 404), der Nachweis fehlt. Freigabe erst, wenn der Tester diese Fälle mit Exit-Code nachweist.

## Geprüft ohne Befund
- Rechte: alle neuen Routen laden über `AccessService::requireNote/requireFolder` (`read`), nicht-numerische IDs → 404, Abgleich `?workspace=` → 404 (CompatController:52–63, 134–142; NotePageViewService:256–264; KanbanViewService:49–57; Alt-URL über `AttachmentService::download`). Linked-Ziele im Kanban nur mit `noteLevel ≥ READ`. Schreibknöpfe ohne Schreibrecht ausgeblendet; serverseitig bleibt 403 (Probe: DELETE Anhang, POST Snapshot).
- CSRF: `NoCSRFRequired` nur auf GET-Seiten bzw. GET-Downloads/Exports ohne Seiteneffekt; `noteInfo` mit CSRF. `ControllerAttributeTest` prüft Allowlist und GET-Verb.
- Templates: alles über `p()`; die zwei neuen `print_unescaped` sind JSON-Inseln mit HEX_TAG/AMP/APOS/QUOT, durch `TemplateOutputTest` abgesichert. Farb-/Icon-Werte in `style` geprüft (`colorHex`/`iconColor`/`iconClass`).
- `AttachmentResponse` ist eine 1:1-Verschiebung; B22 (sandbox, nosniff, SVG als Download, PDF `frame-ancestors 'self'`) unverändert. `api_download_note.php`: `DownloadResponse` setzt `attachment`, Dateiname auf `[A-Za-z0-9_-]` reduziert, `nosniff`.
- Adapter `indexPath`: Token nur bei gleichem Origin und App-Pfad (`/…/index.php/apps/nextnotes/`).
- P13 steht in SPEC §11.1 und `upstream-patches.txt`, B33/B34 sind dokumentiert. `show_note_created` = `'1'` wie in der Upstream-DB. `slash:excalidraw` FORCED_HIDDEN wie im Plan.
- Notiz-Info: Die Anzeigenamen sehen nur Leser des Workspaces. Gelöschte Konten stehen als NULL → „Nicht verfügbar“ (§2 UID-Regel). Kein Datenschutzbefund.

## Hinweise
- N+1: `KanbanViewService:73` – `noteLevel` lädt je Linked-Ziel den Workspace einzeln (`workspaces->find`). Nur bei vielen Verknüpfungen spürbar; Workspaces der Ziele können gesammelt geladen werden.
- `revisions.php:157` `unset($description[1])` setzt voraus, dass der KI/MCP-Absatz in jeder Sprache der zweite ist. In den i18n-Dateien prüfen oder per Test absichern.
- `api_download_note.php` bei `type=linked` liefert den Inhalt der Verknüpfung selbst (meist leer), nicht den des Ziels. Mit Export M4 klären.
- Der Info-Knopf sitzt nur in der Unterzeile; mit `show_note_created=0` ist er nicht erreichbar.
