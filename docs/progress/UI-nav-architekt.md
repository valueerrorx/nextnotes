# UI-nav – Architekt
Status: fertig
## Auftrag
NC-Stil Seitenleiste+Icon-Leiste (nur Theme-Variablen, css-src), Cross-Document View Transitions, Prefetch Icon-Links, Anti-Flicker nur Inhalt, Messung, E2E ui-nav.mjs, Regression. Instanz nextnotes-uinav Port 8520.
## Erledigt
- Start (frisch, keine Vordatei)
- Messung nachher (scratchpad/m/timing-nachher-*.json): alle 13 Icon-Links weiter volle Seitenwechsel, jetzt aus Vorabruf (deliveryType navigational-prefetch, TTFB 0), ready/Klick→ready etwa gleich (±40 ms), Requests +1 (Vorabruf getrennt gezählt), FCP ~470 ms (Chromium meldet erst nach dem Übergang)
- probe-stale: Regel nach 30 s entfernt → Navigation ohne Vorabruf (verworfen)
- Screenshots nachher hell/dunkel; make build 0, check-upstream 0, php -l ok; Instanz nextnotes-uinav abgebaut (down.sh all)
## Nächster Schritt
- keiner (Bericht an Lead)
## Offen
- anti-flicker.mjs Teil B an neues Verfahren anpassen (tester)
