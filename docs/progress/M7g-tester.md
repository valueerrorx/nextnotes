# M7g Tester – Teil 1 Vorbereitung
Auftrag: run-m7.sh (Nachfolger run-m6.sh, App aus Paket, dist offline, upgrade-path 3×3, check-purge 3 DBs, occ --help, lists-katex); nginx fastcgi_hide_header prüfen; bash -n + Probelauf neue Schritte sqlite. Projekt nextnotes-m7g, NN_PORT_BASE=9800. Volle Matrix NICHT starten.
## Erledigt
- (Start, HEAD ac057cfc) Grundlagen gelesen: M7-plan M7g, STATUS M7, M6h-tester, run-m6.sh, Hilfsskripte
- run-e2e.sh findet Specs per Glob tests/nextnotes/e2e/*.mjs → lists-katex automatisch dabei (46 Specs)
- dev/nextcloud/run-m7.sh geschrieben (ONLY=-Schrittauswahl, SPECS=, Paketkopie im Log-Ordner, Phase 3 upgrade-path fe470b2c/86e514e6/1378e5d8 × sqlite/mariadb/pgsql); bash -n OK (shellcheck nicht installiert)
- Probelauf: NN_PROJECT=nextnotes-m7g NN_PORT_BASE=9800 ONLY="dist checkdist info install help purge e2e" SPECS=lists-katex THEMES=light run-m7.sh sqlite → rc 0 (dist 2× sha 38866f49…, zweiter unshare -rn; check-dist OK 431 Einträge; validate-info offline OK; app 0.9.0 aus Paket; --help 15 Befehle OK; check-purge OK; lists-katex hell 28 grün)
- Gegenproben help-Schritt: fehlende Option, unbekannter Befehl, Doku ohne Pflichtbefehl → je rc 1
- nginx (Projekt nextnotes-m7g-ngx, Port 9814, Paket): Referenz-Conf Anhang 2× X-Content-Type-Options; mit fastcgi_hide_header im php-Block 1× überall (Seite, API, Anhang, status, statisch); check-page 0, check-csp 0, E2E hell lists-katex 28 / smoke-m6 48 / frame 142 grün → Doku-Zeile bleibt
- Abgebaut: nextnotes-m7g und nextnotes-m7g-ngx (keine Container/Volumes/Netze), kein worktree übrig
## Nächster Schritt
- Teil 2 (Lead): volle Matrix E2E_PARALLEL=1 dev/nextcloud/run-m7.sh; danach run-m6.sh entfernen + README
## Offen/Annahmen
- run-m6.sh nicht entfernt, README nicht angepasst (Teil 2)
- HEAD während des Laufs auf 11ae8f99 gewechselt (fremder Commit); Probelauf-Paket aus ac057cfc
## Status: fertig (Teil 1)

# M7g Tester – Teil 2 (HEAD 1a65b2a2, App 0.9.2)
Auftrag: run-m7.sh um M7h/M8/M9 ergänzen (blocks-*/onenote-* E2E, node --test, PHPUnit msmock je DB, check-purge 15, check-dist, Log-Suche Token/Secret/eyJ); volle Matrix; Paket bauen/prüfen (nicht signieren); abbauen.
## Erledigt
- Bestand: E2E-Glob nimmt blocks-*/onenote-* schon mit; check-purge prüft schon 15 Tabellen; check-dist schon Schritt; fehlt: node, msmock-PHPUnit, Mock für onenote-E2E (nginx: kein msmock), Log-Suche
- run-m7.sh ergänzt: Schritte node (Vorab), msmock (PHPUnit --group msmock je DB, mkdir-Sperre, msmock up/down), logscan (Phase 1 vor purge + nach E2E sqlite); E2E: onenote-* nur sqlite mit Mock, nginx ohne; bash -n OK
- make -C nextnotes dist rc 0; check-dist OK 490 Einträge, 8581161 B, sha256 0b8400dd…51bf4 (Kopie scratchpad/m7g/pkg)
- Probelauf ONLY="node install msmock logscan e2e" SPECS="onenote-connect blocks-calc" THEMES=light sqlite → rc 0 (node 32 pass, msmock OK 14, Log sauber, beide Specs grün), 6 min
- Matrix 05:54–08:05 (131 min) rc 1: dist reproduzierbar 0b8400dd…, check-dist OK, upstream/info/sql/node OK; Lebenszyklus+help 4×OK; PHPUnit sqlite/mariadb/pgsql je 3 Failures (ControllerAttribute CSRF-Allowlist OneNote, Isolation routeTable OneNote, CoreScenario Fixture-Ausnahmen) → Phase 1 dort abgebrochen (msmock/logscan/grenzen/circlesoff/purge + sqlite-E2E nicht gelaufen); nginx Phase 1 grün, E2E rot nur dark lists-katex (Formeln [0,0]) + dark ui-nav-vt (3 VT-Prüfungen); Upgrade 3 DB × 3 Startpunkte grün
- Tests ControllerAttribute/Isolation/CoreScenario/lists-katex wurden WÄHREND des Laufs von dritter Seite im Arbeitsbaum geändert (07:04–08:05), nicht von mir
- Nachlauf 1: ONLY="install e2e" SPECS="lists-katex ui-nav-vt" THEMES=dark nginx → rc 0 (28 + 31 grün); ui-nav-vt dark = flaky (Test unverändert); lists-katex grün mit Fremd-Änderung (Warten auf Block-Rendern)
## Nächster Schritt
- Nachlauf 2 (läuft): ONLY="install phpunit msmock logscan grenzen circlesoff purge e2e" sqlite mariadb pgsql, Log scratchpad/m7g/re2
## Status: in Arbeit (Teil 2)

# M7g Lead – Abschluss (2026-11-09)
- Matrix-Ergebnis: Phase 1 rot auf sqlite/mariadb/pgsql (je 3 Meta-Testfehler, OneNote-Routen nicht registriert), nginx E2E rot dunkel (lists-katex, ui-nav-vt), Upgrade 9/9 grün.
- Lead-Fix: ControllerAttributeTest (page/callback auf Allowlist), IsolationTest (6 OneNote-Routen, 145), CoreScenarioTest (Fixture-Ausnahmen), lists-katex wartet auf Rendering.
- Nachweis frische sqlite (nextnotes-m7g3, Paket 0b8400dd…): PHPUnit-Filter OK 180; lists-katex + ui-nav-vt hell+dunkel grün. Davor frische sqlite (m7g2) PHPUnit 806: nur die 2 dann korrigierten Status-Erwartungen rot.
- Offen für den Mac: PHPUnit+msmock mariadb/pgsql, logscan, purge, sqlite-E2E komplett.
- Abgebaut: m7g, m7g2, m7g3 (keine Container/Volumes).
## Status: Teil 2 abgeschlossen mit Restliste
