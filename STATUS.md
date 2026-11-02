# Status Nextcloud-Port „Next Notes“

**Aktueller Meilenstein:** M2 – Backend-Kern (M2a fertig, weiter M2b); M1 fertig

## Erledigte Slices
- Setup: Branch `nextcloud-port`, Agenten unter `.claude/agents/`, `UPSTREAM.md`
- M0: Inventur (`docs/INVENTORY.md`, `docs/inventory/`), `docs/SPEC.md`, ADR 0001–0016. Review: `docs/reviews/M0-r1.md` (BLOCKER) → `M0-r2.md` (FREIGABE), Restpunkte O1–O4 eingearbeitet.
  Testnachweis: M0 ist reine Dokumentation, kein ausführbarer Test.
- M0-Nachtrag: Entscheidungen Thomas eingearbeitet; neue Tabelle `nextnotes_teams`, UID-Regel, systemverwaltete Abgabe-Container, `visibleAssignmentIds`/`requireAssignment`, `owner_uid`-Regel. Review `M0-nachtrag-r1` (BLOCKER) → `r2` (1 BLOCKER) → Lead-Entscheid nach 2 Runden (Vorschlag speccritic übernommen).

## Offene Blocker
- keine

## Getroffene Entscheidungen
- Name: „Next Notes“, App-ID `nextnotes` (ADR 0001)
- Zielversion: nur Nextcloud 35, PHP 8.3–8.5 (Thomas, 2026-11-02)
- Themes: nur Hell/Dunkel nach Nextcloud-Theme; Zusatz-Themes, CSS- und Font-Upload gestrichen (ADR 0016)
- Leitprinzip: Original ist Vorlage, kein Zwang; schlank, wartbar, Nextcloud-nativ, einheitlich vor 1:1-Treue. Upstream-Bugs fixen statt übernehmen. Technik entscheidet der Lead.
- Frontend: Poznote-Frontend behalten (ADR 0013); gezielte Edits in `src/`, gelistet in SPEC §11.1
- Lead: ADR 0004, 0005, 0006, 0007, 0011, 0012, 0015; Teilentscheidungen in 0003 (Verwaisen nur per Event/Orphan-Check, Einfrieren ohne Teams-App)

## Entscheidungen für Thomas
- keine offen

## Entscheidungen Thomas zu M0 (2026-11-02)
- ADR 0002, 0003, 0010, 0014 angenommen
- ADR 0008 geändert: Entfernen aus dem Team oder Löschen des Kontos löscht keine Abgaben; Team-Löschung löscht Aufgaben+Abgaben nach 30-Tage-Frist (Export möglich); Lehrkraft kann Aufgaben samt Abgaben manuell löschen
- ADR 0009: kein Import aus Poznote
- M0 freigegeben → weiter mit M1

## Entscheidungen Thomas zu M1 (2026-11-02)
- Lizenz der App: `AGPL-3.0-or-later` (`nextnotes/COPYING`); Upstream-MIT-Hinweis bleibt als `nextnotes/LICENCE`
- GitHub-Repo umbenannt: `valueerrorx/poznote-nc` → `valueerrorx/nextnotes` (info.xml angepasst)
- Autor/Copyright: Thomas Weissel

## M1 (fertig)
- Plan `docs/slices/M1-plan.md`; Review `M1-plan-r1` (BLOCKER: frische Instanz) → `M1-plan-r2` (FREIGABE)
- Lead: Teilung M1a (App, Migration, info.xml, SQLite/Apache) / M1b (MariaDB, PostgreSQL, nginx, URL-Spike, CSP-Check); M1 fertig erst nach M1b
- Lead: `app:remove` erhält Daten (SPEC §8); Löschen beim Entfernen ist Datenschutzfrage für Thomas in M7
- M1a erledigt: App-Skelett `nextnotes/`, Migration (11 Tabellen), Testsetup `dev/nextcloud/` (SQLite/Apache), Schematest `tests/nextnotes/schema/`. Review `M1a-diff-r1` (FREIGABE, 4 Hinweise → M1b).
  Testnachweis (tester, frische Instanz, `nextcloud:35` = 35.0.2): `validate-info.sh` 0; `CHECK_PAGE=1 lifecycle.sh sqlite` 0 (enable, 11 Tabellen, 200/303, Probe-Zeile nach disable+remove erhalten); `check-schema.php` 0 gegen §2.1 (Gegenprobe mit 6 Manipulationen → Exit 1); `down.sh all` 0.

