# Status Nextcloud-Port „Next Notes“

**Aktueller Meilenstein:** M1 – Installierbares Skelett (noch nicht begonnen)

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

## Nächster Schritt
M1 laut SPEC §13: architekt beschreibt Scope (appinfo/info.xml, Application.php, Routen, Navigation, Migration, leere Seite, Docker-Testsetup in `dev/nextcloud/` mit `nextcloud:35`), speccritic gibt frei, dann umsetzen und testen.
