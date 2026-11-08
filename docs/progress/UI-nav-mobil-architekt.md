# UI-nav-mobil – Architekt
Status: fertig
## Auftrag
Mobil 390px: (A) Überlappung Icon-Leiste/Inhalt Unterseiten, (B) Notiz-Klick öffnet Editor nicht, (3) unten abgeschnitten, (4) inert früher, (5) Aufgaben-Erinnerung mit Leserecht in UI. Tests mobile-m6.mjs, material-m6b ergänzen. Instanz nextnotes-mobil :8560.
## Erledigt
- Start (frisch)
- Instanz nextnotes-mobil sqlite :8561 up, build, install, enable, alice/bob (exit 0)
- Befund IST (scratch mm/probe.mjs): Rail absolut (index-mobile.css) über .nn-page-main; #left_col 100dvh, #right_pane 100vh; Upstream-Mobil schiebt per body.scrollLeft, body hat im NC-Rahmen nichts zu scrollen → Notiz bleibt rechts (B68)
- Zusatz 6 (Lead): Einklapp-Pfeil Desktop schwebt neben Leiste, unten abgeschnitten
- Code: layout.css (Pfeil, Mobil ≤800 Rail im Fluss, note-open-Umschaltung, Höhen 100 %, Erinnerung-CSS); 17 Templates `inert`; bootstrap Rückfall 2 s ab Navigationsstart; teams.js openTaskDueModal-Wrapper (nur /task-reminder bei Leserecht)
- Zusatz 6 Pfeil + Kanban-Zurück-Knopf (kanban_content.php) + popstate in late.js; Scratch-E2E scratchpad/mm/mobile-m6.mjs hell grün (93)
- Zusatz 7 (Lead): View Transitions – Kopfleiste steht, Inhaltsfläche steht, nur Inhalt blendet
- Zusatz 7: Ursache Inhaltsfläche: Anti-Flicker opacity:0 am Container im neuen Snapshot → jetzt nur Kinder; Kopf/Rail: alte Bilder aus, keine Animation; scratch mm/ui-nav-vt.mjs hell+dunkel grün (27)
- Punkt 5: scratch mm/material-reminder.mjs hell grün (36)
- Regression gestartet: ui-nav anti-flicker smoke-m4 material-m6b editor hell+dunkel (Log scratchpad/mm/regr.log)
- SPEC §4.3 Punkt 22, §3 Material (Aufgaben-Erinnerung), §11.2 B68; ADR 0019 Nachtrag; check-upstream exit 0
- Regression hell: ui-nav 5 FAIL (B Gruppen-Animationen nn-header/nn-rail, D Container-opacity) und anti-flicker 2 FAIL (Container-opacity) = gewollte Änderung Zusatz 7, Tests prüfen alte Annahme → Tester
- Regression sqlite hell+dunkel: smoke-m4 164, material-m6b 126, editor 119 grün; ui-nav/anti-flicker rot nur an alten Annahmen (s. o.)
- scratch dunkel: mobile-m6 93, material-reminder 36 grün
- Instanz abgebaut (down.sh all), keine Container/Volumes nextnotes-mobil
## Nächster Schritt
- keiner (Tester: Specs aus scratchpad/mm übernehmen, ui-nav B/D + anti-flicker B anpassen)
## Offen
- Eigene Aufgaben-Erinnerung nicht abrufbar (kein GET /task-reminder) → Schalter zeigt dueReminder der Aufgabe
- Nur-Lesen-Notiz (P11) dimmt .noteentry auf 0.5, Termin-Chip wirkt dadurch gesperrt
- Wischen zwischen Liste und Notiz entfällt mobil (Zurück-Knopf, Browser-Zurück)
