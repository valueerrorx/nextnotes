# M3c1 Diff-Review r2 (speccritic)

VERDICT: FREIGABE (Code und Doku; Blocker 2 r1 = Testnachweis liegt beim Tester, Gate des Leads)

## r1-Blocker
1. Erledigt. `nextnotes/lib/Http/CspFactory.php:23,38-39`: `attachments` nur in `SELF_FRAME_VIEWS`, nur `frame-src 'self'`, keine Embed-Domains. Nachtrag in `docs/adr/0012-csp-ausnahmen.md` (M3c1), P13 in SPEC §11.1, B34.
2. Offen beim Tester. Vorhanden: `tests/nextnotes/php/Page/PageIsolationTest.php` (Detektor-Selbsttest, fremde ID je Stelle inkl. `workspace` allein, Lesefreigabe, abgemeldet). Nicht gesehen: Krit. 1b (Namen aus M3a2.4 auf `revisions.php`/`attachments.php`/`kanban_content.php`) und Kanban-Verknüpfung auf fremdes Ziel (s. Hinweis 1). Nicht ausgeführt (Auftrag: keine Container).

## `AccessService::filterReadable` (`AccessService.php:130-139`) – gleichwertig zu `noteLevel ≥ READ`
- eigene: `findOwned` (owner_type=user, owner_id=uid) = computeLevel MANAGE. Gleich.
- geteilt/Lesefreigabe: `findSharedWith` (share_type=user, Join owner_type=user, jede permission) = computeLevel READ/WRITE nur bei owner_type=user. Gleich.
- Team-Workspaces: beide NONE (teamLevel-Platzhalter, `findSharedWith` schließt owner_type≠user aus). Gleich.
- owner_type deleted / Workspace fehlt: beide NONE. Gleich.
- role≠note / workspace_id NULL: beide NONE. Gleich.
- Papierkorb/gelöschte Notiz: weder `noteLevel` noch `filterReadable` prüfen `trashed_at`; Kanban filtert danach selbst (`KanbanViewService.php:74`). Gelöschte Zeilen liefert `findByIds` nicht. Gleich.
Kein Rechteleck.

## Weitere Prüfung ohne Befund
- `KanbanViewService.php:49-61`: nicht-numerische ID → 404, `requireFolder(read)`, Workspace-Schlüssel ≠ Ordner-Workspace → 404; Notizen nur aus `[$wsId]`, Ziele nur nach `filterReadable` + nicht im Papierkorb. N+1 aus r1 behoben.
- `templates/revisions.php:20-27`: MCP-Filter je Absatz; in allen 10 `src/i18n/*.json` genau ein Absatz mit „MCP“ (4 Absätze), Ausgabe über `p()`.
- `templates/partials/note_display.php:76,82-83,150`: neue Knöpfe über `$btn`/`$item` bzw. direkt mit `p()`; nur feste Aktionen und numerische ID in Attributen. `show-snapshot`/`show-note-info` existieren in `index-events.js`. Info im Menü nur bei `showDates=false` (r1-Hinweis 4 erledigt).
- SPEC §4.3 Nr. 12, §1.2 Nr. 2, B33/B34, M4 Krit. 2 (linked-Download offen), M3-plan Teilung M3c1/M3c2 und M4-Vormerkung: konsistent, kein Scope-Wachstum.

## Hinweise
1. Test fehlt: B besitzt Kanban-Ordner mit `type=linked` auf eine Notiz von A ohne Freigabe → Karte zeigt weder Inhalt/Tags/Farbe noch Erinnerung des Ziels (Done M3c Krit. 1, indirekter Lesepfad).
2. M5: `filterReadable` hängt an `visibleWorkspaceIds`. Wenn `teamLevel` umgesetzt wird, beide gemeinsam anpassen, sonst laufen sie auseinander (Kommentar oder Test „filterReadable == noteLevel“ empfohlen).
