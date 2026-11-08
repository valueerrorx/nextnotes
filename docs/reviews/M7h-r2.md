# Review M7h r2 (Diff, speccritic)

VERDICT: FREIGABE

## r1-Blocker
Behoben. `AccessService::ownWorkspaceIds` nimmt nur eigene Workspaces aus `visibility()`. Damit fallen Schüler-Workspaces ohne `studentTeamOpen` heraus. Die einzigen Aufrufer sind `ZipExportService::attachments` (Z.175) und `scope` (Z.210: entries, structured). Alle übrigen `findOwned`-Wege sind auf `kind=normal` gefiltert oder harmlos: `UserExportService`, `ensureDefault`, Namensprüfungen, `makePrivate` (nur Reihenfolge). Der Test prüft Titel- und Ordner-Needles im entpackten ZIP, die Exporte ohne Workspace und auch den Fall „verwaist“. Laut Bericht wird er rot, wenn man den alten Code einsetzt (Mutationsprobe).

## Legitime Fälle (Punkt 2)
- **Lehrkraft und Besitzer:** eigene Workspaces sind `kind=normal`, also MANAGE wie bisher. Team-Workspaces sind nicht `owner_type=user` und daher nicht betroffen. `owner_uid`-Zweige sind unverändert.
- **Eigene Schüler-Workspaces erreichbarer Teams:** bleiben in `listVisible`. Schlüssel, Links und `requireByKey` funktionieren weiter.
- **Vorlagen, Palette, Erwähnungen:** filtern ohnehin auf `kind=normal` bzw. nutzen geteilte und Team-Workspaces.
- **Performance:** `listVisible` braucht weiter genau ein `findOwned` und ein `visibleWorkspaceIds`. Hinzu kommt je Schüler-Workspace `studentTeamOpen`, gecacht über `stateOf`/`memberTeams`. Nur bei nicht aktivem Team kommt ein SQL hinzu (`hasLeftIn`), gecacht je Team. Kein Befund.

## clearLeftOfMembers (Punkt 3)
Korrekt. Es läuft nur beim ersten Übergang (`markOrphaned` true) und nur, solange Circles das Team liefert (DestroyingCircleEvent im manage-Pfad). Das Update ist gechunkt, mit portablem SQL (`IN`, `IS NOT NULL`). Jedes Fehlerbild wirkt restriktiv: Die Marke bleibt, es gibt kein Leck. Zwischen `markOrphaned` und dem Löschen der Marken bekommen wiedereingetretene Mitglieder kurz 404. Das ist unkritisch.

## Hinweise
1. R18 nennt als Fall, in dem die Marke bleibt, nur „ohne Event (nur CleanupJob)“. Es gibt zwei weitere:
   - `TeamService::members()` fällt über `guarded()` still auf `[]` zurück. Das `catch` in `clearLeftOfMembers` greift dann nicht, und es wird keine Warnung geloggt.
   - Wiedereintritt und danach Einfrieren (Teams-App aus) vor dem Abgleich: Die Marke bleibt, und das Mitglied liest während der Sperre nicht.

   Beide Fälle sind restriktiv. Ein Halbsatz in R18 genügt.
2. r1-Hinweis 1, zweiter Teil, gilt weiter: Hat ein Schüler irgendeine Marke im Team, verbirgt `studentTeamOpen` den ganzen Schüler-Workspace des Teams. Das ist restriktiv und kein Leck.
