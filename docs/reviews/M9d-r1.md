# M9d Diff-Review r1 (speccritic)

VERDICT: BLOCKER

Grundlage: `docs/slices/M9-plan.md` r3 (§2 Job, §4.4/4.5, §6, M9d Done 1–9, B1/B9), ADR 0020 mit Nachträgen, Hinweise `M9a-r1`/`M9b-r1`. Geprüft: Arbeitsbaum gegen f2848346 (lib, Tests, SPEC/ADR/Plan-Diff). Eigener Nachweis: `docker run --rm nextcloud:35` mit Guzzle aus `/usr/src/nextcloud/3rdparty` (CurlHandler, wie in NC 35) + `LimitedSink` (1 MiB) gegen einen lokalen `php -S` mit 64 MiB Nullen: `gzip`, `deflate`, `gzip, gzip` und chunked gzip brechen alle bei ≤ 1 048 576 dekodierten Bytes ab (`RequestException`, 0,35–0,41 s, curl 8.14.1 mit brotli/zstd).

## Blocker
1. **Temp-Reste nach Prozessabbruch** (`ResourceFetcher::one`, `OneNoteImportService::cleanup`; Plan §2 Job/§9 M9b Done 4: SIGKILL → „ohne Temp-Reste“; M9d Done 8/9).
   - Aufräumen gibt es nur im `finally` bzw. über `ITempManager::clean()` beim Shutdown. Bei SIGKILL, OOM-Kill oder einem Cron-Zeitlimit des Hosters bleiben `oc_tmp_*.nn-onenote` (bis 256 MiB je Seite) liegen. `ITempManager::cleanOld()` wird in NC 35 nirgends aufgerufen (`grep -rn cleanOld .ref/nextcloud-server` → nur Interface und `TempManager`).
   - Ein Abbruch trifft wiederholt dieselbe Seite: Eine Seite darf bis 15 min laufen, Cron startet alle 5 min. Bei jedem Abbruch bleiben bis 256 MiB zurück, die Platte läuft voll.
   - Reproduktion: msmock-Seite mit Ressource `slow`. `php cron.php` starten, während des Downloads `kill -9`, dann `ls $(occ config:system:get tempdirectory || echo /tmp)/oc_tmp_*.nn-onenote` → Datei bleibt.
   - Fix (klein): `cleanup()` im Job löscht `getTempBaseDir()/oc_tmp_*.nn-onenote` mit mtime älter als 1 h (eine Seite dauert höchstens 15 min, die Datei wächst beim Schreiben). Dazu ein Test.

## Hinweise (nicht blockierend)
1. Die Lease ist 300 s (`GraphClient::LEASE_SECONDS`), ein Ressourcen-Download darf aber bis 900 s dauern (`timeout` = Restzeit der Seite).
   - Im normalen Cron schützt nur die 12-h-Reservierung von `JobList`. Mit `--force-execute` oder einem zweiten Job-Runner übernimmt ein zweiter Prozess die Seite. Er lädt parallel und verliert wieder.
   - Keine Duplikate dank Lease-Check, aber doppelte Anfragen.
   - Abhilfe: Timeout je Ressource auf ≤ 240 s deckeln und die Seitenfrist beibehalten, oder `LEASE_SECONDS` ≥ 900 + Puffer setzen.
2. `cleanName` prüft `strlen > 255` (Bytes). Der Konverter kürzt auf 255 **Zeichen** (`PageWalker::fileName`). Mehrbyte-Namen ab etwa 86 Zeichen (CJK) werden dadurch zum Platzhalter mit Grund `type`, statt gekürzt zu werden. Abhilfe: im Konverter mit `mb_strcut` auf 255 Bytes kürzen und dabei die Endung erhalten.
3. Abweichung „doppelte Berichtseinträge nach `RestructureException`“: `base` setzt nur die Zähler zurück, nicht `report`. Die Einträge `not_found`/`forbidden`/`invalid_id` des neu aufgebauten Notizbuchs stehen danach doppelt, und `onenote_done.reported` ist zu hoch. Billig zu beheben: die Länge des Berichts in `base` mitspeichern und den Bericht darauf kürzen.
4. Der Notiztitel ist jetzt auf 200 Zeichen gekürzt (Cursor), vorher 1000. Plan §5 und SPEC §4.9 nennen das nicht. In M9e dokumentieren.
5. `listAll` bricht bei 50 Anfragen still ab (Teamliste bzw. Notizbücher eines Teams unvollständig, `teams_more` = false). Praktisch kaum erreichbar. Ein Bericht oder ein Flag wäre sauberer.

Geprüft ohne Befund:
- Abbruch über `sink` (Rückgabe 0 → curl-Fehler 23), keine Redirects, feste Hosts, URL aus geprüfter ID neu gebaut (`validId` ohne Punkt-Segmente und leere `!`-Segmente).
- MIME am Inhalt (`detectContent`), SVG/HTML nie als Bild, Dateien nach den Upload-Regeln (`cleanName`/`mimeOf`, gleiche Größengrenze).
- Quote vorab (1 Byte), mit Summe + HTML vor der Transaktion, `enforceQuota` in der Transaktion; Rollback löscht die Anhangsdateien.
- `setImportedContent` läuft über `applyContent` (Validator, Sanitizer, `storable`), nur aus `writePage` im Job mit der uid der Zeile, kein Controller-Pfad.
- M9b-r1 1–9 umgesetzt: Sperre vor Besitz, `claim` mit `next_run_at`, `validId`, `select` ab `created_at`, Cursor-Titel, `tree` 25/50, Doku, `delete-user-data`-Test.
- M9a-r1 1/2/5 erledigt.
- Tests decken Done 1–9 ab (Fetcher-, Budget-, Hardening-, msmock-Tests, E2E Abschnitt E).
- OCP stichprobenartig: `detectContent` @since 18, `ISimpleFolder::newFile` mit Ressource @since 19, `getTemporaryFile` @since 8.
