# M3-plan – Architekt
Status: fertig (r2)
Auftrag: docs/slices/M3-plan.md schreiben (Slices, Dateien, Done, Tests, Risiken, offene Punkte). Nichts implementieren.
## Erledigt
- gelesen: M2-plan, STATUS, SPEC §1/§2.2/§4/§10–§13, ADR 0004/0006/0007/0012/0013/0015/0016
- gelesen: Inventur B, src/public index.php/index_js.php/index_css.php, lazy-libs, globals (i18n, poznoteAssetUrl), theme-manager, note-edit-lock (isLockHeldByDifferentUser), events-auto-save (keepalive vor Beacon), attachments.js (PDF-iframe)
- Befunde: PDF-Antwort EmptyCSP → frame-ancestors 'none'; NC-Image: data-requesttoken im head, data-theme-* am body, initial-state im body; App in custom_apps
- Größen Upstream: JS head+app 895 KB gz, +codemirror 383, hljs 41; CSS ~228 KB gz
- Befunde 2: NC-Header hat id="unified-search" (Kollision mit notes_list.php-Suchfeld, 6 JS-Dateien); globals.js überschreibt poznoteAssetUrl, lazy-libs lädt relativ 'js/mermaid/…' → Late-Adapter; globals i18n überspringbar wenn window.t+loadPoznoteI18n gesetzt; window.open('/api/v1/…') in attachments.js
- Entscheidung Struktur: M3a Build/Adapter/Theme/Hauptansicht-Rahmen, M3b Editor/Beacon/Sperre/Anhänge, M3c Seiten-/Kompat-Routen + Fremdzugriff
- docs/slices/M3-plan.md r1 geschrieben (Slices M3a/b/c, Build, Adapter, Patches P1–P8, Tests, Budget, Risiken R1–R9, O1–O12)
- OCP geprüft: Util::addScript/addStyle/addInitScript, IInitialState, getAppWebPath, linkTo, getLogoutUrl (@since 35), addAllowedFrameAncestorDomain (13); keine @deprecated
## Nächster Schritt
- keiner (Review durch speccritic)
## Offen
- O1–O12 in Plan §6; O6 [THOMAS] Marke/Upstream-Links
- r2 gestartet: Review M3-plan-r1 + Lead-Entscheidungen einarbeiten
- Plan r2: Teilung M3a1/M3a2, Blocker 1–4, Hinweise, O-Tabelle, §7 Änderungsliste eingetragen; nächst: SPEC/ADR-Änderungen
- SPEC §1.1/§1.2/§1.4/§4.3/§4.4/§4.6/§11.1/B22/§13 M3.4 und ADR 0012 Nachtrag geändert
- Befund: HtmlSanitizer erlaubt button[data-action] → SPEC B26, Fix in M3b; r2 fertig
