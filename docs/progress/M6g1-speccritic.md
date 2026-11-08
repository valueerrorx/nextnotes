# M6g1 speccritic
Auftrag: Diff-Review R1 M6g1 (Aufgaben-UI Lehrkraft, ZIP, Speed-Dial-Entfernung, Icon-Leiste). Ergebnis docs/reviews/M6g1-r1.md
Erledigt: git diff HEAD (lib, appinfo, build, templates, js-src, docs) gelesen; neue Dateien AssignmentExportService, AssignmentsViewService, assignments.php, nextnotes-assignments.js (Kernteile), AssignmentExportTest gelesen; AccessService/TeamService/ExportArchive/ExportFormatter gegengeprüft. Kein Docker.
Befund: 1 Blocker (members() ungecacht in pending()/teacherTeams), 3 Hinweise.
Status: fertig
