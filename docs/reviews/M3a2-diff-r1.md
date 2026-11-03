# M3a2 Diff-Review r1 (speccritic)

VERDICT: BLOCKER

1. **build.mjs `withBump`/`PSEUDO_ELEMENT` (ADR 0017) erzeugt ungültige Selektoren, ganze Regeln fallen still weg.**
   Fundstelle: `nextnotes/build/build.mjs` (`PSEUDO_ELEMENT`, `withBump`). Die Regex erkennt ein Pseudo-Element nur am Selektor-Ende; folgt eine Pseudo-Klasse (`::-webkit-scrollbar-thumb:hover`, `::-webkit-scrollbar-track:horizontal`, `::file-selector-button:hover`), wird der Zusatz hinter das Pseudo-Element gehängt: `pre.code-block::-webkit-scrollbar-thumb:hover:not(#nn-0)` bzw. `…:hover:is(#nextnotes-root,#nextnotes-root *)`. Hinter Pseudo-Elementen sind nur User-Action-/Scrollbar-Pseudoklassen erlaubt, also ist der Selektor ungültig, und mit ihm fällt die ganze Selektorliste der Regel weg.
   Belegt in der gebauten CSS: rund 27 Selektoren in `css/nextnotes-core.css`, `nextnotes-dark.css`, `nextnotes-modals.css`. Betroffen sind u. a. die horizontalen Scrollbalken der Codeblöcke (`code-blocks.css`), `#right_col`-Scrollbalken (darin auch der gültige Nachbar `body.note-col-fits #right_col:not(#nn-0)::-webkit-scrollbar-thumb`), Kanban- und Icon-Grid-Scrollbalken sowie Dunkel-Varianten. Das ist eine stille Regression gegenüber Upstream. Kein Test erkennt sie, denn ein ungültiger Selektor erzeugt weder `pageerror` noch Konsolenfehler.
   Nötig:
   - (a) Der Zusatz muss vor die gesamte Folge aus Pseudo-Element und anschließenden Pseudoklassen. Die Regex muss also nachgestellte `:[a-z-]+(\(…\))?` hinter einem Pseudo-Element mit erfassen.
   - (b) Eine Prüfung in `check-upstream` (e) oder im Build: gebaute CSS ohne `::…(:…)*:not(#nn-0)` und ohne `::…:is(#nextnotes-root`. Besser noch: Anzahl der Regeln je Bundle vor/nach `bumpSpecificity` vergleichen, oder die Ausgabe mit esbuild parsen und Warnungen als Fehler werten.

## Hinweise (kein Blocker)
- Done 4/5/6 (XSS-DOM-Test, Grep-Allowlist `print_unescaped`, `?note=`/`?workspace=`-Fremdzugriff): Im Diff fehlen Tests. Unter `tests/` ist nur `budget.mjs` geändert. Der Lead sollte vor dem Commit prüfen, dass der Tester sie liefert. Der Code selbst erfüllt die Regeln: genau ein `print_unescaped` (JSON-Inseln mit HEX-Flags), alles andere über `p()`, Farben in `style` serverseitig geprüft (`iconColor`, `colorHex`), `data-action` nur fest, `?note=` über `requireNote(read)` → NC-404 ohne InitialState/Assets, `?workspace=` nur über sichtbare Schlüssel.
- GET-Seiteneffekt: `defaultWorkspace()` → `ensureDefault()` legt beim ersten Aufruf den Workspace „Notizen“ an. Das ist bewusste Erstanlage (O5, M2) und kein neuer Fehler, sollte aber in SPEC §1.2/§4.4 als Ausnahme von „GET ohne Seiteneffekt“ stehen.
- Der Plan nennt `templates/parts/`, umgesetzt ist `templates/partials/`. Plan oder SPEC angleichen (M3b baut darauf auf).
- SPEC §11.2: B27 ist jetzt „shouldFolderBeOpen“. M3-plan R7 und Done M3b.3 reservieren B27 aber für den 64-KiB-Datenverlust. Die Verweise im Plan umnummerieren.
- SPEC Kopf „Grundlagen … `0002`–`0016`“: ADR 0017 ergänzen.
- P8 „entfällt“ beruht auf dem Timing (NC ersetzt `#unified-search` vor `DOMContentLoaded`). Empfehlung: Regressionsprobe `document.querySelectorAll('#unified-search').length === 1` und `#unified-search` innerhalb `#nextnotes-root` in den M3a2-E2E-Test aufnehmen.
- Sichtbare, aber bis M3b/M3c wirkungslose Einträge (Create-„+“ ohne `#create-menu`, Ordnermenü „Kanban“, Umbenennen/Verschieben/Tag-Modals): Kein Fehler beim Laden. Beim Klick sind Konsolenfehler möglich, das ist im Smoke für Done 3 zu beachten.
- `src/`: kein Diff, `upstream-patches.txt` unverändert, §11.1 (P4/P8 entfällt) konsistent. N+1: keine (Tags gebündelt, Settings über lazy `IUserConfig`, Workspace-Schlüssel gecacht). Build deterministisch (sortierte i18n-Liste). CSP unverändert.
