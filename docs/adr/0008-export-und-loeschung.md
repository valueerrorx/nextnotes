# ADR 0008: Export und Löschung privater Daten und Schülerdaten (Auftragsfrage 7)

Status: vorgeschlagen · [THOMAS] (Datenschutz)

## Kontext
DSGVO Art. 15/17/20: Benutzer (auch Schüler) müssen ihre Daten erhalten und löschen lassen können. Lehrkräfte verarbeiten Abgaben anderer. Inventur widerspricht sich bei Export: A #196–198 „übernehmen“, B #79–81 „streichen (Backup)“. Geprüft in `.ref`: `OCP\User\Events\UserDeletedEvent`, `BeforeUserDeletedEvent` (@since 18), `OCP\UserMigration\IMigrator` (@since 24), `IRegistrationContext::registerUserMigrator` (@since 24), `OCP\Group\Events\GroupDeletedEvent` (@since 18), `AppFramework\Http\ZipResponse` (@since 15).

## Optionen
1. Nur NC-Mechanismen: `IMigrator` (wirkt nur mit installierter App „user_migration“) + Löschung per Event.
2. **Eigener Export (Upstream-Formate) + `IMigrator` als Zusatz + Löschung per Event + `occ`-Befehle für Admins.**
3. Nur Admin-Werkzeuge.

## Empfehlung
Option 2:
- **Export für Benutzer**: Upstream-Export „alle Notizen als ZIP“ und „strukturiert“ bleibt (A #196–198 übernehmen; B #79–81 und B 2.1 #21–23 korrigiert auf „übernehmen“/„ersetzen“). Aufruf aus den persönlichen Einstellungen. Umfang: eigene private Workspaces, eigene Abgaben (inkl. Feedback), Anhänge.
- **`IMigrator`** (`NextNotesMigrator`): gleiche Daten im user_migration-Export/-Import.
- **Lehrkraft**: ZIP aller Abgaben einer Aufgabe (SPEC §9). Kein Export fremder privater Daten.
- **Löschung Benutzerkonto** (`UserDeletedEvent`, Arbeit in `QueuedJob`): private Workspaces samt Notizen, Ordnern, Tags, Anhängen (IAppData), Snapshots, Erinnerungen, Sperren, Erwähnungsstatus, Freigaben an ihn; eigene Abgaben **werden gelöscht** [THOMAS]; Aufgaben, die er als Lehrkraft erstellt hat, bleiben beim Team (`created_by` bleibt als Text, Anzeige „gelöschter Benutzer“). In Team-Workspaces bleiben von ihm verfasste Notizen erhalten; `created_by`/`updated_by` werden auf `NULL` gesetzt [THOMAS].
- **Austritt aus Team**: Abgabe bleibt (Pflichtfall); Schüler behält sofort nur Lesezugriff auf die eigene Abgabe, kein Bearbeiten und kein Abgeben (entschieden, Lead 2026-11-02).
- **Aufbewahrung**: Lehrkraft kann Aufgabe löschen → alle Abgaben + Kopien gelöscht. Keine automatische Frist in v1 [THOMAS: z. B. Löschung 1 Jahr nach Frist].
- **Admin**: `occ nextnotes:export-user <uid> <ziel.zip>`, `occ nextnotes:delete-user-data <uid>` (für Auskunft/Löschung ohne Kontolöschung).
- Kein eigenes Aktivitätsprotokoll in v1 (T20, B #41): weniger personenbezogene Daten. NC-Activity optional später.

## Konsequenzen
- Upstream-Exportcode wird als Service nachgebaut (Formate gleich, damit Import aus/zu Upstream möglich, ADR 0009).
- Testfälle: Kontolöschung entfernt alle Zeilen mit `uid` außer bewusst behaltenen (Liste in SPEC §8).
