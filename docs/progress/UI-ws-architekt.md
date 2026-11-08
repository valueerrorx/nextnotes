# UI-ws – architekt
Status: fertig
Auftrag: Workspace-Chip im Kopf jeder workspacegebundenen Ansicht (klickbar, Menü der Hauptansicht, Wechsel bleibt in Ansicht); Dashboard/Erwähnungen zeigen Bereich. Templates+Adapter, nicht nextnotes-teams.js. E2E workspace-chip.mjs. Instanz nextnotes-uiws, Port 8660.
## Erledigt
## Offen
- Analyse: page_title.php hatte schon statischen Chip (Tags, Einstellungen, Aufgaben, Tagebuch, Speed Dial, Notizen-Manager, Papierkorb, Anhänge, Ordner, Graph, Erstellen); Dashboard Scope-Knopf (Modal); Erwähnungen ohne; Kanban = Hauptansicht (Seitenleiste). Upstream-Menü page-title-workspace-menu.js nie portiert.
- Entscheidung: Chip = Knopf, Menü per Adapter in nextnotes-late.js aus GET api/v1/workspaces (wie Hauptmenü), Upstream-Klassen poznote-page-title-workspace-menu (icon-sidebar.css überall geladen); Optik NC-Sekundärknopf für Chip + Seitenleisten-Kopf (Zusatz Lead)
- Instanz nextnotes-uiws sqlite :8661 läuft (up/install/enable 0); Hilfsspec tests/nextnotes/e2e/zz-look-uiws.mjs (temporär, wird gelöscht)
- Umgesetzt: templates/partials/page_title.php (Chip = Knopf data-nn-ws-chip), templates/partials/left_col.php (Ebenen-Symbol, chevron, aria-haspopup), templates/mentions.php (statisch „Alle Arbeitsbereiche“), js-src/nextnotes-late.js (Menü), css-src/nextnotes-layout.css (UI-ws-Block); E2E tests/nextnotes/e2e/workspace-chip.mjs (Auftrag Lead)
- make build 0; workspace-chip hell grün (156) vor letzter Menü-Feinheit; Hilfsspec gelöscht
- Endlauf: make build 0; workspace-chip sqlite hell+dunkel grün (156/156); layout-left hell grün (113); check-upstream grün
- SPEC §(Frontend-Liste) Punkt 25 ergänzt; kein src-Patch
- Instanz nextnotes-uiws abgebaut (down.sh all)
- Zusatz 1: Einstellungen ohne Chip (settings.php $titleWorkspace=false, page_title.php Bedingung)
- Zusatz 2: Mitten gemessen (Box und Versalhöhe): schon ±0,5 px, außer Tagebuch-Icon +2 px (Upstream-Nudge book-open) → CSS neutralisiert (position static, align-items center)
- E2E erweitert (Mitten je Ansicht 1400/390, Dashboard, Erwähnungen, Einstellungen ohne Chip); Lauf: workspace-chip hell+dunkel grün (168), layout-left hell grün (113), check-upstream grün, make build 0
- Instanz erneut abgebaut
## Nächster Schritt
- keiner
## Offen
- Team-Kennzeichen im Chip-Menü nicht per E2E geprüft (braucht Circles/Team)
- Build/Tests liefen mit uncommitteten M6e2-Änderungen im Arbeitsbaum
