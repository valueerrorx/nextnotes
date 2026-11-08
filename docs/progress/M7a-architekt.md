# M7a – Architekt (Paket bauen)
Status: fertig
Auftrag: `docs/slices/M7-plan.md` r2 §M7a umsetzen (make dist aus worktree, check-dist.sh, install-app.sh mit Paketquelle, .gitignore), Done 1–5.

## Erledigt
- Plan, Makefile, install-app.sh, env.sh, update.sh gelesen
- Dateien: neu nextnotes/build/dist.sh, nextnotes/build/dist-exclude.txt (gemeinsame Ausschlussliste), dev/nextcloud/check-dist.sh; geändert nextnotes/Makefile (dist), nextnotes/.gitignore (/dist/), dev/nextcloud/install-app.sh (NN_APP_TARBALL, Liste)
- Done1: make dist + `unshare -rn make dist` → beide d9bcb29b…31ab7 (Commit b19c77c0, 0.3.3, 7866503 B, 418 Einträge), Exit 0
- Done2: check-dist grün; Gegenprobe js-src+index.php → Exit 1; falscher Dateiname → Exit 1
- Done4: README/lib dirty → gleiche sha256, Probe nicht im Paket
- Done5: Lockfile geändert → Abbruch Exit 2, kein dist/; node_modules fehlt → Abbruch; worktree list sauber
- Done3: NN_PROJECT=nextnotes-m7a NN_PORT_BASE=9100 up.sh sqlite 0; NN_APP_TARBALL install-app 0; app:enable 0 (kein build/ im Container); check-page OK; check-csp OK; THEMES=light run-e2e smoke-m6 (48) + editor (119) Exit 0
- Abbau: down.sh sqlite 0, Restnetz nextnotes-m7a_default entfernt; keine Container/Volumes; worktree list nur Hauptbaum
- Doku: dev/nextcloud/README.md, docs/SPEC.md §1.2

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen/Annahmen
- tar-Modus `u=rwX,go=rX` statt Probelauf `go-w` (strenger, umask-unabhängig)
- dist.sh/dist-exclude.txt stammen aus dem aufrufenden Baum, Inhalt aus HEAD
- down.sh sqlite lässt das Compose-Netz stehen (vorbestehend)
