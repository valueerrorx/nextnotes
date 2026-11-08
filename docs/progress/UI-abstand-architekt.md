# UI-abstand – architekt
Status: fertig
Auftrag: einheitlicher oberer+linker Innenabstand aller Ansichten = Tags-Wert (Thomas 2026-11-08). Nur css-src + layout-left.mjs. Instanz nextnotes-uiabst, Port 8660.
## Erledigt
- Vorher gemessen (1400): Tags oben 20/links 16; andere oben 12–44; Hauptansicht Knopf 42; mobil Tags 27
- css-src/nextnotes-layout.css: --nn-view-pad 5×baseline (mobil 4×), .nn-page-main padding-block-start, Container/Titel oben 0, Unterseiten Verlauf/Anhänge, Hauptansicht (notecard/innernote/note-header/toolbar), Kanban-Kopf padding-block, Dashboard-Raster links
- layout-left.mjs: oben+links je Seite = Tags-Wert ±2, Kachelraster, Hauptansicht, Kanban
- make build 0; layout-left sqlite hell+dunkel grün (113/113), ui-nav hell grün (68), check-upstream grün
- SPEC §(Frontend-Liste) Punkt 24 ergänzt, Punkt 23 verweist darauf
- Instanz nextnotes-uiabst abgebaut
## Offen
- mobil: Tags-Filterfeld leicht eingerückt (vorher schon), nicht im Scope
