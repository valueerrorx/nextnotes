# M4a Diff-Review r1 (speccritic)

VERDICT: FREIGABE

Geprüft: EmbedDomainService, AdminSettingsController, Settings/AdminSettings+AdminSection, templates/settings/admin.php, js-src/nextnotes-admin.js, HtmlSanitizer, CspFactory, PageController (nur M4a-Teil), Attachment-/ExcalidrawService, CompatController, IndexViewService, routes/info.xml, P17 (markdown-parser.js, events-rte-paste.js), SPEC/ADR 0012/upstream-patches, AdminSettingsTest, E2E admin/workspace-create/excalidraw.

Befund ohne Blocker:
- Rechte: Route POST /admin/settings ohne NoAdminRequired/NoCSRFRequired → nur Admin, CSRF aktiv; 403 für Nicht-Admin per PHPUnit und E2E belegt.
- Normalisierung: Schema, Port, Userinfo, `*`, IPv4/IPv6 (auch `[]`, numerische Endlabels), ein Label, Länge, IDN→Punycode (ohne intl: ascii_only); alles-oder-nichts inkl. Grenzwerte; > 100 → too_many. Lesen tolerant, Warnung einmal (lokaler Cache), falscher Typ → leer statt 500.
- Eine Liste, exakt: Sanitizer `isAllowedHost` (in_array), CSP `https://<host>`, InitialState aus derselben Quelle; JS P17 in beiden Dateien exakt. IHtmlSanitizer::IFRAME_DOMAINS entfernt.
- XSS Admin-Template: alle Ausgaben p(), Fehlerliste per textContent, Fehlercodes nur vom Server.
- wasm-eval: CspFactory unverändert ohne allowEvalWasm/worker-src; Duldung im E2E nur Abschnitt D, eng (Direktive + Quelldatei-Regex); 3-MB-Bild nachweislich über JS-Rückfall verkleinert. ADR 0012 Nachtrag + SPEC §4.6 vorhanden.
- Performance: Service per DI geteilt, Ergebnis je Instanz gemerkt → einmal je Request.
- Lead-Auflage (Hinweis „dauerhaft entfernt“) im Template und per E2E geprüft.

Hinweise:
1. Done M4a.4 sagt „Name mit `'` … → Seite wechselt“; Test und InputValidator (B20/Upstream-Namensregel) lehnen `'` im Namen ab, `'` nur in Tags. Verhalten ist korrekt; Done-Text im Plan bzw. STATUS entsprechend festhalten.
2. `HtmlSanitizer::iframeSrcIsTrusted` und `_mdIsAllowedIframeSrc` lassen `http://<gelisteter host>` zu, die CSP erlaubt nur `https://`. Gespeicherte http-iframes bleiben dadurch blockiert (keine Lücke, nur toter Inhalt). Optional: nur https erlauben (Folgeslice).
3. Gespeicherte iframes mit IDN-Host in Unicode-Form werden nicht erkannt (Liste ist Punycode) → entfernt. Restriktiv, unkritisch.
