# M9-plan – Architekt
Status: fertig (r3)

## Auftrag
ADR (vorgeschlagen) + Plan `docs/slices/M9-plan.md` für OneNote-Import über Microsoft Graph (delegiert, PKCE, Hintergrundjob). Nichts implementieren; MS-Learn nur lesend.

## Erledigt
- Bestand gesichtet: ADR 0019 existiert schon (weiche Seitenwechsel) → neue ADR wird 0020.
- Bestand: ImportService (nur Drop md/html/txt/json, kein ZIP, ADR 0009), AttachmentService::createFromString, EmbeddedImageService (src `/api/v1/notes/{n}/attachments/{a}`), HtmlSanitizer::loadHtmlQuiet, checklist-Markup (`ul.checklist>li.checklist-item>input.checklist-checkbox+span.checklist-text`), Testsetup `dev/nextcloud`.
- OCP geprüft (.ref 35.0.2): IClientService::newClient (8.1), IClient get/post (8.1), ICrypto encrypt/decrypt (8), IAppConfig::setValueString sensitive (29+), QueuedJob/TimedJob (15), IJobList::scheduleAfter (28). Client: Local-Address-Schutz + on_redirect-Prüfung (allow_local_remote_servers).
- MS-Learn als Markdown im Scratchpad (`.../scratchpad/ms/`), belegt: Limits OneNote 120/min, 400/h, 5 parallel je App+Benutzer, kein Retry-After; App-only Ende 31.03.2025; Notes.Read/Notes.Read.All/Team.ReadBasic.All delegiert ohne Admin-Pflicht; GroupMember.Read.All Admin-Pflicht; client_secret Pflicht für Web-Apps, PKCE empfohlen für alle; AT 60–90 min, RT 90 Tage (Web), SPA 24 h; Seiten $top max 100, default 20; pagelevel; $expand; Fehler 10008/20266; Output-HTML (absolute divs, img data-fullres-src, object, iframe, data-tag to-do[:completed]); includeInkML in Learn nicht dokumentiert.
- OneNoteMdExporter README: GPL-3.0, Win10+, OneNote 2013+ Desktop, Word 2013+, Ink geht verloren, Ausgabe md + Ressourcenordner.

- ADR `docs/adr/0020-onenote-import.md` geschrieben (vorgeschlagen), inkl. Nachtrag Lead: Authority `<tenant>|organizations|common`, MSA optional; Quellen publisher-verification-overview, configure-user-consent, supported-accounts-validation, howto-convert-app-to-be-multi-tenant.
- Plan `docs/slices/M9-plan.md` r1 geschrieben (Befund, Fakten F1–F15 mit Quellen, Architektur, Datenmodell 2 Tabellen, Ablauf, Abbildung, Sicherheit, Datenschutz, Q1–Q4 [THOMAS], Slices M9a–M9f mit Done/Testnachweis, Notweg, Admin-Anleitung-Entwurf, Risiken).
## Nächster Schritt
- keiner (Bericht an Lead).

## Offen/Annahmen
- ADR-Nummer 0020 statt 0019 (Kollision).
- „vorhandener ZIP/MD-Import“ existiert nicht als ZIP (ADR 0009); Drop-Import ohne relative Bilder → Lead melden.
- SPEC.md/admin.md werden parallel geändert → nicht anfassen, Entwurf im Plan.

## r2 (Review docs/reviews/M9-plan-r1.md, 9 Blocker + Hinweise)
- Gelesen: Review, Plan r1, ADR r1. Bestand: UserDataService::UID_COLUMNS („Daten vorhanden“), check-purge.sh (fest 13), SPEC §8 (13 Tabellen), expected-m1.json, upgrade-path.sh.
- OCP geprüft: IAppManager::isEnabledForUser, IUser::isEnabled, IUserManager::get, ITempManager::getTemporaryFile (8.0, nicht deprecated).
- MS Learn: id-token-claims-reference (sub ohne profile-Scope, tid; MSA-tid 9188040d-…), manage-application-permissions (Admin-Widerruf Entra).
- Entscheidung B1: Seite atomar (kein Staging), gedeckelt je Seite; Begründung im Plan §4.
- Plan r2 geschrieben (Änderungsvermerk oben, B1–B9 + Hinweise; F16/F17 neu; Reihenfolge M9a→M9c→M9b→M9d→M9e, M9b gesperrt bis Q1).
- ADR 0020 r2 geschrieben (Scopes mit openid, common ohne Teams, Lease/bedingte Writes, Seite atomar, Budget je uid, Auskunft mit Bericht).
- Nachkorrektur: start-Abgleich gegen tree-Hashes gestrichen (zusätzliche Spalte unnötig), stattdessen ID-Prüfung + max. 200 Einträge.
- Nächster Schritt: keiner (Bericht an Lead).

## r3 (Lead-Entscheidungen nach Review r2, docs/reviews/M9-plan-r2.md)
- Auftrag: 3 Blocker + 8 Hinweise des Lead in Plan und ADR einarbeiten, Status r3, danach Lead-freigegeben. Nur Plan + ADR.
- Gelesen: Review r2, Plan r2, ADR r2.
- OCP/Server geprüft: JobList::getNext reserviert 12 h (Zeile 179).
- Plan r3 erledigt: Kopf + Vermerk, §2 Job (keine Netz-I/O in Tx, Autocommit-Lease, explizite uid), §3 (team_scopes, Nachzählen, Abbrechen, Aufräumen bei aus), §4.3/4.4/4.5/4.6/4.7/4.8, Done M9a 5a/6a/14, M9b 1a/3a/4/4b/5a/9/11a/11b/12/12a, M9d 9, §11.7, M9e user.md, R8, R10.
- ADR 0020 r3 erledigt (Status, Konfig-Wechsel, Hintergrund, Budget, Konsequenz 12 h).
- Annahme: Workspace/Notiz/Anhang-Services haben uid-Parameter; sonst ergänzt M9b sie (kein Scope-Zuwachs außerhalb lib/OneNote gemeldet).
- Nächster Schritt: keiner (Bericht an Lead).
