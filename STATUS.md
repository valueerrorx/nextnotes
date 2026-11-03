# Status Nextcloud-Port „Next Notes“

**Aktueller Meilenstein:** M4 (Planung); M1, M2, M3 fertig

## Erledigte Slices
- Setup: Branch `nextcloud-port`, Agenten unter `.claude/agents/`, `UPSTREAM.md`
- M0: Inventur (`docs/INVENTORY.md`, `docs/inventory/`), `docs/SPEC.md`, ADR 0001–0016. Review: `docs/reviews/M0-r1.md` (BLOCKER) → `M0-r2.md` (FREIGABE), Restpunkte O1–O4 eingearbeitet.
  Testnachweis: M0 ist reine Dokumentation, kein ausführbarer Test.
- M0-Nachtrag: Entscheidungen Thomas eingearbeitet; neue Tabelle `nextnotes_teams`, UID-Regel, systemverwaltete Abgabe-Container, `visibleAssignmentIds`/`requireAssignment`, `owner_uid`-Regel. Review `M0-nachtrag-r1` (BLOCKER) → `r2` (1 BLOCKER) → Lead-Entscheid nach 2 Runden (Vorschlag speccritic übernommen).

## Offene Blocker
- keine

## Getroffene Entscheidungen
- Name: „Next Notes“, App-ID `nextnotes` (ADR 0001)
- Zielversion: nur Nextcloud 35, PHP 8.3–8.5 (Thomas, 2026-11-02)
- Themes: nur Hell/Dunkel nach Nextcloud-Theme; Zusatz-Themes, CSS- und Font-Upload gestrichen (ADR 0016)
- Leitprinzip: Original ist Vorlage, kein Zwang; schlank, wartbar, Nextcloud-nativ, einheitlich vor 1:1-Treue. Upstream-Bugs fixen statt übernehmen. Technik entscheidet der Lead.
- Frontend: Poznote-Frontend behalten (ADR 0013); gezielte Edits in `src/`, gelistet in SPEC §11.1
- Lead: ADR 0004, 0005, 0006, 0007, 0011, 0012, 0015; Teilentscheidungen in 0003 (Verwaisen nur per Event/Orphan-Check, Einfrieren ohne Teams-App)

## Entscheidungen für Thomas
Vorläufig vom Lead entschieden, zur Durchsicht (M2c):
- Erinnerungen nur als NC-Benachrichtigung, keine E-Mail
- Mentions über NC-Benutzer-ID (`@uid`), Benutzersuche nur nach NC-Sichtbarkeitsregeln
- Aufgaben-Erinnerung: Markierung für alle sichtbar, Benachrichtigung nur an den, der sie gesetzt hat
- „Was ist neu“ (Poznote-Releases) wird nie angezeigt

Vorläufig (M3-Plan O6):
- Marke in der Oberfläche: „Next Notes“ mit App-Icon; Poznote-GitHub-, Doku- und Spenden-Links entfernt; Herkunft „Basiert auf Poznote von Timothé Poznanski (MIT)“ im Über-Dialog

Vorläufig (M3b1):
- Ohne `?note=` bleibt die rechte Spalte leer (Upstream öffnet die zuletzt bearbeitete Notiz)
- Nach einer Suche öffnet rechts der neueste Treffer (wie Upstream) (M3b2)
- Notiz-Info zeigt Anzeigenamen „Erstellt/Geändert von“ für Leser des Workspace (gelöschte Konten: „Nicht verfügbar“), wie Sperr-Banner O12 (M3c1)
- Datumszeile unter dem Titel standardmäßig sichtbar (`show_note_created='1'` wie Upstream-DB) (M3c1)
- Upstream-PR-Kandidaten an Poznote (nur mit Freigabe): P9/B31 (Reload nach Notizöffnen verlässt App), P10/B32 (Tippen während Speichern ging verloren)

