VERDICT: FREIGABE

Review M6-wsbind Plan r3 (speccritic, Runde 2), Basis e258fcd5. Die Befunde aus r1 sind erledigt:

1. r1-1 (make-private) – erledigt. Lead-Entscheid Variante (a): Plan §0 begrenzt die Invariante auf aktive Teams, §3 lässt `make-private` unverändert, L1 richtet sich nach `team_state`. `team_state` liefert die API schon (`AssignmentService` Z. 136). `TeamLifecycleService` bleibt unverändert. Das passt zu SPEC §3/§8.
2. r1-2 (Löschen ohne Ziel) – erledigt. Reihenfolge ist confirm → Vorprüfung → Purge → Sperre mit 409 `workspace_changed`. Die Team-Sperre gilt für beide Zweige, der Teilabbruch ist festgelegt.
3. r1-3 (create) – erledigt. Die Rückgabe von `lockRows` wird ausgewertet, erneutes Lesen folgt, `copyAsTemplate` läuft danach. Done 3 testet den Fall.
4. r1-4 (ID-Preisgabe) – erledigt. `workspace_id` und `workspace` kommen nur bei Leserecht, sonst null.
5. r1-5 (Done-Kriterien) – erledigt: Done 2 (`AssignmentMigrationTest` mit AppData-Dateien und Leerprüfung), Done 4 (null-Sicht, 409, make-private), Done 5 (alte Ressourcen-ID, `isSharedWithTeam`), Done 6 (L1 im E2E).
6. r1-6 (Migration) – erledigt: Kommentar und Leerprüfung sind im Plan, kein Kriterium zu `markProcessed`.
7. r1-7 (Scope §4.1) – Lead-Entscheid: bleibt im Slice.
8. r1-8 – erledigt: §8 hat keine offenen Punkte mehr.

Die Sperr-Reihenfolgen habe ich erneut gegen den Code geprüft. Löschen mit Ziel: Team → Aufgaben (rebind) → `lockOwner`. Das passt zu `AssignmentDeletion` und `create`. Das Workspace-Anlegen nimmt keine Team-Sperre, damit entsteht kein Zyklus.

Hinweise (keine Blocker):
- H1: Nach `make-private` zeigt `workspace_id` auf einen privaten Workspace. Wird ein Team nach dem Umwandeln wieder `active`, ist die Invariante verletzt, und die Aufgabe erscheint auf keiner Seite. Das ist praktisch ausgeschlossen, weil `make-private` `exists()`=null verlangt. Im ADR 0021 als bekannte Grenze nennen.
- H2: `AssignmentMigrationTest` muss für „Spalte fehlt“ einen eigenen `ISchemaWrapper`-Closure übergeben. Nach der Installation existiert die Spalte, der Zweig würde sonst übersprungen. Done 2 sagt „simuliert“; darauf beim Test achten.
- H3: L1 „jede andere Seite“ schließt auch Team-Workspace-Seiten anderer aktiver Teams aus, weil dort die erste Regel greift. Das ist so gewollt. In SPEC §9.6 so formulieren, dass es eindeutig ist.
