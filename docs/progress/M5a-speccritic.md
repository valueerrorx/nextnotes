# M5a speccritic
Auftrag r1: Diff-Review M5a → docs/reviews/M5a-r1.md (fertig, BLOCKER)
Auftrag r2: B1/H1–H5 gelöst? neue Fehler? → docs/reviews/M5a-r2.md
Erledigt r2: QuotaService/LeaveRequest/AttachmentMapper::sizeLeaving, alle forLeave-Aufrufer (Note/Folder/Workspace), AccessService::moveLeaving, WorkspaceDeletion, TeamService::exists gegen Circles-Quelle (scratchpad/circles), ADR 0003/SPEC §2.2/§3/§5/§9/§14, TeamAccessTest eingefroren, TeamQuotaTest
Befunde: keine Blocker; Hinweise Deadlock-Reihenfolge enforce/lockRows, Bytes vor Tx, moveLeaving falsches 403, SPEC §9 getTeamsForUser, TTFB
Nächster Schritt: -
Status: fertig (VERDICT FREIGABE)