Vorläufig (M3c2):
- `workspaces.php` Löschen verschiebt die Notizen wie B18 in den Ziel-Workspace (kein stilles Mitlöschen)
- Workspace teilen/Freigabe aufheben über die Upstream-Oberfläche → 501 bis M5 (Teams)
- Sehr große Notizen: Suche indexiert nur die ersten ~15 MiB (gekürzter Suchtext); auf MariaDB-Standard liegt die Speichergrenze bei ~14–15 MiB statt 16 MiB

Vorläufig (M2d):
- Kontolöschung: Notizen, die andere Benutzer in den (geteilten) Workspaces des gelöschten Kontos geschrieben haben, werden mit diesen Workspaces gelöscht (SPEC §8). Alternative wäre Übergabe an einen Freigabe-Empfänger.

## Entscheidungen Thomas zu M0 (2026-11-02)
- ADR 0002, 0003, 0010, 0014 angenommen
- ADR 0008 geändert: Entfernen aus dem Team oder Löschen des Kontos löscht keine Abgaben; Team-Löschung löscht Aufgaben+Abgaben nach 30-Tage-Frist (Export möglich); Lehrkraft kann Aufgaben samt Abgaben manuell löschen
- ADR 0009: kein Import aus Poznote
- M0 freigegeben → weiter mit M1

## Entscheidungen Thomas zu M1 (2026-11-02)
- Lizenz der App: `AGPL-3.0-or-later` (`nextnotes/COPYING`); Upstream-MIT-Hinweis bleibt als `nextnotes/LICENCE`
- GitHub-Repo umbenannt: `valueerrorx/poznote-nc` → `valueerrorx/nextnotes` (info.xml angepasst)
- Autor/Copyright: Thomas Weissel

## M1 (fertig)
- Plan `docs/slices/M1-plan.md`; Review `M1-plan-r1` (BLOCKER: frische Instanz) → `M1-plan-r2` (FREIGABE)
- Lead: Teilung M1a (App, Migration, info.xml, SQLite/Apache) / M1b (MariaDB, PostgreSQL, nginx, URL-Spike, CSP-Check); M1 fertig erst nach M1b
- Lead: `app:remove` erhält Daten (SPEC §8); Löschen beim Entfernen ist Datenschutzfrage für Thomas in M7
- M1a erledigt: App-Skelett `nextnotes/`, Migration (11 Tabellen), Testsetup `dev/nextcloud/` (SQLite/Apache), Schematest `tests/nextnotes/schema/`. Review `M1a-diff-r1` (FREIGABE, 4 Hinweise → M1b).
  Testnachweis (tester, frische Instanz, `nextcloud:35` = 35.0.2): `validate-info.sh` 0; `CHECK_PAGE=1 lifecycle.sh sqlite` 0 (enable, 11 Tabellen, 200/303, Probe-Zeile nach disable+remove erhalten); `check-schema.php` 0 gegen §2.1 (Gegenprobe mit 6 Manipulationen → Exit 1); `down.sh all` 0.

- M1b erledigt: Compose-Profile mariadb/pgsql/nginx, `run-m1.sh`, URL-Spike (ADR 0004: bestanden, kein Fallback), CSP-Prüfung per Playwright mit Selbsttest. Review `M1b-diff-r1` (FREIGABE, 5 Hinweise).
  Testnachweis (tester, frische Instanzen, NC 35.0.2): `run-m1.sh` (sqlite, mariadb, pgsql je Apache; nginx/fpm) Exit 0; unabhängig je Umgebung: `app:list` aktiv, `check-schema.php --db=…` 0, Seite 200/303, Spike 4×200 per curl, CSP-Gegenprobe (`tests/nextnotes/csp/csp-gegenprobe.mjs`, 3 provozierte Verletzungen erkannt) 0, Lebenszyklus 0; Fehlerfälle (kaputte Erwartung, fehlendes Chromium) → Exit 1 mit Aufräumen; `down.sh all` 0.

