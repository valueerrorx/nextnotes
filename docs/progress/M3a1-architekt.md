# M3a1 – Architekt (Umsetzung)

Auftrag: M3a1 nach `docs/slices/M3-plan.md` r2 umsetzen (Build/esbuild, bundles.json, check-upstream, Adapter, i18n, Tokens, PageController-Gerüst, run-m3.sh), Done M3a1 selbst prüfen.

Status: fertig

## Erledigt
- Plan/Review gelesen

## Nächster Schritt
- Bestand sichten (nextnotes/, dev/nextcloud/, SPEC-Diff)

## Offen/Annahmen
- Bestand gesichtet: Upstream index_js/index_css/css_assets/dark_mode_css, globals.js/lazy-libs/theme-manager, NC layout.user.php (Theme-Variablen auf body `[data-theme-x]`, nicht :root → Tokens auf `body` deklarieren), Util::addScript/JSResourceLocator
- Befund: base.css/excalidraw.css u. a. `@font-face` Inter (webfonts/) → Build entfernt diese @font-face (ADR 0016, sonst 404); als Abweichung melden
- Befund: index-mobile.css per `@media`-Hülle im Build (addStyle kennt kein media)
- build/: package.json + package-lock.json (esbuild 0.28.2 exakt, `npm install` einmalig mit Netz), bundles.json (generiert aus Upstream-Listen via Scratchpad-Skript, exclude/excludePages mit Grund, adapterHooks), build.mjs, Makefile, upstream-patches.txt (leer), .gitignore
- js-src/nextnotes-bootstrap.js, nextnotes-late.js; css-src/nextnotes-tokens.css (auf body), nextnotes-layout.css
- `make build` Exit 0, 122 Dateien; gzip -9: JS Seite 967 KB (≤1190), CSS 133 KB (≤230)
- Entscheidung: input/button-Reset gegen NC-Kern-CSS erst M3a2 (ohne UI nicht prüfbar; jede Reset-Regel überstimmt auch Upstream) → melden
- Entscheidung: 401-Marker wird nicht bei Erfolg gelöscht (sonst zweiter Reload, wenn Seitenaufbau-Aufrufe gelingen); nur 30-s-Fenster
- check-upstream.sh (+ upstream-lists.php, check-upstream.mjs): `make check-upstream` Exit 0
- PHP: PageController (index lädt Assets via PageAssets, InitialState config; login.php/logout.php → App-Index), Service/View/PageTranslator+PageAssets, Http/CspFactory::forView (+embedDomains); templates/main.php „eigene Vorlage“; routes login.php/logout.php; php -l ok
- Abweichung: logout.php leitet NICHT auf getLogoutUrl (Token-URL hebelt CSRF-Schutz aus) → App-Index; login.php → App-Index (NC leitet Abgemeldete selbst um)
- install-app.sh: schließt build/, Makefile, js-src, css-src aus; Warnung ohne Build
- Befund Browser: calendar.js (api/v1/calendar/*.php) und background-settings.js (api_upload_background.php) laden beim Start M4-Routen → 404; bis M4 in bundles.json exclude (Grund dokumentiert), Aufrufer sind alle guarded
- Probe (Scratchpad probe.mjs, sqlite/Apache, alice de): hell + dunkel alle PASS (200, 19 Assets 200, data-theme, bg = --color-main-background, t() de, kein /system/i18n, 0 ≥400, Theme-Wechsel ohne Reload, KaTeX-Fonts 200, fetch/XHR/window.open + requesttoken, 401 1×/0×, 0 CSP, 0 Fehler)
- Hinweis Tester: networkidle wird wegen NC-firstrunwizard nicht erreicht → firstrunwizard im Test deaktivieren; settings/tasklist_insert_order doppelt (Budget M3a2)
- MINIFY=0-Build: Probe hell alle PASS
- Krit. 5: `make -C nextnotes clean build` 2× (2. unter `unshare -rn`, Netz dort rc=6) → 122 Dateien, sha256 identisch; check-upstream Exit 0; Gegenproben G1 src-Edit, G2 bundles ohne tabs.js, G3 dark-mode/zzz.css, G4 SRC-Kopie mit umbenanntem poznoteEnsureMermaid → je Skript-Exit 1 (über make: 2), danach zurückgesetzt, check-upstream Exit 0
- run-e2e.sh, run-m3.sh, env.sh (bob), README; SPEC §1.1, §1.2.3, §4.2 (login/logout) angepasst
- Nächster Schritt: run-m3.sh (E2E_DIR=Scratchpad-Probe) + run-m2.sh sqlite + run-m1.sh sqlite nginx
- run-m3 sqlite nginx #1: sqlite grün (PHPUnit 225 OK, E2E-Probe hell/dunkel grün); nginx rot: connect-src auf http://localhost/apps/files/ (NC-Core reagiert auf simulierte 401; nginx-Testsetup leitet absolut ohne Host-Port um) → dev/nextcloud/nginx/nextcloud.conf `absolute_redirect off;` → nginx E2E grün
- Nach run #1 geändert: bootstrap rewrite vereinfacht, build.mjs Variable umbenannt → Abschlusslauf nötig
- Abschluss: `E2E_DIR=<Scratchpad>/e2e run-m3.sh sqlite nginx` Exit 0 (Build reproduzierbar offline, check-upstream grün, PHPUnit 225 OK, Probe hell/dunkel grün auf Apache+nginx); `run-m2.sh sqlite` Exit 0; `run-m1.sh sqlite nginx` Exit 0; `down.sh all` Exit 0

## Nächster Schritt
- tester: tests/nextnotes/e2e (harness, assets.mjs) unabhängig; run-e2e.sh braucht Specs dort

## Offen/Annahmen
- input/button-Reset gegen NC-Kern-CSS → M3a2
- make gibt bei rotem check-upstream Exit 2 (make-Konvention), Skript selbst Exit 1
- calendar.js/background-settings.js bis M4 ausgeschlossen

## Nachbesserung r1 (docs/reviews/M3a1-diff-r1.md; keine Container/kein make im Repo, tester parallel)
- Blocker 1: build.mjs `rewriteFontFamily` + bundles.json `fontFamilyRewrite` (Inter → var(--font-face) in font-family/font, auch !important); Build in Scratchpad-Kopie: 0 × „Inter“ in css/, 2 Läufe identische Prüfsumme
- Blocker 2: ADR 0016 Nachtrag (accent-text, Schrift); SPEC §1.2.3 angepasst
- CspFactory::embedDomains fängt AppConfigException → [] + Warnung; php -l ok
- Adapter: appPath entfernt `/index.php/` auch bei Unterpfad-Installationen (Review-Hinweis)
- Plan: §1.3 401-Marker, §2 login/logout, M4-Vormerkung calendar/background, O8 Asset-Tags index.php:456-475
- check-upstream.sh (nur lesend) grün
- Status: fertig (r1)
