# UI-toggle – architekt
Auftrag: Aufklappbereich (Upstream toggle-block) volle Breite, Optik (blasse Fläche, Padding), Auf-/Zuklappen, E2E toggle-ui hell+dunkel grün.
Status: fertig
## Erledigt
- Ursache Breite: NC core/css/inputs.scss stylt div[contenteditable=true] (= .toggle-content) als Eingabefeld (Probe per Slash-Menü, Screenshot)
- Zustand nach Reload: Upstream klappt alle Toggles beim Laden zu (note-content-init.js:243, index-events.js) → Test-Annahme „open“ falsch
- CSS-Block UI-toggle in nextnotes-layout.css neu (Reset .toggle-content, Fläche, Paddings, Markdown-Kinder)
- src/public/js/slash-command.js: P40 (Titel markiert nach Einfügen; Enter-Handler über Auswahl, B70); upstream-patches.txt enthält Datei schon
- SPEC §4.3 Nr. 32, §11.1 P40, §11.2 B70
- menu-501 hell grün (91); nach Guard: build 0, check-upstream 0, toggle-ui hell+dunkel grün (39), editor hell grün
- Instanz nextnotes-uisb abgebaut (down.sh all)
## Nächster Schritt
- keiner (Review/Commit durch Lead)
## Offen
- NC-Kern stylt jedes div[contenteditable=true] als Feld; nur .toggle-content zurückgesetzt (Scope)
