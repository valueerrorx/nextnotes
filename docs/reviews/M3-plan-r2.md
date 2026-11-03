VERDICT: FREIGABE

Review M3-plan r2 (speccritic, 2026-11-03). Grundlage: `docs/slices/M3-plan.md` r2 und `git diff docs/` (SPEC, ADR 0012).

Blocker aus r1, alle erledigt
1. XSS: Ausgaberegel steht in Plan §1.2 und SPEC §4.6 (JSON-Flags, Allowlist für `print_unescaped`). Tests: M3a2.4, M3b.7a, M3c.1b und `TemplateOutputTest`.
2. Gestrichenes in der Notizansicht: Done M3b.0 prüft die geöffneten Menüs. Der Harness bricht bei `pageerror` oder Konsolenfehler ab.
3. Such-POST: verstecktes Feld `requesttoken` im Formular, Test des nativen Submits in M3b.7.
4. Lesefreigabe: Pflichtfall M3c.1a, schreibende Aktionen → 403, Lese-Seiten → 200.
Hinweise aus r1 sind übernommen: Vendor unter `js/vendor` bzw. `css/vendor`, Schleifenschutz für den 401-Reload, `css_assets.php` und Dark-Mode-Liste in `check-upstream`, Host-`php` dokumentiert, R7 mit frühem Speichern und Test > 64 KiB.

Lead-Entscheidungen
- O1, O2, O3 (mit Nachtrag §4.4), O7 als Done-Test M3b.4, O11 (SPEC §13 M3.4) und die feste Teilung M3a1/M3a2: konsistent mit SPEC und ADRs, kein Einwand.
- ADR-0012-Nachtrag (PDF `frame-ancestors 'self'`): korrekt begründet. `frame-src` wird nicht gelockert.

B26: `data-action` in Inhalten
- Eine Stichprobe in `src/public/js` fand keinen Upstream-Code, der `<button data-action>` in Notizinhalte schreibt. `html-sanitize.php:283` erlaubt es nur. Die Allowlist ist daher voraussichtlich leer.
- Empfehlung: `data-action` in Inhalten ganz entfernen. Eine Allowlist nur einführen, wenn M3b eine konkrete Fundstelle belegt; die Fundstelle gehört dann in B26.
- Wichtiger als die Attributliste: Viele Upstream-Handler delegieren auch über Klassen (`closest('.…')`). `class` und `id` sind in Inhalten global erlaubt. Ein eingeschleuster `<button class="…">` kann also trotzdem eine Aktion auslösen, und eine `id` kann mit App-IDs kollidieren.
- Auflage für M3b: Die Delegation bzw. der Adapter ignoriert Klicks, deren Ziel im Notizinhalt liegt (`.noteentry`), außer bei belegten Inhaltsaktionen. Test M3b.7a zusätzlich mit einem eingeschleusten Knopf über die Klasse einer destruktiven Aktion und mit `id="right_col"` bzw. `id="unified-search-form"`: keine Aktion, UI intakt.

Hinweise
- B27 nur eintragen, wenn der Test (a2) Datenverlust zeigt. So ist es im Plan vorgesehen.
