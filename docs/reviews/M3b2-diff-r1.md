VERDICT: FREIGABE

Geprüft: Arbeitsbaum gegen HEAD 7906491e (Auftrag nannte 7182dc2f = ältere, nicht mehr vorfahrende M3b1-Fassung) inkl. untracked `nextnotes/templates/audio_player.php`.

Keine Blocker. Geprüft ohne Befund:
- Rechte: `PageController::audioPlayer` → `AttachmentService::download` (requireNote read + checkWorkspace + Anhang an Halter-Notiz), fremd/unbekannt/nicht-numerisch → 404; Lesefreigabe = read, passt. Suche arbeitet nur auf `findList([$wsId])` des aufgelösten Workspace, Ersatznotiz über `requireNote(read)`.
- CSRF: `page#search` (POST index.php) ohne `NoCSRFRequired`, Formular trägt `requesttoken`; history-Patch entfernt Token aus pushState/replaceState (unified-search.js:1164); clearSearch baut URL aus bereinigter location.search.
- Header: PDF bekommt reine `EmptyContentSecurityPolicy` (CSPMiddleware merged sie nicht → `default-src 'none'; … frame-ancestors 'self'`), ADR 0012 Nachtrag deckt das; übrige Typen inkl. SVG weiter `sandbox` + nosniff (B22). Audio-Player: Standard-CSP (frame-ancestors 'self', media self), Inline-`<style>` durch NC-Standard erlaubt.
- Escaping: Suchbegriffe/Datum/Dateinamen über `p()`, Notiz-Links über `http_build_query`; Tasklisten-IDs werden in tasklist-core.js/-subtasks.js numerisch normalisiert, Text über `escapeHtml`.
- Klick-Schutz B26: `data-action` aus gespeichertem Inhalt nicht erreichbar (HtmlSanitizer erlaubt kein data-action; Markdown-Parser escaped HTML, Attribut-Allowlists ohne data-action; Tasklist-JSON roh gespeichert, aber IDs numerisch und Text escaped). Zuordnung fest, `Object.hasOwn`, Inhalts-/UI-Aktionen getrennt.
- SQL: LIKE mit `escapeLikeParameter`, `IN` gechunkt (1000), DB-neutral.
- src-Patches: P2 (7 Dateien) + P12 gelistet in SPEC §11.1 und upstream-patches.txt; keine onclick/onchange-Strings mehr in erzeugtem HTML.

Hinweise (nicht blockierend):
1. Plan-Widerspruch: M3-plan.md Z.86/127 ordnet P1 (events-drag-drop.js:181 `new Function`, B3) und P3 (`action="javascript:void(0)"` tasklist-render.js:19, tasklist-subtasks.js:245, tasklist-embed.js:657) M3b2 zu; beide nicht im Diff. Faktisch harmlos (kein Template setzt `data-onclick`/`onclick` auf Notiz-Links; Submit-Listener rufen preventDefault), aber im Plan/SPEC §11.1 als „entfällt (Begründung)“ oder „verschoben nach …“ markieren, analog P4, sonst bleibt B3 formal offen.
2. Performance `SearchService::pageSearch`: eine `findList`-Abfrage je Suchwort, Wortzahl unbegrenzt, und jede lädt volle Notizzeilen (inkl. content) – nur IDs gebraucht. Wortzahl deckeln (z. B. 20) oder ID-only-Abfrage; `tagsFor` läuft zusätzlich zweimal pro Seitenaufruf (pageSearch und tree).
3. Done M3b.7: Test „POST ohne requesttoken → 412“ und „requesttoken nicht in Adresszeile nach AJAX-Suche“ muss der Tester belegen; nativer `form.submit()` liefert URL ohne Token (POST), ok.
