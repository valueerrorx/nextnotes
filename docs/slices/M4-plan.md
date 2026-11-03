# M4 – Feature-Parität: Plan

Stand: 2026-11-03, Architekt, r2 (Review r1 + Lead-Entscheidungen eingearbeitet). Grundlage: SPEC §1.2 (Nr. 3, 5), §2.2, §3, §4.2–4.6, §5, §6, §11, §13 M4 (Krit. 1–5); ADR 0002, 0004, 0008, 0009, 0012, 0016; Inventur A #186–199, #208, #237, B §1.1, §1.6; STATUS „Nacharbeit“/„Nächster Schritt“; M3-plan „M4-Vormerkungen“.
Nicht in M4: Teams, Freigabe-Oberfläche (`share_workspace`, M5), Aufgaben/Abgaben (M6), `IMigrator`/`occ`-Export (M7, M2-plan O10), CleanupJob (M5).
Regeln wie M3: Daten über `AccessService`; Templates nach Ausgaberegel §4.6; Seiten-GET ohne Seiteneffekt; jede `src/`-Änderung in §11.1 + `upstream-patches.txt`; keine Migration in M4 geplant (Speicher: vorhandene Tabellen, `IUserConfig`, `IAppConfig`, `IAppData`).

## 0. Slice-Teilung

