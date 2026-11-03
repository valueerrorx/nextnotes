# M3-fix4 Tester
Auftrag: editor.mjs hell „Reload Tab 1 behält Sperre“ rot – Ursache klären (Produkt/Test), ggf. fixen, lock-return/lock-nolocks hell nachweisen. Eigene Instanz.
## Erledigt
- Start; Instanz: Scratchpad t4/nextcloud, Projekt nextnotes-t4, Port 8098 (sqlite)
- editor.mjs hell Lauf 1 rc=0, 118 PASS (grün)
- Lauf 2 rc=1: nur B4 (Beacon, 3 FAIL, bekannt flaky), Reload-Check PASS
- Lauf 3 rc=1: 114 ok/4 FAIL (siehe Bericht)
- Diagnose-Spec Scratchpad t4/diag/reload-diag.mjs geschrieben (Reload-Wettlauf t1/t2)
- Ursache gefunden: Web-Lock-Callback kommt erst ~2,2–2,8 s nach Request (um load), 2-s-Timer gewinnt → neue ID bei jedem Laden + verwaister Lock; Reload-Test zusätzlich Timing (reload-Promise vor DCL) und t2-Poll-Wettlauf. Fix bootstrap §10: Timer erst ab load, späte Zusage sofort freigeben
- Fix umgesetzt (bootstrap §10 holdSession: Frist ab load, späte Zusage freigeben, keepLate für Ersatz-ID); editor.mjs Reload-Check angepasst; Diagnose: ID 6/6 gleich (vorher 0/8), lockt 3/3 gleiche ID
- Nach Fix: editor rc=1 (nur B4, 116 ok, Reload-Checks PASS), lock-return rc=0 76, lock-nolocks rc=0 20; B4-Ursache vermutl. Tippen vor Sperrantwort → open() wartet auf isNoteEditLockSettled
- editor.mjs hell 2× nach Testfix: rc=0 119, rc=0 119
- Instanz nextnotes-t4 abgebaut (down.sh rc=0, Netz entfernt); demo/gftest unberührt
## Offen
- SPEC §4.3 Nr. 8 / ADR 0005: „binnen 2 s“ jetzt „2 s ab load“ – Doku-Nachtrag durch Architekt/Lead
- Waisen-Lock pro Seite bei spätem Grant behoben (keepLate nur für Ersatz-ID)
## Status: fertig