## Entscheidungen Thomas zu M2 (2026-11-02)
- Plan-O5: Standard-Workspace heißt „Notizen“ (lokalisiert), keine Willkommensnotiz
- M2a-diff-r1 Blocker 1: Workspace löschen verschiebt Inhalte nur in einen eigenen, nicht geteilten Workspace (sonst Standard-Workspace anlegen) – nie in geteilte
- Leitlinie (Thomas, 2026-11-02): Reihenfolge M2b→M2c→M2d→M3 beibehalten; Lead entscheidet wo möglich selbst. Ziel: bugfrei, performant, einfache UI, MS Teams nur als lose Orientierung (bekannte Begriffe/Abläufe), nicht kopieren – Poznote-UI ist Basis, eigene bessere Lösungen erwünscht
- M2b: Anhang-Quote zählt gegen den Workspace-Besitzer (auch bei Uploads durch Freigabe-Empfänger/Team)

## M3 (fertig)
- M3-Plan `docs/slices/M3-plan.md`: Slices M3a1 (Build, check-upstream, Adapter, i18n, Theme), M3a2 (Seitenrahmen, Gestrichenes, Navigation), M3b (Editor, Anhänge, Mermaid/KaTeX, Beacon, Sperre, Suche), M3c (Fremdzugriff Seiten-/Kompatibilitätsrouten, Abschluss). Review `M3-plan-r1` (BLOCKER: XSS-Ausgaberegel, Gestrichenes in Menüs, Such-POST ohne Token, Lesefreigabe-Fall) → `M3-plan-r2` (FREIGABE mit Auflage M3b).
  Lead: i18n als gecachte Datei je Sprache (SPEC §1.4); Export-Menüpunkte bis M4 ausgeblendet; unbekannter `?workspace=` → Standard-Workspace (§4.4); duplizierter Tab → Nur-Lesen als Done-Test; PDF `frame-ancestors 'self'` (ADR 0012 Nachtrag).
  Lead B26: `data-action` in Notizinhalten wird vom Sanitizer ganz entfernt (Allowlist nur bei belegter Upstream-Fundstelle); Klick-Delegation ignoriert Ziele im Notizinhalt; Kollision eingeschleuster `id`/Klassen mit App-Elementen wird in M3b behandelt und getestet.
- M3a1 erledigt: reproduzierbarer Build (`make -C nextnotes build`, esbuild + Lockfile, offline), `check-upstream`, Adapter `nextnotes-bootstrap.js`/`nextnotes-late.js` (Token nur an App-Pfad, 401-Reload-Schutz), i18n als gecachte Datei je Sprache, Theme-Tokens Hell/Dunkel, Schrift folgt NC (`Inter` → `var(--font-face)`, inkl. Legasthenie-Schrift). Keine Änderung in `src/`. Review `M3a1-diff-r1` (BLOCKER: Schrift, ADR-0016-Doku) → `M3a1-diff-r2` (FREIGABE).
  Testnachweis (tester, frische Instanzen NC 35.0.2): Build 2× (2. offline) sha256 identisch, 122 Dateien; check-upstream 0 + 7 Gegenproben rot; E2E `assets.mjs` 82 Prüfungen je Theme grün auf Apache und nginx (0 CSP, 0 Konsolenfehler, 0 ≥400); Budget JS 997 KB/CSS 136 KB gz, 3 API-Requests, TTFB 21 ms; `run-m3.sh sqlite nginx`, `run-m2.sh` (3 DBs), `run-m1.sh` Exit 0; `down.sh all` 0.

