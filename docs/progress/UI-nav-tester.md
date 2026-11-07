# UI-nav – Tester
Status: fertig
## Auftrag
anti-flicker.mjs Teil B an neues Anti-Flicker (nur Inhalt, inert, 2-s-Fallback) anpassen + Gegenprobe; anti-flicker/ui-nav/smoke-m4 hell+dunkel; Mobile-Stichprobe 390x844. Instanz nextnotes-uinavt, Port 8540.
## Erledigt
- Start (frisch)
## Offen
- Instanz sqlite :8541 (make build, up, install, enable) exit 0
- anti-flicker.mjs neu (A Inhaltsbereiche, B index+tasks: inert/opacity/Fokus/Klick, Leisten bedienbar, Rückfall 2,8 s; AF_STRIP=css|js Gegenprobe)
- light anti-flicker grün 55 (af2.log)
- Gegenprobe AF_STRIP=css exit 1 (2 FAIL opacity), AF_STRIP=js exit 1 (6 FAIL inert/Fokus)
- run-e2e sqlite anti-flicker ui-nav smoke-m4 hell+dunkel exit 0 (55/60/164 je Theme), run3.log
- Mobil-Stichprobe (scratch mobile.mjs) exit 0; Befund: tasks.php 390px Icon-Leiste überlappt .nn-page-main; Notiz-Klick mobil zeigt Notiz nicht (right_pane x=382). Vergleich mit HEAD folgt
- HEAD-Vergleich (Worktree-Build, Mobile-Skript): Überlappung tasks.php und Notiz-Klick mobil schon in 63151a3f; neu durch UI-nav: Leiste transparent → Inhalt scheint unter Icons durch
- Instanz abgebaut (down.sh all), Worktree entfernt
## Nächster Schritt
- keiner
## Offen
- Mobil 390px Unterseiten: .nn-page-main beginnt bei x=8 unter #icon_sidebar (vorbestehend), mit UI-nav jetzt Text hinter Icons sichtbar
- inert erst ~400 ms nach Erscheinen des Inhalts (Bootstrap-Start), Rückfall dadurch ~2,4 s; davor Tab-Fokus im unsichtbaren Inhalt möglich (vorbestehend)
