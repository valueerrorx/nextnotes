VERDICT: FREIGABE

Blocker r1-1 (Schrift) erledigt: `build.mjs` `rewriteFontFamily` + `bundles.json` `fontFamilyRewrite`, nur reine String-Ersetzung (deterministisch). Probe mit Node (Scratchpad, ohne make): `'Inter'`, `"Inter"`, `Inter` ohne Anführung, vor `!important`, nach Zeilenumbruch in der Liste → `var(--font-face)`; `'Inter Tight'`, `content:'font: Inter'` und ungleiche Anführungszeichen bleiben unberührt. Upstream-CSS hat keinen `font`-Shorthand mit Inter (grep), einziger unquotierter Fall `table-picker.css:165` wird erfasst.
Blocker r1-2 erledigt: ADR 0016 Nachtrag (accent-text → `--color-primary-element-light-text`, Text auf Akzentfläche bleibt `--pz-text-inverse`; Schrift-Umschreibung), SPEC §1.2.3 nachgezogen.
Hinweise r1 erledigt: `CspFactory` fängt `OCP\Exceptions\AppConfigException` (Oberklasse von `AppConfigTypeConflictException`, in .ref geprüft) → leere Liste + Warnung; Plan M4-Vormerkung calendar.js/background-settings.js; O8 um Asset-Tags `index.php:456-475` ergänzt.
Adapter: `appPath = baseUrl.replace(/\/index\.php\//, '/')` deckt Unterpfad ab (`/nc/index.php/apps/nextnotes/` → `/nc/apps/nextnotes/`); Token weiter nur bei gleicher Origin und App-Pfad-Präfix, kein Weg zu fremden Pfaden/Origins.

Hinweise (kein Blocker)
- `font`-Shorthand mit vorangestellter Größe (`font: 400 14px 'Inter'`) wird nicht umgeschrieben; heute ohne Fundstelle. Bei Upstream-Updates fällt das nur per Sichtprüfung auf; ggf. später in `check-upstream` (grep `Inter` in Build-Ausgabe CSS ≠ 0 → Fehler) aufnehmen.
- Benutzerdefinierte Properties mit Namensende `font` (`--x-font: Inter`) werden mit umgeschrieben; gewollt/harmlos.