- M3a2 erledigt: Hauptansicht im NC-Rahmen (Icon-Leiste, Workspace-Auswahl, Ordnerbaum, Notizliste), `IndexViewService` + `templates/index.php`/`partials/` (nicht `parts/`, Upstream-`.gitignore`), Gestrichenes/M4 in Menüs ausgeblendet, Navigation, fremde/ungültige `?note=` → NC-404, B27 (Ordner per Ahnenkette), B28 (GET ohne Setting-Schreiben; Ausnahme Erstanlage Standard-Workspace §4.4), B29 (doppelter Tasklist-Request). P4/P8 entfallen (SPEC §11.1). Keine Änderung in `src/`.
  Lead ADR 0017 (Scope-Zuwachs, technisch): Upstream-CSS im Build um +1 ID-Stelle gehoben (`build/css-bump.mjs`), reine Element-Regeln nicht auf NC-Kopf/Popover/Dialoge/Toasts; Prüfung `build/check-css.mjs` (Chromium als Parser, Regelzahl vor/nach gleich, kein Zusatz hinter Pseudo-Element) in `check-upstream` (e2). `check-upstream` braucht Chromium. Review `M3a2-diff-r1` (BLOCKER: ~27 Selektoren hinter Pseudo-Elementen ungültig, Scrollbalken-Regeln fielen still weg) → `M3a2-diff-r2` (FREIGABE). Tester-Befund Dunkel: `*`-Regel traf `#header` selbst → `NOT_NC_CHROME` schließt Elemente selbst aus (Lead-Fix).
  Lead: `KNOWN_MISSING` in `e2e/stripped.mjs` bleibt vorläufig – Kanban (404 + CSP `script-src-attr` aus `nextnotes-app.js:38`) → M3c; „Ordner/Notiz verschieben“ (pageerror bzw. Konsolenfehler), „+“-Menü ohne Funktion → M3b. Müssen bis M3c-Abschluss leer sein.
  Testnachweis (tester, frische Instanzen NC 35.0.2, nach allen Fixes): Build 2× (2. offline) identisch, 123 Dateien; check-upstream 0 inkl. (e2), Gegenproben `Inter`/Zusatz hinter Pseudo-Element rot; `run-m3.sh sqlite mariadb pgsql nginx` Exit 0: PHPUnit 231/7404 auf 3 DBs, E2E 20/20 (assets, budget, frame, stripped, xss; Hell/Dunkel; Apache/nginx), Regel-Scan NC-Kopf 24× PASS inkl. geöffneter NC-Menüs, Scrollbalken-Regeln vorhanden; Budget 1 API-Request, keine Duplikate, JS 998 KB/CSS 139 KB gz, TTFB ~25 ms; `run-m1.sh` (4 Umgebungen) und `run-m2.sh` (3 DBs) Exit 0 (nach Build-Fix, vor einzeiligem CSS-Fix); `down.sh all` 0.
  Lead: Teststufen eingeführt (`.claude/agents/tester.md`): Nachlauf ≤ 10 min nur betroffene Tests, Slice ≤ 30 min sqlite + ein Webserver, volle Matrix nur am Meilenstein-Ende (Feedback Thomas: Tests zu lang).

- M3b1 erledigt: Editor öffnen/bearbeiten/speichern (Fragment-Modus `X-Poznote-Fragment`, gleiche Rechte wie Seite, fehlender Workspace-Schlüssel → 404), „+“-Menü, Verschieben-Dialoge, Tags mit `'`, Beacon mit Token (B4), >64 KiB beim Schließen als normaler Request (B30, ersetzt eigenes visibilitychange-Speichern), Sperre/duplizierter Tab nur lesbar (P7), Lesefreigabe ohne Sperr-Anfrage/Banner und nicht editierbar (P11), B26 (`data-action` entfernt, Klick-Schutz, `Object.hasOwn`), B31 (P9), B32 (P10). `src/`-Patches P7, P9, P10, P11 (SPEC §11.1). M3b geteilt in M3b1/M3b2.
  Review `M3b1-diff-r1` (BLOCKER: print_unescaped-Allowlist) → `M3b1-diff-r2` (BLOCKER: Parse-Fehler im Allowlist-Eintrag, Lead-Fix). Tester-Befund: Tasklist mit `\x0B`-Präfix roh ausgegeben (`trim()` in `isStructuredJson`) → Lead-Fix: nur JSON-Leerraum, ungültige Tasklist nur escaped.
  Testnachweis (sqlite/nginx): PHPUnit voll 235/235 (7486 Assertions); E2E `editor` 118 je Theme (Hell/Dunkel), `stripped` 113, `assets` 82, `budget` 21, `xss` 82, `frame` 143 – 0 CSP/pageerror/Konsolenfehler; check-upstream grün; `down.sh all` 0. Andere DBs/Apache nicht (kein SQL geändert; Teststufe Slice).
  Hinweis: PHPUnit voll dauert ~10 min – bei Nachläufen gefiltert laufen lassen.

