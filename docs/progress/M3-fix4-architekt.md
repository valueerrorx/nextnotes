# M3-fix4 Architekt
Auftrag: 4 Befunde Thomas: (1) Audio aufnehmen (Mikrofon-Policy), (2) not_implemented-Menüpunkte ausblenden + Detektor, (3) Sperre nach Rückkehr ohne Web Locks (Heartbeat legt nicht an), (4) Dialoge unter NC-Kopfleiste.
## Erledigt
- Start
- (3) Ursache: Heartbeat = acquire (legt an), assertCanSave legte bei Sitzung ohne Sperre an → später Heartbeat/Notfall-PATCH der alten Seite holt Sperre nach pagehide-Freigabe zurück (ohne Web Locks neue Sitzung → 409)
- (3) Fix: LockMapper::extend, LockService::heartbeat (nur verlängern, fremd → 409, keine → 200 lock null), assertCanSave legt nie an; Controller heartbeatLock; php -l ok
- (3) PHPUnit: LockServiceTest B13-Test auf B53 umgestellt, neu tests/nextnotes/php/Lock/LockHeartbeatTest.php
## Nächster Schritt
- Analyse 1–4
## Offen/Annahmen
- (1) Ursache: js/speech-to-text.js + css ausgeschlossen (ADR 0010) → window.openAudioRecorder undefined, Klick tut nichts; zusätzlich Feature-Policy microphone 'none' (NC-Standard). Toolbar = normale Editor-Fokus-Toolbar (Rechtsklick fokussiert Editor)
- (1) bundles.json: speech-to-text.js/css wieder drin (Upstream-Position); templates/partials/modals.php dictateModal (nur Aufnahme)
- Instanz: Scratchpad nc4, Projekt nextnotes-fix4, Port 8097, App installiert
- Lead-Nachtrag (5): Suchen/Ersetzen zeigt [object Object] als Trefferanzeige
- (5) Ursache: search-replace.js (auch events-utils.js, attachments.js u. a.) fangen beim Laden `window.t || …` ein; vor globals.js ist window.t = NC-t(app,text) → Objekt zurück. Fix: bootstrap §14 Upstream-t() früh (NC-Aufrufe mit String-2.-Arg delegiert) → B54
- (1) CspFactory::featurePolicyForView (index: microphone 'self'), PageController::page setzt sie
- (1) E2E tests/nextnotes/e2e/audio-record.mjs (harness runSpec opts.args) hell grün 18; Negativprobe: Anhang-Seite (microphone 'none') getUserMedia → NotAllowedError, Index ok
- (5) E2E search-count.mjs hell grün 13
- (3) E2E lock-nolocks.mjs hell grün 20
- (4) layout.css: Overlays (.modal, .alert-modal-overlay, …) top = --header-height, Inhalt max-height calc(100vh - header - 36px) + Scroll; Spec tests/nextnotes/e2e/dialogs.mjs geschrieben (noch nicht gelaufen)
- (2) bekannte 501: convert-html/convert-markdown (slash paste-markdown, Vorlage typübergreifend), notes/{id}/convert (Umwandeln), api_export_* (Export), workspaces.php share
- PHPUnit-Lauf Nachbartests (JobsTest… CoreScenarioTest) läuft im Hintergrund; App währenddessen neu installiert → ggf. wiederholen
- Doku: SPEC §4.3 Punkt 8 + neu 14, B53, B54, §14 FeaturePolicy; ADR 0005/0010/0012 Nachträge M3-fix4. Kein src/-Patch bisher
- E2E menu-501.mjs (Detektor) geschrieben, noch nicht gelaufen
- PHPUnit sqlite gefiltert Lock*: rc=0 (12 Tests); Nachbartests (Jobs, Isolation, Scenario, B38 …): rc=0 (156 Tests)
- (4) dialogs.mjs hell grün 115 (max-height berücksichtigt Upstream-Rand 5 % aus modals/responsive.css)
- (2) menu-501.mjs: beforeunload annehmen, große Untermenüs Stichprobe (erstes/letztes), Blatt-ID je Typ einmal; Lauf läuft (Log Scratchpad m501.log)
- check-upstream (Zwischenstand) rc=0
- (2) FORCED_HIDDEN + 'slash:paste-markdown' (bis M4b); Detektor-Lauf 3 läuft (vor Ausblendung, zur Bestandsaufnahme)
- (2) Detektor: Laufzeit >600 s → Seite wird zwischen Editor-Blättern wiederverwendet (alle 8 neu laden), Zerstörendes (delete-note/-folder, empty-folder) übersprungen; Lauf läuft
- (2) Detektor grün (109 Prüfungen, 501 gesamt 0 nach Ausblendung, 9,4 min) → weiter gekürzt (Stichproben, Werkzeugleiste je Aktion einmal); Endlauf aller Specs gestartet
- check-upstream Lauf 2: hing in check-css.mjs ohne CPU (bekannt aus M3-fix1) nach allen e2-Bündeln, abgebrochen → Wiederholung nach E2E
- E2E hell Endlauf: audio-record 18, search-count 13, lock-nolocks 20, dialogs 115, menu-501 89, stripped 126, lock-return 76, attachments-search 137 grün; editor ROT: B4 einmal (Wiederholung grün, flaky), 'Reload Tab 1 behält Sperre' rot – auch mit HEAD-Build (git worktree) rot → vorbestehend, nicht durch M3-fix4
- E2E dunkel dialogs 115 grün rc=0; check-upstream Lauf 3 läuft
- Instanz nextnotes-fix4 abgebaut (down.sh rc=0, Volume/Netz fix4 manuell entfernt). Achtung: kopiertes down.sh entfernt fest 'nextnotes-dev_nc-sqlite' (Präfix im Skript); Container nextnotes-dev war vorher schon weg, Volume danach nicht vorhanden. demo/gftest unberührt
- check-upstream hing reproduzierbar: check-css.mjs bleibt bei process.exit() im Node-24-Worker-Join hängen (nur mit größerem CSS-Bündel, HEAD-Build ohne speech-to-text.css nicht); Fix nextnotes/build/check-css.mjs process.exitCode statt process.exit (3/3 ok)
- Endstand: make build rc=0; check-upstream rc=0 (grün); check-forbidden-sql rc=0
## Ausgeblendet (FORCED_HIDDEN) und Wiedererscheinen
- slash:paste-markdown (Einfügen → „Markdown einfügen“ RT / „HTML als Markdown einfügen“ MD; convert-markdown/convert-html 501) → M4b
- Bereits vorher verborgen (M3a2, nicht vom Detektor erreichbar): Umwandeln (notes/{id}/convert) → M4b/M4c; Export/Herunterladen/Drucken (api_export_*) → M4c/M4d; wsmenu:edit-workspaces → M4h; Workspace teilen (workspaces.php share) → M5
- Nicht ausgeblendet: „Vorlage“ mit Vorlage anderen Notiztyps ruft convert-* → 501 bis M4b (gleicher Typ funktioniert); Detektor ohne Vorlagen-Testdaten
## Status: fertig
