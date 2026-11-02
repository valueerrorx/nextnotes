VERDICT: BLOCKER

Review M2-plan r1 (speccritic, 2026-11-02). Geprüft: `docs/slices/M2-plan.md` gegen SPEC §2–§8, §11.2, §13 M2, §14, ADR 0002/0005/0014, Inventur A §1.1, OCP 35.0.2. Routenzählung stimmt (M2a 53 + M2b 23 + M2c 36 = 112 = alle Zeilen #1–184 ohne „streichen“).

## Blocker
1. **Fehler-Middleware verschluckt NC-Sicherheitsausnahmen** (Plan §1 „Fehlerformat“). App-Middleware wird nach der Kern-Middleware registriert (`DIContainer.php:201-241`), `afterException` läuft rückwärts (`MiddlewareDispatcher.php:89-98`) → `ApiExceptionMiddleware` sieht CSRF- (412), NotLoggedIn- und andere `SecurityException`s zuerst. „Unbekannte Ausnahmen → 500“ macht daraus 500. Forderung: nur eigene `Service\Exception\*` (ggf. `DoesNotExistException`) abbilden, alles andere unverändert weiterwerfen; Done-Test: ohne Login → 401, ohne Token → 412.
2. **Kontolöschung: Rechte-UIDs bleiben bis zum Cron-Lauf stehen** (Plan M2d, SPEC §2 UID-Regel, §5 „keine Logik darf von pünktlichem Cron abhängen“). Zwischen `UserDeletedEvent` und `UserDataDeletionJob` (Cron evtl. AJAX/Stunden) erhält ein neu angelegtes Konto mit gleicher UID Zugriff auf alte private Workspaces (`owner_id`) und fremde Freigaben (`ws_shares.share_with`). Forderung: rechtetragende Spalten synchron im Listener neutralisieren (mind. `ws_shares` an/von UID löschen, eigene Workspaces vom UID lösen, z. B. eigener `owner_type`/Tombstone), Inhalt weiter im Job; Done M2d 2 ergänzen: „C neu anlegen **vor** Joblauf → `GET /workspaces` ohne alte Workspaces, A's Workspace 404“.
3. **Isolation prüft Ziel-IDs im Body nicht systematisch** (Plan §3.3, Done M2a 3/M2b 1). Je Route nur eine OWNED-Variante. Für Routen mit Quelle + Ziel muss je ID-Parameter eine Variante laufen, in der nur dieser A gehört und die übrigen B: z. B. `POST /notes {workspace:<A>, folder_id:<A>}`, `POST /notes/{B}/folder {folder_id:<A>}`, `PATCH /notes/{B}` mit `folder_id`/`linked_note_id` von A, `POST /folders {parent_id:<A>}`, `/folders/{B}/move` nach A, `/folders/move-files`, `/notes/reorder`, `/folders/reorder`, `/folders/restore`, Anhang-`move` von B nach A. Erwartung jeweils 403/404 und A-Zustand unverändert. Forderung: Parameterkarte deklariert Body-ID-Felder; Generator erzeugt die Varianten; fehlende Deklaration → rot.
4. **Erinnerungen als indirekter Lesepfad ohne Rechteprüfung** (Plan M2c `ReminderJob`/`Notifier`; SPEC §3 „jeder Lesepfad über AccessService“). Nach Entzug der Freigabe erhält B weiter Benachrichtigungen mit Titel/Link von A's Notiz (`prepare` läuft bei jeder Anzeige). Forderung: `ReminderJob` und `Notifier::prepare` prüfen Leserecht des Empfängers (sonst nicht senden bzw. `UnknownNotificationException`/`markProcessed`); Freigabe-Entzug löscht Erinnerungen des Empfängers in diesem Workspace. Done M2c 3 um diesen Fall ergänzen.
5. **Widerspruch Plan ↔ ADR 0005** (Plan M2b `LockService`, O9). ADR 0005: Konflikt 409, Übernahme nur bei `holder_session = ?`. Plan: 423 und „gleiches Konto aus jeder Sitzung“ plus Takeover (Upstream). Forderung: ADR 0005 und SPEC §13 M2.6 im selben Zug anpassen (423, kontobasiert, Takeover-Regel) oder Plan an ADR angleichen.

## Nötige SPEC-Änderungen vor M2a (Lead, keine Thomas-Fragen)
- O1 §13 M2.1 auf Router-Zeilen `api/v1/index.php` präzisieren – sachlich richtig (Kompat./Seiten/Kalender haben eigene Kriterien M3.6/M4.4).
- §4.1 nennt `{name}` `[^/]+`, Plan `.+` → §4.1 anpassen. O2 → §1.2.5, O3 → §11.2 B12, O8 → §4.2, §14 (`filteredSearch` 35, `UserDeletedEvent::getUid` ist @since 31, nicht 18).
- O5 ist korrekt als [THOMAS] markiert (einzige Produktfrage). O6, O7, O10–O14 SPEC-konform; O14 (Netz für Upstream-Build) entscheidet der Lead; Fallback `openapi.yaml` erfüllt Krit. 3 nur abgeschwächt.

## Hinweise
- Workspace-Schlüssel: zwei Freigeber mit gleichem Anzeigenamen und gleichem Workspace-Namen → dauerhaft 409, Workspace für B unerreichbar. Deterministischen Fallback festlegen (z. B. Zusatz mit UID/ID).
- `ensureDefault` und „Archives“: paralleles Anlegen ohne Unique-Index → doppelte Workspaces; Wettlauf absichern (erneut lesen nach Insert oder Sperre).
- `IDBConnection::insertIgnoreConflict` (16) für Tags/Mentions statt Unique-Fang; in PostgreSQL transaktionssicher.
- `NoteService::purge` innerhalb äußerer Transaktion (UserDataService): „Datei nach Commit“ braucht eine Sammelliste, die erst nach dem äußersten Commit abgearbeitet wird.
- XSS: neben Inhalt auch `color`/`icon_color`/`title_color`/`icon`, Ordner-/Workspace-/Tag-Namen serverseitig validieren (Format/Whitelist wie Upstream).
- CSRF/Attribute systematisch: Reflection-Test über alle Controller-Methoden (`NoAdminRequired` überall, `NoCSRFRequired` nur Allowlist).
- Krit. 8: festlegen, ob TEXT-Spalten (`content`, `search_text`) mitgeprüft werden (Erwähnungen von C im Inhalt von A sind Nutzerinhalt); Testszenario eindeutig machen.
- `recently_opened_notes` (IUserConfig, `markOpened`) enthält Notiz-IDs; beim Ausliefern über AccessService filtern.
- MariaDB: NC legt Tabellen mit `utf8mb4_bin` an (`ConnectionFactory.php:235`) → Groß/Klein wie SQLite/PG; Setup muss `mysql.utf8mb4` true haben (im Test prüfen).