- M3b2 erledigt: Anhänge (Zeile, Download, Löschen, Bild-Drop), PDF im iFrame (B22), Audio-Player, Mermaid/KaTeX unter CSP, Such-POST mit Token (requesttoken nie in URL/History), Tag-Klick-Filter, P2 (keine Inline-Handler mehr), P1 (`new Function` entfernt, B3), P3 entfällt, P12 (Anhang-Menü unter App-Pfad). Suche: eine ID-Abfrage `NoteMapper::idsMatchingAll`, max. 20 Begriffe, Tags einmal pro Seite. Keine neuen `print_unescaped`.
  Review `M3b2-diff-r1` FREIGABE mit Hinweisen (Performance Suche, P1/P3) → vom Lead nachgezogen.
  Testnachweis: PHPUnit voll sqlite 235/235; nach Fix `PageSearchTest|UnifiedSearchTest` auf sqlite, mariadb, pgsql OK; E2E nginx alle 7 Specs Hell+Dunkel grün (attachments-search 137, editor 118, frame 143, stripped 113, xss 82, assets 82, budget 21), Apache `attachments-search`+`editor` Hell grün inkl. Auslieferungs-Header; 0 CSP/pageerror/Konsolenfehler; check-upstream grün.

- M3c1 erledigt (M3c geteilt): Kanban, `revisions.php`, `attachments.php`, `api_note_info.php`, `api_download_note.php`, Export-Routen (Rechte, dann 501), Alt-URL `api_attachments.php?action=download`, Revisionen-/Info-Knopf (Info auch im ⋮-Menü). `CompatController`, `AttachmentResponse`, `AccessService::filterReadable` (Kanban ohne N+1, äquivalent zu `noteLevel`, per Test). CSP `attachments` nur `frame-src 'self'` (ADR 0012 Nachtrag). Patches P5 (App-Präfix beim ersten Request), P13 (PDF iframe statt embed, keine PDF-Vorschau in Dateiauswahl). Fixes: `indexPath` im Adapter, `.speed-dial-state-off` (Speed Dial entfernte statt hinzuzufügen). B33/B34.
  Review `M3c1-diff-r1` (BLOCKER: CSP-Ausweitung, Testnachweis) → `M3c1-diff-r2` (FREIGABE).
  Testnachweis (sqlite/nginx): PHPUnit voll 248/248 (PageIsolationTest inkl. Detektor-Selbsttest, A vorher=nachher, `workspace` allein; FilterReadableTest 36 Fälle; PageCspTest; ControllerAttributeTest), E2E alle Specs Hell+Dunkel grün, `KNOWN_MISSING` leer; Nachlauf nach Fix `pages` 186, `editor` 118, `attachments-search` 137 je Theme, 0 CSP/Konsolenfehler/unpräfixierte Requests; Apache `attachments-search` Hell 137.

## M2 (fertig)
- Plan `docs/slices/M2-plan.md` r1: Slices M2a (Gerüst, Rechte, Notizen/Ordner/Workspaces/Tags/Papierkorb, M1-Nacharbeit), M2b (Anhänge, Snapshots, Suche, Sperre, Unified Search), M2c (Tasks, Mentions, Erinnerungen, Settings, Konvertierung), M2d (Kontolöschung, Abschlusslauf 3 DBs)

