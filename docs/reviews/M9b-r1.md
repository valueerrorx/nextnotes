# M9b Diff-Review r1 (speccritic)

VERDICT: FREIGABE

Grundlage: `docs/slices/M9-plan.md` r3 (§2 Job, §3, §4.3–4.8, §6, §9 M9b), ADR 0020 inkl. Nachtrag M9b, STATUS Q1/Q2 (Thomas 2026-11-08). Geprüft: `OneNoteImportService`, `ImportRun`, `OneNoteImportJob`, Mapper (`findDue`/`claim`/`findStale`, Items), `ConnectService`/`GraphClient`-Diff, `createImported` in Note-/Workspace-/FolderService, Controller/Routen, Notifier, Template/JS, Tests (Abdeckung der Done-Punkte, nicht ausgeführt). Arbeitsstand des Architekten nicht gelesen (Rollenregel).

Keine Blocker. Befund zu den Schwerpunkten:
- Job/Nebenläufigkeit: Graph-/Token-Anfragen nur außerhalb von `Transaction::run`; `inTx` beginnt mit bedingtem Lease-UPDATE; `save`/`release`/`backoff`/`finish`/`fail`/`toReauth`/Refresh/`reserve`/`extendLease` alle an `id`+`uid`+`lease_token`+`status=running` gebunden. `claim` ist ein bedingtes UPDATE (Zeilenzahl), DB-neutral. `cancel`/Kontolöschung leeren bzw. löschen die Zeile → nächstes `extendLease`/`reserve`/Lease-Check liefert 0 → Ende ohne Schreibwirkung. SIGKILL-Pfad: abgelaufene Lease bei `running` wieder claimbar, Test vorhanden.
- Rechte: Ziel nur `owner_type=user`, `owner_id=uid`, `kind=normal`; Prüfung in der Seiten-Transaktion; Ordner nur im Ziel-Workspace und nicht im Papierkorb. `createImported` nur vom Job mit der uid der Zeile aufgerufen, kein Controller-Pfad. tree/start nur `findOwn` + Status `select`, fremde ID → 404 (Test). Positivliste ≤ 200, `group:` nur mit wirksamen Team-Scopes.
- Kontozustände, Konfigurationswechsel, Team-Schalter, 403/404/429/5xx, 404-Serie, Backoff-Reset nach angelegter Seite bzw. Strukturschritt: wie Plan.
- Idempotenz: Item in derselben Transaktion, `replaceTarget` bedingt, Papierkorb = vorhanden, purgiert = neu (Test).
- Datenschutz: Log nur Klasse/Code/Import-ID/request-id; Benachrichtigung nur Zähler und Code; Bericht nur über `findOwn`.
- UI: kein `innerHTML`, Graph-Namen über `textContent`; keine CSP-Ausnahme.
- 0.9.2: nötig, damit `<background-jobs>` bei bestehenden Installationen registriert wird; in Ordnung.

## Hinweise (nicht blockierend)
1. `OneNoteImportService::pageStep`/`structureStep`: `ownWorkspace()` liest vor `lockWorkspace()`. Sperre zuerst, dann Besitz/`kind` prüfen, sonst kann eine gleichzeitige Umwandlung in einen Team-Workspace (Millisekunden-Fenster) eine Seite noch hineinschreiben. Gleiches für `targetWorkspace` (find vor Sperre).
2. `OneNoteImportMapper::claim`: ohne Bedingung `next_run_at <= now`. Ein zweiter Prozess mit älterem `findDue`-Ergebnis kann nach `release`/`backoff` des ersten sofort neu claimen und den Backoff einmal überspringen (eine Zusatzanfrage nach 429). Bedingung ergänzen.
3. `GraphClient::validId` lässt `.`/`..` zu, `rawurlencode` kodiert Punkte nicht → Pfad `…/notebooks/..` aus der Auswahl. Nur eigener Token, nur GET auf Graph, daher gering; reine Punkt-IDs ablehnen.
4. Aufräumen `select` nach 24 h misst `updated_at`; ein Token-Refresh in `tree` setzt `updated_at` neu, Token können so länger als die in §7 genannten ≤ 24 h liegen. Frist an `created_at` bzw. Zeitpunkt des Callbacks binden.
5. Nach `RestructureException` wird das Notizbuch neu gelistet: `pages_total` und `pages_skipped` zählen bereits in diesem Lauf importierte Seiten erneut (Anzeige/ETA zu hoch).
6. Cursor speichert Seitentitel bis 1000 Zeichen je Seite und wird nach jeder Seite ganz neu geschrieben; bei Abschnitten mit Tausenden Seiten MB-große UPDATEs. Titel im Cursor kürzen (z. B. 200).
7. `tree`: bis 1 + 20 + 20×20 Anfragen in einer Web-Anfrage bei 0,6 s Abstand möglich; Abstand gilt nur je Prozess (zwei parallele `tree` desselben Benutzers laufen parallel).
8. Doku: SPEC §4.2-Zeile (OneNote-Port-Routen) ohne `tree`/`start`, SPEC §5 ohne `OneNoteImportJob`, §6 ohne `onenote_done`. Testnachweis im Plan nennt `BudgetTest.php`; Budget steckt in `AccountStateTest::testBudgetAndBackoff` (Plan oder Testname angleichen).
9. Done 4c nennt auch `delete-user-data` mitten im Lauf; Test deckt nur Kontolöschung ab (gleicher Löschpfad, trotzdem einen Fall ergänzen oder im Testnachweis begründen).
