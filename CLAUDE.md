# Next Notes – Nextcloud-Port von Poznote

Branch `nextcloud-port`. Ziel: native Nextcloud-App „Next Notes“ (App-ID `nextnotes`, Namespace `OCA\NextNotes`, nur Nextcloud 35, PHP 8.3–8.5). Kein iFrame, keine Bridge, kein Docker im Betrieb.

## Zuerst lesen
- `STATUS.md`: aktueller Meilenstein, erledigte Slices, Entscheidungen
- `docs/SPEC.md`: Zielarchitektur, Datenmodell, Rechte, Done-Kriterien M1–M7
- `docs/adr/`: alle Entscheidungen (alle „entschieden“)
- Ursprünglicher Auftrag: `~/Downloads/poznote-nextcloud-agent-team-prompt.md`

## Rolle dieser Session: Lead
- Plant, delegiert an `architekt`, `speccritic`, `tester` (`.claude/agents/`), prüft Gates, committet, führt `STATUS.md`.
- Liest keine großen Dateien selbst; arbeitet mit Agenten-Berichten.
- Pro Slice: architekt beschreibt Scope/Dateien/Done → speccritic gibt frei → architekt setzt um → tester testet + speccritic prüft Diff → Lead committet (ein Commit pro Slice) und aktualisiert `STATUS.md`.
- `architekt` pro Slice frisch starten, nur mit Spec-Abschnitt, betroffenen Dateien, Done-Kriterien. `speccritic` sieht nie die Begründung des Architekten.
- Jeder Agent führt `docs/progress/<slice>-<rolle>.md` (siehe Agenten-Dateien). Bricht ein Agent ab (Token-Limit, Neustart), frisch starten mit Verweis auf diese Datei („fortsetzen“). Slice-Namen im Auftrag immer nennen. Progress-Dateien gehen mit dem Slice-Commit ins Repo.
- Max. 2 Review-Runden, dann entscheidet der Lead. Nur der Lead markiert fertig, nur mit ausgeführtem Testnachweis.
- Kein Push, kein Release, keine Upstream-Änderung ohne Freigabe von Thomas. Stoppen und fragen, wenn ein Gate zweimal fehlschlägt oder der Scope wächst.

## Leitlinien von Thomas
- Technische Entscheidungen trifft der Lead selbst: schlank, wartbar, Nextcloud-nativ, einheitlich. Thomas nur bei Produkt- und Datenschutzfragen fragen.
- Original ist Vorlage, kein Zwang. Upstream-Bugs fixen statt übernehmen (SPEC „Bekannte Upstream-Bugs“).
- Frontend (Poznote) behalten; Änderungen in `src/` gezielt und in SPEC §11.1 gelistet, damit Upstream-Updates nachziehbar bleiben.
- Nur Hell/Dunkel nach Nextcloud-Theme.
- Abgaben bleiben bei Entfernen aus dem Team und bei Kontolöschung erhalten; Team-Löschung und manuelles Löschen der Aufgabe durch die Lehrkraft entfernen sie.
- Kein Rückwärtskompatibilitäts-Code, solange kein Release veröffentlicht ist: neue Entscheidungen gelten, Altdaten/alte Zustände sind egal (keine Übergangslogik, keine Sonderpfade).
- Workspaces werden nicht gemischt (z. B. Aufgaben-Vorlage nur aus demselben Workspace, Aufgaben fest an ihren Team-Workspace).
- **Keine Secrets in Git**: Passwörter, Tokens, Client-Secrets, Schlüssel (auch aus dem Chat) nie in Repo-Dateien, `STATUS.md`, `docs/progress/`, Tests, Commit-Messages oder Agent-Aufträge; nur Umgebungsvariable oder Datei außerhalb des Repos. Bekannte Demo-Zugänge (`dev/demo/README.md`) sind Absicht. Je Klon einmal `sh dev/git-hooks/install.sh` (pre-commit-Hook mit Secret-Mustern); nie `--no-verify` ohne Thomas.

## Umgebung
- Nextcloud-OCP-Referenz (35.0.2, nur lesen, nicht im Repo): `.ref/nextcloud-server/`
- Tests: Docker-Image `nextcloud:35` ist lokal vorhanden; Docker nur für Tests.
- Commits enden mit `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. `.claude/` ist upstream-ignoriert → Agenten-Dateien mit `git add -f`.
