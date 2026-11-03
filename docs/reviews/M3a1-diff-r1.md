VERDICT: BLOCKER

1. Schrift (ADR 0016 „Schrift = var(--font-face)“, Konsequenz „Legastheniker-Schrift von NC wirkt automatisch“; Plan §1.4): Build entfernt nur `@font-face` (bundles.json `stripFontFace`); ~90 Upstream-Deklarationen `font-family: 'Inter', …` (u. a. `notes/noteentry.css` mit `!important`, `base.css`, `markdown.css`) fallen auf `-apple-system`/`sans-serif` bzw. ein lokal installiertes Inter statt NC-Schrift; NC-Option OpenDyslexic (`DyslexiaFont.php:46`) wirkt im Editor nicht. `nextnotes-layout.css` (body/#nextnotes-root) überstimmt Element-Selektoren nicht. Forderung: Build schreibt in `font-family`-Werten `'Inter'`/`"Inter"`/`Inter` auf `var(--font-face)` um (gesteuert über bundles.json, deterministisch, kein src/-Patch); Test: berechnete `font-family` eines Probe-Elements mit Upstream-Klasse (z. B. `.noteentry`) beginnt mit dem Wert von `--font-face`.
2. Widerspruch ADR 0016 ↔ Code: ADR nennt `--pz-accent-text: var(--color-primary-element-text)`, `css-src/nextnotes-tokens.css` setzt `--color-primary-element-light-text`. Code ist richtig (Upstream nutzt `--pz-accent-text` als Akzent-Textfarbe auf normalem Grund, `tokens.css:252`; `--color-primary-element-text` ist Text AUF Primärfläche, oft weiß). Forderung: Nachtrag in ADR 0016 (Abweichung + Grund).

Bewertung der Abweichungen
1 calendar.js/background-settings.js bis M4 raus: akzeptabel (Grund in bundles.json); Wiederaufnahme als Done-Punkt M4 in STATUS/Plan festhalten; `pageerror`-Prüfung M3a2 deckt Aufrufe aus anderem Upstream-JS ab.
2 Inter: nicht akzeptabel ohne Umschreibung, siehe Blocker 1 (Antwort: ja, umschreiben).
3 index-mobile in `@media (max-width: 800px)`: akzeptabel, semantisch gleich dem `media`-Attribut, Reihenfolge core→mobile→modals bleibt (PageAssets).
4 login/logout → App-Startseite: akzeptabel und besser (kein Logout-CSRF per GET; Abgemeldete leitet NC mit Rücksprung); SPEC §4.2 schon nachgezogen. Plan §2 M3a1 (getLogoutUrl) ist damit überholt.
5 Reset input/button nach M3a2: akzeptabel (leere Seite ohne Formulare, nicht prüfbar).
6 401-Marker ohne Löschen: akzeptabel, sicherer als Plan (Löschen nach Erfolg erlaubt Reload-Schleife bei dauerhaft 401 einer einzelnen Route); SPEC §4.3.7 passt, Plan §1.3 „wird gelöscht“ ist überholt. Backend liefert sonst keine 401 (grep lib/).
7 accent-text: fachlich akzeptabel, Doku fehlt (Blocker 2).
8 nginx `absolute_redirect off`: akzeptabel, nur Testsetup, betrifft nur nginx-eigene Redirects.
9 `!/build/`: akzeptabel; node_modules bleibt ignoriert (geprüft mit `git status --ignored`), install-app.sh schließt build/ aus.

Hinweise
- Adapter geprüft: Token nur same-origin + App-Pfad (URL normalisiert `..`), keine fremden Origins, kein offener Redirect, window.open ohne Token, i18n ohne Netz. Unterpfad-Installationen (`/nc/index.php/apps/…`): `appPath` entfernt `/index.php` nur am Anfang → Aufrufe über `/nc/apps/nextnotes/` ohne Token (nur 412, kein Leck).
- `CspFactory::embedDomains`: `getValueArray` wirft bei abweichend gespeichertem Typ (`occ config:app:set` ohne `--type`) → Seite 500; abfangen → leere Liste (spätestens M4).
- `check-upstream` prüft nicht die direkten Asset-Tags in `src/public/index.php:456-475` (jsSingle/cssSingle/Reihenfolge/Media); neues Upstream-Skript dort bliebe unbemerkt → in O8 aufnehmen.
- src/ unverändert, upstream-patches.txt leer, Build deterministisch (sortiert, LF, keine Zeitstempel, esbuild gepinnt, Lockfile mit integrity). OCP stichprobenartig ok: `getLogoutUrl` @since 35, `getValueArray` @since 29, `addAllowedFrameDomain` @since 8.1.
