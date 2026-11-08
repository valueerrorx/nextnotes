# Review M6f r1 (Diff gegen HEAD 3e5be89d + neue Dateien)

VERDICT: FREIGABE

Keine Blocker. Geprüft: AssignmentOwnership, Command/AssignmentTransfer, AssignmentMapper/SubmissionMapper/WorkspaceMapper, TeamService::teachers, TeamLifecycleService, TeamCleanupService (beforePurge, purge, checkDemoted), AssignmentDeletion(onlyOrphaned), UserDataService, DistributionService/AssignmentService (notify), Notifier, l10n, info.xml, SPEC §5/§6/§8/§9.7, ADR 0008 Nachtrag, Plan §13, AssignmentLifecycleTest.

- Kontolöschung: releaseDeleted vor der Transaktion (Circles außerhalb), wirft nie; in der Transaktion owner_uid NULL für Reste (UserDataService.php:84). Ohne Ziel NULL, mit Ziel Übergang + Meldung. Abgaben bleiben (detachStudent unverändert).
- Zielwahl: exists() fehlerfrei, Kandidaten nur lokale Benutzer mit Status Mitglied und Level >= 4, Level einzeln per currentLevel bestätigt, Besitzer ausgeschlossen; Fehler -> kein Ziel; Austritt/Herabstufung nur bei aktivem Team (AssignmentOwnership.php:178).
- Austritt: strikte Level-0-Prüfung und exists() vor markLeft/Übergang; verwaistes Team setzt nichts. left_team_at in jedem Status unbedenklich: Spalte wirkt nur auf Anzeige und reconcile-Reset (grep), Rechte hängen am Level.
- Purge: Team-Sperre -> Zustand orphaned unter Sperre -> Aufgabe (AssignmentDeletion.php:53); Schüler-Workspaces unter Team-Sperre; Team-Zeile nur unter Sperre ohne Aufgaben/Team-Workspaces (TeamCleanupService.php:324). Rückweg bricht ab. reconcile im verwaisten Team teilt nicht aus (getestet).
- occ: Benutzer muss existieren; aktives Team nur Lehrkraft auch mit --force; sonst nur mit --force; bedingtes UPDATE, Log, Exit 1 bei Fehlschlag.
- Meldungen: assignment_transferred nur solange Empfänger owner_uid; Team-Meldung nur eigene Zählung, keine fremden Daten; Sammelmeldung blendet bei Verlängerung aus.
- SQL portabel (QB, selectDistinct mit ORDER-Spalten in der Auswahl, IN in 1000er-Blöcken); keine Migration.
- M6e2-r1 Hinweise 1–4 umgesetzt und getestet.

## Hinweise
1. Plan M6 §2.5 („Austritt … für offene und abgegebene Abgaben“) widerspricht jetzt SPEC §8/ADR 0008 Nachtrag e/Plan §13 („alle Abgaben“); Satz in §2.5 angleichen.
2. Done 4 „Tag 30 (gemockt)“ und Herabstufung „über CleanupJob-Schritt“: Tests rufen purge() bzw. checkDemoted() direkt, nicht TeamCleanupService::run; ein Lauf über run() mit gemockter Zeit wäre der genauere Nachweis. Export durch owner_uid in der Frist erst M6g.
3. adminTransfer: vorübergehender Circles-Fehler bei aktivem Team ergibt Zustand „unknown“, mit --force dann beliebiger Benutzer; sobald Circles wieder antwortet, setzt checkDemoted den Besitzer stillschweigend auf eine Lehrkraft zurück. Spec-konform, aber in der occ-Ausgabe/Admin-Doku erwähnen.
4. Tester: Löschpfade laut Done-Testart auch auf mariadb/pgsql (ownersInActiveTeams, assignmentIdsWithLeft, markLeft).
