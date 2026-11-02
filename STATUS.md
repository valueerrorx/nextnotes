# Status Nextcloud-Port „Next Notes“

**Aktueller Meilenstein:** M2 – Backend-Kern (noch nicht begonnen); M1 fertig

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

## Nacharbeit (vor/mit M2)
- Favicon 404: `nextnotes/img/app.svg`/`app-dark.svg` beginnen mit SPDX-Kommentar → theming erkennt kein SVG; Kommentar ins `<svg>` oder `<?xml …?>` voranstellen (tester M1b)
- `nginx:stable-alpine` auf Versionstag pinnen, getestete Versionen notieren (M1b-diff-r1 H1)
- `down.sh:14` `mapfile < <(…)` Fehler wird nicht erkannt (H2); `KEEP=1`-Kommentar in `run-m1.sh`/README korrigieren (H3)

## Nächster Schritt
M2 – Backend-Kern (SPEC §13 M2): architekt plant Slice(s) inkl. Nacharbeit oben → speccritic → Umsetzung.
