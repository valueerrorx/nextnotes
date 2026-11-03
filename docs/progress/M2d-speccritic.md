# M2d speccritic (Diff-Review r1)
Auftrag: Diff ggü. 030da15d gegen M2-plan §1/§2 M2d/§5, SPEC §2/3/5/8/13/14, ADR 0003/0008 → docs/reviews/M2d-diff-r1.md
Erledigt:
- git diff 030da15d + neue Dateien gelesen (Listener, UserDataService, Job, Migration 000200)
- OCP/Kern geprüft: QueuedJob::start (remove vor run), User::delete + CleanupDeletedUsers, Manager UID-Regex, DB\Exception-Reasons
- Mapper-Chunking, AccessService deleted→NONE, Transaction depth/IAppData
- Review geschrieben: docs/reviews/M2d-diff-r1.md, VERDICT BLOCKER (1: Job nicht wiederaufnehmbar)
Nächster Schritt: keiner
Status: fertig

## Runde 2
- Gelesen: DeletedWorkspaceSweepJob, UserDataService (sweep/purgeWorkspaces deadline), info.xml, SPEC §5/§8; WorkspaceMapper::deleteById, FileCleanupQueue::flush, AttachmentService::queueFolderDeletion
- Review: docs/reviews/M2d-diff-r2.md, VERDICT FREIGABE (Hinweis: Fehler je Kennung im Sweep fangen)
Status: fertig
