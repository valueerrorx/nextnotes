# M3 – Frontend-Grundlage und Editor: Plan

Stand: 2026-11-03, Architekt, r2 (nach Review `docs/reviews/M3-plan-r1.md` und Lead-Entscheidungen, §7). Grundlage: SPEC §1 (1.1, 1.2, 1.4), §4.1–4.6, §10, §11.1, §11.2 (B3, B4, B13, B20, B22), §12 (R3–R6, R11, R13), §13 M3 (Kriterien 1–6); ADR 0004, 0006, 0007, 0012, 0013, 0015, 0016; Inventur B §1.1, §1.6, §2–§5. Backend M2 (`5f98f548`) ist fertig und wird nur ergänzt (Seiten-/Kompatibilitätsrouten, PDF-CSP).
Jede Änderung in `src/` steht in SPEC §11.1 und in `nextnotes/build/upstream-patches.txt`. Vorrang: Adapter und abgeleitete Templates vor Patch.

## 0. Slice-Teilung

| Slice | Inhalt |
|---|---|
| **M3a1** | Build (`make -C nextnotes build`, reproduzierbar, offline), `check-upstream` (Patches/Bundles/Templates/Adapter-Namen), Adapter `nextnotes-bootstrap.js` + `nextnotes-late.js`, i18n, Theme-Tokens (`nextnotes-tokens.css`, `nextnotes-layout.css`), PageController-Gerüst mit Asset-Laden auf der bisherigen leeren Seite, Testsetup M3 (`run-m3.sh`, Playwright-Grundgerüst) |
| **M3a2** | Hauptansicht `index.php` als Rahmen (Icon-Leiste, Workspace-Wahl, Ordnerbaum, Notizliste, leere rechte Spalte), Ausgaberegel §1.2, gestrichene Elemente und Links auf M4-Seiten/Exporte in den Templates weggelassen, Navigation |
| **M3b** | Notizansicht/Editor (`note_display`, HTML- und Markdown-Notiz), Fragment-Modus `index.php` (`X-Poznote-Fragment`), Suche (POST `index.php`), benötigte Modals, Anhänge im Editor inkl. Bild/Audio (`audio_player.php`) und PDF-Vorschau, Mermaid/KaTeX, Autosave + Beacon (B4), Sperre im Frontend (SPEC B13), Tag-Escaping, CSP-Patches P1–P3 |
| **M3c** | Seiten- und Kompatibilitätsrouten aus §13 M3.6 (`revisions.php`, `attachments.php`, `kanban_content.php`, `excalidraw_editor.php`, `workspaces.php` POST, `api_note_info.php`, `api_save_excalidraw.php`, `api_download_note.php`, `api_export_note.php`, `api_export_folder.php`, `api_attachments.php` Alt-URL) mit zentraler Rechteprüfung; Fremdzugriffstest; Abschlusslauf M3 |

Jede Slice ist für sich lauffähig (`run-m3.sh` grün) und wird einzeln committet (Schätzung: M3a1 ≈ 5 PT, M3a2 ≈ 5 PT).

**Zuordnung SPEC §13 M3 → Slice**
| Krit. | Inhalt | M3a1 | M3a2 | M3b | M3c |
|---|---|---|---|---|---|
| 1 | Notiz anlegen/Text/Reload, Bild, Mermaid, KaTeX | – | – | ✔ | Regression |
| 2 | Hell und Dunkel, Hintergrund = `--color-main-background` | leere Seite | Rahmen | Szenario vollständig | Regression |
| 3 | 0 CSP-Verletzungen, 0 × 404 ohne App-Präfix | leere Seite | Rahmen | Editor-Szenario | neue Seiten |
| 4 | Inhalt beim Schließen gespeichert, Beacon mit `requesttoken`, 200 | – | – | ✔ | – |
| 5 | Build reproduzierbar, `src/`-Diff ⊆ §11.1 | ✔ | Wiederholung | Wiederholung | Wiederholung |
| 6 | Fremdzugriff Seiten-/Kompatibilitätsrouten | – | `index.php?note=` (Seite) | Fragment-Modus, Suche | alle übrigen ✔ |
| XSS | Ausgaberegel, DOM-Injektionstest (§1.2) | – | ✔ | ✔ (Notizansicht) | ✔ (neue Seiten) |
| Gestrichen | nicht sichtbar, 0 `pageerror`/Konsolenfehler | – | Hauptansicht | Notizansicht mit Menüs | – |
| Perf | Bundle-Budget, Requests beim Seitenaufbau (§3.4) | JS/CSS-Größe | ✔ | ✔ | – |

## 1. Gemeinsame Architektur (alle Slices)

### 1.1 Build (`nextnotes/Makefile`, `nextnotes/build/`)
- Werkzeug: Node (≥ 20; getestet 24) mit **einer** Build-Abhängigkeit `esbuild` (exakt gepinnt) in `nextnotes/build/package.json` + eingechecktem `package-lock.json`; `node_modules` unter `nextnotes/build/` (von `make dist` ohnehin ausgeschlossen, von `install-app.sh` ausgeschlossen). Netz nur für `npm ci --prefix nextnotes/build` (einmalig, freigegeben). `make build` bricht mit klarer Meldung ab, wenn `build/node_modules` fehlt; es ruft nie `npm install`.
- `nextnotes/build/bundles.json` (eingecheckt, gepflegte Quelle): Gruppen `head`, `app` (Reihenfolge = `poznoteGetIndexJsGroups()`), CSS-Gruppen `core`, `modals` (= `poznoteGetIndexCssGroups()`), `dark` (`css/dark-mode/*.css`, sortiert wie `dark_mode_css.php`), Einzeldateien je Seite (`codemirror`, `highlight`, `syntax-highlight`, `lazy-libs`, `katex.css`, `index-mobile.css` …), Liste `exclude` (gestrichen: `offline-*`, `pwa-helpers.js`, `pwa/*`, `sw.js`, `two-factor.js`, `login-page.js`, `ai-*.js`, `speech-to-text.js`, `backup-*.js`, `restore-import.js`, `git-*.js`, `icon-sidebar-git.js`, `other-accounts.js`, `session-guard.js`, `theme-init.js`, `css/fonts.css`, `custom-css.css`, `ai-chat.css`, `speech-to-text.css` …; jede Zeile mit Grund).
- `build/build.mjs` (nur Node-Bordmittel + esbuild):
  1. JS-Gruppen konkatenieren wie `index_js.php` (Kopf `/* --- <datei> --- */`, Trenner `\n;\n`), dann esbuild `transform` mit `minifyWhitespace` + `minifySyntax`, **ohne** `minifyIdentifiers` (Globals bleiben), `legalComments: 'inline'`, kein `format` (klassisches Skript) → `js/nextnotes-head.js`, `js/nextnotes-app.js`; `MINIFY=0` schaltet für Fehlersuche ab. Endung immer `.js` (ADR 0006).
  2. CSS-Gruppen konkatenieren mit `url()`-Umschreibung wie `poznoteRewriteCssUrls` (relativ zur Ausgabedatei in `nextnotes/css/`), esbuild-CSS-Minify → `css/nextnotes-core.css`, `nextnotes-modals.css`, `nextnotes-dark.css`; `css-src/nextnotes-tokens.css` und `css-src/nextnotes-layout.css` unverändert danach.
  3. Vendor unverändert: Skripte nach `js/vendor/` (`codemirror-dist`, `excalidraw-dist` + Assets, `mermaid`, `katex`, `highlight`, `Sortable.min.js`), Stylesheets nach `css/vendor/` (`katex.min.css` mit Fonts in `css/vendor/katex/fonts/`, `url()` umgeschrieben). Grund: `Util::addScript` lädt nur aus `js/`, `addStyle` nur aus `css/` (`Util.php:135`). Kein Verzeichnis `vendor-assets/` (SPEC §1.1/§1.2 angepasst).
  4. i18n: `src/i18n/*.json` → `i18n/<lang>.json` (für `/system/i18n`, Plan M2 O4) **und** `js/i18n/nextnotes-i18n-<lang>.js` = `window.__NEXTNOTES_I18N__={lang,strings}` mit `en` als Rückfall rekursiv eingemischt (wie `SystemApiController::i18n`); siehe O1.
  5. `js-src/*.js` → `js/` (unverändert kopiert, ebenfalls Whitespace-Minify).
  6. Deterministisch: Dateiliste sortiert bzw. aus `bundles.json`, keine Zeitstempel/absoluten Pfade in Ausgaben, `JSON.stringify` mit Quellreihenfolge, LF. Ausgabeverzeichnisse werden vorher geleert.