- Plan-Review `M2-plan-r1` (BLOCKER: Middleware 401/412, UID-Wiederverwendung, Body-IDs, Erinnerungen, Sperre vs. ADR 0005) → `M2-plan-r2` (FREIGABE). Lead: ADR 0005 gilt (409/423, Übernahme nur selbe Sitzung, Upstream-Abweichung B13); Netz einmalig für composer/Fixture-Build, Fixtures eingecheckt.
- M2a erledigt: Nacharbeit M1, Grundgerüst (AccessService, Fehler-Middleware, HtmlSanitizer, InputValidator, Workspace-Schlüssel), 53 Routen (Notizen, Ordner, Workspaces, Tags, Papierkorb), PHPUnit-Setup, Upstream-Fixtures. Review `M2a-diff-r1` (BLOCKER: Workspace-Löschen verschob in geteilte Workspaces → Thomas-Entscheid) → `M2a-diff-r2` (FREIGABE, N1 umgesetzt). SPEC §11.2 B12–B20.
  Lead: fremde IDs in `POST /notes/reorder` (`scope=kanban`, `visible_order`) und `linked_note_id` bei `PATCH /notes/{id}` werden ignoriert (200, kein Leck, A unverändert, wie Upstream) – akzeptiert.
  Testnachweis (tester, frische Instanzen NC 35.0.2): `run-m2.sh sqlite mariadb pgsql` Exit 0, je 110 Tests/2173 Assertions; `mysql.utf8mb4`=true; `check-forbidden-sql.sh` 0 Treffer (Selbsttest ok); Gegenprobe (`requireNote` für `linked_note_id` entfernt → Isolationstest rot, Code zurückgesetzt); `down.sh all` 0.
- M2b erledigt: Anhänge (IAppData, Quote je Workspace-Besitzer mit Sperre in Transaktion, Inline-Regel B22), Snapshots, Suche, Sperre (ADR 0005, B13), indirekte Lesepfade, Unified Search, LockCleanup-/SnapshotRetentionJob. Review `M2b-diff-r1` (BLOCKER: Doku Anhang-Auslieferung, B13) → `M2b-diff-r2` (FREIGABE).
  Testnachweis (tester, frische Instanzen NC 35.0.2): `run-m2.sh sqlite mariadb pgsql` Exit 0, je 153 Tests; `check-forbidden-sql.sh` 0; Gegenprobe (Leserecht in `AttachmentService::download`, `holder_uid` in `LockMapper::takeOver` entfernt → Isolation/Lock/Attachment/IndirectRead rot, zurückgesetzt); `down.sh all` 0.
  Lead: PDF-Anhänge behalten NC-Standard-CSP (`frame-ancestors 'none'`); falls M3 PDF im iFrame zeigt, dort `'self'` ergänzen (ADR 0012 prüfen).
- M2c erledigt: Tasks, Mentions, Erinnerungen (ReminderJob, Notifier, Leserecht, Wiederholung in Benutzerzeitzone, max. 1000 offen/Benutzer), Settings (lazy, Sperrliste, Limits), System, Users, Konvertierung 501; alle 112 API-Routen registriert. Review `M2c-diff-r1` (BLOCKER: task_id ungeprüft, Schreibpfad ohne Schreibrecht im Job) → `M2c-diff-r2` (FREIGABE). SPEC B23–B25.
  Testnachweis: tester (frische Instanzen NC 35.0.2) je DB 222 Tests, nur `testInvalidDueAtTimeOfDay` rot (Uhrzeit ungeprüft); Gegenprobe (`canRead` in `Notifier::prepare` entfernt → 2 Tests rot, zurückgesetzt). Nach Fix (`InputValidator::validDateTime`, Limit auch in `syncReminder`) Endlauf durch architekt mit unveränderten tester-Tests: `run-m2.sh` einzeln frisch sqlite/mariadb/pgsql rc=0, je 222 Tests/7075 Assertions, ohne Warnung; `down.sh all`.
  DNS-Warnung im Test: Ursache NC-App `password_policy` (HIBP-Abfrage beim Anlegen von Testbenutzern), nicht Next Notes; Testumgebung setzt `has_internet_connection=false`.
