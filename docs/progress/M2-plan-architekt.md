# M2-plan – Architekt
Status: fertig
Auftrag: Plan `docs/slices/M2-plan.md` (Slices, Done-Kriterien, Testsetup, Risiken). Nichts implementieren.

## Erledigt
- SPEC §2–§14, Inventur A, ADRs 0002/0003/0005/0008/0010/0011/0014 gelesen
- Bestehenden Code + Testsetup gelesen (nextnotes/, dev/nextcloud/, tests/nextnotes/), M1b-Review-Hinweise
- Befunde: Image ohne server-tests/phpunit/composer → eigener Bootstrap (base.php, PHPUNIT_RUN); HTTP-Tests mit Basic/App-Token + `OCS-APIRequest` bestehen CSRF (Request.php:436), CSRF-Fehler = 412; `ISearch::search` deprecated 35 → `filteredSearch`; Upstream-Sperrkonflikt = 423, gleiches Konto erhält Sperre aus jeder Sitzung; Workspace-Namen-Regex ohne Klammern; whats-new ruft GitHub
- Upstream-Isolationssuite `tests/integration/isolation.test.php` als Vorlage für Krit. 2
- Upstream-Sanitizer `src/lib/html-sanitize.php` → M2a; Konvertierung = eigene Upstream-Parser → offener Punkt O2
- Image-Versionen: nginx 1.30.6, MariaDB 11.4.13, PostgreSQL 17.11; `notifications` im Image
- Plan geschrieben: `docs/slices/M2-plan.md` (r1); Teilung M2a 53 / M2b 23 / M2c 36 Routen / M2d Kontolöschung

## Nächster Schritt
- Review durch speccritic (Lead)

## Offene Punkte
- O1–O14 im Plan §5; [THOMAS] nur O5 (Standard-Workspace)

## r2 (Review M2-plan-r1 BLOCKER, Lead-Entscheidungen)
Status r2: fertig
- Review gelesen; geprüft: `UserDeletedEvent::getUid` @since 31, `insertIgnoreConflict` @since 16, `AlreadyProcessedException` @since 17, `mysql.utf8mb4` in ConnectionFactory
- Nächster Schritt: Plan r2 schreiben, dann SPEC (§1.2.5, §4.1, §4.2, §5, §8, §11.2 B12/B13, §13 M2.1/M2.8, §14)
- Plan r2 geschrieben (alle Blocker/Hinweise, §5 Entscheidungstabelle)
- SPEC geändert: §1.2.5, §2.1 (Standard-Workspace), §4.1, §4.2, §4.4, §5 (ReminderJob, UserDataDeletionJob), §6, §8, §11.2 B12/B13, §13 M2.1/M2.8, §14; ADR 0005 unverändert
- Nächster Schritt: Review r2 durch speccritic (Lead)
