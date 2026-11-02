# ADR 0016: Themes und Schriften

Status: entschieden (Thomas, 2026-11-02)

## Kontext
Upstream: 6 Themes (`light`, `dark`, `black`, `lavender`, `sepia`, `terminal`), Theme-Schalter mit `localStorage`, Admin-Upload eigener CSS-Themes und Schriften (`api_upload_css.php`, `api_upload_font.php`), Instanzlogo (`api_branding.php`), Schrift Inter. Alle Stylesheets lesen nur `--pz-*`-Tokens aus `tokens.css` (Inventur B §5). Inventur widersprach sich (A #209–211 „streichen“, B #73–75 „ersetzen“).

## Optionen
1. Upstream-Themes und Uploads behalten.
2. **Nur Hell/Dunkel nach NC-Theme; `tokens.css` zeigt auf NC-Variablen.**

## Entscheidung
Option 2:
- Kein eigener Theme-Schalter. Hell/Dunkel folgt der NC-Einstellung (Body-Attribute `data-theme-<id>`, z. B. `data-theme-dark`, `data-theme-light`; `default` folgt `prefers-color-scheme`, siehe `apps/theming/lib/Themes/DarkTheme.php`, `ThemingController.php:433`). Der Adapter setzt daraus `html[data-theme='dark'|'light']`, das Upstream-CSS erwartet.
- Zusatz-Themes, Custom-CSS-Upload, Font-Upload, eigene Schriften, Instanzlogo: gestrichen (v1). Schrift = `var(--font-face)`.
- `tokens.css` bleibt unverändert im Upstream; ein zusätzliches Stylesheet `nextnotes-tokens.css` (nach `tokens.css` geladen) setzt die Werte auf NC-Variablen (geprüft in `apps/theming/lib/Themes/DefaultTheme.php`): z. B. `--pz-bg: var(--color-main-background)`, `--pz-text: var(--color-main-text)`, `--pz-text-muted: var(--color-text-maxcontrast)`, `--pz-border: var(--color-border)`, `--pz-accent: var(--color-primary-element)`, `--pz-accent-hover: var(--color-primary-element-hover)`, `--pz-accent-soft: var(--color-primary-element-light)`, `--pz-accent-text: var(--color-primary-element-text)`, `--pz-danger: var(--color-error)`, `--pz-success: var(--color-success)`, `--pz-warning: var(--color-warning)`, `--pz-radius-sm: var(--border-radius-small)`, `--pz-radius-lg: var(--border-radius-element)`, `--pz-radius-xl: var(--border-radius-large)`, `--pz-radius-pill: var(--border-radius-pill)`. RGB-Tokens über `color-mix()` bzw. `--color-*-rgb` (vorhanden für `main-background`, `box-shadow`, `error`, `success`, `warning`, `info`).
- Notizfarben (`--pz-color-*`), Syntax-Farben, Excalidraw-Filter bleiben Upstream-Werte (Benutzerinhalt), Dunkelvarianten weiter aus `tokens.css`.

## Konsequenzen
- Inventur: A #209, #210, #211 bleiben „streichen“; B #73, #74, #75 und 2.1 #7, #8, #9 korrigiert auf „streichen“; Inter-Zeile (B 4.3) → streichen.
- Theme-Menüeinträge werden per Adapter ausgeblendet (Upstream-Option `__POZNOTE_HIDDEN_UI_ELEMENTS__` bzw. CSS), kein Upstream-Edit.
- Hoher Kontrast/Legastheniker-Schrift von NC wirken automatisch über die Variablen.