- M2d erledigt: Kontolöschung (synchroner Listener neutralisiert alle UID-/Rechte-Spalten, Löschkennung `#<16hex>`, `UserDataDeletionJob`, täglicher `DeletedWorkspaceSweepJob` als Nachlauf mit Fehlerisolation je Kennung), Migration 0.1.1 (5 Indizes), SQLite-Sperr-Timeout/Deadlock → 503 mit Retry-After, `run-m2.sh` über alle DBs mit Gesamt-Exit. Review `M2d-diff-r1` (BLOCKER: Löschung nach Abbruch nicht fortgesetzt) → `M2d-diff-r2` (FREIGABE, Hinweis Fehlerisolation umgesetzt).
  Testnachweis (tester, frische Instanzen NC 35.0.2): `run-m1.sh` (sqlite, mariadb, pgsql, nginx) Exit 0; `run-m2.sh sqlite mariadb pgsql` Exit 0, je 225 Tests/7267 Assertions; grep §13 M2.5 0 Treffer; Gegenprobe (`ws_shares`-Neutralisierung entfernt → UserDeletionTest rot, zurückgesetzt); `down.sh all` 0.
- M3c2 erledigt: `excalidraw_editor.php` (eigene Seite im NC-Rahmen, CSP ohne Ausnahme), `api_save_excalidraw.php` (`ExcalidrawService`, Sperre → 423 übersetzt, P14 Nur-Lesen), `workspaces.php` POST (Lesefreigabe → 403, Löschen/Verschieben/Sortieren mit `WorkspaceMapper::lockRows`), B35–B40. Sanitizer gehärtet: `Pcre` (Regex-Fehler → 413, nie leerer/unbereinigter Inhalt), lineare Markdown-/HTML-Bereinigung, Inhalt ≤ 16 MiB, Tag-/Attribut-Budget, `on*`-Scanner in Markup-/Text-/Fremd-Sicht, `InputEncodingMiddleware` (ungültiges UTF-8 → 400). MariaDB: `search_text` in eigenem UPDATE, Grenze maskiert ≤ `max_allowed_packet` − 1 MiB, Suchtext an UTF-8-Grenze gekürzt (`fitStorable`), Duplikat prüft Größe, `Transaction` verdeckt Ursache nicht mehr.
  Reviews `M3c2-diff-r1` (BLOCKER) → `r2` (BLOCKER) → Lead-Entscheid nach 2 Runden: Befunde (Sanitizer-DoS/Datenverlust) in r3–r6 behoben, per Fuzz/Lasttest belegt; `M3c2-diff-r3` (BLOCKER Suchtext-Paketlimit) → r8 behoben und getestet.
  Testnachweis: sqlite Teil 1 (B37–B39, ExcalidrawSave u. a. 97 Tests; 16-MiB-Angriffsformen ≤ 0,5 s → 413; Fuzz 3×300k ohne verbliebene Handler), E2E `excalidraw` Hell/Dunkel je 46, 0 CSP. **M3-Abschluss volle Matrix:** `run-m3.sh` sqlite (PHPUnit 274, E2E 9 Specs × Hell/Dunkel), pgsql 274, nginx E2E 9×2, mariadb 274 (nach r7) – alle rc0; Build 2× 129 Dateien reproduzierbar, check-upstream grün; `run-m2.sh` (3 DBs je 274) rc0; `run-m1.sh` (sqlite, mariadb, pgsql, nginx) rc0. Nachlauf r8 sqlite+mariadb: 276 Tests (inkl. İ-Notiz 10,5 MiB → 200 + Suche, Duplikat über Grenze → 413 ohne Teilkopie) grün.

