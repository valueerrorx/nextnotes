VERDICT: FREIGABE

Review M2-plan r2 (speccritic, 2026-11-02). Geprüft: `docs/slices/M2-plan.md` r2, `git diff docs/SPEC.md`, ADR 0005 (unverändert), Upstream `NotesController.php:165-196`, `src/public/js/note-edit-lock.js:768,816`.

## Blocker aus r1
1. Fehler-Middleware: erfüllt (Plan §1, nur eigene Ausnahmen + `DoesNotExistException`, Rest weitergeworfen; Done M2a 6 prüft 401/412).
2. Kontolöschung: erfüllt (synchroner Listener, `owner_type=deleted`, Job nur über Workspace-IDs; SPEC §5/§8/§13 M2.8 angepasst; Done M2d 2 „vor Joblauf“).
3. Isolation Body-IDs: erfüllt (Plan §3.3 Parameterkarte inkl. Query/Body, Pflichtdeklaration, Generator je ID-Stelle, beide Richtungen).
4. Erinnerungen: erfüllt (`canRead` in Job und `prepare`, `AlreadyProcessedException` @since 17, `unshare` löscht; SPEC §5/§6; Done M2c 3).
5. Sperre: erfüllt, Plan folgt ADR 0005 (409 Erwerb, 423 Speichern, nur dieselbe Sitzung); SPEC §11.2 B13; §13 M2.6 passt.

Hinweise aus r1 (Schlüssel-Fallback `#<id>`, Wettlauf Standard-Workspace, `insertIgnoreConflict`, `FileCleanupQueue`, `InputValidator`, Attribut-Reflection, Krit.-8-Spalten, `opened`-Filter, `mysql.utf8mb4`) sind eingearbeitet und prüfbar.

## Hinweise (keine Blocker)
- SPEC §2.1 `nextnotes_workspaces.owner_type` nennt weiter nur `user | team`; `deleted` (§8, Plan §1) dort und in §3 (kein Rechteweg) nachtragen, damit Datenmodell und Lebenszyklus übereinstimmen.
- `LockService`: Übernahme/Heartbeat/Release zusätzlich an `holder_uid = uid` binden, nicht nur an die vom Client gelieferte `X-Editor-Session-ID` (Payload gibt die Sitzung zwar nicht heraus, Absicherung kostet nichts).
- M3-Vorwarnung zu R11: `note-edit-lock.js:768/816` wertet `isLockHeldByDifferentUser(lock)` aus; bei gleichem Konto in anderem Tab (`holder_is_current_user` true) zeigt das Upstream-Frontend vermutlich kein Sperr-Banner und läuft beim Speichern in 423. Das wird in M3 wahrscheinlich eine §11.1-Änderung oder Adapter-Logik brauchen; jetzt kein M2-Thema.
- `ensureDefault`/Namensprüfung müssen `owner_type=user` filtern, sonst kollidiert ein neu angelegter gleicher UID mit noch nicht gelöschten `deleted`-Workspaces (Done M2d 2 deckt das indirekt ab).
