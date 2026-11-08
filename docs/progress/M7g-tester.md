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
