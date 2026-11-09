# M9e (Doku-Teil) – Architekt
Status: fertig
Auftrag: OneNote-Import dokumentieren (admin.md, user.md, README, SPEC/Plan-Konsistenz, ADR 0020 Status, Abnahme-Checkliste, CHANGELOG). Keine Instanz, nur check-upstream/validate/Links.

## Erledigt
- Gelesen: Plan M9 r3 (§1, §4–§11), ADR 0020 + Nachträge, SPEC §1.4/§2.1/§4.2/§4.8/§4.9/§5/§6/§8/§14, admin.md, user.md, README, CHANGELOG, STATUS (Q1–Q4 entschieden: Q3 = kein Notweg)
- Belege: Redirect http://localhost erlaubt, Port bei localhost ignoriert (reply-url.md); Benutzer-Widerruf Org-Konto https://myapps.microsoft.com (howto-convert-app-to-be-multi-tenant.md, application-consent-experience.md); private Konten: keine URL belegt; Admin-Zustimmung → Benutzer können nicht einzeln widerrufen (howto-convert)
- Callback-Route `/apps/nextnotes/onenote/callback` (routes.php), URL aus linkToRouteAbsolute; Demo Apache-Image mit Pretty-URLs → erwartet http://localhost:8090/apps/nextnotes/onenote/callback (Admin-Karte maßgeblich)

- admin.md: §5 Job-Zeile, §7 Verweis, §9 export-user, §10 Verweis+Ausnahme, neu §13 (13.1–13.9)
- user.md: neuer Abschnitt „Aus OneNote importieren“ (Ablauf, Ergebnis, Dauer, Abbrechen/Fortsetzen, Abbildungstabelle, Widerruf), Schlusssatz ergänzt; kein Notweg (Q3 Thomas)
- README: Feature-Punkt + Link admin.md#13, Cron „required for OneNote“, Doku-Liste
- CHANGELOG [Unreleased]: M9d-Eintrag geschärft + Doku-Eintrag; 0.9.1/0.9.2 bleiben historisch
- docs/app/onenote-acceptance.md neu (Redirect http://localhost:8090/apps/nextnotes/onenote/callback, Image hat apache-pretty-urls.config.php RewriteBase /; Demo ohne Cron → cron.php-Schleife für Thomas)
- Plan: Kopf (Q1–Q4 entschieden), §8 Entscheidungen, §9 Mock PHP/Apache, M9b entsperrt, §10 Notweg entfällt, §7 Widerruf geprüft, M9e Abnahme Demo
- ADR 0020: Status entschieden, Route-Name korrigiert, Notweg entfällt, Q1 entschieden, Nachtrag M9e; ADR 0008 Nachtrag M9e
- SPEC: §1.4 Admin-Abschnitt +OneNote, §2.1 beide Tabellen (aus Migration), §4.8 Titel-Hinweis 200, §6 Objekttyp onenote_import, §8 veralteten Satz „Fristen und Job folgen in M9b“ ersetzt, §14 Zeile M9; §4.2/§4.9/§5 geprüft, stimmig (Ratenlimits 10/5 im Controller bestätigt)
- Plan §5: „Unbenannte Notiz“, Workspace-Kollision „Name OneNote“
- admin.md 13.5: Job-Budget 120 s je Lauf geteilt → Hinweis ab ~3 gleichzeitigen Importen langsamer (runDue gelesen)
- `make -C nextnotes check-upstream` rc 0; `dev/nextcloud/validate-info.sh` rc 0 (CHANGELOG 0.9.2 = info.xml); Link-Check (Skript im Scratchpad) 14 lokale Links/Anker, 0 Fehler; AADSTS50011 in reply-url.md belegt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen
- Benutzer-Widerruf privates Konto ohne URL (nicht in Learn-Quellen belegt)
- Demo hat keinen Cron → Checkliste gibt Thomas eine cron.php-Schleife
- Plan M9e Done 3 (run-m5) und 4 (Abnahmeprotokoll) offen: Matrix mit M7g, Abnahme Thomas
- 12-h-Neustart: `background-job:execute --force-execute` setzt reserved_at=0 (NC-35-Image gelesen)
