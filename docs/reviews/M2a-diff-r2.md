VERDICT: FREIGABE

Geprüft: Nachbesserung zu `M2a-diff-r1.md`. Geändert sind `WorkspaceService`, `WsShareMapper`, `NoteService`, `HtmlSanitizer`, `InputValidator`, `NotesApiController`, SPEC §11.2 B18–B20 und `M2-plan.md`. Container habe ich keine gestartet.

## Blocker 1 (r1): erfüllt
- `WorkspaceService::delete()`: Als Ziel kommen nur eigene Workspaces mit `kind=normal` in Frage, ohne Eintrag in `ws_shares` (`WsShareMapper::sharedWorkspaceIds`) und nicht „Archives“. Bevorzugt wird der Standard-Name, sonst die kleinste ID. Gibt es keinen solchen Workspace, wird in derselben Transaktion ein neuer angelegt („Notizen“, bei Namenskonflikt „Notizen 2“ …). Das entspricht der Entscheidung von Thomas und SPEC B18.
- Die Freigaben des gelöschten Workspace werden gelöscht. Das Rechte-Gate `manage` ist unverändert. Auf PostgreSQL ist das unkritisch: Workspace-Namen haben keinen Unique-Index, der Insert in der Transaktion kann also nicht an einer Unique-Verletzung scheitern.

## Hinweise r1
- H1/B19: erfüllt. `href`/`src` werden nach dem Entity-Decoding durch DOM ohne `[\x00-\x20\x7F\p{Z}]` geprüft. `java&#9;script:` und `&#0;` werden so erkannt, `&colon;` dekodiert DOM.
- Abweichung 5/B20: erfüllt. Der Apostroph ist in Tags wieder erlaubt; `<`, `>`, `"`, Backtick und Steuerzeichen bleiben verboten (400).
- Abweichung 3: erfüllt (Plan nachgezogen). Abweichung 9: erfüllt, `show()` hat den toten Zweig nicht mehr.
- H2/H3: im Plan als Notizen für M6/M2c vermerkt. H4: erfüllt, `beacon` nutzt jetzt `requireNote(..., 'write')` ohne Papierkorb.

## Neue Hinweise (kein Blocker)
- N1: `delete()` wählt das Ziel und prüft die Freigaben außerhalb der Transaktion. Eine Freigabe, die genau dazwischen angelegt wird, bleibt unbemerkt. Das Fenster ist sehr klein. Abhilfe ist einfach: Auswahl in den `tx->run`-Block verschieben.
- N2: Weil der Apostroph in Tags erlaubt ist, muss M3 prüfen, ob das Frontend Tags in Attributen und JS-Strings escapt. Inline-Handler entfallen ohnehin (SPEC §4.3.6).