- M3-fix1 erledigt (Befund Thomas): Notiz blieb nach Anhang-Seite/Excalidraw ~90 s „in anderem Tab bearbeitet“ (später Heartbeat holte die Sperre mit alter Sitzung zurück, Adapter erzwang neue Sitzungs-ID je Laden). Fix nur im Adapter (`nextnotes-bootstrap.js` §10): Sitzungs-ID je Tab, belegt per Web Lock solange eine Seite lebt; belegt → neue ID (Duplikat/`window.open` bleiben Nur-Lesen, P7); ohne Web Locks altes Verhalten. SPEC §4.3 Nr. 8.
  Review `M3-fix1-diff-r1` (BLOCKER: Duplikat über NC-Seite ohne Bootstrap → zwei Tabs gleiche ID) → `r2` (FREIGABE); Hinweis synchroner `locks.request`-Fehler → neue ID (Lead).
  Testnachweis: E2E `lock-return` (A Anhang, B/C Excalidraw, D Duplikat, E1/E2 Duplikat über Dateien-App, F Zurück) 76 Prüfungen grün, gegen alten Stand rot; `editor` 118, `excalidraw` 46 (sqlite, hell). Firefox/Safari/dunkel nicht getestet.

  **M3 fertig** – Frontend im NC-Rahmen, Editor, Anhänge, Suche, Kanban, Revisionen, Excalidraw; volle Matrix grün (2026-11-03).

  **M2 fertig** – Kriterien 1–8 je mit Test belegt (RouteDiff, Isolation/IndirectRead/DenyByDefault, CoreScenario/Attachment, WorkspaceName, check-forbidden-sql + 3 DBs, LockService, ReminderJob/UnifiedSearch, UserDeletion).
- Offen für M3: PDF-Vorschau im iFrame (CSP, s. o.); `requesttoken` im Beacon-FormData (B4); Sperr-Banner bei gleichem Konto in zweitem Tab (R11); Escaping von Tags mit `'` im Frontend.

## Nacharbeit (vor/mit M2)
- erledigt in M2a
- erledigt in M2d: SQLite-503
- erledigt in M3a2: doppelter Tasklist-Request, doppeltes `X-Requested-With`, `Inter`-Prüfung
- M4: `allowed_iframe_domains` im Admin-Formular auch mit Schema (`https://…`) annehmen bzw. normalisieren, Fehler melden statt still verwerfen
- M3b: Suche schreibt `requesttoken` per History-Update in die URL (mit Such-POST beheben); an `body` angehängte Poznote-Elemente ohne Formular-Reset prüfen; eigene Overrides brauchen Spezifität ≥ 1,1,0
- M7: nginx-Beispielkonfiguration sendet `X-Content-Type-Options` doppelt (App setzt ihn bereits) – in Betriebsdoku/Beispiel-Conf bereinigen
- M5: `moveNotes`/`reorder` prüfen bei Teams den Eigentümer (B17); CleanupJob für verwaiste Excalidraw-Vorschauen
- Tests: Nachlauf-Filter eng halten (`Note`/`Search` treffen Methodennamen → fast volle Suite)
- M5: `AccessService::filterReadable` und `noteLevel`/`visibleWorkspaceIds` gemeinsam um Teams erweitern (FilterReadableTest sichert ab)
- Hinweis: CSS-Nesting (`&`) zählt `check-css` nicht mit – bei Upstream-Updates beachten

## Nächster Schritt
M4 – Plan `docs/slices/M4-plan.md` (Export, `calendar.js`/`background-settings.js`, `allowed_iframe_domains` normalisieren, `api_download_note` für verknüpfte Notizen, „Neuer Workspace“, Excalidraw-Bild einfügen unter CSP): architekt plant → speccritic → Slices.
