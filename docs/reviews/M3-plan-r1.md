VERDICT: BLOCKER

Review M3-plan r1 (speccritic, 2026-11-03), Grundlage `docs/slices/M3-plan.md` r1.

Blocker
1. XSS in abgeleiteten Templates und JSON-Inseln (§1.2, Done M3a/M3b): Es fehlt eine Ausgaberegel und eine Prüfung. `InputValidator::heading` erlaubt `<`, `>`, `"` in Titeln; Workspace- und Ordnernamen kommen ebenfalls in die Seite. Gefordert: alle `$_`-Ausgaben mit `p()`/`h()` escapen, JSON-Inseln und InitialState mit `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`, Notizinhalt nur über HtmlSanitizer. Done-Test: Titel, Ordner und Workspace `"><img src=x data-action=…>` sowie `</script><b>x` → kein zusätzliches Element bzw. Attribut im DOM, 0 Konsolenfehler. Eingeschleuste `data-action`-Attribute würden über die Delegation ausgeführt; davor schützt die CSP-Nonce nicht.
2. Gestrichene Funktionen in der Notizansicht (Done M3b): Done M3a.4 prüft nur die Hauptansicht. `note_display.php` enthält Menüeinträge für Teilen und Offline (`utils-menus.js:55-75`, `.share-state-*`, `.offline-state-*`). Gefordert: Prüfung wie M3a.4 auch in M3b, mit geöffnetem Notiz-Aktionsmenü, Toolbar und Slash-Menü. Außerdem in M3a und M3b 0 `pageerror` bzw. Konsolenfehler, weil Upstream-JS auf weggelassene Elemente zugreifen kann.
3. Suche per Formular-POST ohne Token (M3b, POST `index.php`): `notes_list.php:105` ist `<form method="POST">`, und `unified-search.js:1097/1100` fällt auf `form.submit()` zurück. Diesen Request erfasst der Adapter nicht, das Ergebnis wäre 412. Gefordert: verstecktes Feld `requesttoken` im Template und Done-Test für den nativen Submit.
4. Krit. M3.6 „403 bei lesbar, nicht schreibbar“ (Done M3c.1): Im Plan steht „sobald vorhanden“. `WsShareMapper` und `AccessService` (READ/WRITE) sowie die Testhilfe in `ApiTestCase` gibt es aber bereits. Gefordert: Pflichtfall mit Lesefreigabe von A an B. `api_save_excalidraw.php` und schreibende `workspaces.php`-Aktionen → 403, Lese-Seiten → 200.

Offene Punkte
- O1: Abweichung von SPEC §1.4. Sie ist sinnvoll (cachebar), SPEC §1.4 muss geändert werden; der Lead entscheidet.
- O2: SPEC-konform (§1.2: 501 bis M4). Die Export-Menüpunkte sollten wie bei O5 bis M4 ausgeblendet werden, sonst gibt es sichtbare 501-Fehler.
- O3: Weicht von §4.4 ab („unbekannt → 404“ gilt dort für die API). Ein Leck entsteht nicht, weil `resolveKey` nur sichtbare Workspaces auflöst. Nachtrag in §4.4 für Seiten nötig.
- O4, O8, O9, O10: Lead bzw. Architekt, ohne Einwand. Bei O9 die 7 Dateien für P8 erst nach Bestätigung im Browser ändern.
- O5: einverstanden.
- O6: Produktfrage, richtig bei Thomas.
- O7: Datenverlust-Szenario wie B13. Als Done-Test in M3b aufnehmen (dupliziertes Tab → Nur-Lesen), nicht nur „nach Test“ entscheiden.
- O11: Ändert ein Done-Kriterium, also SPEC-Änderung durch den Lead. Formulierung akzeptabel.
- O12: ohne Einwand.

Hinweise
- Assets (§1.1.3 gegen §1.2): `Util::addScript` lädt nur `nextnotes/js/<datei>` (Util.php:135), `addStyle` nur `css/`. CodeMirror, highlight und `katex.css` liegen laut Plan in `vendor-assets/` und wären so nicht ladbar. Sie gehören nach `js/` bzw. `css/`, für die KaTeX-Fonts mit `url()`-Umschreibung.
- R7: `sendBeacon` hat dieselbe Grenze von 64 KiB wie `keepalive`. „Beacon bei > 60 KiB“ hilft also nicht. In M3b eine Notiz > 64 KiB messen und auf frühes Speichern bei `visibilitychange` setzen.
- PDF `frame-ancestors 'self'`: vereinbar mit ADR 0012 und B22. Es ist keine Lockerung von `frame-src`, sondern entspricht dem NC-Standard (Empty → `'none'` heute). Nachtrag wie geplant genügt.
- 401 → `location.reload()`: Schutz gegen Reload-Schleifen fehlt (z. B. Marker in `sessionStorage`).
- `check-upstream`: `css_assets.php` und die Liste der Dark-Mode-Dateien (ADR 0007) fehlen im Bundle-Vergleich. Die Prüfung braucht Host-`php`, das sollte im Plan stehen.
- M3a ist mit ≈ 10 PT groß. Teilung M3a1/M3a2 gleich festlegen statt erst bei Bedarf.
- SPEC §1.1 (Layout) um `nextnotes-late.js`, `nextnotes-layout.css` und `build.mjs` ergänzen. R11 im Plan meint SPEC B13, nicht SPEC R11 (Abgaben).