- M1b erledigt: Compose-Profile mariadb/pgsql/nginx, `run-m1.sh`, URL-Spike (ADR 0004: bestanden, kein Fallback), CSP-Prüfung per Playwright mit Selbsttest. Review `M1b-diff-r1` (FREIGABE, 5 Hinweise).
  Testnachweis (tester, frische Instanzen, NC 35.0.2): `run-m1.sh` (sqlite, mariadb, pgsql je Apache; nginx/fpm) Exit 0; unabhängig je Umgebung: `app:list` aktiv, `check-schema.php --db=…` 0, Seite 200/303, Spike 4×200 per curl, CSP-Gegenprobe (`tests/nextnotes/csp/csp-gegenprobe.mjs`, 3 provozierte Verletzungen erkannt) 0, Lebenszyklus 0; Fehlerfälle (kaputte Erwartung, fehlendes Chromium) → Exit 1 mit Aufräumen; `down.sh all` 0.

## Entscheidungen Thomas zu M2 (2026-11-02)
- Plan-O5: Standard-Workspace heißt „Notizen“ (lokalisiert), keine Willkommensnotiz
- M2a-diff-r1 Blocker 1: Workspace löschen verschiebt Inhalte nur in einen eigenen, nicht geteilten Workspace (sonst Standard-Workspace anlegen) – nie in geteilte

## M2 (laufend)
- Plan `docs/slices/M2-plan.md` r1: Slices M2a (Gerüst, Rechte, Notizen/Ordner/Workspaces/Tags/Papierkorb, M1-Nacharbeit), M2b (Anhänge, Snapshots, Suche, Sperre, Unified Search), M2c (Tasks, Mentions, Erinnerungen, Settings, Konvertierung), M2d (Kontolöschung, Abschlusslauf 3 DBs)

- Plan-Review `M2-plan-r1` (BLOCKER: Middleware 401/412, UID-Wiederverwendung, Body-IDs, Erinnerungen, Sperre vs. ADR 0005) → `M2-plan-r2` (FREIGABE). Lead: ADR 0005 gilt (409/423, Übernahme nur selbe Sitzung, Upstream-Abweichung B13); Netz einmalig für composer/Fixture-Build, Fixtures eingecheckt.
- M2a erledigt: Nacharbeit M1, Grundgerüst (AccessService, Fehler-Middleware, HtmlSanitizer, InputValidator, Workspace-Schlüssel), 53 Routen (Notizen, Ordner, Workspaces, Tags, Papierkorb), PHPUnit-Setup, Upstream-Fixtures. Review `M2a-diff-r1` (BLOCKER: Workspace-Löschen verschob in geteilte Workspaces → Thomas-Entscheid) → `M2a-diff-r2` (FREIGABE, N1 umgesetzt). SPEC §11.2 B12–B20.
  Lead: fremde IDs in `POST /notes/reorder` (`scope=kanban`, `visible_order`) und `linked_note_id` bei `PATCH /notes/{id}` werden ignoriert (200, kein Leck, A unverändert, wie Upstream) – akzeptiert.
  Testnachweis (tester, frische Instanzen NC 35.0.2): `run-m2.sh sqlite mariadb pgsql` Exit 0, je 110 Tests/2173 Assertions; `mysql.utf8mb4`=true; `check-forbidden-sql.sh` 0 Treffer (Selbsttest ok); Gegenprobe (`requireNote` für `linked_note_id` entfernt → Isolationstest rot, Code zurückgesetzt); `down.sh all` 0.
- Offen für M3: `requesttoken` im Beacon-FormData (B4); Sperr-Banner bei gleichem Konto in zweitem Tab (R11); Escaping von Tags mit `'` im Frontend.

## Nacharbeit (vor/mit M2)
- erledigt in M2a

## Nächster Schritt
M2b – Anhänge, Snapshots, Suche, Sperre, Unified Search (Plan §2 M2b): architekt setzt um → tester + speccritic.