- `Makefile`: `build`, `check-upstream`, `clean`. `dist` folgt in M7.
- `build/check-upstream.sh` (M3-Teil von ADR 0007): (a) `git diff --name-only <Upstream-Basis aus UPSTREAM.md> -- src/` = `build/upstream-patches.txt` (beide Richtungen); (b) Host-`php` (Voraussetzung, wie Schematest M1; README nennt sie) liest `poznoteGetIndexJsGroups()`, `poznoteGetIndexCssGroups()`, `poznoteCssManifest()` (`src/css_assets.php`) und die Dateiliste `src/public/css/dark-mode/*.css` (Reihenfolge wie `dark_mode_css.php`) und vergleicht mit `bundles.json` (+`exclude`); (c) jede Datei in `templates/` hat Kopfkommentar `abgeleitet von <pfad> @ <commit>`, Pfad existiert; (d) Upstream-Namen, an denen die Adapter hängen (`poznoteAssetUrl`, `poznoteEnsureMermaid`, `poznoteEnsureKatex`, `poznoteEnsureScript`, `__poznoteThemeStorage`, `__poznoteThemeList`, `api/v1/system/i18n`, `window.t && window.loadPoznoteI18n`), per `grep` in `src/public/js` vorhanden. Rest von ADR 0007 → O8.

