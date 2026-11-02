VERDICT: FREIGABE

Prüfgegenstand: docs/slices/M1-plan.md (r2) gegen die Befunde aus docs/reviews/M1-plan-r1.md. Die Lizenzwahl selbst ist von Thomas entschieden und wird hier nicht bewertet.

Erledigt
- B1 (frische Instanz): `lifecycle.sh` beginnt mit `down.sh` → `up.sh` (`down -v`). Vor `app:enable` bricht es ab, sobald `db:schema:export` eine `oc_nextnotes_*`-Tabelle findet. Der `hasTable`-Guard ist entfernt (Plan Z. 14, 33, 51). Erledigt.
- H1: Neues Skript `check-page.sh` in M1a, Aufruf per `CHECK_PAGE=1`-Hook (Z. 34, 100). Erledigt.
- H2: Die [THOMAS]-Frage ist gestrichen, der Plan verweist auf SPEC §8. `probe-row count` = 1 nach `app:remove` ist ergänzt (Z. 56, 95). Erledigt.
- H3: Die Normalisierungsregeln für die Erwartungsdatei sind festgehalten (Z. 47). Erledigt.
- H4: Der Login-Redirect wird per Regex `/login\?redirect_url=` geprüft (Z. 34, 54). Erledigt.
- H5: Die Fundstelle für ExportSchema ist korrigiert (`register_command.php` Z. 46/192, Image-Pfad) (Z. 53). Erledigt.
- H6: `web-nginx` nutzt dasselbe benannte Volume wie `nc-fpm`, read-only (Z. 68). Erledigt.

Neue Prüfung
- Die Lizenzdateien passen zur Doku: `COPYING` (AGPL) und `nextnotes/LICENCE` (Upstream-MIT) entsprechen SPEC §1.1 Z. 43, SPEC §13 M7.3 („LICENCE (MIT) enthalten“) und ADR 0001 („LICENCE und Copyright-Hinweise bleiben“). Die Root-`/LICENCE` bleibt unverändert (ADR 0007). Kein Widerspruch.
- Der Scope wächst nicht. `COPYING` und `LICENCE` stehen in SPEC §1.1 und werden nur vorgezogen.

Hinweise (nicht blockierend)
H1. Plan Z. 101 sagt, `check-page.sh` laufe in M1b auch auf nginx. Laut `run-m1.sh` (Z. 37) wird nginx aber nur mit `spike-urls.sh` geprüft. Entweder `check-page.sh nginx` in `run-m1.sh` aufnehmen oder den Satz streichen.
H2. Die Lizenzentscheidung (AGPL-3.0-or-later für die App, MIT für übernommene Teile) steht bisher nur im Slice-Plan. Beim Commit in STATUS.md oder SPEC §1.1 festhalten, damit M7.3 einen Bezug hat.
H3. Der SPDX-Kopf gilt laut Plan für „jede neue Datei“. `COPYING` und `LICENCE` (reine Lizenztexte) davon ausdrücklich ausnehmen.
