# ADR 0019: Weiche Seitenwechsel (Stufe 1)

Status: entschieden (Lead-Delegation an Architekt UI-nav, 2026-11-08; technische Entscheidung)

## Kontext
Jede Ansicht der Icon-Leiste (Dashboard, Aufgaben, Tagebuch, Graph, Speed Dial, Erwähnungen, Einstellungen …) ist eine eigene PHP-Seite (ADR 0004). Ein Klick lädt die Seite voll neu; bis zum Adapter-Start war ganz `#app-content` unsichtbar (M4h), dadurch wirkte jeder Wechsel wie ein Neustart (Messung UI-nav: Klick → sichtbar ~500 ms, TTFB ~35 ms, ~89 Requests). Wunsch Thomas: wirkt wie eine App, ohne Umbau zur Single-Page-App.

## Optionen
1. Single-Page-Navigation (Seiten per Fragment nachladen, Skripte neu initialisieren): Upstream-Skripte sind seitenweise gebaut, großer Umbau.
2. **Cross-Document View Transitions + Vorladen + Anti-Flackern nur für den Inhalt** (Plattform-Mittel, keine Änderung der Seitenstruktur).
3. Nur Vorladen ohne Übergang: Wechsel bleibt ein harter Bildwechsel.

## Entscheidung
Option 2:
- `@view-transition { navigation: auto; }` in `nextnotes-layout.css`, nur bei `prefers-reduced-motion: no-preference`. Gilt nur zwischen Seiten der App (beide Seiten müssen zustimmen). `view-transition-name` für NC-Kopfleiste (`nn-header`), Icon-Leiste (`nn-rail`) und Seitenleiste (`nn-sidebar`) nur während des Übergangs (`html:active-view-transition`): ein dauerhafter Name machte die Leisten zu Stapelkontexten und sperrte ihre fixed-Menüs unter den Inhalt. Inhalt blendet über (Wurzel), Dauer 150 ms.
- Vorladen: Adapter (`nextnotes-bootstrap.js` §13a) fügt bei Hover (60 ms) oder Fokus eines Icon-Leisten-Links eine Speculation-Rules-Regel `prefetch` (Liste, nur diese URL) ein; nur gleiche Origin und App-Pfad, alle Leisten-Ziele sind GET-Seiten ohne Seiteneffekte (View-Services schreiben nicht). Kein `prerender` (würde Seitenskripte, Sperren und Beacons im Hintergrund starten). Die Regel trägt die Nonce der Seite (`document.currentScript.nonce`), daher keine CSP-Ausnahme (ADR 0012). Regel nach 30 s entfernt, damit kein veralteter Stand geöffnet wird. Ohne Unterstützung (Firefox) kein Vorladen.
- Anti-Flackern nur für den Inhalt (`#right_pane`, `main.nn-page-main`, `#outline-panel`); Kopf-, Icon- und Seitenleiste stehen sofort, 2-s-Rückfall bleibt. Der Adapter setzt Breite/Eingeklappt der Seitenleiste und ausgeblendete Leisten-Einträge vor dem ersten Zeichnen.

## Konsequenzen
- Request-Zahl je Seite unverändert; ein Vorabruf entsteht nur bei Hover/Fokus und ersetzt den Dokument-Request der folgenden Navigation.
- Notiz- und Ordnerklicks in der Hauptansicht bleiben ohne Neuladen (Upstream, `#right_col`-Fragment); sie sind nicht Teil dieser Stufe.
- Stufe 2 (falls nötig): SPA-artige Navigation bleibt offen, eigene ADR.
- E2E `ui-nav.mjs` prüft Regel, Übergang, Gruppen, reduzierte Bewegung, Vorabruf (`deliveryType`) und Anti-Flackern.

## Nachtrag UI-nav-mobil (2026-11-08)
- Kopf- und Icon-Leiste stehen still: `::view-transition-old(nn-header|nn-rail)` ausgeblendet, `-new` und Gruppe ohne Animation; Seitenleiste ebenso, wenn sie auf beiden Seiten steht.
- Die Inhaltsfläche bleibt deckend: Anti-Flackern blendet nur ihre Kinder aus (vorher die Fläche selbst, der neue Schnappschuss zeigte Milchglas); nur die Wurzel blendet, alter Inhalt auf deckender Fläche aus, neuer ein.
