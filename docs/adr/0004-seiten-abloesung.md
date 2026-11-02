# ADR 0004: Ablösung der PHP-Seiten (Auftragsfrage 3)

Status: entschieden (Lead, 2026-11-02)

## Kontext
Der Auftrag nennt 74 PHP-Seiten; die Inventur B findet 89 direkt aufrufbare Dateien, davon 50 HTML-Seiten/Fragmente, 21 Kernansichten mit Status „übernehmen“. Die Seiten rendern Markup serverseitig und lesen dabei direkt aus der SQLite (`$con`). Das JS (≈ 224 Dateien, 140 000 Zeilen) erwartet dieses Markup (IDs, `data-action`, JSON-Inseln) und klassische Globals. Leitprinzipien (Thomas, SPEC 1–4): Das Original ist Vorlage, kein Zwang; schlank, wartbar, Nextcloud-nativ, Einheitlichkeit vor 1:1-Treue; gezielte Änderungen in `src/` erlaubt, gelistet in SPEC §11.1.

## Optionen
1. **Eine `TemplateResponse` je Ansicht** (PageController), Templates aus den Upstream-Seiten abgeleitet, Daten über Services statt `$con`.
2. **Eine App-Shell** (eine Seite, Ansichten clientseitig gerendert) – verlangt Neuschreiben der serverseitigen Teile in JS.
3. Upstream-PHP unverändert per Include laufen lassen, mit Shim für `$con`, `$_SESSION` – widerspricht den Vorgaben (kein `$_SESSION`, kein PDO, kein DB-spezifisches SQL).

## Empfehlung
Option 1:
- Je Ansicht eine Route im PageController mit **demselben Dateinamen wie Upstream** (`/index.php`, `/dashboard.php`, `/diary.php`, `/tasks.php`, `/graph.php`, `/kanban_content.php`, `/trash.php`, `/revisions.php`, `/attachments.php`, `/attachments_list.php`, `/workspaces.php`, `/settings.php`, `/excalidraw_editor.php`, `/list_tags.php`, `/list_folders.php`, `/notes_manager.php`, `/speed_dial.php`, `/mentions.php`, `/create.php`, `/welcome.php`, `/favorites.php`, `/audio_player.php`) plus `/` → wie `/index.php`. `#[NoAdminRequired]`, `#[NoCSRFRequired]`, `TemplateResponse::RENDER_AS_USER`; Fragmente (`index.php` mit `X-Poznote-Fragment`, `kanban_content.php?ajax=1`) als `RENDER_AS_BLANK`. Damit funktionieren relative Upstream-Navigationen (`'dashboard.php'`, `'index.php?note=…'`) und gespeicherte Links ohne JS-Änderung.
- **Spike in M1**: Routen mit `.php`-Endung müssen über den Front-Controller (`/index.php/apps/nextnotes/dashboard.php`) und mit „pretty URLs“ (`/apps/nextnotes/dashboard.php`; nginx-Referenzkonfiguration leitet nicht freigegebene `.php` per `rewrite … /index.php$request_uri` um, Apache per `.htaccess`) im Docker-Testsetup erreichbar sein. Scheitert er: Routen ohne `.php` und direkte Änderung in `src/public/js/navigation.js` (SPEC §11.1, P6).
- Templates unter `nextnotes/templates/`, je Datei Kopfkommentar „abgeleitet von src/public/<datei> @ <upstream-commit>“. Markup, IDs, Klassen bleiben gleich; nur Datenbeschaffung (Service-Aufrufe im Controller), Inline-Skripte (→ `IInitialState`) und Inline-Handler (→ `data-action`) ändern sich.
- Seitenübergreifende Teile (`icon_sidebar.php`, `modals.php`, `notes_list.php`, `note_display.php`) werden einmal als Partials portiert.
- Root-absolute Upstream-Pfade (`/api/v1/…`, `/audio_player.php`) schreibt der Adapter `nextnotes-bootstrap.js` bzw. der Server beim Lesen von Inhalten um (SPEC §4, §10).

## Konsequenzen
- Die App läuft im NC-Rahmen (`#content`), die Upstream-Icon-Leiste bleibt als App-interne Navigation; keine Vollbild-Navigation außerhalb.
- Jede Upstream-Änderung an einer Seite muss in das abgeleitete Template übertragen werden; der Kopfkommentar plus `make -C nextnotes check-upstream` (ADR 0007) zeigen, welche.
- Pro Ansicht ein Seitenaufruf (wie Upstream). Kein SPA-Umbau.