### 1.2 Seiten (PageController, Templates)
- Routen in `appinfo/routes.php` mit Upstream-Dateinamen (ADR 0004): `GET /`, `GET /index.php`, `POST /index.php` (Suche, CSRF aktiv; Formular `#unified-search-form` erhält verstecktes Feld `requesttoken` = `$_['requesttoken']`, weil `unified-search.js:1097/1100` auf natives `form.submit()` zurückfällt, Blocker r1-3), M3c-Routen (§2). `GET` Seiten: `#[NoAdminRequired]`, `#[NoCSRFRequired]`, ohne Seiteneffekt. Spike-Route `dashboard.php` bleibt bis M4 (leere Seite).
- `lib/Controller/PageController.php` dünn; Daten aus `lib/Service/View/*ViewService` (z. B. `IndexViewService`: Workspaces mit Schlüssel §4.4, Ordnerbaum, Notizliste in Benutzersortierung, aktuelle Notiz über `NoteSerializer` + `ContentUrlRewriter::toClient`, Einstellungen aus `SettingsService` mit Upstream-Defaults, Tags, Mention-Mitglieder). Zugriff nur über `AccessService` (`requireNote`/`visibleWorkspaceIds`), wie M2.
- Fremde/unbekannte Notiz in `?note=` → NC-404-Seite (`TemplateResponse('core', '404', [], 'guest')`, Status 404), kein Inhalt. Unbekannter `?workspace=`-Schlüssel → Standard-Workspace des Benutzers (O3, SPEC §4.4 Nachtrag „Seiten“; kein Leck, `resolveKey` löst nur sichtbare Workspaces auf).
- Templates `templates/index.php`, `templates/partials/{icon_sidebar,notes_list,folders_display,note_display,modals}.php` abgeleitet (Kopfkommentar; `partials/` statt `parts/`, weil die Upstream-`.gitignore` `parts/` ignoriert). Änderungen gegenüber Upstream nur: Datenquelle (`$_[…]` statt `$con`), Inline-Skripte → InitialState/JSON-Inseln, `on…=` → `data-action`, root-absolute `/api/v1` → `$_['apiBase']`, gestrichene Teile (Login/Logout, Konten, AI, Teilen-Links, PWA/Offline, Backup, Themes/Schriften, Git-Sync, What's-new, Poznote-GitHub/-Doku/-Spenden-Links) **weggelassen** statt versteckt; Links auf noch nicht portierte Seiten (M4) und Export-Menüpunkte (501 bis M4) weggelassen bis zur Portierung (O2, O5). Menüeinträge, die Upstream-JS erzeugt (z. B. `utils-menus.js:55-75` Teilen/Offline, `.share-state-*`, `.offline-state-*`), werden über `__POZNOTE_HIDDEN_UI_ELEMENTS__` bzw. CSS in `nextnotes-layout.css` ausgeblendet; Upstream-JS, das auf weggelassene Elemente zugreift, darf keinen Fehler werfen (sonst Platzhalter-Element mit `hidden` im Template). Marke: „Next Notes“ + App-Icon statt Poznote-Logo, Herkunft „Basiert auf Poznote“ im Über-Dialog (O6, vorläufig).
- **Ausgaberegel (Blocker r1-1)**: jede Ausgabe aus `$_` in Templates mit `p()` (NC) bzw. `$tr->h()`; `print_unescaped` nur für (a) Notizinhalt, der beim Speichern durch `HtmlSanitizer` lief und beim Ausliefern nur durch `ContentUrlRewriter::toClient` geht, (b) JSON-Inseln/InitialState mit `json_encode(…, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)`, (c) feste Markup-Bausteine ohne Benutzerdaten. Benutzerdaten nie in `data-action`, `style`, `on…`, `href`/`src` ohne Schema-Prüfung. `InputValidator::heading` erlaubt `<`, `>`, `"` in Titeln – deshalb ist die Regel Pflicht, nicht die Eingabeprüfung. Grep-Prüfung im Test: `print_unescaped` in `templates/` nur an gelisteten Stellen (Allowlist mit Begründung).
- Übersetzung in Templates: `lib/Service/View/PageTranslator` (liest `i18n/<lang>.json` + `en`, Sprachabbildung §1.4) als `$_['tr']` mit `t()`/`h()` (escaped); ersetzt Upstream `t()`/`t_h()` 1:1.
- Assets je Seite in fester Reihenfolge (`Util::addScript`/`addStyle`, alle `defer`): `i18n/nextnotes-i18n-<lang>` → `nextnotes-bootstrap` → `nextnotes-head` → `vendor/codemirror-dist/…`/`lazy-libs`/`vendor/highlight/…`/`syntax-highlight` (wie `index.php:467-475`) → `nextnotes-app` → `nextnotes-late` → Seitenskript. CSS: `core`, `index-mobile` (Media-Query über `nextnotes-layout.css`), `modals`, `dark`, `vendor/katex.min`, `syntax-highlight`, dann `nextnotes-tokens`, `nextnotes-layout`.
- InitialState `nextnotes/config` (§4.1) + `assetBase` (= `IAppManager::getAppWebPath('nextnotes')`), `lang`, `editorSettings`; seitenspezifische Daten als eigener Key oder JSON-Insel wie Upstream (`page-config-data`, `current-note-data`, …).
- CSP über `CspFactory::forView(string $view)`: Ansichten mit Notizanzeige (`index`, `revisions`, `kanban_content`) `frame-src 'self'` + Admin-Embed-Domains (`IAppConfig` `allowed_iframe_domains`, Default leer; ADR 0012), sonst NC-Standard. Keine weitere Ausnahme in M3.

### 1.3 Adapter (`js-src/`)
`nextnotes-bootstrap.js` (vor allen Upstream-Skripten) setzt SPEC §4.3 um, ergänzt um:
- **URL**: Präfix für `/api/v1/…`, `api/v1/…`, `/audio_player.php` in `fetch`, `XMLHttpRequest.open`, `navigator.sendBeacon`, `window.open`; `fetch`-Optionen (`keepalive`, `credentials`, Body) bleiben unverändert. `window.NEXTNOTES.url(path)` als öffentliche Hilfe (für P5, falls nötig).
- **CSRF**: Header `requesttoken` (aktueller Wert aus `document.head.dataset.requesttoken`, nach Token-Erneuerung durch NC `OC.requestToken`) + `X-Requested-With` für same-origin-App-Aufrufe; Beacon: `FormData` → Feld `requesttoken`, sonst `?requesttoken=` (B4).
- **401** von App-Aufrufen → `location.reload()` (NC leitet auf Login mit Rücksprung) – ersetzt `session-guard.js` (aus dem Build ausgeschlossen). Schleifenschutz: Marker mit Zeitstempel in `sessionStorage`; liegt der letzte 401-Reload < 30 s zurück, kein weiterer Reload, sondern Upstream-Fehlermeldung (`showNotificationPopup`); der Marker wird nicht bei Erfolg gelöscht, nur das 30-s-Fenster zählt (sonst Reload-Schleife bei dauerhaftem 401 einer einzelnen Route; Umsetzung M3a1). Routen `login.php`/`logout.php` (§4.2) als Redirect bleiben Sicherheitsnetz (M3a1).
- **i18n**: `fetch` auf `…/system/i18n` wird mit dem vorgeladenen `window.__NEXTNOTES_I18N__` als synthetische `Response` beantwortet (kein Netz-Request; `globals.js` bleibt unverändert und feuert `poznote:i18n:loaded` wie Upstream).
- **Hell/Dunkel** (ADR 0016): Modus aus `body[data-themes]`/`data-theme-*` (`dark`, `dark-highcontrast` → dunkel; `default` → `prefers-color-scheme`), setzt `html[data-theme]`; `window.__poznoteThemeStorage` (get = NC-Modus, set = nichts) und `window.__poznoteThemeList` (nur `light`/`dark`), damit `theme-manager.js` unverändert den NC-Modus anwendet; beobachtet Body-Attribute und `prefers-color-scheme` und ruft `applyTheme`. Gegen Flackern (R4): `nextnotes-layout.css` blendet `#app-content` per `visibility:hidden` aus, bis der Adapter `html.nextnotes-ready` setzt (Rückfall-Timeout 2 s).
- **MutationObserver** für `src`/`href` (§4.3.4); ob er 404-Vorabladungen verhindert, misst M3b (sonst P5).
- **Editor-Sitzung** (O7, B13): beim Laden erzeugt der Adapter einen neuen Wert für den `sessionStorage`-Schlüssel von `note-edit-lock.js`, außer bei `performance.getEntriesByType('navigation')[0].type === 'reload'`; damit gelten duplizierte Tabs und per `window.open` geöffnete Tabs als eigene Sitzung (409/Banner statt stillem Überschreiben). Gegenstück: die alte Sperre gibt `pagehide` frei (Upstream).
- **Frühes Speichern** (R7): bei `visibilitychange` → `hidden` ruft der Adapter das Upstream-Speichern (`saveNoteToServer` bzw. Autosave-Trigger, Name in M3b festgelegt und in `check-upstream` (d) aufgenommen) auf, solange die Seite noch voll arbeitet; damit hängt das Schließen nicht an der 64-KiB-Grenze von `keepalive`/`sendBeacon`.

`nextnotes-late.js` (nach `nextnotes-app.js`, vor `DOMContentLoaded`): überschreibt `window.poznoteAssetUrl` (Basis `assetBase` + `?v=`) sowie `poznoteEnsureScript`/`poznoteEnsureMermaid`/`poznoteEnsureKatex` (Pfade `js/vendor/…`), weil `globals.js` bzw. `lazy-libs.js` sie seitenrelativ setzen. Kein Patch in `src/`; Regressionsschutz über Krit. 1 (Mermaid/KaTeX gerendert).

### 1.4 Hell/Dunkel und Layout (`css-src/`)
- `nextnotes-tokens.css` nach ADR 0016 (alle `--pz-*` aus Inventur B §5 auf NC-Variablen, RGB-Tokens per `color-mix()`; Dunkelwerte gelten automatisch, weil die NC-Variablen thematisiert sind; `html[data-theme='dark']`-Selektoren der Upstream-CSS bleiben wirksam). Schrift `var(--font-face)`.
- `nextnotes-layout.css`: Upstream-Vollbildlayout (`100vh`, `position: fixed`) in `#content` des NC-Rahmens einpassen (Höhe `calc(100vh - var(--header-height))`), Kollisionen mit NC-Kern-Styles für `input`/`button`/`select` innerhalb `#nextnotes-root` zurücksetzen, mobile Breakpoint wie `index-mobile.css`.

### 1.5 Upstream-Patches in M3 (SPEC §11.1)
| # | Slice | Datei | Änderung |
|---|---|---|---|
| P1 | M3b | `events-drag-drop.js:181` | `new Function` → `data-action`-Registry (B3) |
| P2 | M3b | 7 Dateien aus §11.1 | `onclick="…"` in erzeugtem HTML → `data-action` + Delegation |
| P3 | M3b | `tasklist-render.js:19` u. a. | `action="javascript:void(0)"` → Listener mit `preventDefault` |
| P4 | M3a2 | `index-events.js` | entfällt, wenn der Auslöser `navigate-to-profile` im Template fehlt (Vorschlag); sonst Patch wie §11.1 |
| P5 | M3b (bedingt) | §11.1 | nur bei gemessenen 404 trotz MutationObserver |
| P7 (neu) | M3b | `note-edit-lock.js:309` | `isLockHeldByDifferentUser` → „von anderer Editor-Sitzung gehalten“ (`!holder_is_current_editor_session`); Banner, Nur-Lesen und Übernahme-Hinweis auch beim gleichen Konto im zweiten Tab (SPEC B13; Plan-M2 R11). Upstream-PR-Kandidat: nein (folgt aus ADR 0005) |
| P8 (neu, bedingt) | M3a2 | `notes_list.php` (Template) + `unified-search.js`, `note-loader.js`, `notes-list-events.js`, `search-highlight.js`, `keyboard-shortcuts.js`, `tabs.js`, `syntax-highlight.js` | ID `unified-search` kollidiert mit `#unified-search` im NC-Header (`layout.user.php:76`); erst nach Bestätigung im Browser (M3a2-Smoke) ändern: ID → `pz-unified-search`; vorher kein Eintrag in §11.1. Alternative ohne Patch gibt es nicht sauber |
| P9 (neu, M3b1) | M3b | `note-loader.js:818` | `updateBrowserUrl` löst gegen `location.href` statt `location.origin` auf (B31) |
| P10 (neu, M3b1) | M3b | `notes.js` `saveNoteToServer` | weicht der Bildschirm bei Eingang der Antwort vom gesendeten Stand ab, wird der gesendete Stand Vergleichsbasis und erneut gespeichert (B32) |
| P11 (neu, M3b1) | M3b | `note-edit-lock.js` `handleCurrentNoteLoaded` | Notiz ohne Schreibrecht (`data-nextnotes-readonly`): kein Lock-Request, Nur-Lesen ohne Banner/403 |
Ziel ≤ 10 Einträge nach M3. M3b ist geteilt in M3b1 (Editor, Fragment, Beacon/B30, Sperre/P7, B26, „+“-Menü, Verschieben-Dialoge, Tags) und M3b2 (Anhänge, PDF, Mermaid/KaTeX, Suche, P1–P3). B30 (M3b1): statt eigenem frühem Speichern stuft der Adapter `keepalive` ≥ 64 KiB ab (SPEC §4.3.8).

## 2. Slices

### M3a1 – Build, check-upstream, Adapter, i18n, Theme-Tokens

**App**
- `Makefile`, `build/{package.json,package-lock.json,bundles.json,build.mjs,check-upstream.sh,upstream-patches.txt}` (§1.1); `.gitignore` um `build/node_modules/` ergänzen.
- `js-src/nextnotes-bootstrap.js`, `js-src/nextnotes-late.js`; `css-src/nextnotes-tokens.css`, `css-src/nextnotes-layout.css`.
- `lib/Controller/PageController.php` (bisherige leere Seite lädt alle Assets der Hauptansicht über `PageAssets`; `login.php`/`logout.php` leiten auf die App-Startseite (Abgemeldete leitet NC selbst mit Rücksprung zum Login; keine Weiterleitung auf die Logout-URL mit Token, sonst Logout-CSRF per GET; Umsetzung M3a1, SPEC §4.2)), `lib/Service/View/{PageTranslator,PageAssets}.php`, `lib/Http/CspFactory.php` (`forView`).
- `dev/nextcloud/install-app.sh`: `--exclude=build/node_modules --exclude=js-src --exclude=css-src`; `dev/nextcloud/README.md`: Host-`php` und Node für Build/`check-upstream`.

**Done M3a1**
1. Krit. 5: `make -C nextnotes clean build` zweimal → `find nextnotes/{js,css,i18n} -type f | sort | xargs sha256sum` identisch; zweiter Lauf unter `unshare -rn` (kein Netz) Exit 0; `make -C nextnotes check-upstream` Exit 0; Gegenproben je Exit 1 (danach zurücksetzen): Zeile in nicht gelisteter `src/`-Datei geändert; `bundles.json` ohne eine Upstream-Datei; Datei in `css/dark-mode/` ergänzt; Adapter-Name aus (d) in einer Kopie umbenannt.
2. Asset-Laden: leere Seite angemeldet 200; alle per `addScript`/`addStyle` eingebundenen Dateien 200 (auch KaTeX-Fonts per `url()` beim Rendern einer Probeformel im Test); `MINIFY=0`-Build lädt ebenfalls fehlerfrei (R4).
3. Krit. 2/3 (leere Seite), Playwright in NC-Hell und NC-Dunkel (`occ user:setting alice theming enabled-themes '["light"]'` bzw. `'["dark"]'`): `getComputedStyle(#app-content).backgroundColor` = aufgelöster Wert von `var(--color-main-background)` (Probe-Element); `html[data-theme]` = `light`/`dark`; Theme-Wechsel ohne Reload (Body-Attribut im Test geändert) wird übernommen; 0 `securitypolicyviolation`; 0 `pageerror`, 0 Konsolenfehler; 0 Antworten ≥ 400 auf same-origin-Requests; kein Request auf `/system/i18n`; `window.t('…')` liefert deutschen Text bei NC-Sprache `de`.
4. Adapter: `fetch`/XHR auf `/api/v1/notes` und `api/v1/notes` → Request auf App-Pfad mit Header `requesttoken`; `window.open('/api/v1/…')` → App-Pfad; 401-Simulation (Route im Test) → genau ein Reload, zweiter 401 innerhalb 30 s → kein Reload.
5. Performance: JS-/CSS-Größe nach §3.4 grün.
6. Regression: `run-m2.sh` (sqlite) und `run-m1.sh sqlite nginx` Exit 0.

### M3a2 – Hauptansicht-Rahmen, Gestrichenes, Navigation

**App**
- `PageController::index` (GET) mit `lib/Service/View/IndexViewService.php`; `templates/index.php`, `templates/partials/{icon_sidebar,notes_list,folders_display}.php` (Rahmen; rechte Spalte leer mit Upstream-Leerzustand), Ausgaberegel §1.2.
- Ausblendliste für JS-erzeugte gestrichene Einträge (`__POZNOTE_HIDDEN_UI_ELEMENTS__`/CSS); P4-Entscheidung; P8 nach Browser-Befund.
- SPEC: §11.1 (P8 nur nach Bestätigung, Status P4).

**Done M3a2**
1. Seite: angemeldet `GET /index.php/apps/nextnotes/` und `/apps/nextnotes/index.php` → 200 mit `#nextnotes-root`, Icon-Leiste, Workspace-Name „Notizen“, Ordnerbaum und Notizliste mit per API angelegten Testnotizen; Klick auf Ordner/Workspace-Wechsel navigiert ohne Fehler; abgemeldet → Redirect Login.
2. Krit. 2/3 (Rahmen) wie M3a1.3 auf der Hauptansicht, inkl. 0 `pageerror`/Konsolenfehler.
3. Gestrichenes nicht sichtbar: im DOM der Hauptansicht (auch nach Öffnen von Icon-Leisten-Menü, Ordner-Aktionsmenü, Workspace-Menü) keine sichtbaren Elemente zu Login/Logout, Konten, AI, Teilen-Link, PWA/Offline, Backup, Theme-/Schriftwahl, Git-Sync, Export, Poznote-GitHub/-Doku/-Spenden, noch nicht portierten Seiten (Selektor-/Textliste im Test, abgeleitet aus Upstream-`icon_sidebar.php`, `notes_list.php`, `utils-menus.js`).
4. XSS (Blocker r1-1): per API Notiztitel, Ordnername, Workspace-Name (Workspace nur im Rahmen der Namensregel, ansonsten Titel/Ordner) `"><img src=x data-action=delete-note>` bzw. `</script><b>x` → Hauptansicht: Anzahl `img`, `b`, `[data-action]` gleich wie mit harmlosem Namen, Text sichtbar als Text; JSON-Inseln parsebar (`JSON.parse`), 0 Konsolenfehler; Grep-Allowlist `print_unescaped` grün.
5. Fremdzugriff Seite: B öffnet `index.php?note=<A-Notiz>` → 404, kein A-Titel/Inhalt im Body; `index.php?workspace=<A-Schlüssel>` → 200 mit B's Standard-Workspace, kein A-Marker.
6. Performance-Budget §3.4 (Rahmen) grün; `check-upstream` Exit 0; Regression M3a1.

### M3b – Editor, Notizansicht, Beacon, Sperre, Anhänge

**App**
- `templates/partials/{note_display,modals}.php` (nur Modals, die Hauptansicht und Editor brauchen; Rest M4), `PageController::index` Fragment-Modus (Header `X-Poznote-Fragment: right_col` → `RENDER_AS_BLANK`, nur `#right_col`), `POST /index.php` (Suche wie `search_handler.php`, über `SearchService`).
- `audio_player.php` (PageController, `RENDER_AS_BLANK`, `?note_id&attachment_id` mit `requireNote(read)`).
- `AttachmentsApiController`: PDF-Antwort erhält `EmptyContentSecurityPolicy` mit `addAllowedFrameAncestorDomain("'self'")` (heute `frame-ancestors 'none'` → PDF-Vorschau im iFrame der Notiz blockiert); ADR 0012 Nachtrag und SPEC B22 bereits in r2 eingetragen (§7).
- Patches P1, P2, P3, P7 (+ P5 bei Bedarf) in `src/public/js/`, je mit §11.1-Eintrag und `upstream-patches.txt`.
- Tag-Escaping: Tags mit `'` (B20 erlaubt) in Liste, Unterzeile, Tag-Modal, Filter; Fundstellen, die Tags in Attribut-/JS-Strings ohne Escaping setzen, werden im Template korrigiert, in JS als Patch (§11.1) – Umfang nach Befund, Meldung an Lead wenn > 3 Dateien.
- Frühes Speichern bei `visibilitychange` im Adapter (§1.3, R7).
- `HtmlSanitizer` (Befund r2): `button` mit `data-action` ist in Notizinhalten erlaubt (wie Upstream `html-sanitize.php:283`, für Excalidraw-Knöpfe) → eingeschleuste Knöpfe könnten über die Delegation beliebige UI-Aktionen im Recht des Lesers auslösen (geteilte Workspaces). Fix: `data-action` in Inhalten nur mit Werten, die Upstream selbst in Inhalte schreibt (Liste in M3b aus `excalidraw*.js` ermittelt), sonst Attribut entfernt; SPEC §11.2 B26 .

**Done M3b**
0. Gestrichenes und Fehlerfreiheit (Blocker r1-2): Notizansicht mit geöffnetem Notiz-Aktionsmenü (`utils-menus.js`), Titelmenü, Toolbar inkl. Überlauf, Slash-Menü, Anhang-Menü → keine sichtbaren Einträge aus der Liste M3a2.3 (u. a. Teilen, Offline, AI, Spracheingabe, Export); 0 `pageerror`, 0 Konsolenfehler im gesamten M3b-Szenario.
1. Krit. 1 (Playwright, `tests/nextnotes/e2e/editor.mjs`): neue HTML-Notiz über die UI, Text tippen, Autosave abwarten, `reload` → Text da; Bild per Upload in die Notiz → `<img>` geladen (`naturalWidth > 0`, URL mit App-Präfix); neue Markdown-Notiz mit ```` ```mermaid ```` → `svg` im Vorschaubereich; `$$E=mc^2$$` → `.katex` vorhanden; Mermaid/KaTeX erst bei Bedarf geladen (vorher kein Request auf `js/vendor/mermaid`).
2. Krit. 2/3: Szenario aus 1 in Hell und Dunkel; 0 CSP-Verletzungen; 0 Antworten 404 auf Pfade `/api/v1/…`, `/audio_player.php` ohne App-Präfix (Request-Log), insgesamt 0 same-origin ≥ 400 außer erwarteten (Liste im Test, z. B. 409 im Sperrtest).
3. Krit. 4 (SPEC §13 M3.4 präzisiert): (a) ungespeicherte Änderung, `page.close({runBeforeUnload:true})` → Inhalt per API in neuer Sitzung gespeichert; (a2) dasselbe mit Notiz > 64 KiB Inhalt: Inhalt gespeichert (über frühes Speichern bei `visibilitychange`), Ergebnis und Weg im Testlog; schlägt (a2) ohne das frühe Speichern fehl, SPEC §11.2 B30 bestätigen (sonst streichen); (b) Notfallpfad erzwungen (`fetch` mit `keepalive` im Test zum Werfen gebracht), `emergencySave` → Request `POST …/api/v1/notes/<id>/beacon` mit Multipart-Feld `requesttoken` = aktuellem Token, Antwort 200, Inhalt gespeichert; (c) Gegenprobe ohne Token (curl mit Sitzungs-Cookie) → 412.
4. Sperre (SPEC B13): Tab 1 öffnet Notiz und hält Sperre; Tab 2 (gleiches Konto, neue Seite) zeigt Sperr-Banner, Editor nicht editierbar, Tippen erzeugt keinen `PATCH`/423; Tab 1 schließen → Tab 2 erhält die Sperre (Test wartet auf `release` beim `pagehide`, nicht auf TTL). **Duplizierter Tab** (O7): Tab 1 öffnet per `window.open(location.href)` eine Kopie (übernimmt `sessionStorage`) → Kopie zeigt Banner/Nur-Lesen; Text aus Tab 1 nach Tippen in beiden Tabs und Reload unverändert vorhanden (kein Datenverlust); Reload von Tab 1 behält seine Sperre.
5. Tags: Tag `l'été` und `O'Neil` per API → Anzeige korrekt, Klick filtert, keine Konsolenfehler, kein zusätzliches Attribut im DOM.
6. PDF: PDF-Anhang mit Einstellung `attachment_previews_in_note=1` → iFrame lädt (Antwort 200, kein `frame-ancestors`-Verstoß); PHPUnit: PDF-Antwort enthält `frame-ancestors 'self'`, andere Typen weiter `sandbox`.
7. Fragment-Modus: B ruft `index.php?note=<A>` mit `X-Poznote-Fragment: right_col` → 404, kein A-Marker. Suche (POST `index.php`) ohne `requesttoken` → 412; mit → Treffer nur eigener Notizen; **nativer Submit** (Blocker r1-3): im Browser `document.getElementById('unified-search-form').submit()` (bzw. umbenannte ID nach P8) → 200, Trefferliste, Feld `requesttoken` im Formular-Body.
7a. XSS Notizansicht: Titel, Tags (`'` erlaubt) und Ordnername aus M3a2.4 in Notizkopf, Unterzeile, Tabs, Notiz-Info, Tag-Modal → kein zusätzliches Element/Attribut, 0 Konsolenfehler. Notizinhalt per API mit `<img src=x onerror=…>`, `<a href="javascript:…">`, `<button data-action="delete-note">` gespeichert → Seite rendert ihn ohne Handler/`javascript:`; `data-action` in Inhalten nur mit Werten aus der Allowlist (s. u.), Klick auf den eingeschleusten Knopf löst keine Aktion aus.
8. `check-upstream` Exit 0 (Patches gelistet); Krit. 5 wiederholt; Budget §3.4 (Editor) grün; Regression M3a1/M3a2-Tests.

### M3c – Seiten- und Kompatibilitätsrouten, Fremdzugriff

**App**
- `PageController`: `revisions.php`, `attachments.php`, `kanban_content.php` (`?ajax=1` → `RENDER_AS_BLANK`), `excalidraw_editor.php` (CSP ohne Ausnahmen; Worker/WASM erst nach M4-Test, ADR 0012); Templates abgeleitet; jede Route beginnt mit `requireNote`/`requireFolder` (`read`), unbekannt oder fremd → 404.
- `lib/Controller/CompatController.php`: `workspaces.php` POST (Upstream-Aktionen → `WorkspaceService`, `manage`), `api_note_info.php` (JSON wie Upstream), `api_save_excalidraw.php` (`write`; speichern über `NoteService`/`AttachmentService`), `api_download_note.php` (gespeicherte Form als Datei, `#[NoCSRFRequired]`, GET ohne Seiteneffekt), `api_export_note.php`/`api_export_folder.php` (Rechteprüfung, dann 501 `not_implemented` bis ExportService M4, O2), `api_attachments.php?action=download` (Alt-URL §10 → gleiche Auslieferung wie Anhang-GET). CSRF aktiv auf allen POST; `#[NoCSRFRequired]` nur auf GET-Downloads (§4.5); `ControllerAttributeTest`-Allowlist entsprechend erweitert.
- Nur Routen, die in §13 M3.6 stehen oder von M3-Seiten verlinkt werden; übrige Seiten (Dashboard, Diary, …) M4. Export-Menüpunkte bleiben bis M4 ausgeblendet (O2). Ausgaberegel §1.2 gilt für alle neuen Templates.

**Done M3c**
1. Krit. 6 (`tests/nextnotes/php/Access/PageIsolationTest.php`, Muster wie `IsolationTest` M2): für jede Route aus M3.6 + `audio_player.php` + `api_attachments.php` je Variante „alle IDs von A“ und je ID-Stelle (Query/Form: `note`, `note_id`, `id`, `folder_id`, `workspace`, `attachment_id`, `diagram_id`) „nur diese von A“: B → 404, kein A-Marker im Body; Kontrollanfrage A → 2xx bzw. 501 bei Export; A-Zustand vorher = nachher; Detektor-Selbsttest.
1a. **Lesefreigabe (Blocker r1-4, Pflichtfall)**: A teilt den Workspace mit B lesend (`ws_shares` `permission=read`, Testhilfe aus `ApiTestCase`) → B: `api_save_excalidraw.php` (Speichern einer A-Notiz), schreibende `workspaces.php`-Aktionen (umbenennen, löschen, Farbe, Reihenfolge, Teilen) → 403, A-Zustand unverändert; Lese-Seiten `index.php?note=`, Fragment-Modus, `revisions.php`, `attachments.php`, `kanban_content.php`, `excalidraw_editor.php`, `api_note_info.php`, `api_download_note.php`, Anhang-Download → 200; Exporte → 501 nach bestandener Leseprüfung.
1b. XSS: Namen aus M3a2.4 auf `revisions.php`, `attachments.php`, `kanban_content.php`, `excalidraw_editor.php`, `workspaces.php` → kein zusätzliches Element/Attribut.
2. Seiten: A öffnet `revisions.php?note_id=…`, `attachments.php?note_id=…`, Kanban einer A-Ordner-ID, `excalidraw_editor.php?note_id=…` → 200, 0 CSP-Verletzungen, 0 × 404 ohne App-Präfix (Playwright, Hell).
3. `ControllerAttributeTest` grün mit erweiterter Allowlist; `check-forbidden-sql.sh` 0 Treffer.
4. Abschluss M3: `run-m3.sh` (§3) Exit 0 auf allen Umgebungen; `run-m2.sh` und `run-m1.sh` Exit 0 (Regression).

### M4-Vormerkungen (aus M3a1)
- Aus M3b1 verschoben: „Neuer Workspace“ im Workspace-Menü (`wsmenu:new-workspace`, Sidebar-Aktion `new-workspace`) bleibt bis M4 ausgeblendet (FORCED_HIDDEN); Dialog und `workspaces.php`-Anbindung mit der Workspace-Verwaltung.
- Done M4: `js/calendar.js` und `js/background-settings.js` wieder in den Build (aus `bundles.json` `exclude` nehmen), sobald `api/v1/calendar/*.php` (DiaryApiController) bzw. `api_upload_background.php` (CompatController) registriert sind; Probe: 0 Antworten ≥ 400 beim Laden.

## 3. Testsetup

### 3.1 Skripte (Architekt)
- `dev/nextcloud/run-m3.sh [env…]` (Standard: sqlite, mariadb, pgsql, nginx): `npm ci` für Build nur, wenn `nextnotes/build/node_modules` fehlt → `make -C nextnotes clean build` zweimal mit Prüfsummenvergleich (zweiter Lauf `unshare -rn`) → `check-upstream` → `validate-info.sh` → `check-forbidden-sql.sh` → je Umgebung `down`/`up`/`install-app`/`app:enable` → PHPUnit (alle M2-Tests + neue Seiten-Tests; nur Apache-Umgebungen) → Playwright-Suite (sqlite und nginx; nginx prüft Asset-Auslieferung aus `custom_apps` und pretty URLs) → `down`. Exit 0 nur wenn alles grün; `KEEP=1` wie M2.
- `dev/nextcloud/run-e2e.sh <env> [spec…]`: legt Benutzer `alice`, `bob` an (Sprache `de`), setzt Theme per `occ user:setting … theming enabled-themes`, ruft `node tests/nextnotes/e2e/<spec>.mjs <base> …` mit `playwright-core` aus `dev/nextcloud/node_modules` und System-Chromium (`CHROMIUM=`, Standard `/usr/bin/chromium`), wie M1.

### 3.2 Playwright-Suite (Tester, `tests/nextnotes/e2e/`)
- `lib/harness.mjs`: Login über NC-Formular, `addInitScript` mit `securitypolicyviolation`-Zähler (wie `check-csp.mjs`), Request-/Response-Log je Seite (Methode, URL, Status, Größe), Konsolenfehler-Sammler, Selbsttests (künstliche CSP-Verletzung muss erkannt werden; künstlicher Request auf `/api/v1/x` ohne Präfix muss als 404 erkannt werden).
- Jede Spec läuft zweimal: `theme=light`, `theme=dark`. Screenshots je Seite und Theme nach `tests/nextnotes/e2e/out/` (nicht eingecheckt) zur Sichtprüfung durch Thomas, kein Pixelvergleich.
- Specs: `assets.mjs` (M3a1), `frame.mjs`, `xss.mjs`, `stripped.mjs` (M3a2, in M3b/M3c erweitert), `editor.mjs`, `beacon.mjs`, `lock.mjs`, `tags.mjs`, `pdf.mjs`, `search.mjs` (M3b), `pages.mjs` (M3c), `budget.mjs` (§3.4).
- Harness bricht jede Spec bei `pageerror` oder Konsolenfehler ab (Allowlist nur mit Begründung, z. B. erwartete 409 im Sperrtest als Netzwerkfehler-Log).

### 3.3 PHPUnit (Tester)
- Neu: `Page/TemplateOutputTest` (Grep-Allowlist `print_unescaped`, JSON-Flags), `Page/PageRoutesTest` (Status, Fragment-Modus, Login-Redirect, CSP-Header je Ansicht inkl. `frame-src`), `Access/PageIsolationTest` (M3c), `AttachmentPdfCspTest` (M3b); `RouteDiffTest` unverändert (nur `/api/v1`), `ControllerAttributeTest` um `PageController`/`CompatController` erweitert (Seiten-GETs `NoCSRFRequired`, POSTs nicht).

### 3.4 Performance (Messgrößen, Test `budget.mjs`)
Gemessen beim Öffnen von `index.php?note=<id>` (HTML-Notiz), Testdaten 1 000 Notizen in 100 Ordnern (Seed per PHP-Skript im Container), sqlite/Apache:
| Messgröße | Grenze | Referenz |
|---|---|---|
| JS der Seite (alle `nextnotes`-Skripte, gzip -9, kalter Cache) | ≤ 1 190 KB | Upstream `index.php`: ≈ 1 320 KB (head 209 + app 686 + CodeMirror 383 + highlight 41) |
| CSS der Seite (gzip -9) | ≤ 230 KB | Upstream ≈ 228 KB (core 152 + modals 49 + dark/mobile/katex/syntax 27) |
| Mermaid, KaTeX, Excalidraw beim Seitenaufbau | 0 Requests | Upstream lazy |
| API-Requests bis `networkidle` | ≤ 8, keine doppelte (Methode+URL), kein `/system/i18n`; Liste als Allowlist im Test | – |
| Antworten ≥ 400 beim Seitenaufbau | 0 | – |
| `index.php` TTFB (Median aus 5, warm) | ≤ 500 ms | – |
| Zweiter Aufruf (warmer Cache): App-Assets | alle aus Cache (kein 200 mit Body für `js/`, `css/`) | NC hängt `?v=` an |
Werte werden im Testlauf ausgegeben; Überschreitung = rot. Grenzen ändert nur der Lead.

## 4. OCP-Klassen (Nachweis `.ref/nextcloud-server/lib/public/`, 35.0.2; Layout im Image `nextcloud:35.0.2-apache`)
- `Util::addScript` (4.0), `Util::addStyle` (4.0), `Util::addInitScript` (28; nur falls Flackern trotz §1.3 bleibt); `AppFramework/Services/IInitialState::provideInitialState` (20); `App/IAppManager::getAppWebPath` (18), `getAppPath`; `IURLGenerator::linkTo` (6), `linkToRoute`, `getLogoutUrl` (35); `AppFramework/Http/TemplateResponse` (`RENDER_AS_USER`/`RENDER_AS_BLANK`), `ContentSecurityPolicy`, `EmptyContentSecurityPolicy::addAllowedFrameDomain` (8.1), `addAllowedFrameAncestorDomain` (13), `DataDownloadResponse`/`FileDisplayResponse`; Attribute `NoAdminRequired`, `NoCSRFRequired`, `UserRateLimit` (27). Alle ohne `@deprecated` geprüft.
- Kern-Markup (Image, nicht in `.ref`): `core/templates/layout.user.php:23` `<head data-requesttoken>`, `:55-56` `body data-theme-<id>` + `data-themes`, `:58` InitialState-Container, `:76` `id="unified-search"`; `layout.initial-state.php:9` `<input id="initial-state-<app>-<key>">`; Theme-Einstellung `theming`/`enabled-themes` (`apps/theming/lib/Service/ThemesService.php:160`). R13 damit bestätigt; M3a1-Smoke prüft live.

## 5. Risiken
- **R1 Umfang Hauptansicht** (SPEC R6): `index.php` + Partials ≈ 6 000 Zeilen Upstream-PHP mit DB-Logik. Gegenmaßnahme: View-Services statt Logik im Template, Partials einmal portiert, Teilung M3a1/M3a2; nach M3a2 Neuschätzung M4.
- **R2 NC-Kern-CSS** wirkt auf Poznote-Formulare und -Knöpfe → `nextnotes-layout.css` scoped auf `#nextnotes-root`; Screenshots je Theme zur Sichtprüfung.
- **R3 Flackern/Reihenfolge** (SPEC R4): `defer` + Theme erst im Adapter → Ausblenden bis `nextnotes-ready`; Test prüft, dass `html[data-theme]` vor dem ersten sichtbaren Frame gesetzt ist (Screenshot nach `domcontentloaded`).
- **R4 Minify** ändert Semantik (z. B. Funktionsnamen, `'use strict'` am Anfang einer Datei gilt für das ganze Bundle – wie Upstream-Konkatenation) → keine Bezeichner-Umbenennung, `MINIFY=0` als Gegenprobe im Test (Szenario 1 auch unminifiziert grün, einmal in M3a1).
- **R5 Late-Adapter** hängt an Upstream-Namen (`poznoteEnsureMermaid`, `poznoteAssetUrl`, `__poznoteThemeStorage`, i18n-URL) → bricht still bei Upstream-Umbenennung; abgesichert durch Krit. 1/3, Namen zusätzlich in `check-upstream` per `grep` geprüft.
- **R6 Doppelte Editor-Sitzung**: `editor_session_id` liegt in `sessionStorage`; „Tab duplizieren“ und `window.open` kopieren ihn → zwei Tabs gelten als dieselbe Sitzung. Gegenmaßnahme: neue ID je Laden außer Reload (§1.3), Done M3b.4. Restrisiko: Browser, die „Tab duplizieren“ als `reload` melden – dann bleibt das Upstream-Verhalten (dokumentieren).
- **R7 Notfallspeichern**: Upstream versucht zuerst `fetch` mit `keepalive`, dann `sendBeacon` – beide mit 64-KiB-Grenze, Fehler bei `keepalive` asynchron (Beacon-Zweig dann nicht erreicht). Gegenmaßnahme: frühes Speichern bei `visibilitychange` (Adapter), Messung mit Notiz > 64 KiB in Done M3b.3 (a2); bei Datenverlust ohne Gegenmaßnahme SPEC §11.2 B30 (vorgemerkt; B27 ist `shouldFolderBeOpen`).
- **R8 404-Vorabladungen** durch JS-erzeugtes Markup vor dem MutationObserver → P5 nach Messung.
- **R9 Testdauer**: Playwright × 2 Themes × 2 Umgebungen; Specs teilen Login/Seed, Budget-Test nur sqlite.

## 6. Offene Punkte (Stand r2)
| # | Punkt | Entscheidung / Vorschlag | Wer |
|---|---|---|---|
| O1 | i18n statt InitialState als statische Datei | **entschieden** (Lead): `js/i18n/nextnotes-i18n-<lang>.js` + synthetische Antwort für `/system/i18n`; SPEC §1.4 geändert | – |
| O2 | Export-Routen in M3.6, Export ist M4 | **entschieden**: Rechteprüfung + 501 bis M4; Export-Menüpunkte bis M4 ausgeblendet; `api_download_note.php` liefert gespeicherte Form | – |
| O3 | Unbekannter `?workspace=` auf Seiten | **entschieden**: Standard-Workspace; fremde `?note=` → 404; SPEC §4.4 Nachtrag | – |
| O4 | Minify mit esbuild (Whitespace/Syntax) | ohne Einwand übernommen | – |
| O5 | Links auf M4-Seiten | **entschieden**: bis zur Portierung weggelassen | – |
| O6 | Marke: „Next Notes“ + App-Icon, Poznote-GitHub/-Doku/-Spenden-Links entfernt, Herkunft im Über-Dialog | vorläufig so umgesetzt (Lead) | **[THOMAS]** |
| O7 | Editor-Sitzung bei duplizierten Tabs | **entschieden**: Adapter-Lösung §1.3, Done M3b.4 | – |
| O8 | `check-upstream` vollständig (Schema, neue `fetch`-Ziele, Inline-Skripte, i18n, `vendor.json`, direkte Asset-Tags in `src/public/index.php:456-475` gegen `jsSingle`/`cssSingle`/Reihenfolge/Media) | M3: Patches, Bundles inkl. `css_assets.php`/Dark-Mode, Templates, Adapter-Namen; Rest mit M4 | Lead |
| O9 | P8 (ID-Kollision `unified-search`) | **entschieden**: erst nach Browser-Bestätigung in M3a2 | – |
| O10 | SPEC §4.3 ergänzen | erledigt in r2 (§7) | – |
| O11 | SPEC §13 M3.4 präzisieren | **entschieden**, SPEC geändert (§7) | – |
| O12 | Sperr-Banner zeigt Anzeigename des Halters | wie Upstream, nur Workspace-Mitgliedern sichtbar; keine Änderung | – |

## 7. SPEC-/ADR-Änderungen in r2 (vom Architekten eingetragen)
| Stelle | Änderung | Anlass |
|---|---|---|
| SPEC §1.1 | Layout um `js-src/nextnotes-late.js`, `css-src/nextnotes-layout.css`, `build/build.mjs`, `build/package.json`; Ausgabe `js/`, `css/` (inkl. `js/vendor/`, `css/vendor/`, `js/i18n/`), kein `vendor-assets/` | Hinweis r1 |
| SPEC §1.2 | Punkt 4 Vendor nach `js/vendor/`, `css/vendor/` (`Util::addScript`/`addStyle` laden nur aus `js/`/`css/`); esbuild als einzige Build-Abhängigkeit; Punkt 5 i18n-JS-Datei | Hinweis r1, O1, O4 |
| SPEC §1.4 | Wörterbuch als statische, gecachte JS-Datei je Sprache statt InitialState; Skriptreihenfolge inkl. i18n-Datei und `nextnotes-late.js` | O1 |
| SPEC §4.3 | Ergänzt: `window.open`, 401-Reload mit Schleifenschutz statt `session-guard.js`, i18n-Antwort, Theme-Shim, neue Editor-Sitzung je Laden, frühes Speichern bei `visibilitychange`, Late-Adapter | O7, O10, R7 |
| SPEC §4.4 | Nachtrag Seiten: unbekannter `?workspace=` → Standard-Workspace | O3 |
| SPEC §4.6 | Ausgaberegel für Templates (`p()`, JSON-Flags, `print_unescaped` nur Allowlist) | Blocker r1-1 |
| SPEC §11.1 | P7 (`note-edit-lock.js`), P8 als bedingt | B13, O9 |
| SPEC §11.2 B22 | PDF: `frame-ancestors 'self'` statt `'none'` | Hinweis r1 |
| SPEC §13 M3.4 | „Inhalt beim Schließen gespeichert; Beacon-Pfad (erzwungen) mit gültigem `requesttoken`, HTTP 200“ | O11 |
| SPEC §11.2 B26 | `data-action` in Notizinhalten nur Allowlist (Umsetzung M3b) | Befund r2 |
| ADR 0012 | Nachtrag: PDF-Anhänge `frame-ancestors 'self'` (entspricht NC-Standard für Seiten) | Hinweis r1 |
