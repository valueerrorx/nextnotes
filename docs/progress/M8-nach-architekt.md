# M8-nach – Architekt

Status: fertig

Auftrag: Nacharbeiten aus M8c-r2: Budget je Notiz + Unicode-fn-Regex (blocks.js, Test), Build-Patch-Prüfung, user.md Einheiten-Regel, Notices es6-shim.

## Erledigt
- blocks.js: Budget je Note-ID-Satz (scan setzt budgetKey, Reset nur bei Wechsel oder Klick auf Knopf), kein Reset bei leerer Schlange; fn-Regex mit math.js-isAlpha-Zeichen

- block-queue.mjs: Notiz-Stub, Test Sperrwechsel 4×/12 s, Klick, Notizwechsel, κ(x)=; gegen alten Build rot (4000 ms, κ nicht erkannt), nach `make build` (Exit 0) 5/5 grün
- build.mjs: vendorPatches zählt Anwendungen, check() nach esbuild; Gegenprobe (file → nicht-da.js) Exit 1 mit Meldung, bundles.json zurück; make build Exit 0, Patch im Bündel
- docs/app/user.md Einheiten-Regel mit Beispiel; THIRD-PARTY-NOTICES es6-shim (MIT); check-notices Exit 0, check-upstream Exit 0, node 32/32
- E2E nextnotes-m8n (11000), graceful, THEMES=light run-e2e.sh sqlite blocks-calc blocks-sheet: Exit 0 (169 + 217); down.sh all Exit 0, keine m8n-Container

## Nächster Schritt
- keiner

## Offen
- SPEC §4.7 Nr. 14 sagt noch „Budget neu, sobald die Schlange leer ist“ → Lead (SPEC gesperrt wegen M9a)
- E2E Inhaber „Viele Blöcke“: 2/12 gerechnet (vorher bis 4/12): Einhängen lesend vor Sperrerhalt verbraucht Budget, Wechsel auf edit bekommt keins mehr (gewollt laut Auftrag)
