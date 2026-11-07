# M5-plan – architekt
Auftrag: Plan für M5 Teams schreiben (`docs/slices/M5-plan.md`), nichts implementieren. Circles im Testimage prüfen, Test-Setup planen.

## Erledigt
- Gelesen: STATUS, SPEC (ganz), ADR 0003/0014, M4-plan (Stil), tester.md
- Code gelesen: AccessService (teamLevel Platzhalter), WorkspaceService (share/unshare vorhanden, moveNotes/reorder manage), MentionService (nur owner_type=user), CompatController share 501
- Image nextcloud:35.0.2-apache: circles 35.0.0 shipped + defaultEnabled, nicht alwaysEnabled; contacts fehlt
- Probe (Container nextnotes-m5probe, danach samt anonymem Volume gelöscht): circles:check Loopback ok (apache); Gruppe → Level 1; CFG_INVITE → Invited, kein getLink, nicht in getTeamsForUser; removeMember → MembershipsRemovedEvent synchron (singleId); CircleDestroyedEvent/CircleMemberRemovedEvent nicht im Prozess (Loopback); app:disable circles → hasTeamSupport false; getMembersOfTeam enthält Gruppe (R2)
- OCP geprüft: ITeamManager/ITeamResourceProvider, isEnabledForUser (8), filteredSearch (35), insertIgnoreConflict (16), registerTeamResourceProvider (29)
- `docs/slices/M5-plan.md` geschrieben (M5a–M5e, O1–O4, Q1–Q5)

## Nächster Schritt
- keiner

## Offen
- Q1–Q4 [THOMAS], Q5 Lead

Status: fertig

## r2 (2026-11-07)
Auftrag: Review M5-plan-r1 (4 BLOCKER, H1–H7) + Entscheidungen Thomas Q1–Q4/BLOCKER 4/make_private + Lead einarbeiten.
- Review gelesen; Verlassen-Wege im Code: NoteService update/moveToFolder/archive, FolderService move/moveFiles/archive, DuplicateService, AttachmentService::move, WorkspaceService moveNotes/delete
- Plan r2: §2.1 Probe-Regel/resetCache, §2.2 Freigaben nur user + Verlassen-Regel requireLeave, §2.3 Q1/Q2/Schlüssel, §2.4 Listener/make_private-Ausnahme, §2.5 Massenschutz/Rückweg/getTeam vor Purge/Tx je Workspace, §2.7 ShareTargetPolicy/users/search, Done M5a–M5d ergänzt, §4/§5/§6, neue §7 Änderungsliste
- OCP geprüft: Share\IManager shareApiEnabled, sharingDisabledForUser, shareWithGroupMembersOnly(ExcludeGroupsList 27), currentUserCanEnumerateTargetUser (23), allowEnumerationFullMatch (21.0.1)
- Offen: O5 (eigene Schülernotiz mit write) Lead-Bestätigung
Status r2: fertig

## r3 (2026-11-07)
Auftrag: Review M5-plan-r2 (2 BLOCKER, H1–H5) einarbeiten, §7 nachtragen.
- Geprüft: UserDataService neutralisiert notes.created_by/updated_by und workspaces.created_by global (M2d) → H2 nur dokumentiert + Test
- Plan r3: §2.1 exists() getrennt von probe(); §2.2 O5 entschieden, requireLeave(LeaveRequest) ersetzt manage bei Team-Quellen, Anhang-Regel; §2.4 created_by; §2.5 Massenschutz (b), nur Schritt 1, --force/README; §2.7 Richtlinie nur neue Empfänger; §2.8 M6-Haken; Done M5b 6, M5c 7/8, M5d 0; §6 O5; §7 Zeilen r2
Status r3: fertig
