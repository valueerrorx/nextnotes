# Status Nextcloud-Port „Next Notes“

**Aktueller Meilenstein:** M0 abgeschlossen – wartet auf Freigabe durch Thomas

## Erledigte Slices
- Setup: Branch `nextcloud-port`, Agenten unter `.claude/agents/`, `UPSTREAM.md`
- M0: Inventur (`docs/INVENTORY.md`, `docs/inventory/`), `docs/SPEC.md`, ADR 0001–0016. Review: `docs/reviews/M0-r1.md` (BLOCKER) → `M0-r2.md` (FREIGABE), Restpunkte O1–O4 eingearbeitet.
  Testnachweis: M0 ist reine Dokumentation, kein ausführbarer Test.

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
1. ADR 0002: Notizen in der Nextcloud-DB, nicht als Dateien in Nextcloud Files (kein Desktop-Sync; Export als Ersatz)
2. ADR 0003: Gelöschtes Team → Workspace verwaist, Ersteller kann binnen 30 Tagen übernehmen, danach Löschung
3. ADR 0008: Konto gelöscht → eigene Abgaben gelöscht; Team-Notizen bleiben (Autor anonymisiert); ausgetretene Schüler behalten Lesezugriff; keine automatische Löschfrist für Abgaben in v1
4. ADR 0009: Import aus Poznote als ZIP-Import, optional bis M7
5. ADR 0010: AI-Chat und Transkription in v1 streichen; später Transkription über Nextcloud TaskProcessing
6. ADR 0014: Workspace-Teilen in v1 über Team-Eigentum + einzelne Benutzer; Gruppen später
