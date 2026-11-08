# UI-rail – Architekt

Status: fertig

## Auftrag
Icon-Leiste: Dashboard, Notizen (Hauptansicht), Aufgaben, Tagebuch, Rest; „Erstellen“-Seite entfernen (create.php → Hauptansicht); App-Start (`/` ohne Parameter) = Dashboard. Keine M6f-Dateien anfassen.

## Befunde
- `icon_sidebar_order`: nur Schlüssel in `SettingsService::JSON_LISTS`; Reihenfolge-Modal gestrichen, `icon-sidebar-order.js` nicht gebündelt → keine gespeicherte Reihenfolge wird angewendet, nichts zu migrieren
- Hauptansicht-Link `#iconSidebarHomeBtn` (Upstream tabs.js/icon-sidebar-toggle.js setzen dessen href) → wird Leisten-Eintrag „Notizen“; Logo bekommt `iconSidebarLogo` → Dashboard
- „+“-Menü der Seitenleiste (partials/modals.php) deckt html, markdown, list, diary, folder, subfolder ab = alles aus create.php; Workspace/Excalidraw bot Create nicht
- Keine Links auf create.php in Templates/JS außer Leiste; Upstream-JS prüft nur `pathname.endsWith('create.php')` (toter Zweig)
- `/` und `/index.php` beide page#index; Route-Name `nextnotes.page.index` = baseUrl überall → `/` bekommt `defaults` start, index() leitet ohne Parameter auf dashboard.php

## Erledigt
- routes.php: `/` mit defaults start=1; create.php bleibt Route
- PageController: index() → 303 dashboard.php bei start ohne Parameter/Fragment; create() → 303 index.php?workspace=<aufgelöster Key>
- templates/create.php gelöscht; bundles.json cssPages/pages.create entfernt; nextnotes-late.js Karten-Handler entfernt
- partials/icon_sidebar.php: neue Reihenfolge, Logo `iconSidebarLogo` → dashboard.php, `iconSidebarHomeBtn` = Leisten-Eintrag Notizen (folder-tree), notes_manager „Alle Notizen“ (layout-list)
- ui_customization_sections.php: leere „Create Cards“-Sektion entfernt, Icon-Leisten-Liste in neuer Reihenfolge
- ui-nav.mjs Abschnitt E; SPEC §4.2 (2 Zeilen), §4.3 Punkt 26, M4e-Hinweis; ADR 0019 Nachtrag
- bundles.json excludePages.create; make build 0 (159 Dateien); check-upstream grün
- dev/nextcloud run-phpunit.sh, check-page.sh, check-csp.sh: Seitenprüfung auf index.php statt App-Wurzel (jetzt 303)
- Instanz nextnotes-uirail/8660 sqlite up+install+enable 0; phpunit Filter PageRoutes|ListPages|TemplateOutput|PageIsolation|TeamIsolation|RouteDiff|CoreScenario: 44 Tests OK, Exit 0
- e2e `THEMES=light run-e2e.sh sqlite ui-nav`: 84 Prüfungen grün, Exit 0 (1. Lauf rot: Nav-Link im DOM nicht gerendert → Test liest Initial State core-apps)
- `down.sh all` Exit 0, keine uirail-Container
- PHP-Tests: ListPages, TemplateOutput, PageIsolation, TeamIsolation ('' und create.php → 303), PageRoutes testStartAndCreateRedirect

## Nächster Schritt
keiner (Bericht an Lead)

## Offen
- weitere E2E-Specs nutzen noch create.php bzw. App-Wurzel '' als Hauptansicht: pages-m4e, diary-tasks, layout-left, workspace-chip, mobile-m6, smoke-m4, stripped (create.php); anti-flicker, assets (App-Wurzel) – nicht angepasst, nicht gelaufen
- SPEC.md enthält parallel M6f-Hunks; beim Commit trennen
