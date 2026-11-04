VERDICT: FREIGABE

Diff-Review M4h, Runde 1 (statisch, Basis 5ce0824b + untracked). `build/check-upstream.sh` exit 0.

Geprüft ohne Befund:
- Routen `page#settings`, `compat#workspacesPage`, `compat#storageStatsUser`: `NoAdminRequired`+`NoCSRFRequired`, nur GET ohne Seiteneffekt, in `ControllerAttributeTest` gelistet. Redirect-Ziele kommen aus `linkToRoute` + `http_build_query`, also kein Open Redirect.
- `SettingsViewService::storage`/`AttachmentService::usage`: nur `owner_type=user, owner_id=uid`. Lesefreigaben zählen nicht, `?workspace=` geht nur escaped in `href`. Abgedeckt durch Test (`SettingsM4hTest::testStorageCountsOwnWorkspacesOnly`, E2E Schritt 8).
- `NotePalette::sanitize` entspricht Upstream (ID `[a-z0-9_-]{1,32}`, Hex nur `#rgb/#rrggbb`, Name ≤ 40, ≤ 24 Einträge). `hex()` normalisiert auch gespeicherte `#…`-Werte, ist also strenger als das alte `colorHex` (`{3,8}`). CSS-Ausgabe (`style="background-color: …"`) läuft nur mit normalisiertem Hex und `p()`. Die Palette eines Benutzers sieht nur er selbst (keine fremde Ausgabe der Namen).
- `open-app-url` (`nextnotes-bootstrap.js:471-476`): `new URL` mit gleicher `origin` und Pfad-Präfix `/apps/nextnotes/` bzw. `/index.php/apps/nextnotes/` (mit abschließendem `/`). `javascript:`/`data:` haben die Origin `null`, `//host` eine fremde Origin. `..`/`%2e%2e` normalisiert der WHATWG-Parser. Kein Inline-Handler. `data-url` setzt nur der Server.
- CSP: `forView('settings')` ist Standard-CSP, keine neue Ausnahme, kein ADR-Nachtrag nötig. Inseln in `settings.php` mit `JSON_HEX_*`.
- Gestrichene Karten fehlen im Template (Liste im Kopfkommentar, E2E-Prüfliste `settings-m4h.mjs:13`). Export-Karten verweisen direkt auf `api_export_*`, „alle“ = eigene (ADR 0008).
- P29 steht in SPEC §11.1 und `upstream-patches.txt`. check-upstream (g)/(h) grün.
- Neue Mapper-Abfragen (`countByWorkspace`) sind portabel (QB, `count`, `groupBy`, Chunk 1000).

Hinweise (keine Blocker):
1. `WorkspaceService.php:264-271`: Der Vergleich nutzt den Rohnamen (`$oldName`), gespeichert ist aber der Schlüssel (§4.4). Szenario: A besitzt „Foo“ (Standard) und verwaltet den Team-Workspace „Foo“ (Schlüssel „Foo (Team)“). Benennt A das Team in „Bar“ um, springt `default_workspace` vom eigenen „Foo“ auf „Bar“. Umgekehrt bleibt ein Schlüssel mit Zusatz nach dem Umbenennen stehen. Fix: alten Schlüssel vor dem Update aus `keys($uid)[id]` holen und nach `reset()` mit dem neuen Schlüssel vergleichen bzw. setzen. Betroffen sind nur die eigenen Einstellungen; ohne Datenverlust.
2. `NotePalette::hex()` löst Paletten-IDs mit der Palette des Betrachters auf. Bei geteilten bzw. Team-Workspaces (M5) sehen Mitglieder die eigenen Farben des Besitzers nicht oder anders (`color_hex` null), und gelöschte Paletten-IDs bleiben ohne Farbe in Notizen/Ordnern stehen (wie Upstream). Spätestens für M5 in SPEC als bewusste Vereinfachung festhalten oder mit der Palette des Besitzers auflösen.
3. `NotePalette` portiert `src/lib/note-colors.php`, ist aber nicht in `build/upstream-ported.txt` (check-upstream f). Aufnehmen, damit Upstream-Änderungen am Sanitizer auffallen.
4. Anti-Flackern `nextnotes-layout.css:36-50`: Mit `opacity: 0` sind die Inhalte bis zu 2 s unsichtbar, aber fokussier- und klickbar und für Screenreader lesbar. `visibility` verhinderte das vorher. Gering, weil es nur kurz dauert. Optional zusätzlich `pointer-events: none` bis `nextnotes-ready`.
