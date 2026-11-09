# M7-fix – Architekt (Lauf 2: E2E-Matrix-Befunde)
Status: fertig
(Lauf 1 – Listen/KaTeX – fertig, Commit ac057cfc)

## Auftrag
1. frame.mjs „bob ?workspace=<alice>“ rot nach dashboard-ws (bobs last_opened = Team-WS): Specs reihenfolgeunabhängig. 2. CSS-Leck: Upstream `.notification-*` (reminders.css im Bündel nextnotes-modals) trifft NC-Benachrichtigungen im Kopf.

## Erledigt
- Worktree war auf Upstream-main → `git reset --hard nextcloud-port` (746b60d8).
- Befund 1: App-Semantik `PageFrameService::workspace`: unbekannt/fremd → wie ohne Parameter (default_workspace-Einstellung, sonst last_opened normal, sonst Standard), wie Upstream getWorkspaceFilter; frame.mjs setzt bei alice schon last_opened vorher. dashboard-ws räumt bobs last_opened nicht auf.
- Befund 2: Quelle `src/public/css/modals/reminders.css:335–365` (bare `.notification-icon/-heading/-time`), Bump global `:not(#nn-0)` ohne NC-Kopf-Ausschluss.

- Fix 2: bundles.json `cssNotChrome.files` [reminders.css]; css-bump.mjs `bumpSelector/bumpSpecificity(…, notChrome)` hängt NOT_NC_CHROME an Klassen-Regeln; build.mjs cssFile reicht es durch. make build Exit 0, Ausgabe geprüft (.notification-icon…:where(:not(:is(#header…)).
- Fix 1: frame.mjs setzt bobs last_opened = eigener Standard vor ?workspace=<A>; dashboard-ws räumt bobs last_opened zurück (privB).
- Betroffene Specs: frame, dashboard-ws, material-reminder, material-m6b, teams-m5d (lib/readonly).

- Instanz nextnotes-m7fix (sqlite, 11201) up/install/enable Exit 0.
- run-e2e dashboard-ws frame hell+dunkel: grün (frame 142). bobs last_opened danach „Notizen“. frame allein mit bobs last_opened = Team-WS: grün.
- frame.mjs erzeugt eigene NC-Benachrichtigung (occ notification:generate), sonst sieht der Regel-Scan das Leck nur nach Vorläufern.
- Gegenprobe ohne Fix (cssNotChrome leer): frame hell+dunkel ROT, 6 Fehler je Theme (.notification-icon/-heading/-time); frame allein (Benachrichtigungen gelöscht) ebenfalls ROT. Fix wiederhergestellt, build, install.

- run-e2e sqlite dashboard-ws frame material-reminder material-m6b teams-m5d hell+dunkel: grün (42/142/45/144/95 je Theme).
- make -C nextnotes build Exit 0; make -C nextnotes check-upstream grün.
- ADR 0017 Nachtrag M7-fix (Option d: Wächter je Datei). Kein src/-Patch → kein SPEC §11.1/upstream-patches.txt; kein Upstream-Bug (Port-Kollision) → kein §11.2.
- Instanz nextnotes-m7fix abgebaut (down.sh all, 0 Container, 0 Volumes); Demo unberührt.

## Nächster Schritt
- keiner (Commit im Worktree)

## Offen
- Semantik „unbekannt → Standard“ = Start-Workspace inkl. last_opened: an Lead melden.
