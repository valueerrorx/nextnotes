# ADR 0021: Aufgabe gehört genau einem Team-Workspace

Status: entschieden (Thomas/Lead, 2026-11-09; Slice M6-wsbind, Plan `docs/slices/M6-wsbind-plan.md` r3)

## Kontext
Aufgaben speicherten nur `team_id`. Jeder Workspace eines Teams zeigte deshalb dieselben Aufgaben; Links und Teams-Ressource zeigten auf den ersten Team-Workspace. Thomas: Workspaces werden nicht gemischt (UI-tplpick), eine Aufgabe gehört dorthin, wo sie angelegt wurde. Aufgaben gibt es nur für Teams.

## Optionen
1. `workspace_id` nullable, nur Anzeige; Altaufgaben ohne Workspace bleiben team-weit sichtbar.
2. **`workspace_id` NOT NULL als Ort (Liste, Links, Lebensdauer); Rechte bleiben team-basiert; Altaufgaben löscht die Migration.**
3. Rechte zusätzlich am Workspace prüfen.

## Entscheidung
Option 2.
- `nextnotes_assignments.workspace_id` bigint NOT NULL, Index `nn_asg_ws`, keine FK. Migration `Version000800Date20261112000000` (0.9.3) löscht vorhandene Aufgaben über `AssignmentDeletion::purge` (gleicher Pfad wie manuelles Löschen) und prüft danach, dass die Tabelle leer ist; sonst Abbruch ohne Schemaänderung. Kein Kompatibilitäts-, Befüllungs- oder NULL-Code.
- `POST /assignments` verlangt `workspace` (Team = Besitzer des Workspace); Quelle genau aus diesem Workspace; Workspace wird in der Transaktion gesperrt und neu gelesen, erst dann die Vorlage.
- Rechte unverändert (§9.7, `team_id`): Team-Workspaces sind für jedes Mitglied lesbar, eine Workspace-Prüfung ergäbe dieselbe Menge (Option 3 verworfen).
- API liefert `workspace_id`/`workspace` nur bei Leserecht am Workspace, sonst null.
- Seite: Team-Workspace zeigt nur seine Aufgaben aktiver Teams; jede andere Seite nur Aufgaben nicht aktiver Teams (SPEC §9.6). Links über `WorkspaceService::assignmentKey`.
- Workspace löschen mit Ziel: Aufgaben ziehen mit um. Ohne Ziel (letzter, `confirm`): Aufgaben samt Abgaben werden gelöscht (ADR 0008 Nachtrag M6-wsbind). Ziel unter der Sperre geändert → 409 `workspace_changed`.
- Teams-Ressource „Aufgaben – {Workspace}“ je Team-Workspace (`assignments:<Workspace-ID>`).

## Konsequenzen
- Upgrade auf 0.9.3 löscht alle Aufgaben und Abgaben (Dev/Demo-Daten, Lead). Thomas vor dem Upgrade der Demo informieren.
- `purge` in der Migration nutzt aktuellen Code gegen das Schema vor 0.9.3; spätere Migrationen, die Mapper/Services von `purge` ändern, müssen das berücksichtigen.
- Invariante (`workspace_id` → Team-Workspace dieses Teams) gilt nur für aktive Teams. Nach `make-private` zeigt `workspace_id` auf einen privaten Workspace; die Aufgaben gehen mit der Team-Frist.
- **Bekannte Grenze:** Würde ein Team nach `make-private` wieder `active`, erschiene die Aufgabe auf keiner Seite (Regel 1 verlangt den Team-Workspace, Regel 2 ein nicht aktives Team). Praktisch ausgeschlossen, weil `make-private` ein gelöschtes Team verlangt (`exists()` = null); Rechte und Daten bleiben unberührt (API, Export, Löschen).
- Kein Verschieben zwischen Teams: der Besitzer eines Workspace ändert sich nur bei Anlegen, `make-private` und Kontolöschung (`owner_type=user`).
