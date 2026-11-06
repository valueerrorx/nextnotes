# ADR 0017: Upstream-CSS gegen NC-Kern-CSS

Status: entschieden (Lead-Delegation an Architekt M3a2, 2026-11-03; technische Entscheidung)

## Kontext
NC-Kern (`core/css/server.css`) stylt `button`, `input`, `select`, `textarea` global mit Spezifität bis 0,4,1 (z. B. `button:not(.button-vue,[class^=vs__])`: Hintergrund Primärfarbe, Padding, Rand, `min-height`, `font-weight: bold`; `input:not(…)`: `width`, `height: 36px`, 2-px-Rand, `margin`). Poznote-Regeln für dieselben Elemente sind meist reine Klassen (0,1,0) und verlieren: Knöpfe im Seitenkopf wurden blaue Flächen, Suchfeld und Symbolknöpfe zerfielen (M3a2-Smoke). NC lädt Kern-CSS ohne Cascade Layers; ein reiner Reset in `nextnotes-layout.css` gewinnt entweder gegen NC **und** Upstream oder gegen keinen von beiden.

## Optionen
1. Reset in `nextnotes-layout.css` allein: setzt Upstream-Werte außer Kraft (Klassenregeln verlieren auch gegen den Reset).
2. Upstream-CSS in `@layer` legen: dann gewinnt NC-Kern überall (Überschriften, Listen, Links), schlimmer.
3. Upstream-Selektoren per Patch in `src/` ändern: tausende Stellen, unwartbar.
4. **Build hebt die Spezifität jeder Upstream-Regel um eine ID-Stelle; dazu ein Reset auf Browser-Standard nur für NC-Formularregeln im App-Bereich.**

## Entscheidung
Option 4:
- `build/css-bump.mjs` (`bumpSpecificity`, aufgerufen von `build.mjs`) für alle Upstream-CSS-Gruppen (`core`, `modals`, `dark`, `nextnotes-mobile`, `syntax-highlight`; nicht Vendor): Selektoren mit Klasse/ID/`[class|id|data-…]` außerhalb führender `html`/`body`/`:root`-Teile erhalten am Subjekt `:not(#nn-0)` (+1,0,0, passt immer). Reine Element-Selektoren (`a`, `input[type=text]`, `pre code` …) gelten ohne Zusatz-Spezifität nur außerhalb der NC-Oberfläche (`:where(:not(#header,#header *,.v-popper__popper,.v-popper__popper *,.modal-mask,.modal-mask *,.toastify,.toastify *))`, also die NC-Elemente selbst und ihre Nachfahren (M3a2-Testbefund: Dunkel-`*`-Regel traf `header#header`)) und als gehobene Kopie mit `:is(#nextnotes-root,#nextnotes-root *)`. Damit färbt z. B. Upstream `a{color}` nicht mehr das NC-Benutzermenü (M3a1-Befund im Smoke: Menülinks in Akzentfarbe). Reihenfolge der Upstream-Regeln untereinander bleibt gleich (alle +1 ID). `@keyframes`/`@font-face` unverändert. Endet der Selektor auf ein Pseudo-Element samt folgenden Pseudo-Klassen (`::-webkit-scrollbar-thumb:hover`, `::file-selector-button:hover`), steht der Zusatz vor dieser ganzen Folge; dahinter wäre der Selektor ungültig und der Browser verwürfe die ganze Regel still (Review M3a2 r1).
- Prüfung: `build.mjs` bricht ab bei Zusatz hinter Pseudo-Element (vor und nach Minify) oder geänderter Regelzahl. `check-upstream` (e2, `build/check-css.mjs`) parst mit Chromium: Stilregeln der Upstream-Quellen ohne/mit Zusatz gleich; keine Regel der gebauten Bundles ist nur durch den Zusatz ungültig (engine-fremde Upstream-Regeln wie `::-moz-range-thumb` sind es auch ohne).
- `nextnotes-layout.css`: `:where(#nextnotes-root) :is(button,input,select,textarea)` mit Spezifität 0,5,1 setzt die von NC gesetzten Eigenschaften auf `revert` (Browser-Standard wie in Poznote). Upstream-Regeln (≥ 1,0,0) setzen sich darüber durch.
- Kein Eingriff in `src/`, keine CSP-Änderung.

## Konsequenzen
- Eigene Regeln in `css-src/`, die Upstream-Elemente überschreiben, brauchen ≥ 1,1,0 (z. B. `#nextnotes-root .klasse`) und stehen nach den Upstream-Bundles.
- Von Upstream an `body` angehängte Elemente (Menüs, Modals) erhalten Upstream-Regeln mit Klasse wie im App-Bereich; der Formular-Reset gilt dort nicht (NC-Werte nur für Eigenschaften, die Upstream nicht setzt). Prüfen in M3b, ggf. Reset-Bereich erweitern. Geprüft M3b2 (Hell/Dunkel: Anhang-, Bild-, Slash-Menü, Fälligkeits- und Verschieben-Dialog, Eingabe-Modal): nur das Eingabe-Modal (`ui.js` `#inputModal`) erhält NC-`min-height`/`margin` an Knöpfen und Feld, optisch unauffällig → Reset-Bereich bleibt `#nextnotes-root`.
- Upstream-Klassen, die zufällig auch NC-Elemente tragen, wirken dort stärker. Smoke M3a2: 0 App-Regeln treffen Elemente in NC-Kopf und Benutzermenü (Regel-Scan im Browser).
- NC-Fokusring auf Knöpfen (`box-shadow … !important` bei `:focus-visible`) bleibt erhalten.
- CSS-Größe: gzip 135,7 → 139,0 KB (+2,4 %, Grenze 230 KB).
- Laufzeit (M4i-fix): Wächter jetzt gleichwertig als `:where(:not(:is(#header,.v-popper__popper,.modal-mask,.toastify) *):not(#header,.v-popper__popper,.modal-mask,.toastify))` – ein Vorfahren-Durchlauf statt vier. Die alte Liste machte jede volle Stilberechnung bei `*`-Regeln teuer (Hauptansicht ~4 600 Elemente: ~110 ms je Berechnung, 5 je Seitenstart, dunkel mehr wegen `html[data-theme=dark] *`). `::highlight(name)` ohne Element-Teil bleibt ohne Zusatz (Namen nur aus App-Code, NC stylt sie nicht); die 14 Syntax-Regeln waren sonst je Element geprüfte `*`-Regeln. Messung Hauptansicht dunkel nach vollem E2E-Lauf (171 Notizen): Phase bis `nextnotes-ready` 1 108–1 130 → 837–908 ms (Erstaufruf), 735 → 410–444 ms (warm).