| Slice | Inhalt | Testzeit (Schätzung) |
|---|---|---|
| **M4a** | Admin-Einstellungen (`ISettings`), Embed-Domains normalisieren und vereinheitlichen (B41), „Neuer Workspace“, Excalidraw „Bild einfügen“ unter CSP | ≈ 15 min |
| **M4b** | Konvertierung (Parser-Port), `/convert-*`, `notes/{id}/convert`, Snapshot `render=1`, Base64-Bilder → Anhänge, Drag&Drop-Import einzelner Dateien (B44), Kanban-Kartenlinks (#1672) | ≈ 25 min |
| **M4c** | Export Einzelnotiz: `api_export_note.php` (HTML, Markdown, JSON, eingebettet, Druckansicht), `api_download_note.php`, verknüpfte Notizen (B42, `?note=` → Ziel), Menüpunkte Download/Drucken/Export/Umwandeln | ≈ 20 min |
| **M4d** | ZIP-Exporte: Ordner, alle Notizen, strukturiert, Anhänge (B45); Ordner-Menü „Herunterladen“; Upstream-Import-Lesbarkeit | ≈ 20 min |
| **M4e** | Seiten I: `trash.php`, `list_tags.php`, `list_folders.php`, `attachments_list.php`, `notes_manager.php`, `create.php`; Icon-Leiste dazu | ≈ 25 min |
| **M4f** | Tagebuch und Aufgaben: `diary.php`, `DiaryApiController` (A #186–190), `calendar.js` zurück in den Build, Tagebuch-Automatik (Vorlage, Datumsordner), `tasks.php` | ≈ 25 min |
| **M4g** | `dashboard.php`, `graph.php`, `speed_dial.php`, `mentions.php` (+ Benachrichtigung `mention`), Hintergrundbild (`api_upload_background.php`, B43, `background-settings.js` zurück) | ≈ 25 min |
| **M4h** | Persönliche Einstellungen (`settings.php` reduziert), `storage-stats-user.php`, Workspaces-Bereich (`wsmenu:edit-workspaces`), `workspaces.php` GET, Export-Karten | ≈ 25 min |
| **M4i** | Abschluss: Krit. 1–5 vollständig (alle Ansichten, gestrichene Routen 404, Fremdzugriff aller M4-Routen, indirekte Lesepfade), Budget, `check-upstream` Rest (M3 O8), volle Matrix | Meilenstein-Abschluss (Stufe „volle Matrix“) |

Reihenfolge = Tabelle. M4c/M4d hängen an M4b (Konvertierung), M4h an M4d (Export-Karten) und M4g (Hintergrund). Jede Slice einzeln lauffähig und committet.

**Zuordnung SPEC §13 M4 → Slice**
| Krit. | Inhalt | Slices |
|---|---|---|
| 1 | Smoke je Ansicht inkl. Hauptaktion, 0 CSP | M4a (Excalidraw-Bild, Workspaces anlegen), M4e, M4f, M4g, M4h; vollständig M4i |
| 2 | Exporte laden herunter, Upstream-Import liest sie; linked | M4c, M4d |
| 3 | Gestrichenes unsichtbar, Routen 404 | je Slice für neue Seiten/Menüs; Routenliste M4i |
| 4 | Fremdzugriff M4-Routen | je Slice für eigene Routen; Gesamtlauf M4i |
| 5 | Indirekte Lesepfade | M4f (Kalender), M4g (Graph, Mentions, Dashboard); Gesamtlauf M4i |

## 1. Gemeinsame Architektur

### 1.1 Seiten
- Je Seite: Route in `routes.php` (Upstream-Dateiname, ADR 0004), `PageController::<seite>` dünn, Daten aus `Service/View/<Seite>ViewService`, Template `templates/<seite>.php` (Kopfkommentar „abgeleitet von …“), Bündel `pages.<seite>` in `bundles.json` (Skriptliste gegen `src/public/<seite>.php`, `check-upstream`), CSS aus vorhandenem `cssPages.<seite>`.
- M4-Hauptansichten (Dashboard, Tagebuch, Aufgaben, Graph, Speed Dial, Mentions, Tags, Ordner, Anhänge, Notizverwaltung, Papierkorb, Erstellen, Einstellungen) erhalten die Icon-Leiste (`partials/icon_sidebar.php`): sie sind gleichrangige Ansichten. Unterseiten aus M3 (Revisionen, Anhänge einer Notiz) bleiben ohne. Icon-Einträge kommen mit der Slice, die die Seite liefert; bis dahin weggelassen (wie M3 O5).
- Listen aus einer Abfrage je Typ über `visibleWorkspaceIds`/`filterReadable` (kein N+1); unbekannter `?workspace=` → Standard-Workspace (§4.4).
- Inline-Skripte der Upstream-Seiten → JSON-Inseln/InitialState (§4.6); `on…=` → `data-action` + Delegation (§4.3.11). Formular-POSTs (Papierkorb-Suche) mit verstecktem `requesttoken` wie `POST index.php`.
- CSP: NC-Standard; `CspFactory::NOTE_VIEWS` nur, wo Notizinhalt mit iframes gerendert wird (`trash`, `diary`, `dashboard`-Vorschau nur falls Inhalt gerendert wird – im Slice belegen).

### 1.2 Konvertierung (M4b, entscheidet SPEC §1.2.5)
- **Entscheidung: Port der Upstream-Parser** (`src/markdown_parser.php`, `src/html_to_markdown.php`) als Klassen `Service/Convert/MarkdownRenderer` und `Service/Convert/HtmlToMarkdown`, keine Composer-Abhängigkeit. Grund: Ausgabe-Parität mit dem Frontend (Callouts, Tabellenformeln, Mermaid, KaTeX, Excalidraw-Container), die league/commonmark nicht liefert; kein Präfix-Build. Änderungen beim Port nur: Namespace/Klasse, `preg_*` → `Pcre` (B37-Regel: Regex-Fehler → 413, nie leerer Inhalt), keine Globals.
- Jede Ausgabe, die den Server als HTML verlässt (Snapshot `render=1`, Tagebuch-Einträge, Export, Konvertierung), läuft durch `HtmlSanitizer`; Speichern wie immer über `NoteService::applyContent`.
- **Regex:** jeder Regex-Aufruf in `Service/Convert/*`, `ExportFormatter`, Import-Helfern über `Pcre` (kein direktes `preg_*`; geprüft per `grep` in `check-upstream`, ab M4b). Regex-Fehler → 413, nie leere/teilweise Ausgabe.
- **Grenzen (gemessen, Host-PHP 8.5, Upstream-Parser):** normaler Text 1 MiB md→html 0,32 s, Listen 0,54 s; quadratische Pfade: Unterstrich-Hervorhebung `_a ` (`markdown_parser.php:559`, `(.*?\S)_` mit `/s`) 64 KiB 0,87 s / 256 KiB 13,9 s, html→md bei vielen offenen `<a href>`/verschachtelten Tags 128 KiB 0,7 s; `*`×1 MiB bzw. `_a `×1 MiB → Regex-Fehler, Upstream liefert still leere Ausgabe (B46). Festlegung:
  - `ConversionLimits::MAX_RENDER_INPUT = 1 MiB` für jede einzelne Umwandlung (`/convert-markdown`, `/convert-html`, `notes/{id}/convert`, Snapshot `render=1`, Tagebuch-Eintrag, Export-Rendering); darüber 413 vor dem Parsen.
  - Port behebt die quadratischen Pfade (B46: Hervorhebung nicht über Leerzeilen und höchstens 4 096 Zeichen Spanne; html→md-Muster mit begrenzter Spanne/possessiv). Ziel: schlechtester Fall des Grenzfall-Korpus bei 1 MiB ≤ 1 s. Ist ein Pfad im Slice nicht behebbar, sinkt die Grenze auf 64 KiB (gemessen ≤ 1 s) und der Architekt meldet es.
  - **Mehrfach-Render je Request:** Budget `MAX_RENDER_PER_REQUEST = 1 MiB` Summe der Eingaben. Kanban-Karten: Vorschau aus den ersten 4 KiB je Karte (wie Upstream nur Ausschnitt). `diary/entries.php`: Einträge bis zum Budget gerendert, Rest mit `html: null, truncated: true` (Frontend zeigt Link „Öffnen“; Befund im Slice, ggf. Adapter). Snapshot `render=1`: ein Render, > 1 MiB → 413.
- `check-upstream`: neue Liste `build/upstream-ported.txt` (Upstream-Datei + sha256 der Basis) für nachgebaute Logik (Parser, Export-Helfer, Import-Helfer, Tagebuch-Helfer). Abweichender Hash → Exit 1 mit Hinweis „Port prüfen“, nach Prüfung Hash aktualisieren. Schließt M3 O8 teilweise.

### 1.3 Export (M4c, M4d)
- `Service/ExportService` (+ `ExportFormatter` für Front Matter, Dateinamen, Tasklist→Markdown wie `src/export_helpers.php`). Formate und ZIP-Aufbau wie Upstream (Dateinamen, `index.html`, `README.md`, Front Matter, `attachments/`, `poznote_attachments_metadata.json`), damit Upstream-Poznote sie importiert.
- **ZIP-Bau:** je Export ein Ordner aus `ITempManager::getTemporaryFolder('nextnotes-export')`; darin das ZIP (`ZipArchive`). Nicht `ZipResponse`: der NC-`Streamer` liefert bei Mac-User-Agents tar (`isUserAgentPreferTar`), Upstream-Import erwartet zip. Notizinhalte per `addFromString` (je ≤ 16 MiB). **Anhänge nie per `addFromString`:** `ISimpleFile::read()` → `stream_copy_to_stream` in eine Temp-Datei im Export-Ordner → `ZipArchive::addFile` (Speicher O(Puffer), Platte ≤ 2 × Exportgröße bis `close()`).
- **Aufräumen:** `try/finally` im `ExportService` löscht den Export-Ordner rekursiv, auch bei Ausnahme/Abbruch. Auslieferung: fertiges ZIP mit `fopen('rb')` öffnen, Ordner löschen, `StreamResponse(<Ressource>)` (OCP nimmt Ressourcen; Linux gibt den Platz nach Request-Ende frei) → nach Rückgabe liegt nichts mehr auf der Platte. Für harte Abbrüche (Timeout, Fatal, Kill): neuer `BackgroundJob/ExportTempCleanupJob` (`TimedJob`, stündlich, `info.xml`) löscht in `ITempManager::getTempBaseDir()` Einträge `oc_tmp_*.nextnotes-export` älter als 1 h. Kern ruft `ITempManager::cleanOld()` nicht auf (geprüft 35.0.2), daher eigener Job.
- **Gesamtgrenze:** `ExportService::MAX_EXPORT_BYTES = 1 GiB` (unkomprimiert). Vor dem Packen eine Summenabfrage aus der DB (`func()->sum('size')` der einbezogenen Anhänge + `func()->sum(func()->octetLength('content'))` der Notizen über `IFunctionBuilder`, je eine Abfrage, kein DB-spezifisches SQL; Mapper-Methoden in DB-Matrix) → darüber 413 mit Meldung, kein Temp-Ordner angelegt. Hinweis: `attachment_max_file_mb` kann bis 2048 MiB stehen; ein einzelner Anhang > 1 GiB ist nur einzeln über `attachments/{id}` ladbar, nicht im Export (Admin-Formular nennt das).
- Alle Export-GETs `#[NoCSRFRequired]` (Download, ohne Seiteneffekt), `#[UserRateLimit(limit: 10, period: 60)]` auf ZIP-Exporten (§4.5). Laufzeit wird im Test mit 1 000 Notizen gemessen.
- **Umfang** (ADR 0008): „alle Notizen“, „strukturiert“ ohne `workspace`, „alle Anhänge“ = nur eigene Workspaces (`owner_type=user`, `owner_id=uid`). Einzelnotiz, Ordner, strukturiert mit `workspace=` = alles, was der Aufrufer lesen darf (Q2, vorläufige Lead-Entscheidung). Papierkorb nie (B45).
- **Referenzen im Inhalt (Rechte, B47):**
  - Eingebettet (`html_embedded` → `data:`) oder ins ZIP gelegt werden nur Anhänge der exportierten Notizen selbst (Abfrage `note_id IN (<exportierte IDs>)`, ohne Papierkorb). Anhang-URLs im Inhalt (`/api/v1/notes/{nid}/attachments/{aid}`, `api_attachments.php?…note_id=`) mit fremder `note_id` oder unbekannter `aid` bleiben als Link stehen, nichts eingebettet, kein Lesezugriff auf die Datei.
  - `[[<id>|…]]` und Notiz-Links: alle Ziel-IDs des Exports gesammelt, **ein** Aufruf `AccessService::filterReadable` (kein N+1), dann eine Titelabfrage nur für die lesbaren. Nicht lesbare/fehlende Ziele → neutraler Text „Notiz nicht verfügbar“ (übersetzt), ohne Titel und ohne Link-ID.
  - Verknüpfte Notizen (`type=linked`) in Ordner-/Gesamt-/strukturierten ZIPs: Ziele ebenfalls über diesen einen `filterReadable`-Aufruf; lesbar → Inhalt des Ziels, sonst leere Hülle mit Titel der Hülle (Titel gehört dem Aufrufer).
- **`disposition`:** `inline` nur bei `format=html` (Druckansicht); bei jedem anderen Format wird `inline` ignoriert → `attachment`. Alle Datei-Antworten mit `X-Content-Type-Options: nosniff`.
- Druckansicht `api_export_note.php?format=html&disposition=inline`: `TemplateResponse` `RENDER_AS_BLANK` (`templates/export_print.php`), Skript `js-src/nextnotes-print.js` (Callouts öffnen bei `beforeprint`, Mermaid/KaTeX über `nextnotes-late.js`-Pfade, setzt `window.poznotePrintReady`), CSP wie Notizansicht (`CspFactory::NOTE_VIEWS` mit `frame-src` der erlaubten Embed-Domains). Datei-Exporte: Inhalt `HtmlSanitizer`, keine Skripte in exportierten HTML-Dateien (Upstream-Inline-Skript für Callouts entfällt im Download; Druck nutzt die Seite).

### 1.4 Embed-Domains (M4a)
- `Service/EmbedDomainService` ist die einzige Quelle für `HtmlSanitizer::iframeSrcIsTrusted`, `CspFactory`, InitialState `allowedIframeDomains` (JS-Prüfung `markdown-parser.js`). `HtmlSanitizer::IFRAME_DOMAINS` entfällt.
- `normalize(string): string` nimmt `youtube.com`, `https://www.youtube.com/`, `HTTPS://YouTube.com/embed/x`, `youtube.com.`; entfernt Schema (`http`/`https`), Pfad, Query, Fragment, Schlusspunkt; IDN → Punycode (`idn_to_ascii`, fehlt `intl` → Fehler „nur ASCII“). Fehler (mit Eintrag und Grund): anderes Schema, Port, Benutzerinfo, `*`, IP-Adresse, leerer/zu langer Host, ungültige Labels, Host aus nur einem Label (`localhost`, `com`), IP-Literal (IPv4, IPv6 auch in `[]`). Mehr als 100 Einträge → Fehler. Keine Public Suffix List (Lead-Entscheidung: zu viel Wartung); nicht nötig, weil Einträge **exakte Hosts** sind (kein Wildcard) und damit `*.com` nicht entstehen kann.
- Admin-Formular: Speichern → 400 mit Liste aller fehlerhaften Einträge, **nichts** gespeichert; sonst normalisierte, deduplizierte Liste (`IAppConfig::setValueArray`). Lesen (auch nach `occ config:app:set`) normalisiert tolerant; verworfene Einträge → einmal `warning` im Log statt still.
- **Exakte Hosts, eine Liste (B41):** Sanitizer, CSP und JS prüfen gleich: `host === eintrag`, keine Subdomains. CSP je Eintrag genau `https://<host>`. Wer `m.youtube.com` will, trägt es selbst ein. JS: `markdown-parser.js` `_mdIsAllowedIframeSrc` erlaubt Upstream `endsWith('.' + domain)` → Patch P17 (nur exakter Vergleich), sonst zeigt die Vorschau Subdomain-iframes, die CSP blockiert und Sanitizer beim Speichern entfernt.

## 2. Slices

### M4a – Admin-Einstellungen, Embed-Domains, Neuer Workspace, Excalidraw-Bild

**App**
- `lib/Settings/{AdminSettings,AdminSection}.php` (`ISettings`, `IIconSection`), `info.xml` `<settings>`, `templates/settings/admin.php`, `js-src/nextnotes-admin.js` (Formular ohne Inline-Skript, Fehlerliste anzeigen), `lib/Controller/AdminSettingsController.php` (POST, ohne `NoAdminRequired` → nur Admin, CSRF aktiv): `allowed_iframe_domains`, `attachment_max_file_mb` (1–2048), `attachment_quota_mb` (1–1 048 576), Werte geprüft → 400. Hinweistext: Exporte sind auf 1 GiB begrenzt (§1.3); Embed-Liste ab Werk leer, Hinweis auf `youtube-nocookie.com` (Q1).
- `Service/EmbedDomainService` (§1.4); `HtmlSanitizer`, `CspFactory`, `PageController` umgestellt.
- „Neuer Workspace“: `createWorkspaceModal` in `partials/modals.php` (aus `src/modals.php:646`), `wsmenu:new-workspace` aus `FORCED_HIDDEN`; `workspaces-create.js` ist im App-Bündel (prüfen, sonst aufnehmen). Server: vorhandenes `POST /workspaces` (Name nach B20, Tags).
- Excalidraw „Bild einfügen“: Probe auf `excalidraw_editor.php` (PNG 200 KB und 3 MB einfügen, speichern, neu laden). Der Vendor erzeugt pica-Worker per `data:`-URL und nutzt WebAssembly. Entscheidung nach Befund: (a) keine Verletzung → nichts; (b) Funktion arbeitet über den JS-Rückfall, Browser meldet nur die blockierte `worker-src`/`wasm`-Ausnahme → keine CSP-Lockerung, genau diese Meldung als erwartete Ausnahme im Test (nur diese Seite, Direktive + `blockedURI`-Schema), ADR 0012 Nachtrag; (c) Funktion scheitert → ADR 0018 (`worker-src data:` bzw. `allowEvalWasm()` nur für `excalidraw_editor`), Lead entscheidet. Diagramm > 7 MiB (große Bilder) → 413 mit übersetzter Meldung im Editor (vorhandene Grenze, Text prüfen).

**Done M4a**
1. Admin: Formular unter NC-Verwaltung sichtbar nur für Admin; Nicht-Admin POST → 403. Eingaben `https://www.youtube.com/embed/x`, `YouTube.com`, `bücher.example` → gespeichert `www.youtube.com`, `youtube.com`, `xn--bcher-kva.example`; Eingabe mit `ftp://x`, `a.com:8080`, `*.a.com`, `1.2.3.4`, `[::1]`, `com`, `localhost` → 400, Antwort nennt alle sieben, gespeicherte Liste unverändert; CSP enthält keinen `*`-Eintrag (PHPUnit). Ab Werk ist die Liste leer (Q1, vorläufig). `occ config:app:set nextnotes allowed_iframe_domains --type=array --value='["https://Vimeo.com/"]'` → CSP enthält genau `https://vimeo.com` (kein `*.vimeo.com`), Log-Warnung bei ungültigem Eintrag.
2. B41 (PHPUnit): Notiz mit `<iframe src="https://www.youtube.com/embed/x">` bei leerer Liste → iframe entfernt; nach Eintrag `www.youtube.com` → bleibt, Seite `index.php?note=` hat passendes `frame-src`; Eintrag nur `youtube.com` → `www.youtube.com`-iframe entfernt (Sanitizer) und JS-Vorschau rendert ihn nicht (exakt, P17); E2E: iframe lädt ohne CSP-Verletzung (Testdomain über lokalen Stub-Host, kein Netz).
3. Quota/Max-Datei: Werte außerhalb → 400; geänderte Grenze wirkt beim nächsten Upload.
4. Neuer Workspace (E2E Hell/Dunkel): Workspace-Menü → „Neuer Workspace“ → Name mit `'` und Tags → Seite wechselt auf den neuen Workspace; Name mit `<` → Fehlermeldung im Dialog, nichts angelegt; 0 CSP/Konsolenfehler.
5. Excalidraw: Bild einfügen + speichern + Vorschau in der Notiz sichtbar; CSP-Ergebnis nach (a)/(b)/(c) dokumentiert.
**Testart:** Slice: PHPUnit (EmbedDomain, AdminSettings, Sanitizer, Csp) sqlite; E2E `admin`, `workspace-create`, `excalidraw` Hell+Dunkel, nginx. DB-Matrix entfällt (kein SQL).

### M4b – Konvertierung, Import, Kanban-Links

**App**
- `Service/Convert/{MarkdownRenderer,HtmlToMarkdown}` (§1.2), `Service/ConversionService`: `POST /convert-markdown`, `POST /convert-html` (reine Umwandlung, Eingabe ≤ `MAX_RENDER_INPUT` = 1 MiB sonst 413, Ausgabe sanitisiert; `#[UserRateLimit(limit: 60, period: 60)]`), `POST /notes/{id}/convert` (Schreibrecht, Sperre wie PATCH, Snapshot vor Umwandlung wie Upstream, Base64-Bilder → Anhänge über `AttachmentService` inkl. Quote).
- Base64-Bilder beim Speichern von HTML-Notizen (Upstream `NotesController.php:1430`) → Anhänge; Grenzen: `attachment_max_file_mb` je Bild, Quote; über Grenze → Bild bleibt nicht als Base64 (413 mit Meldung), keine Teiländerung. **Rollback:** in diesem Speichervorgang geschriebene `IAppData`-Dateien werden bei Fehler/Rollback gelöscht (Liste der neuen Dateien, `finally` nach fehlgeschlagener Transaktion); DB-Zeilen über die Transaktion.
- `SnapshotsApiController` `render=1` → `html` aus `MarkdownRenderer` (Revisionen-Seite zeigt Markdown-Vorschau); Inhalt > 1 MiB → 413 (§1.2).
- `notes/{id}/convert` und Notizinhalt im Export: Eingabe > 1 MiB → 413 (Speichern bleibt bis 16 MiB möglich; nur die Umwandlung ist begrenzt).
- `CompatController::importDroppedNotes` (`api_import_dropped_notes.php`, POST, CSRF, `#[UserRateLimit]`): Dateien `.md`, `.markdown`, `.txt`, `.html`, `.htm`, Tasklist-`.json`; Front Matter (Titel, Tags, Favorit) wie `import_helpers.php` `parseFrontMatter`/`extractNoteMetadata`; Anlage über `NoteService` (Sanitizer, Titel-Eindeutigkeit); Ziel-Workspace Pflicht (`workspace`-Schlüssel, Schreibrecht), Ordner muss darin liegen (B15). `.zip` → 400 „ZIP-Import nicht unterstützt“ (ADR 0009). Höchstens 50 Dateien, je ≤ 16 MiB, **Summe ≤ 64 MiB je Request** (sonst 413 vor der Verarbeitung, nichts angelegt); Import rendert nicht (HTML → HTML-Notiz über Sanitizer, Markdown → Markdown-Notiz), daher keine Render-Grenze; Base64-Bilder in HTML wie oben.
- Kanban: Kartentext mit Links (#1672) aus `MarkdownRenderer`/Sanitizer statt Klartext; Vorschau aus den ersten 4 KiB je Karte, Budget 1 MiB je Request (§1.2); Karten über Budget → Klartext-Vorschau wie M3.
- UI: „Umwandeln“ im Notizmenü, Markdown-einfügen/HTML-einfügen-Modals (`insert-markdown-modal.js`, `paste-markdown-modal.js`), Slash-Befehle, die `/convert-*` rufen, wieder sichtbar.

**Done M4b**
1. Parität (PHPUnit): Fixture-Korpus (≥ 40 Fälle: Callouts, Tabellenformeln, Mermaid, KaTeX, Aufgabenlisten, Code mit Sprache, Excalidraw-Container, verschachtelte Listen, HTML-Durchreichung, Escapes); Erwartung mit Upstream-Funktionen per Host-PHP erzeugt (Skript in `tests/`), Port-Ausgabe nach Sanitizer = Upstream-Ausgabe nach Sanitizer (byte-gleich, Abweichungen nur mit Begründung in Liste).
2. Grenzen (PHPUnit, Zeitmessung im Container): Grenzfall-Korpus je 1 MiB (normaler Text, Listen 3 Ebenen, Tabellen, Zitat-Verschachtelung, `*`, `_a `, `[[[(`, Backticks; html: `<a href>` offen, `<div><ul><li><b>` verschachtelt, `<span class=callout>` offen) für `MarkdownRenderer` und `HtmlToMarkdown` → je ≤ 1 s und Ergebnis 200 oder 413, nie 500, nie leerer/teilweiser gespeicherter Inhalt. 1 MiB + 1 Byte an `/convert-*`, `notes/{id}/convert`, Snapshot `render=1` → 413 ohne Parsen. Fällt die Grenze auf 64 KiB (§1.2), gilt dasselbe mit 64 KiB.
3. Mehrfach-Render (Seed 1 000 Notizen, ein Ordner mit 1 000 Karten à 16 KiB): Kanban-Request Render-Anteil ≤ 1 s, Gesamt-TTFB ≤ 2 s; Budget-Überlauf → Klartext-Karten, 200.
4. Regex: `grep` findet kein `preg_` in `Service/Convert`, `ExportFormatter`, Import-Helfern (Prüfschritt in `check-upstream`).
5. Base64-Rollback: HTML mit 3 Bildern, 3. über `attachment_max_file_mb` → 413, Notiz unverändert, keine neuen Zeilen in `nextnotes_attachments`, keine neuen Dateien in `IAppData` (Dateiliste vorher = nachher).
6. Import: 50 Dateien Summe 65 MiB → 413, nichts angelegt; 51 Dateien → 400.
7. Rechte: B ruft `notes/{A}/convert` → 404; Lesefreigabe → 403; Import in fremden/lesend geteilten Workspace → 404/403.
8. E2E: HTML-Notiz → Markdown umwandeln → Inhalt da, Bild als Anhang; Markdown-Revision zeigt gerenderte Vorschau; Datei `notiz.md` mit Front Matter auf die Seitenleiste ziehen → Notiz mit Titel/Tags im aktuellen Workspace; Kanban-Karte zeigt Link. 0 CSP/Konsolenfehler.
**Testart:** PHPUnit Convert/Import/Snapshot/Kanban-Budget sqlite; Mapper-unabhängig → keine DB-Matrix; E2E `convert-import` Hell+Dunkel, nginx.

### M4c – Export Einzelnotiz, verknüpfte Notizen

**App**
- `CompatController::exportNote` → `ExportService::note` (Formate `html`, `html_embedded`, `markdown` (ZIP mit Anhängen wie Upstream `exportAsMarkdownZip`), `json` nur Tasklist, Druckansicht §1.3), Rechte `read`, `?workspace=` muss zur Notiz passen (sonst 404 wie `exportFolder`).
- `downloadNote` über `ExportService::download` (Upstream-Formatierung `generateDownloadStyledHtml` für HTML).
- **Verknüpfte Notizen (B42):** `type=linked` → Ziel über `AccessService::requireNote(linked_note_id, read)` auflösen (eine Stufe, Ziel selbst `linked` → 404); kein Leserecht/fehlt → 404. Gilt für Download, Export, Druck und `index.php?note=<linked>` (Seite zeigt das Ziel wie Upstream, Adresszeile bleibt). Bearbeiten des Ziels über die verknüpfte Hülle nur mit Schreibrecht auf das **Ziel** (`AccessService::canWrite(target)`); sonst Nur-Lesen-Ansicht wie bei Lesefreigabe, PATCH auf das Ziel → 403.
- Referenzen im Inhalt nach §1.3 (B47): Einbetten nur eigener Anhänge der Notiz, `[[…]]` über einen `filterReadable`-Aufruf.
- `disposition=inline` nur für `format=html`, sonst `attachment`; immer `nosniff` (§1.3, B48).
- UI: Notizmenü „Herunterladen“, „Drucken“, Export-Modal (`exportModal`) aus `src/modals.php`, `utils-export-create.js` (im Bündel prüfen). Diary-Export-Aufrufe (Slash/Tagebuch) erst M4f.

**Done M4c**
1. Je Typ (HTML, Markdown, Tasklist, Excalidraw) und Format: Download 200, Dateiname/Endung/MIME wie Upstream, Inhalt enthält Titel und Text; `html_embedded` enthält Bild als `data:`; Markdown-ZIP enthält Front Matter und Anhang.
2. Druckansicht öffnet, Mermaid/KaTeX gerendert, `poznotePrintReady` erfüllt; Notiz mit erlaubtem Embed (`youtube.com` in der Liste, Stub-Host) → iframe lädt, Antwort-CSP hat dasselbe `frame-src` wie `index.php?note=`, 0 CSP-Verletzungen.
3. Linked: A verlinkt eigene Notiz X → Download/Export liefern X; A verlinkt (Altbestand per DB) Notiz von C ohne Recht → 404, kein Inhalt von C; `?note=<linked>` zeigt X. Ziel X lesend geteilt (A hat nur Lesen auf X) → `?note=<linked>` Nur-Lesen, PATCH X → 403.
4. Fremdzugriff: B → 404 auf `api_export_note.php`/`api_download_note.php` für A-Notiz (alle Formate); Lesefreigabe → 200 (**bedingt Q2**).
5. **Fremdreferenzen (B47):** A-Notiz enthält `<img src="/api/v1/notes/<C-Notiz>/attachments/<C-Anhang>">`, `api_attachments.php?action=download&note_id=<C>&attachment_id=<C-Anhang>` und `[[<C-Notiz-ID>|x]]` → `html_embedded`, `markdown`-ZIP, `html`: kein `data:` mit C-Bytes, kein C-Anhang im ZIP, C-Titel nirgends im Export (Byte-Suche), `[[…]]` als „Notiz nicht verfügbar“; dieselben Referenzen auf A-eigene Notiz/Anhang → eingebettet bzw. mit Titel. Abfragezähler: Auflösung von 50 `[[…]]` ≤ 3 Abfragen.
6. `disposition=inline` mit `format=markdown`/`json`/`html_embedded` → `Content-Disposition: attachment`; alle Antworten `X-Content-Type-Options: nosniff`.
7. Menüpunkte sichtbar (Hell/Dunkel), keine 501 mehr im Request-Log.
**Testart:** PHPUnit Export/PageIsolation (gefiltert) sqlite; E2E `export-note` Hell+Dunkel, nginx; Apache einmal `export-note` Hell (Header `Content-Disposition`).

### M4d – ZIP-Exporte

**App**
- `CompatController::{exportFolder, exportEntries, exportStructured, exportAttachments}` → `ExportService` (§1.3), Routen `api_export_entries.php`, `api_export_structured.php`, `api_export_attachments.php`. Parameter `skip_s3_attachments` ignoriert (S3 gestrichen). ZIP-Bau, Aufräumen, Gesamtgrenze 1 GiB und Referenz-Regeln nach §1.3.
- `BackgroundJob/ExportTempCleanupJob` (`TimedJob`, 1 h, `info.xml`) nach §1.3.
- Ordner-Menü „Herunterladen“ (`utils-folders.js:483`) wieder sichtbar; Notizlisten-Menü „Alle exportieren“ (`utils-note-create.js:570`) sichtbar.

**Done M4d**
1. Je Export: ZIP öffnet (`ZipArchive::open` ok), Aufbau wie Upstream (Dateiliste gegen Fixture eines Upstream-Exports derselben Testdaten, erzeugt mit Upstream-Code per Host-PHP), Front Matter-Schlüssel gleich, Anhänge unter gleichem Pfad, `poznote_attachments_metadata.json` gültig.
2. **Upstream-Import liest sie:** Testskript lädt `src/import_helpers.php` per Host-PHP (`php -n`, nur Lesen) und ruft `poznoteAnalyzeImportZipStructure`, `parseFrontMatter`, `extractNoteMetadata`, `poznoteIsAttachmentsArchiveManifest` auf jedem Export auf → erkannte Notizen/Ordner/Anhänge = Export-Inhalt.
3. Umfang: A hat eigenen und einen von C lesend geteilten Workspace → „alle Notizen“/„alle Anhänge“ enthalten nur A-eigene; Papierkorb nie; strukturiert mit `workspace=<geteilt>` → 200 mit dem lesbaren Inhalt (**bedingt Q2**).
4. **Fremdreferenzen (B47):** A-Ordner enthält (a) Notiz mit C-Anhang-URL und `[[<C-ID>]]`, (b) `type=linked` auf C-Notiz ohne Recht, (c) `type=linked` auf A-eigene Notiz → Ordner-, „alle“-, strukturierter ZIP: kein C-Anhang in `attachments/` und Manifest, C-Titel/-Inhalt nirgends (Byte-Suche über alle Einträge), (b) als leere Hülle, (c) mit Zielinhalt; Ziel-Auflösung eines Exports mit 1 000 Notizen in ≤ 5 Abfragen (Abfragezähler).
5. Fremdzugriff: B → Ordner von A 404; `api_export_entries.php` als B enthält keine A-Daten; Rate-Limit greift (11. Aufruf in 60 s → 429).
6. Last: 1 000 Notizen/100 Ordner/50 Anhänge (Seed aus M3 `budget`) → Export ≤ 10 s, Spitzen-RSS des PHP-Prozesses ≤ 64 MB über dem Leerlauf. **Großer Anhang:** ein Anhang 200 MiB (`attachment_max_file_mb` dafür hochgesetzt) → „alle Anhänge“-ZIP 200, enthält ihn byte-gleich (sha256), RSS ≤ 64 MB über Leerlauf; danach keine `oc_tmp_*.nextnotes-export*` im Temp-Verzeichnis (`getTempBaseDir`). Abbruch: Client bricht den Download nach 1 MiB ab → Temp-Verzeichnis danach ebenfalls leer.
7. Gesamtgrenze: Export mit Summe > 1 GiB (PHPUnit: Mapper-Summe liefert 1 GiB + 1) → 413, kein Temp-Ordner angelegt.
8. Cleanup-Job: Temp-Ordner `oc_tmp_x.nextnotes-export` mit mtime −2 h und einer mit −10 min → Job-Lauf löscht nur den alten; fremde `oc_tmp_*` bleiben.
**Testart:** PHPUnit Export sqlite + mariadb + pgsql nur `ExportServiceTest` (neue Mapper-Abfragen inkl. `octetLength`-Summe); E2E `export-zip` Hell, nginx (Download-Ereignis); Dunkel nur Menü-Sichtbarkeit.

### M4e – Seiten I: Papierkorb, Tags, Ordner, Anhänge, Notizverwaltung, Erstellen

**App**
- Seiten `trash.php` (Liste, Suche per Formular-POST mit Token, wiederherstellen, endgültig löschen, leeren), `list_tags.php`, `list_folders.php`, `attachments_list.php`, `notes_manager.php` (Filter, Mehrfachaktionen über vorhandene API), `create.php` (Erstellen-Hub; Tagebuch-Eintrag erst ab M4f sichtbar).
- `favorites.php`: nicht portiert (B11, kein Link im Upstream-UI); Favoriten über die Seitenleiste. `welcome.php`: nicht portiert (kein Link; Standard-Workspace §4.4, keine Willkommensnotiz).
- Icon-Leiste: Einträge Tags, Ordner, Anhänge, Notizen, Papierkorb, „+“ → `create.php`.

**Done M4e**
1. Krit. 1 je Seite (Hell/Dunkel): Seite 200, Hauptaktion (Papierkorb: wiederherstellen; Tags: Tag-Klick filtert; Ordner: Liste lädt, Ordner öffnen; Anhänge: Liste lädt, Download; Notizverwaltung: Liste lädt, Filter; Erstellen: Notiz anlegen öffnet Editor); 0 CSP/pageerror/Konsolenfehler, 0 ≥ 400.
2. Krit. 4: `PageIsolationTest` um die sechs Routen erweitert (B sieht keine A-Titel/Tags/Ordner/Anhänge; Workspace-Parameter von A → B's Standard-Workspace).
3. XSS: Namen aus M3a2.4 auf allen sechs Seiten → kein zusätzliches Element/Attribut.
4. Gestrichenes: keine Teilen-/Offline-/Konten-Elemente auf den Seiten.
5. Performance: Seed 1 000 Notizen, TTFB je Seite ≤ 500 ms, ≤ 8 API-Requests, keine doppelte Abfrage pro Notiz (Abfragezähler im PHPUnit-Test ≤ 15 je Seite).
**Testart:** PHPUnit Page sqlite + mariadb + pgsql nur für die neuen ViewServices (neue Abfragen); E2E `pages-m4e` Hell+Dunkel, nginx.

### M4f – Tagebuch, Kalender, Aufgaben

**App**
- `DiaryApiController`: `GET api/v1/calendar/diary-entry.php`, `notes-by-date.php`, `notes-on-date.php`, `GET|POST api/v1/diary/diaries.php`, `GET api/v1/diary/entries.php` (gerendert über `MarkdownRenderer`/Sanitizer, Render-Budget 1 MiB je Request, Rest `html: null, truncated: true`, §1.2), Pfade inkl. `.php` (§4.2), alle über `visibleWorkspaceIds`/`requireWorkspace`; Datumswerte nach `InputValidator::validDateTime`.
- Tagebuch-Automatik wie Upstream (`NotesController.php:1075`, `:1357`): leere neue Notiz mit Tagebuch-Datumstitel in einem Tagebuch → Vorlage (`diary_templates`); Titeländerung auf ein Datum verschiebt nach `Tagebuch/JJJJ/MM` (Ordner bei Bedarf, im selben Workspace, Schreibrecht).
- `diary.php`, `tasks.php` (Aufgaben aller lesbaren Tasklisten, abhaken über vorhandene API); `js/calendar.js` aus `bundles.json` `exclude` → App-Bündel; Mini-Kalender in `notes_list.php`; Tagebuch-Modal, Tagebuch-Eintrag in `create.php`.

**Done M4f**
1. Krit. 1: Tagebuch „Eintrag für heute“ anlegen → Notiz mit Vorlage im Datumsordner; Aufgaben: Aufgabe abhaken → nach Reload abgehakt; Mini-Kalender lädt ohne ≥ 400.
2. Krit. 4/5: B ruft alle fünf Kalender/Tagebuch-Endpunkte mit Datum, an dem A Notizen hat → keine A-Daten; Tagebuch-ID von A → 404; `entries.php` rendert keine fremden Inhalte.
3. Ungültiges Datum (`2026-02-30`) → 400.
4. Budget Hauptansicht mit `calendar.js`: höchstens +1 API-Request gegenüber M3 (≤ 8 gesamt), 0 ≥ 400.
5. Render-Budget: Seed 1 000 Tagebuch-Einträge à 16 KiB in einem Monat → `entries.php` ≤ 2 s TTFB, Render-Anteil ≤ 1 s, Einträge über Budget mit `truncated: true`, Seite zeigt sie ohne Fehler.
**Testart:** PHPUnit Diary/Page sqlite + mariadb + pgsql (neue Datumsabfragen, kein DB-spezifisches SQL; `check-forbidden-sql.sh`); E2E `diary-tasks` Hell+Dunkel, nginx.

### M4g – Dashboard, Graph, Speed Dial, Mentions, Hintergrundbild

**App**
- `dashboard.php` (ersetzt die Spike-Seite), `graph.php` (vorhandene `GraphApiController`), `speed_dial.php`, `mentions.php` (Mitglieder nach ADR 0014).
- Benachrichtigung `mention` (§6, M2-plan O10): beim Speichern einer Notiz in einem geteilten Workspace neu hinzugekommene `@uid` (Diff alt/neu) → eine Benachrichtigung je Empfänger mit Leserecht; höchstens 20 Empfänger je Speichervorgang (weitere `@uid` werden ignoriert, kein Fehler; Zählung nach Deduplizierung); nie an sich selbst; `prepare` prüft Leserecht; Entzug der Freigabe löscht sie (vorhandener Pfad).
- Hintergrundbild (B43): `CompatController::background` (`api_upload_background.php` GET/POST/DELETE; `scope` `background`|`speed_dial`), Speicher `IAppData` `backgrounds/<workspaceId>/<uid>/<scope>.<ext>` (je Benutzer wie Upstream; Workspace zuerst, damit Workspace-Löschung **einen** Ordner über alle Benutzer entfernt), nur JPG/PNG/GIF/WebP nach Inhaltserkennung (`IMimeTypeDetector::detectContent`), ≤ 5 MiB; **Upload/Löschen nur mit Schreibrecht** auf den Workspace (Leser bekommen den Eintrag nicht angezeigt, POST → 403), Anzeige mit Leserecht; Kontolöschung entfernt `backgrounds/*/<uid>` (Liste der Workspace-Ordner, eine Ebene); Bild-URL `api_upload_background.php?image=1&workspace=…&scope=…&v=<mtime>` (`#[NoCSRFRequired]` nur für GET, `nosniff`, `Content-Disposition: inline`, CSP `sandbox`). Löschen bei Workspace-Löschung (`DeletedWorkspaceSweepJob`/Löschpfad: Ordner `backgrounds/<workspaceId>` für alle Benutzer) und Kontolöschung (`UserDataService`). `background-settings.js` aus `exclude`; Ebene `#poznote-background-layer` beginnt unter dem NC-Kopf (`nextnotes-layout.css`, kein Patch).

**Done M4g**
1. Krit. 1: Dashboard Karte öffnen → Notiz; Graph: Knoten sichtbar; Speed Dial: Kacheln der Tag-`speed-dial`-Notizen; Mentions: Liste lädt mit Erwähnung aus geteiltem Workspace.
2. Mention: A erwähnt B in geteiltem Workspace → genau eine NC-Benachrichtigung für B; erneutes Speichern ohne neue Erwähnung → keine weitere; C ohne Recht erwähnt → keine; 30 verschiedene berechtigte `@uid` in einem Speichervorgang → genau 20 Benachrichtigungen, Speichern 200.
3. Hintergrund: Upload PNG → Hauptansicht zeigt Ebene, Workspace umbenennen → Bild bleibt; SVG/HTML als `.png` → 400; 6 MiB → 413; B ruft Bild-URL mit A-Workspace → 404; abgemeldet → kein Bild; Leser des geteilten Workspace: POST → 403, Leser ohne eigenes Bild: GET → 404 (Bilder sind je Benutzer). Workspace löschen, in dem A und ein Schreib-Empfänger je ein Bild haben → `backgrounds/<wsId>` weg (beide); Kontolöschung von A → nur A-Bilder weg.
4. Krit. 4/5: Dashboard/Graph/Speed Dial/Mentions als B → keine A-Daten (Graph-Kanten zu fremden Notizen gefiltert).
**Testart:** PHPUnit Page/Mention/Background sqlite (+ mariadb/pgsql nur Mention-Abfrage); E2E `views-m4g` Hell+Dunkel, nginx; Apache einmal Bild-Auslieferung (Header).

### M4h – Persönliche Einstellungen, Workspaces-Bereich

**App**
- `settings.php` als App-Seite (Bündel mit `settings-page.js`), Karten nur mit Funktion im Port: Datum/Zeitformat, Anzeige (ohne Schriftwahl, ADR 0016), Elementsichtbarkeit, Seitenleiste (ohne Offline), Notizinhalt, Markdown (ohne Schrift), Tagebuch, Workspaces (`workspaces_section.php`: Liste, Umbenennen, Löschen/Verschieben über `workspaces.php` POST, Farbe, Standard-Workspace, Hintergrundbild), Export (direkte Links auf `api_export_*`, kein `backup_export.php`), Speicher (`storage-stats-user.php?fragment=1`, Summe aus `nextnotes_attachments` je Besitzer + Quote), Über (Herkunft Poznote). Sprache/Zeitzone/Profil: Hinweis + Link auf NC-Einstellungen. Weggelassen: Konto/Passwort/2FA/App-Passwörter/Konto löschen, Backup, Git, Integrationen, Apps/Erweiterung, Admin-Werkzeuge, Schriften.
- `settings-page.js` darf auf weggelassene Karten nicht scheitern (Befund im Slice; sonst Platzhalter `hidden` im Template, Patch nur wenn unvermeidbar).
- `workspaces.php` GET → 303 auf `settings.php#section=settings-workspaces-section-grid` (wie Upstream); `wsmenu:edit-workspaces` aus `FORCED_HIDDEN` (danach leer).

**Done M4h**
1. Krit. 1 „Workspaces: Liste lädt“: Einstellungen → Workspaces → umbenennen, Standard setzen, Hintergrund hochladen; jede Einstellung einmal ändern → nach Reload übernommen (Stichprobe 5 Karten).
2. Gestrichenes: keine der weggelassenen Karten im DOM sichtbar; Suche in den Einstellungen findet sie nicht.
3. `storage-stats-user.php` als B → nur B's Zahlen; Lesefreigabe zählt nicht in B's Summe.
4. Export-Karten laden ZIP herunter (aus M4d).
5. 0 CSP/pageerror/Konsolenfehler Hell+Dunkel.
**Testart:** PHPUnit Page/Storage sqlite; E2E `settings` Hell+Dunkel, nginx.

### M4i – Abschluss M4
- Krit. 1: `tests/nextnotes/e2e/smoke-m4.mjs` öffnet alle Ansichten aus B §1.1 (übernehmen) und führt die Hauptaktion aus (Liste §13 M4.1; Favoriten über die Seitenleiste, B11).
- Krit. 3: Routenliste aller „streichen“-Dateien aus Inventur B §1.2–1.5 und A (Status streichen) → 404 (`/shared*` → `[]`); UI-Prüfung `stripped.mjs` auf allen M4-Seiten.
- Krit. 4: `PageIsolationTest` vollständig über alle Seiten-/Kompatibilitätsrouten (M3 + M4) inkl. Detektor-Selbsttest, Lesefreigabe-Fälle.
- Krit. 5: `IndirectReadTest` um Kalender, Tagebuch-Einträge, Dashboard, Speed Dial, Mentions-Seite, Kanban (linked), Exporte erweitert; Exporte je Fall: (a) eingebettete Fremd-Anhang-URL (`html_embedded`, Markdown-ZIP, Ordner-/„alle“-/strukturierter/Anhang-ZIP) → keine C-Bytes, (b) `[[<C-ID>]]` → kein C-Titel, (c) `type=linked` auf C → leere Hülle bzw. 404 (Einzelnotiz); Fälle mit Lesefreigabe **bedingt Q2**.
- Grenzen-Gesamtlauf: Parser-Grenzfall-Korpus (M4b.2), Großanhang-Export + leeres Temp-Verzeichnis (M4d.6), Render-Budget Kanban/Tagebuch (M4b.3, M4f.5) einmal auf mariadb/pgsql.
- `check-upstream`: Rest O8 (direkte Asset-Tags der M4-Seiten gegen `pages`, `upstream-ported.txt`), `KNOWN_MISSING` leer, `FORCED_HIDDEN` leer.
- Budget §3.4 (M3-plan) auf Hauptansicht und Dashboard; volle Matrix: `run-m4.sh` (sqlite, mariadb, pgsql, nginx; PHPUnit + E2E Hell/Dunkel), Regression `run-m3.sh`, `run-m2.sh`, `run-m1.sh`.

## 3. Testsetup
- `dev/nextcloud/run-m4.sh [env…]` wie `run-m3.sh`, ergänzt um Host-PHP-Schritt für Parser-/Import-Fixtures (Upstream-Code nur lesend, außerhalb des Containers, `php -n`, Skript liegt unter `tests/`) und Seed 1 000 Notizen.
- Eine E2E-Spec je Slice (oben genannt), Harness aus M3 unverändert.
- Stufen nach `.claude/agents/tester.md`: Slice ≤ 30 min (sqlite + nginx, Hell+Dunkel; DB-Matrix nur gefiltert für neue Abfragen; Apache nur bei Header/Auslieferung), volle Matrix nur M4i.

## 4. Upstream-Patches (SPEC §11.1)
Geplant: P17. Weitere Kandidaten nur nach Befund, je mit Eintrag:
| # | Datei | Anlass | Slice |
|---|---|---|---|
| P15 (bedingt) | `settings-page.js` | Zugriff auf weggelassene Karten wirft | M4h |
| P16 (bedingt) | `utils-export-create.js` / `diary-page.js` | Druck-/Export-URL nicht über Adapter auflösbar | M4c/M4f |
| P17 | `markdown-parser.js` `_mdIsAllowedIframeSrc` | Subdomain-Vergleich → exakter Host (B41, eine Regel für Sanitizer/CSP/JS) | M4a |
Vorrang wie M3: Adapter, Template-Platzhalter, CSS vor Patch.

## 5. Upstream-Bugs (SPEC §11.2, einzutragen mit dem Slice)
| # | Fundstelle | Problem | Lösung | Slice |
|---|---|---|---|---|
| B11 | `src/public/favorites.php` | unverlinkt, toter Code | nicht portiert; Favoriten über Seitenleiste | M4e |
| B41 | `src/public/index.php:16` (CSP `https://<d>` exakt) vs. `src/lib/html-sanitize.php:64`, `markdown-parser.js:652` (Subdomains erlaubt); Port: Sanitizer feste Liste, CSP Admin-Liste | gespeicherte Subdomain-Embeds (`m.youtube.com`) werden von der CSP blockiert; im Port zusätzlich zwei Listen | eine Liste (`EmbedDomainService`) für Sanitizer, CSP und JS; exakte Hosts ohne Subdomains, CSP `https://<host>`; Normalisierung mit Fehlermeldung; JS-Patch P17 | M4a |
| B42 | `src/public/api_download_note.php:72` (`$validTypes` ohne `linked`) | Download/Export einer verknüpften Notiz liefert die leere Hülle | Ziel nur mit Leserecht, sonst 404 | M4c |
| B43 | `src/public/api_upload_background.php`, `docker/nginx/default.conf:117` | Hintergrundbilder ohne Anmeldung unter erratbarem Pfad ausgeliefert; Ordner nach Workspace-Name → Umbenennen verliert das Bild | `IAppData` je Benutzer + Workspace-ID, Auslieferung mit Anmeldung und Leserecht, Inhaltserkennung | M4g |
| B44 | `src/public/api_import_dropped_notes.php:41` | ohne `workspace` Import in den alphabetisch ersten Workspace | `workspace` Pflicht (Frontend sendet ihn), Schreibrecht | M4b |
| B45 | `src/public/api_export_attachments.php:107` | Anhang-Export enthält Anhänge von Notizen im Papierkorb, Notiz-Exporte nicht | Papierkorb in keinem Export | M4d |
| B46 | `src/markdown_parser.php:559` (`_…_` mit `(.*?\S)` und `/s`), `src/html_to_markdown.php` (offene `<a>`/verschachtelte Tags) | quadratische Laufzeit (256 KiB `_a ` = 13,9 s); bei 1 MiB `*`/`_a ` Regex-Fehler → still leere Ausgabe | Spanne begrenzt, `Pcre` (Fehler → 413), Eingabe ≤ 1 MiB | M4b |
| B47 | `src/public/api_export_note.php:265–290`, `:709`; ZIP-Exporte | Export bettet Anhänge/Titel beliebiger `note_id`/`[[id]]` aus dem Inhalt ein (fremde Daten exfiltrierbar) | nur Anhänge der exportierten Notizen; `[[…]]`/linked über einen `filterReadable` | M4c, M4d |
| B48 | `src/public/api_export_note.php:43–60` | `disposition=inline` für alle Formate (Markdown/JSON/eingebettetes HTML im App-Ursprung angezeigt) | `inline` nur `format=html` (Druck), sonst `attachment`, immer `nosniff` | M4c |
Weitere Funde im Slice ergänzen.

## 6. SPEC-/ADR-Änderungen (mit dem jeweiligen Slice)
| Stelle | Änderung | Slice |
|---|---|---|
| §1.2 Nr. 3 | `calendar.js`, `background-settings.js` wieder im Build | M4f, M4g |
| §1.2 Nr. 5 | Konvertierung: Port der Upstream-Parser, `upstream-ported.txt` | M4b |
| §1.4 / §4.6 | Admin-Formular (Embed-Domains, Grenzen); `EmbedDomainService` | M4a |
| §2.2 | Snapshot `render=1` umgesetzt; Hintergrundbilder `IAppData` | M4b, M4g |
| §4.2 | Routen Export/Import/Hintergrund/Kalender/Seiten; `workspaces.php` GET | je Slice |
| §8 | Kontolöschung/Workspace-Löschung entfernen Hintergrundbilder | M4g |
| §4.5 | Grenzen: Render 1 MiB + Budget, Export 1 GiB, Import 64 MiB/Request, Mention 20; `ExportTempCleanupJob` | M4b, M4d, M4g |
| §11.2 | B46–B48 | M4b, M4c |
| §13 M4.1 | Favoriten über Seitenleiste (B11) | M4e |
| ADR 0012 | Nachtrag Excalidraw-Bild (a/b) oder ADR 0018 (c) | M4a |

## 7. Risiken
- **R1 Umfang Seiten**: ≈ 5 500 Zeilen Upstream-Seiten-PHP + `settings-page.js` 5 500 Zeilen. Gegenmaßnahme: View-Services, Templates nur Datenquelle tauschen; Teilung nach Testzeit; wächst eine Slice über ≈ 1 500 Zeilen App-Code, teilt der Architekt (x1/x2) und meldet es.
- **R2 Parser-Port**: 120 Regex-Aufrufe; gemessen quadratisch (B46). Gegenmaßnahme: `Pcre`, Eingabe ≤ 1 MiB, Render-Budget je Request, Grenzfall-Korpus ≤ 1 s (M4b.2/3); Rückfall 64 KiB. Abweichung von Upstream-Ausgabe bei Hervorhebungen > 4 096 Zeichen (in der Paritätsliste).
- **R3 Export-Speicher/Platte**: Platte bis 2 × 1 GiB je laufendem Export (Kopien bis `close()`), 10 Exporte/min/Benutzer. Gegenmaßnahme: Gesamtgrenze vorab aus DB, Stream → Temp → `addFile`, `finally` + Cleanup-Job, Lastprobe M4d.6. Rest: gleichzeitige Exporte mehrerer Benutzer können das Temp-Verzeichnis füllen (Betriebshinweis in README).
- **R4 Excalidraw-CSP**: pica nutzt `data:`-Worker + WASM; ggf. ADR 0018 (Lead).
- **R5 Hintergrundbild-Ebene** liegt über dem NC-Rahmen (`position: fixed`). Gegenmaßnahme: Layout-CSS, Screenshot Hell/Dunkel.
- **R6 Requests beim Laden** (Kalender, Hintergrund) → Budget M4f.4/M4i.

## 8. Offene Punkte
| # | Punkt | Entscheidung / Vorschlag | Wer |
|---|---|---|---|
| O1 | Parser: Port vs. league | **entschieden**: Port (§1.2) | – |
| O2 | ZIP-Bau | **entschieden**: `ZipArchive` + Temp-Datei (kein tar für Mac-UA) | – |
| O3 | Einstellungen als App-Seite oder NC-Persönlich | **entschieden**: App-Seite (Frontend-Wiederverwendung); Admin-Teile in NC-Verwaltung | – |
| O4 | M4-Seiten mit Icon-Leiste | **entschieden**: ja (§1.1) | – |
| O5 | `favorites.php`, `welcome.php` | **entschieden**: nicht portiert | – |
| O6 | Hintergrundbild je Benutzer oder je Workspace | **entschieden**: je Benutzer und Workspace (wie Upstream, keine Wirkung auf andere) | – |
| O7 | Grenzwerte | **entschieden** (Lead/Architekt, gemessen): Render-Eingabe 1 MiB (Rückfall 64 KiB), Render-Budget 1 MiB/Request, Kanban-Vorschau 4 KiB/Karte, Export 1 GiB, Mention 20 Empfänger/Speichern, Import 64 MiB/Request | – |
| O8 | Export-Referenzen | **entschieden** (Lead): nur eigene Anhänge der exportierten Notizen; `[[…]]`/linked über einen `filterReadable` (B47) | – |
| Q1 | Standard-Embed-Domains: Upstream erlaubt ab Werk YouTube/Bilibili (Drittanbieter, Tracking); **vorläufig (Lead): leer**, Admin trägt ein (Hinweis im Formular auf `youtube-nocookie.com`) | Datenschutz, bei Thomas angefragt | **[THOMAS]** |
| Q2 | Darf ein Freigabe-Empfänger einen geteilten Workspace/Ordner/eine Notiz als Datei/ZIP exportieren? **Vorläufig (Lead):** wer lesen darf, darf exportieren (Einzelnotiz/Ordner/Workspace); „alle Notizen“/„alle Anhänge“ nur eigene Workspaces (ADR 0008). Kriterien „bedingt Q2“: M4c.4, M4d.3, M4i Krit. 5 (Lesefreigabe-Fälle) | Datenschutz, bei Thomas angefragt | **[THOMAS]** |

## Lead-Auflagen aus Review r2 (verbindlich für die Slices)
- M4d: höchstens ein laufender ZIP-Export je Benutzer (Sperre über `IMemcache::add`, sonst 429); `Content-Length` aus `fstat` der ausgelieferten Datei.
- M4c: die 1-MiB-Render-Grenze (413) gilt nur für Formate mit Umwandlung; Markdown-Export einer Markdown-Notiz ist nicht begrenzt (Fall in M4c.1).
- M4a: Admin-Hinweis nennt, dass der Sanitizer iframes nicht gelisteter Hosts beim Speichern dauerhaft entfernt.
- M4d: Schwelle `ExportTempCleanupJob` = max(1 h, `max_execution_time` + Reserve); im Slice festhalten und testen.
