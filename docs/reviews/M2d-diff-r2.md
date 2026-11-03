# M2d Diff-Review r2 (speccritic)

VERDICT: FREIGABE

- Blocker 1 (r1) behoben: `DeletedWorkspaceSweepJob` (TimedJob 86400 s, TIME_INSENSITIVE, in info.xml registriert) läuft täglich über alle `owner_type=deleted`-Kennungen, unabhängig vom Job-Argument, und nutzt denselben Pfad `purgeWorkspaces`; SPEC §5 nachgezogen.
- SPEC-Hinweis aus r1 umgesetzt: §8 nennt jetzt den Fehlerfall des Listeners (Rethrow, `CleanupDeletedUsers` wiederholt die Löschung) und das Restrisiko bei neu angelegter UID.
- Sweep und regulärer Job gleichzeitig: unschädlich. Alle Löschungen gehen über ID/workspace_id und treffen beim zweiten Lauf 0 Zeilen; `deleteById` wirft nicht; die IAppData-Löschung fängt `NotFoundException`, `FileCleanupQueue::flush` protokolliert nur. Mögliche Sperrwartezeit oder Deadlock trifft nur einen der beiden Läufe, den Rest erledigt der nächste Sweep.
- Budget/Fortschritt: Deadline wird zwischen Workspaces geprüft, je Workspace eine Transaktion (äußerste, depth 0), IAppData erst nach deren Commit; Fortschritt ist implizit, weil fertige Workspaces gelöscht sind. Ein einzelner sehr großer Workspace kann das Budget überschreiten (vertretbar).
- Hinweis (nicht blockierend): Wirft `purgeWorkspaces` für eine Kennung dauerhaft (z. B. defekte Zeile), bricht `sweep` ab. Weil nach `owner_id ASC` sortiert wird, blockiert dieselbe Kennung jeden Tag alle späteren. Empfehlung: Fehler je Kennung fangen, protokollieren, mit der nächsten Kennung weitermachen.
