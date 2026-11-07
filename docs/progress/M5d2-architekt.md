# M5d2 Architekt – Nacharbeit Teams (Erwähnungen, Team-Ressourcen)
Status: fertig
Auftrag: 1) `@`-Menü in Richtext-Notizen (Team-WS) reparieren + E2E in teams-m5d.mjs. 2) Team-Workspaces in Contacts→Teams-Ressourcen (ITeamResourceProvider) + PHPUnit.
Instanz: NN_PROJECT=nextnotes-m5d2 (nicht 8090, nicht m5e-Ports). M5e-Dateien nicht anfassen.

## Erledigt
- Befund: Provider `nextnotes/lib/Teams/WorkspaceResourceProvider.php` existiert schon (M5a) und ist registriert.

- Instanz nextnotes-m5d2 sqlite läuft (Port 8391, NN_PORT_BASE=8390), App installiert (exit 0).
- Ursache 1: Workspace-Wechsel ohne Neuladen (`switchToWorkspace`, Zurück-Taste ui.js, utils-folders) ersetzt nur #left_col; `window.PoznoteMentionMembers` bleibt vom Start-Workspace (privat = leer). Auch neuer Team-WS über Dialog → In-place-Wechsel. Upstream-Bug.
- Fix P32: `src/public/js/workspaces-create.js` neu `refreshMentionMembers`, aufgerufen in `refreshLeftColumnForWorkspace` (GET /api/v1/mentions/members, ohne sich selbst, verspätete Antworten verworfen).

- E2E teams-m5d.mjs Abschnitt 3a ergänzt (neuer Team-WS → neue Notiz → „@“-Menü → Enter → Autosave → Benachrichtigung B; Wechsel privat/geteilt/anderes Team/Zurück-Taste). Hell grün (82 Prüfungen). Ohne P32: 10 FAIL (Bug belegt).
- Offen dokumentieren: frisch hinzugefügtes Mitglied erscheint erst nach Wechsel/Neuladen (Liste je Seitenaufruf/Wechsel).

- Befund 2: Provider liefert korrekt (OCS /teams/{id}/resources, /teams/resources/nextnotes/{id}). NC35-Teams-UI (circles, /apps/circles/teams/team/<id>) zeigt in der Navigation „Team-Ressourcen“ fest nur Team-Ordner/Collective/Deck/Seiten; Fremd-Provider stehen unter „Mit dem Team geteilt“ (Screenshot belegt, groupfolders+contacts auf eigener Instanz installiert). Navigationseintrag nicht per OCP möglich.
- Provider verbessert: Icon in currentColor (dunkles Theme), App-Icon je Workspace-Ressource (vorher allgemeines Dokument-Icon).
- PHPUnit `tests/nextnotes/php/Teams/TeamResourceProviderTest.php` sqlite grün (49 Assertions).

- SPEC: §3 Teams-Ansicht (M5d2), §11.1 P32, §11.2 B67. Kein ADR-Nachtrag (ADR 0003 deckt Provider ab). upstream-patches.txt unverändert (Datei schon gelistet).
- make build 0, check-upstream grün, check-forbidden-sql 0 Treffer.
- PHPUnit TeamResourceProviderTest: sqlite/mariadb/pgsql grün; TeamUi/TeamWorkspace/TeamAccess+Provider sqlite grün (15 Tests).
- E2E hell sqlite: teams-m5d (82) + smoke-m4 (164) grün.

## Nächster Schritt
- keiner (Instanz nextnotes-m5d2 abgebaut, keine Container/Volumes übrig)

## Offen
- Frisch hinzugefügtes Teammitglied: Vorschläge erst nach Workspace-Wechsel oder Neuladen (Liste je Seitenaufruf/Wechsel), bewusst so.
- Teams-App-Navigation „Team-Ressourcen“ nicht erweiterbar (circles fest codiert); Workspaces unter „Mit dem Team geteilt“.
