# M7-mac – tester

Auftrag: volle Matrix `dev/nextcloud/run-m7.sh` (sqlite mariadb pgsql nginx) erstmals auf macOS/Colima; Mac-Befunde melden, Testskripte minimal fixen; danach abbauen (außer Demo).

## Erledigt
- Vorab geprüft: down.sh all nutzt NN_PROJECT (Standard nextnotes-dev), trifft Demo (nextnotes-demo) nicht. Docker: nur Demo-Container laufen. bash 5.3, timeout (coreutils) vorhanden, ss/unshare fehlen (lsof-Fallback, Offline-Warnung erwartet).

## Nächster Schritt
- run-m7.sh läuft (Start 1, Log scratchpad run1.log, NN_LOG_DIR=dev/nextcloud/.cache/run-m7-mac1); bei Abbruch: Ergebnis dort prüfen, nicht doppelt starten

## Offene Punkte / Mac-Befunde
- Lauf 1 (run-m7-mac1): Vorab grün (dist reproduzierbar 23f59846…, check-dist 490, check-upstream, info, sql 0, node 32/32). Phase 1: mariadb+pgsql ROT direkt nach Lebenszyklus: `FEHLER: Port 8084 ist schon belegt` (env.sh nn_check_ports, aufgerufen von down.sh im install-Schritt, während nginx parallel down/up macht; Lauscher = Colima-ssh-Mux, kein eigener Container im Moment der Prüfung).
- Skriptänderung 1: dev/nextcloud/env.sh nn_check_ports – Container-Ports in nn_read_ports; Lauscher ohne eigenen/fremden Container → bis 5× 2 s neu lesen, dann erst FEHLER.
- Lauf 1 sqlite Phase 1 ROT: PHPUnit 806 Tests, 7047 Assertions, 629 Errors, 3 Failures (Exit 2). Ursache: `Too many open files` im Container (Colima: `ulimit -n` = 1024, hard 524288). Einzeln (`--filter DeleteUserDataTest`) grün 9/214.
- Skriptänderung 2: dev/nextcloud/run-phpunit.sh – phpunit über `sh -c 'ulimit -n <hard>…; exec "$@"'`. Probe-Umgebung NN_PROJECT=nextnotes-mac NN_PORT_BASE=8180 (sqlite) für Gegenprobe, fd-Zählung in scratchpad fdcount.txt. Abbauen nicht vergessen!
- Lauf 1 abgebrochen (Lead: neuer Stand d9883c4b, dist muss neu gebaut werden). nginx Phase 1 war grün, nginx-E2E lief bis menu-501 ohne Auffälligkeiten. nextnotes-dev abgebaut (Trap), Demo unberührt.
## Nächster Schritt
- Lauf 2 läuft (Start ~10:20, d9883c4b, Log scratchpad run2.log, NN_LOG_DIR=dev/nextcloud/.cache/run-m7-mac2); bei Abbruch dort Ergebnis lesen
- Gegenprobe (nextnotes-mac, sqlite, Paket fba89c32) mit Skriptänderung 2: PHPUnit 806 Tests, 22514 Assertions, 1 skipped, Exit 0. Max. offene fd des phpunit-Prozesses ≈ 3300 (wächst über die Suite → Hinweis auf fd-Leck in Tests/App, an Lead melden). nextnotes-mac abgebaut.
- Lauf 2 (run-m7-mac2, d9883c4b, Paket sha256 6e32c10c…, 83 min): Vorab grün (node 32/32, upstream, info, sql). Phase 1: PHPUnit gesamt grün sqlite 806/22514 (1 skip), mariadb 806/22529, pgsql 806/22524 → Fix 1+2 wirken. msmock ROT: sqlite+pgsql ImportMockTest::testSlowResourceHitsPageDeadline (failed nur [0]), mariadb testSigkillAndResume (Zeitüberschreitung waitFor). nginx Phase 1 grün. E2E nginx rot (14 Spec-Läufe, s. u.), sqlite-E2E nicht gelaufen (Phase 1 rot). Upgrade 9/9 grün.
- Nachlauf msmock sqlite allein (run-m7-mac3-msmock, ONLY="install msmock" KEEP=1): 14 Tests/386 Assertions grün, aber 1 Warning `dns_get_record(): A temporary server error occurred` (DnsPinMiddleware.php:111, MockIntegrationTest::testLocalAddressProtectionBlocksMock:135) → Exit 1. Netz (Hotspot-DNS), kein Mac-Skriptfehler. testSlowResourceHitsPageDeadline allein grün → Lauf-2-Fehler = Parallel-Last.
- E2E-Analyse Lauf 2 nginx: assignment-tplpick = Artefakt (Spec aus e258fcd5 kam während des Laufs in den Baum, Paket d9883c4b). frame „bob ?workspace=<alice>“ rot nur in Lauf 2 (Lauf 1 grün) → vermutlich Folge dashboard-ws/d9883c4b (Zustand), nicht Mac. blocks-calc/-formula/-sheet, mention-tasks-m6c in Lauf 1 UND 2 rot → Verdacht Mac-Tasten (Control+End/Control+z/Control+Enter/Control+Klick; Playwright mac-Editierbefehle).
- msmock sqlite frisch (run-m7-mac4-msmock): OK 14 Tests/385 Assertions → Lauf-2-Rot = Last. Dazwischen Befund: ONLY="msmock" lief gar nicht (p1-Liste ohne msmock/logscan, meldete grün).
- Skriptänderung 3: dev/nextcloud/run-m7.sh:164 p1 um msmock logscan ergänzt.
- Skriptänderung 4 (Mac-Tasten): tests/nextnotes/e2e/lib/harness.mjs KEY_DOC_END/KEY_LINE_END (darwin: Meta+ArrowDown/Meta+ArrowRight); Control+End → KEY_DOC_END in blocks-calc (3), editor (2), menu-501 (1); mention-tasks-m6c:175 End → KEY_LINE_END.
- Hinweis: andere Agenten fahren parallel eigene Umgebungen (nextnotes-wsbind*, -uisb) → Zusatzlast im Colima-VM. Baum bewegt sich (HEAD jetzt 7f64db29).
- Nachlauf Tasten (nextnotes-mactest, Port 8281, run-m7-mac5-keys, light): blocks-calc grün, mention-tasks-m6c grün; blocks-formula weiter rot (Klick auf span.math-inline: „<p> intercepts pointer events“). Diagnose (scratchpad diag-math.mjs): verstecktes .katex-mathml hat auf mac-Chrome Fläche (math 16×15, Hülle 1×1 bei y-2), Playwright-Klickpunkt landet auf <p>; Klick auf .katex-html öffnet Popover.
- Skriptänderung 5: harness.mjs clickMath(loc, how) (nur darwin: .katex-html statt Host); blocks-formula (8 Stellen), blocks-sheet (2 Stellen). Nachlauf läuft (scratchpad e2e-math.log). Umgebung nextnotes-mactest steht noch → am Ende abbauen.
- blocks-formula 147 / blocks-sheet 217 Prüfungen grün (light, sqlite, mactest). mactest abgebaut.
- Lauf 6 gestartet (run-m7-mac6, HEAD 7f64db29 statt d9883c4b – enthält dashws; Paket nur HEAD): ONLY ohne upstream (fremde uncommittete Änderungen in nextnotes/src im Baum; make clean build würde sie mitbauen) und ohne upgrade (Lauf 2: 9/9 grün, keine Migration seitdem). SPECS = 51 getrackte Specs (ohne fremde untracked toggle-ui, tree-longname).
## Nächster Schritt
- Lauf 6 auswerten; Zeitfehler einzeln nachlaufen (kennzeichnen); danach nextnotes-dev abbauen, Bericht.
- Lauf 6 Ergebnis (146 min, Phase 1 ~95 min wegen Fremdlast): Paket 78b7d560…, node 32/32. PHPUnit gesamt grün: sqlite 807/22561 (1 skip), mariadb 807/22575, pgsql 807/22571. pgsql komplett grün (msmock 14/385, Log, grenzen, circles-off, purge). msmock rot (Zeitüberschreitung waitFor 60 s): sqlite testParallelExecute, mariadb testSigkillAndResume → Rest von sqlite/mariadb Phase 1 + sqlite-E2E nicht gelaufen. nginx Phase 1 grün; nginx-E2E 51 Specs: rot nur frame (hell+dunkel, „bob ?workspace=<alice>“) und assignments-m6g dunkel (S1 privat); blocks-*, mention grün (Fixes wirken).
- Ursache frame: dashboard-ws setzt last_opened_workspace von bob auf Team-WS (dashboard-ws.mjs:38/149/159), frame.mjs:418 erwartet Standard-WS → Spec-Wechselwirkung, nicht Mac. frame dunkel zusätzlich Regel-Scan: nextnotes-modals.css .notification-icon/-heading/-time treffen NC-Kopf (App-CSS, nicht Mac).
- Nächster Lauf 7: ONLY="install msmock logscan grenzen circlesoff purge e2e" PARALLEL=0 mariadb sqlite, Paket run-m7-mac6.
