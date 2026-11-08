# UI-fein – Architekt
Status: fertig
## Auftrag
(1) Nur-lesen-Notiz voll lesbar + Kennzeichen, Werkzeuge einzeln gesperrt; (2) Einstellungen ohne „Mein Konto“ (Datum→Anzeige, Speicher→Workspaces, Links weg); (3) Seitenleiste blendet bei Verschwinden/Erscheinen, ui-nav B echter Assert. Instanz nextnotes-uifein :8600.
(4, Lead-Zusatz) Alle Views linksbündig statt zentriert (nur css-src), E2E layout-left + Vorher/Nachher-Screenshots.
## Erledigt
- Start (frisch), Stand 10ad9749
- Instanz nextnotes-uifein sqlite :8601 up, build, install, enable (exit 0)
- Befund 1: Dimmung kommt aus NC core inputs.css `div[contenteditable=false]{opacity:.5; background; border; color maxcontrast}` (nicht Upstream)
- 1 fertig: layout.css Reset div[contenteditable] + .nn-readonly-badge; note_display.php Kennzeichen; l10n de/de_DE „Material – read only“; teams.js personalControls gibt Verlauf/Suche/Info/Überlauf/Ordnerpfad frei
- Tests 1: lib/readonly.mjs (neu), material-m6b (+Suche ohne Schreiben), material-reminder (Chip-Opazität), teams-m5d (C Lesefreigabe UI) – hell grün (144/37/95)
- 2 fertig: settings.php (Abschnitt weg, Datum→Anzeige, Speicher→Workspaces), ui_customization_sections (Speicher nach Standard-Workspace), PageController (ncProfileUrl/ncLanguageUrl weg), CompatController Redirect → #section=settings-workspaces-section-grid, l10n 5 tote Strings weg
- Tests 2: settings-m4h (+Abschnitte/Datum/Speicher/kein NC-Link), SettingsM4hTest (Redirect, GONE) – e2e hell grün 127; PHPUnit noch nicht gelaufen
- 3 fertig: Ursache `animation: none` an Gruppe setzte Dauer 0 (Bilder erben); `:not(:only-child)` matcht in Chromium nie (Paar animierte). Jetzt Gruppe animation-name none, Bilder 0 s, :only-child 150 ms
- Tests 3: ui-nav B echte Asserts (aus/ein/steht), Animationen nur Dauer > 0 – ui-nav 68, ui-nav-vt 31, anti-flicker 57 hell grün
- 4: Vorher-Screenshots scratchpad/uf/shots/*-vorher-light.png (Stand nach 1–3), Messung lay-vorher.txt
- 4: layout.css Block „Ansichten linksbündig“ (--nn-view-pad, Container margin 0 auto, Titel/Leisten/Leerzustände links, Unterseiten, Toolbar, Notizinhalt, mobil shared-container/Filterzeile/Vorschau-Schalter)
- Tests 4: neu tests/nextnotes/e2e/layout-left.mjs (15 Seiten × 1400/390 + Hauptansicht) hell+dunkel grün 106
- Kanban: Titel links (order), Kopf/Spalten --nn-view-pad; Graph-Kopf nur ≥641 px; layout-left + Kanban hell+dunkel grün 107
- Docs: SPEC §3 Material, §4.2 Redirect, §4.3 Punkt 19/22/neu 23, §11.1 P11; ADR 0019 Nachtrag UI-fein
- check-upstream exit 0 (erster Lauf rot nur wegen parallelem Build)
- Reset contenteditable verengt auf NC-Spezifität (0,1,1), nur =false (CodeMirror/Excalidraw nicht überstimmen) – nach Regression neu prüfen
- Regression (breiter Reset) 13 Specs hell+dunkel grün: settings-m4h 127, material-m6b 144, material-reminder 37, teams-m5d 95, ui-nav 68, ui-nav-vt 31, anti-flicker 57, smoke-m4 164, editor 119, layout-left 107, mobile-m6 94, pages-m4e 295/171, views-m4g 76/62 (exit 0)
- Befund: paralleler Slice M6d ändert dieselbe Arbeitskopie (info.xml 0.3.1, routes, Assignments, IndexViewService u. a.); Instanz brauchte occ upgrade; Rerun schlug deshalb mit 503 fehl
- occ upgrade; Rerun verengter Reset hell+dunkel grün: material-m6b 144, material-reminder 37, teams-m5d 95, editor 119, smoke-m4 164; check-upstream grün
- PHPUnit SettingsM4hTest: alte Prüfung „Hinweis-Karte NC-Sprache“ ersetzt (kein NC-Link, Datum in Anzeige, Speicher in Workspaces) → OK 6 Tests/94
- Instanz abgebaut (down.sh all), 0 Container/Volumes uifein
## Nächster Schritt
- keiner hell+dunkel (Log scratchpad/uf/regr.log), check-upstream, PHPUnit SettingsM4hTest
## Offen
- Fremde Sperre (anderer Tab): nur ungedimmt, Werkzeuge wie Upstream (keine Lesewerkzeug-Freigabe), kein Kennzeichen (Banner bleibt)
- Arbeitskopie enthält parallel M6d-Änderungen (nicht von mir)
