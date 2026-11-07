# M5d Diff-Review r1 (speccritic)

VERDICT: FREIGABE

Keine Blocker. Geprüft: Rechte Freigabe/Suche/Erwähnungen, Policy gegen OCP 35 (`Share20\Manager::currentUserCanEnumerateTargetUser`, `ISearch::filteredSearch`-Signatur), XSS (Templates `p()`, JS nur `textContent`, `innerHTML` nur `''`), Banner/Umwandeln, update.sh, Routen 120→121 konsistent (routes.php 161→162, IsolationTest, RouteDiff/CoreScenario PORT_ONLY, allowed-diff `workspaces[].team`), SPEC §3/§4.2/§4.5/§6/§11.1 (P30/P31, B65/B66)/§12 R16, ADR 0014 Nachtrag.

## Wichtige Hinweise
1. R16 unvollständig: JSON-Antworten lösen Farb-IDs weiter mit der Palette des Betrachters auf – `NoteSerializer.php:101,123`, `FolderService.php:135`, `NotesApiController.php:187`, `FoldersApiController.php:128` (`colorHex($color)` ohne Besitzer). Folge bei abweichenden Paletten: C setzt Farbe im Workspace von A, sieht sofort seine Farbe, nach Reload die von A. Mit `NotePalette::ownerOf($ws)` angleichen oder als Restgröße in §12 R16 nennen.
2. `share_workspace` ohne `allowed_users` (`CompatController::shareList`, `$raw === null` → `[]`) entzieht alle Empfänger samt deren Erinnerungen/Erwähnungs-Status/Hintergründen. Explizites `[]` = Aufheben ist vertretbar; fehlender Parameter sollte 400 sein (`unshare_workspace` deckt das Aufheben ab). PageIsolationTest Z. 421 prüft genau diesen Fall als 200 – dann mit `allowed_users=[]` senden.
3. `KanbanViewService.php:55`: neue Eigenschaft `$paletteOwner` steht zwischen dem Docblock von `build()` und der Methode; `@return/@throws` hängen jetzt an der Eigenschaft (Psalm/IDE).
4. `dev/demo/update.sh`: `occ app:list … | grep -q '"circles"'` unter `set -o pipefail` kann durch SIGPIPE falsch-negativ werden (Teams werden dann still übersprungen); besser Ausgabe in Variable. Sonst idempotent (Konten/Teams nur bei Fehlen, Mitglieder ergänzt), nur Compose-Datei `dev/demo/compose.yaml`; Passwörter `schueler-demo-2026` neu, im Skript-Echo dokumentiert – in STATUS/README zu Demo nachtragen.
5. `tests/nextnotes/e2e/lib/docker.mjs`: neue Helfer `createTeam`/`orphanTeam` (zerstört Kreise) wählen den Container nur über den Port von `base`; kein Schutz gegen Port 8090. Ein Guard (`port === '8090'` → throw) verhindert versehentliche Läufe gegen die Demo.

## Bestätigt (Prüfschwerpunkte)
- Freigabe: `requireByKey(…,'manage')` + `requireShareable` (Team → 403, fremd → 404, Test vorhanden); Policy nur für neue Empfänger, Rechtewechsel per Update, Entzug nie blockiert; alles-oder-nichts vor der Transaktion; einheitlich 400 `Invalid share target` (unbekannt, sich selbst, deaktiviert, (b)–(d)); Liste ≤ 200, UID ≤ 64.
- Suche: `UserRateLimit(30,60)`, ≥ 2 / ≤ 100 Zeichen, NC-UserPlugin übernimmt Enumeration/Gruppe/Telefon, zusätzlich `allowed()`; Antwort nur `{uid, displayName}`; Tests (a)–(e), 429.
- Erwähnungen: `members()` erst nach `requireByKey(read)`, nur aktive Teams, ≤ 500; Benachrichtigung zusätzlich `workspaceLevel ≥ READ`; `owner_path` = Team-ID nur in sichtbaren Workspaces (Mitglieder kennen sie über `GET /teams`).
- Banner: nur bei Zustand ≠ active, Umwandeln nur `created_by`, Backend-Regeln aus M5b unverändert.
