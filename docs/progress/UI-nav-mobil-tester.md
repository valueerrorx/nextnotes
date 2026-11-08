# UI-nav-mobil – Tester
Status: fertig
## Auftrag
Scratch-Specs mobile-m6/material-reminder/ui-nav-vt nach tests/nextnotes/e2e übernehmen; ui-nav (B, D) und anti-flicker (B) an neues Verhalten anpassen, Gegenproben; Lauf hell+dunkel sqlite (12 Specs), make build, check-upstream. Instanz nextnotes-mobilt :8581.
## Erledigt
- Instanz nextnotes-mobilt sqlite :8581 up, build, install, enable (exit 0)
- Specs übernommen: mobile-m6 (Kanban-Assert verschärft: .kanban-board + Karte der Notiz), material-reminder, ui-nav-vt (+ view-transition-name im Übergang gegen Leerlauf, Gegenprobe VT_STRIP=1)
- ui-nav B (Namen gesetzt, keine Animation nn-header/nn-rail, keine Gruppen-Animation nn-sidebar, root blendet) + D (Fläche opacity 1, Kinder 0 + inert vor ready)
- anti-flicker: STATE mit gerenderten Kindern, A/B2 Kinder sichtbar, B1 Kinder 0 + Fläche deckend; Gegenprobe AF_STRIP=html (inert im Template) statt js
- Gegenproben: AF_STRIP=css rot (2 FAIL), AF_STRIP=html rot (6 FAIL), VT_STRIP=1 ui-nav-vt rot (8 FAIL)
- run-e2e.sh sqlite 12 Specs hell+dunkel exit 0 (748 s)
- make build exit 0, check-upstream exit 0
- Instanz abgebaut (down.sh all), 0 Container/Volumes mobilt
## Nächster Schritt
- keiner
## Offen
- Befund: index→tasks keine Animation für ::view-transition-old(nn-sidebar) (ohne Zusatz-7-Regeln blendet sie aus); CSS-Kommentar sagt „beim Verschwinden blendet sie“. Spec schweigt; Test nur info.
