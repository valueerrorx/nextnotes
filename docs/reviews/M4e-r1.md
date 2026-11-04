# M4e Diff-Review r1 (speccritic)

VERDICT: BLOCKER

1. CSP-Ausnahme ohne ADR: `nextnotes/lib/Http/CspFactory.php:19` nimmt `trash` in `NOTE_VIEWS` auf (`frame-src 'self'` + Embed-Domains). SPEC §4.6 verweist auf ADR 0012, dort fehlt aber ein Nachtrag M4e (letzter Nachtrag M4c nennt nur `export_print`, `docs/adr/0012-csp-ausnahmen.md:43-44`). Der Kommentar in `CspFactory` sagt selbst „ohne ADR keine Ausnahme“. Fehlt: „Nachtrag M4e“ in ADR 0012 (Papierkorb-Vorschau zeigt Einbettungen, dieselbe Policy wie `index`, keine weitere Lockerung). Inhaltlich ist die Policy nötig und nicht lockerer als in der Notizansicht.

Geprüft ohne Befund:
- Rechte/Isolation: `PageFrameService::workspace` löst nur sichtbare Workspaces auf (`resolveKey`), ein fremder oder unbekannter Schlüssel führt zu Standard → zuletzt geöffnet → `defaultWorkspace`. Alle Mapper-Abfragen sind auf `wsId` begrenzt und haben `role='note'`, dadurch keine Abgaben. Wiederherstellen, endgültig Löschen und Leeren laufen über die bestehende API mit `requireNote(...,'write')` bzw. `resolveOptional(...,'write')`/`writableWorkspaceIds`; `pageWorkspace` ist nie leer (`keyFor`). Ohne Schreibrecht zeigen trash, list_folders und create keine Aktionsknöpfe.
- CSRF: `trashSearch` (POST) hat kein `NoCSRFRequired`, `$_['requesttoken']` ist gesetzt (NC `Template/Base.php:28`), und `ControllerAttributeTest` ist ergänzt. Die GET-Seiten haben keine Seiteneffekte (B58).
- XSS: In den Templates wird durchgehend `p()` verwendet. `print_unescaped` steht nur bei JSON-Inseln und bei `$card['html']`; letzteres ist gespeicherter, bei Kürzung neu bereinigter Inhalt, `renderSnapshot` oder der escapende Tasklisten-Renderer (`linkify` maskiert vorher, nur `http(s)`/`www`). `style` erhält nur geprüfte Hex-Werte bzw. Zahlen. P23 ist korrekt. `notes-manager.js` `escHtml` maskiert Anführungszeichen. `list_tags.js` schreibt per `innerHTML` nur Übersetzungen.
- SQL ist portabel (`substring`@12, `count`@14 in `.ref` bestätigt), Abfragen sind gebündelt, kein N+1.
- „Leeren“ (`TrashService::empty`) löscht den gesamten Papierkorb des Workspace, nicht nur die 50 angezeigten. Die Zahl zeigt `total`, der Dialog sagt „vollständig“. Ältere Notizen erreicht man über die Suche (search_text) und den Sortierwechsel. Kein Datenverlust außer dem gewollten, daher kein Blocker.
- `--pz-danger` → `--color-element-error` ist richtig (Hell `#c90000`: weiße Schrift gut lesbar).

Hinweise:
- H1 `TrashViewService.php:96,130-141`: Aufgabenlisten bis 256 KiB werden ganz geladen und gerendert. 50 × 256 KiB ≈ 12,5 MiB überschreitet das im Kommentar genannte Render-Budget von 1 MiB je Request (Plan §1.2). Vorschlag: Summe begrenzen oder Aufgaben-Vorschau kürzen.
- H2 `trash.php:105-108`: Der Hinweis auf das Limit nennt nicht den Weg zu älteren Notizen. Vorschlag: „… Suche verwenden“ ergänzen.
- H3 Dunkel: weiß auf `#FF5050` hat etwa 3,2:1 (unter AA für normale Schrift). Bei Gefahr-Knöpfen mit normaler Schriftgröße beim Smoke Dunkel prüfen.
