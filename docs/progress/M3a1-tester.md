# M3a1 – Tester

Auftrag: M3a1 testen (Plan M3 r2 §2 M3a1, §3, §3.4): reproduzierbarer Build + check-upstream mit Gegenproben, Playwright-Suite tests/nextnotes/e2e (hell/dunkel, CSP, Adapter, i18n, KaTeX, 401), Budget, Regression run-m3/m2/m1.

Status: fertig

## Erledigt
- Plan gelesen

- Krit.5 Build: `make -C nextnotes clean build` rc0, `unshare -rn make … clean build` rc0 (Netz dort curl rc6), 122 Dateien sha256 identisch, keine abs. Pfade in Ausgaben
- check-upstream rc0; Gegenproben je rc1: G1 tabs.js-Edit, G2 bundles ohne js/tabs.js, G3 dark-mode/zzz (Repo + nur SRC-Kopie), G4 SRC-Kopie poznoteEnsureMermaid umbenannt, G5 Template ohne Kopf, G6 gelistet aber unverändert; G7 über make rc2; alles zurückgesetzt, danach rc0
- Dateien: tests/nextnotes/e2e/{lib/harness.mjs,assets.mjs,budget.mjs,.gitignore(out/)}
- Lead-Nachtrag eingegangen (Inter→--font-face, CspFactory, Unterpfad); ab hier Stand „neu“: Build neu, grep Inter css/*.css alle 0
- run-e2e sqlite assets (neu) hell+dunkel grün, 82 Prüfungen je Theme (inkl. Schrift/OpenDyslexic per OCS-Theming-API)
- Harness-Fix: /ocs/…/apps/x/api/v1 nicht als „ohne Präfix“ werten
- budget sqlite hell grün: JS gzip 996,8 KB (1000er-KB), CSS 135,7 KB, API 3 (tasklist_insert_order 2×, als bekannt WARN), TTFB-Median 21 ms, Cache ok; Gegenprobe LIMIT_JS=500 → rc1
- CSP: allowed_iframe_domains=x (ohne --type) → 200, frame-src 'self'; ["www.youtube.com"] array → frame-src 'self' https://www.youtube.com; "https://…"-Einträge werden still verworfen (Info)
- MINIFY=0-Build: assets hell grün; danach normal neu gebaut
- Regression gestartet (Stand neu): run-m3 sqlite nginx → run-m2 sqlite mariadb pgsql → run-m1, Logs im Scratchpad reg-*.log
- Regression (Stand neu): run-m3.sh sqlite nginx rc0 (Build reproduzierbar offline, check-upstream grün, PHPUnit 225 OK, assets+budget hell/dunkel grün auf sqlite und nginx), run-m2.sh sqlite mariadb pgsql rc0 (je 225 OK), run-m1.sh rc0
- Gegenproben G1/G2 auf neuem Stand erneut rc1, zurückgesetzt, check-upstream rc0; down.sh all rc0, 0 Container/Volumes

## Nächster Schritt
- keiner (M3a2: frame/xss/stripped-Specs, Budget mit Seed 1000 Notizen auf index.php?note=)

## Offen/Annahmen
- tasklist_insert_order 2× beim Seitenaufbau: in budget.mjs KNOWN_DUPLICATES (WARN), Lead entscheidet
- X-Requested-With bei XHR doppelt ("XMLHttpRequest, XMLHttpRequest"; Adapter + NC-Kern), funktional ohne Folgen
- allowed_iframe_domains: Einträge mit Schema (https://…) werden still verworfen, nur Hostnamen wirken
- Budget-Seed (1000 Notizen/100 Ordner) und note-Ansicht erst ab M3a2/M3b möglich (NN_BUDGET_PATH)
- KB = 1000 Byte in budget.mjs (Architekt maß KiB: 967)
- OpenDyslexic über OCS-Theming-API statt occ (gleiche Benutzereinstellung)
