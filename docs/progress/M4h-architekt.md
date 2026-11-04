# M4h – Architekt (Umsetzung)
Auftrag: M4h laut docs/slices/M4-plan.md (settings.php, Workspaces-Bereich, Export-, Speicher-Karte, Farben verwalten, Hintergrundbild-UI, P15 bedingt). Basis 5ce0824b. Eigene Test-Instanz, am Ende abbauen.
Status: fertig (inkl. Nacharbeit r1)
## Erledigt
- Plan gelesen; Upstream settings.php/settings_shell/workspaces_section/storage-stats-user/ui_customization_sections/modals/settings-page.js gesichtet
- Test-Instanz: Scratchpad nc/ (Projekt nextnotes-m4h, Ports 8181–8184, down.sh Volume-Präfix angepasst); up sqlite+nginx rc=0
- NotePalette, InputValidator/SettingsService/View-Services umgestellt; FolderMapper/NoteMapper::countByWorkspace; AttachmentService::usage; SettingsViewService (php -l ok)
- PageController::settings (ncLanguageUrl/ncProfileUrl, Sektionen language-locale/profile-contact geprüft im Container), listPage hiddenUi+config
- routes.php: page#settings, compat#workspacesPage (303), compat#storageStatsUser; CompatController-Aktionen
- templates/storage_stats_user.php, partials/ui_customization_sections.php (gefiltert), partials/shared_modals.php (aus modals.php verschoben: fontSize, createWorkspace, folderTreeHighlight, noteAgeFilter)
- PageTranslator::strings (Token-Legende)
- partials/settings_modals.php, templates/settings.php (Generator aus Upstream-Karten), Icon-Leiste Einstellungen/Über, bundles.json pages.settings (+omit), Bootstrap-Globals
- make build rc=0; make check-upstream rc=0 (grün)
- Routen live: settings 200, workspaces.php GET 303, storage fragment 200 / ohne fragment 303 (App nach Install per occ app:enable aktivieren!)
- E2E tests/nextnotes/e2e/settings-m4h.mjs geschrieben; Lauf 1 light: 117 ok, 1 rot (Standard-Workspace folgt Umbenennen nicht) → WorkspaceService::update setzt default/last_opened wie Upstream
- background-settings.js auf Einstellungen weggelassen (isIndexPage trifft /index.php/apps/…/settings.php, Bild lag über der Seite); config-Durchreichung wieder entfernt
- l10n de/de_DE: 11 neue Texte
- Befund: ?open=about landete zufällig auf Workspaces. Ursache Port: #app-content visibility:hidden bis DOMContentLoaded, ui-customization.js (deferred, readyState interactive) prüft visibility → blendet Abschnitte aus → Nav-Fallback. Fix css-src/nextnotes-layout.css opacity statt visibility (6/6 Läufe ok)
- E2E-Toggles zustandsunabhängig (Umschalten statt Sollwert)
- P29 settings-page.js (refreshSnapshotsBadge) + upstream-patches.txt; SPEC §4.2 (workspaces.php GET, storage), §4.3 Punkt 19, §11.1 P29
- PHPUnit: SettingsM4hTest neu; ControllerAttribute/TemplateOutput ergänzt; sqlite gefiltert (SettingsM4h|ControllerAttribute|TemplateOutput|PageIsolation) 34 Tests/1447 ok
- „Farben verwalten“/„Workspaces verwalten“ in Dashboard/Tagebuch-Modals per Adapter-Aktion open-app-url; build/check-upstream/forbidden-sql grün
- PHPUnit sqlite voll: 378 Tests, 1 rot CoreScenarioTest Fixture-Schlüssel versions bei /folders/{id}/tags (aus 5ce0824b, nicht M4h); pgsql/mariadb gefiltert (SettingsM4h|ViewsM4g|SettingsSystem|WorkspaceName) je 24/486 ok
- E2E nginx: settings-m4h light 118 + dark 118 grün; views-m4g 76, pages-m4e 295 grün
- down.sh all: nextnotes-m4h abgebaut
## Nacharbeit r1 (Review M4h-r1, Tester anti-flicker)
- WorkspaceService::update: Settings-Abgleich über keys($uid) je ID (alt/neu), PHPUnit testRenameMatchesKeysNotNames
- upstream-ported.txt: src/lib/note-colors.php → NotePalette; SPEC R16 (Palette des Betrachters, M5)
- Anti-Flicker: Adapter setzt #app-content.inert am Start, frei bei DOMContentLoaded bzw. nach 2 s; CSS pointer-events none bis ready; SPEC §4.3 Punkt 19
- build/check-upstream/forbidden-sql grün
- Befund frame rot nach settings-m4h: default_workspace blieb auf umbenanntem Test-Workspace → settings-m4h stellt Standard am Ende zurück
- Befund anti-flicker rot (Konsole/404 /core/preview): NC-Dashboard nach Login (Empfehlungs-Widget, Resume.odt) → Spec prüft ab Mark nach Login
- Tests: PHPUnit sqlite SettingsM4h|WorkspaceName|CoreScenario 11/501 ok; E2E light anti-flicker 2× 27 grün (Fokus/Klick 0), settings-m4h 118, stripped 126, editor 119, frame 143; down.sh all
## Entscheidungen (Befund)
- Farben verwalten braucht Benutzer-Palette serverseitig: neuer Service NotePalette (sanitize wie Upstream, ≤24), ersetzt statisches IndexViewService::colorHex und InputValidator::colorHex-Default (Scope-Zuwachs, melden)
- Karten weg (keine Funktion im Port): Konto/Passwort/2FA/App-Passwörter/Konto löschen, Sprache/Zeitzone (→ Hinweis-Karte NC), Backup/Restore/Import, Git, Offline, Integrationen, Apps, Admin, Schriften, Login-Titel, Icon-Leisten-Reihenfolge (icon-sidebar-order.js nicht gebündelt), Icon-Leisten-Skalierung (theme-init.js gestrichen), What's new (nie Inhalt), Discord/Diskussionen/Tipps/Spenden
- utils-updates.js auf der Seite weggelassen (/system/updates nicht geroutet; Version-Karte nicht klickbar)
- Snapshots-Karte bleibt (snapshots_keep_count wirkt), ohne Sicherheitsversionen/Anhang-Warnung
- listPage: Benutzer-hidden_ui_elements statt nur FORCED_HIDDEN (sonst wirkt Elementsichtbarkeit nicht auf Seiten)
## Nächster Schritt
- keiner (Bericht an Lead)
## Offene Punkte/Annahmen
- tests/ wird wie in M4g vom Architekten ergänzt (Auftrag Lead), obwohl Rolle es ausschließt
