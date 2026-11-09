<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Next Notes – Kurzanleitung

Next Notes ist eine Notiz-App in Nextcloud. Die Anmeldung läuft über Nextcloud, ein eigenes Konto gibt es nicht. Für Administratoren: [admin.md](admin.md).

## Aufbau
- **Icon-Leiste** links: Dashboard (Startseite), Notizen, To-dos, Aufgaben (nur in Team-Workspaces), Tagebuch, dann Alle Notizen, Erwähnungen, Ordner, Tags, Anhänge, Graph, Papierkorb; unten Einstellungen.
- **Workspace-Auswahl** oben: Jede Ansicht zeigt einen Workspace. In der Oberfläche heißt er teils „Arbeitsbereich“.
- Neue Notizen, Markdown-Notizen, Aufgabenlisten, Tagebuch-Einträge und Ordner legst du über das **„+“** in der Seitenleiste an.
- Hell oder dunkel folgt deiner Nextcloud-Einstellung (*Persönliche Einstellungen → Darstellung und Barrierefreiheit*).

## Workspaces
Es gibt drei Arten:
- **Privat:** nur für dich.
- **Geteilt:** dein Workspace, den du mit einzelnen Personen teilst. Workspace-Menü → *Teilen* → Person suchen → *Kann bearbeiten* oder *Nur lesen*. Anhänge zählen immer zu deinem Speicher, auch wenn andere sie hochladen.
- **Team-Workspace:** gehört einem Nextcloud-Team (siehe unten).

Neuer Workspace: Workspace-Menü → *Neuer Arbeitsbereich*. Umbenennen, Farbe, Hintergrund, Reihenfolge und Löschen findest du unter *Einstellungen → Arbeitsbereiche*.

## Teams und Material
- Teams legst du in der App **Kontakte** an (Link *Teams verwalten*, wenn verfügbar). Wer im Team **Moderator oder höher** ist, gilt in Next Notes als **Lehrkraft**, einfache Mitglieder als **Schüler**.
- Lehrkräfte legen Team-Workspaces an: *Neuer Arbeitsbereich* → *Arbeitsbereich für* → *Team: …*. Alle Team-Mitglieder sehen ihn und können darin schreiben. Zugriff folgt der Mitgliedschaft: Wer das Team verlässt, verliert den Zugriff.
- **Material:** Eine Lehrkraft kennzeichnet einen obersten Ordner im Team-Workspace über das Ordnermenü → *Als Material kennzeichnen*. Darin (mit allen Unterordnern) bearbeiten nur Lehrkräfte, Schüler lesen (Buch-Symbol, Hinweis „Material – nur lesen“). Schüler können eine Material-Notiz in ihren privaten Workspace duplizieren und dort weiterbearbeiten.
- Umbenennen und Löschen eines Team-Workspace dürfen nur Lehrkräfte. Beim Löschen wandern die Notizen in einen anderen Workspace desselben Teams; beim letzten Team-Workspace werden sie gelöscht.
- Wird das Team gelöscht, löscht Next Notes seine Workspaces und Aufgaben nach 30 Tagen. Vorher kommt eine Benachrichtigung. Wer den Team-Workspace angelegt hat, kann ihn in der Frist in einen privaten umwandeln.

## Aufgaben für Lehrkräfte
Im Team-Workspace über **Aufgaben** in der Icon-Leiste.
1. **Anlegen:** *Neue Aufgabe* (Quelle: eine Notiz aus den Workspaces dieses Teams) oder im Notiz-Menü *Als Aufgabe austeilen…*, mit Titel, **Frist** und optional Höchstpunktzahl. Die Aufgabe bekommt eine eingefrorene Kopie der Notiz samt Anhängen als Vorlage. Bis zum Austeilen kannst du die Vorlage noch bearbeiten.
2. **Austeilen:** Jeder Schüler des Teams bekommt eine eigene Kopie und eine Benachrichtigung. Später beigetretene Schüler erhalten ihre Kopie automatisch. Fehlen Kopien (z. B. Speicher des Teams voll), erscheint *An fehlende austeilen (n)*.
3. **Abgaben ansehen:** Die Tabelle zeigt den Stand je Schüler. Öffne eine Abgabe zum Lesen, gib **Feedback** und **Punkte**, dann *Zurückgeben*. Erst dann sieht der Schüler Feedback und Punkte. *Wieder öffnen* gibt die Abgabe zur Überarbeitung frei.
4. *Frist ändern*, *Aufgabe schließen* (offene Abgaben gelten dann als verpasst), *Exportieren* (ZIP aller Abgaben).
5. **Aufgabe löschen** löscht die Aufgabe mit allen Abgaben endgültig. Exportiere vorher, wenn die Schule eine Aufbewahrung vorgibt. Abgaben bleiben sonst erhalten, auch wenn Schüler das Team verlassen (sie sehen sie dann selbst nicht mehr) oder ihr Konto gelöscht wird.

Nach Fristende bekommst du eine Sammelmeldung, wie viele abgegeben haben.

## Aufgaben für Schüler
1. Bei einer neuen Aufgabe kommt eine Benachrichtigung. Im Team-Workspace öffnest du **Aufgaben** und dort die Aufgabe.
2. Bearbeite deine **Kopie**. Nur du und die Lehrkräfte des Teams sehen sie.
3. *Abgeben* vor der Frist. Danach ist die Kopie schreibgeschützt. Bis zur Frist kannst du mit *Abgabe zurückziehen* weiterarbeiten, solange noch kein Feedback da ist. Gibst du nicht erneut ab, gilt die Aufgabe als nicht abgegeben.
4. Nach der Rückgabe siehst du **Feedback** und **Punkte** bei der Aufgabe. Deine Abgabe kannst du als ZIP herunterladen.
5. Verlässt du das Team, siehst du seine Aufgaben und deine Abgaben nicht mehr. Du behältst nur, was du vorher heruntergeladen oder abgeschrieben hast. Die Lehrkraft sieht deine Abgabe weiter. Trittst du wieder ein, sind deine Abgaben wieder da.

## To-dos
**To-dos** sammelt die Einträge aller Aufgabenlisten (Notiztyp *Aufgabenliste*) im gewählten Workspace an einer Stelle. Fälligkeit und Erinnerungen setzt du an den Einträgen. In schreibgeschützten Listen (z. B. Material) kannst du nichts abhaken, aber Erinnerungen setzen.

## Tagebuch
**Tagebuch** zeigt einen Kalender und die Einträge je Tag. Neue Einträge legt Next Notes im Tagebuch-Ordner des Workspace nach Jahr und Monat ab. Ordner und Vorlage für neue Einträge stellst du unter *Einstellungen → Tagebuch* ein.

## Formeln, Rechentabellen und Rechnen
Nur in Richtext-Notizen. Knopf **Σ** in der Werkzeugleiste oder `/Formel`, `/Tabelle` bzw. `/Rechnen` im Text.
- **Formel:** Eingabe im Formelfeld (am Tablet mit Bildschirmtastatur), *Übernehmen* mit Enter. *In eigener Zeile* macht einen Formel-Block. Antippen öffnet die Formel wieder.
- **Tabelle rechnen:** Zahlen mit Komma eingeben (`3,5`), Formeln mit `=` und deutschen Namen, Argumente mit `;` (`=SUMME(A1:A3)`, `=WENN(A1>5;"ja";"nein")`). Doppelklick (am Tablet zweites Antippen) bearbeitet eine Zelle, die Zeile darüber zeigt immer den Inhalt. Zeilen und Spalten fügst du über **⋯** oder das Kontextmenü ein, Formeln passen sich an. Höchstens 50 Zeilen × 26 Spalten. Fehler wie `#DIV/0!` erklärt ein Hinweis beim Darüberfahren. Nur deutsche Funktionsnamen; `*` und `?` in `ZÄHLENWENN`/`SUMMEWENN` sind normale Zeichen (keine Platzhalter). Sehr viele Formeln über große Bereiche lehnt die Tabelle mit einem Hinweis ab. Es gibt nur ein Blatt: Apostroph `'` und Ausrufezeichen `!` stehen in Formeln nur innerhalb von Anführungszeichen (`="Hallo!"`).
- Rechnen kann die Tabelle nur, wer die Notiz gerade bearbeiten darf. Wer nur liest (Freigabe, Material, gesperrte Notiz, Druck, Export), sieht die zuletzt gespeicherten Ergebnisse.
- Wandelst du die Notiz in Markdown um, bleiben nur die Ergebnisse (als Markdown-Tabelle), nicht die Formeln.
- **Rechnen:** eine Rechnung je Zeile, das Ergebnis steht rechts daneben. Variablen gelten für die folgenden Zeilen (`a = 3`, dann `b = a^2 + 1`), jeder Name nur einmal. Dezimalkomma oder -punkt (`3,5` = `3.5`), Argumente mit `;` (`round(2,567; 2)`). Einheiten mit `to` umrechnen (`5 km / 20 min to km/h`, `sin(30 deg)`). Nicht ganze Ergebnisse stehen zusätzlich als Bruch da (`1/3 + 1/6` → `0,5 = 1/2`). Text nach `#` ist ein Kommentar.
  - Funktionen (englische Namen wie am Taschenrechner): `sqrt`, `cbrt`, `nthRoot`, `abs`, `exp`, `log` (natürlich, `log(x; b)` zur Basis b), `log10`, `log2`, `round`, `floor`, `ceil`, `fix`, `sign`, `mod`, `pow`, `hypot`, `gcd`, `lcm`, `factorial` (auch `5!`), `combinations`, `permutations` (Zahlen bis 10.000), `mean`, `median`, `std`, `variance`, `sum`, `sin`, `cos`, `tan`, `asin`, `acos`, `atan`, `atan2`; Konstanten `pi`, `e`. Vergleiche (`<`, `==`, …) ergeben *wahr*/*falsch*, `a > 0 ? 1 : -1` wählt.
  - Eigene Funktionen: `f(x) = x^2 - 2`, danach `f(3)`. Funktionen mit einem Parameter zeichnet der Block als Graph (höchstens 5, x von −10 bis 10, Zoomen und Verschieben mit Maus oder Fingern); *Graph ausblenden* blendet ihn aus. Eine Funktion kann sich nicht selbst aufrufen. Sehr aufwendige Rechnungen brechen mit „Rechenaufwand zu groß“ ab, aufwendige Graphen mit „Graph zu aufwendig“.
  - Ein Name, den du im Block zuweist (`m = 2`), ist im ganzen Block keine Einheit mehr; vor der Zuweisung ist er unbekannt.
  - Wer nur liest, sieht die zuletzt gespeicherten Ergebnisse und den Graphen. Höchstens 200 Zeilen mit je 500 Zeichen.
  - Enthält eine Notiz viele aufwendige Rechen- oder Tabellenblöcke, rechnet sie beim Öffnen nur die ersten; die übrigen zeigen ihre gespeicherten Ergebnisse und „Nicht berechnet. Zum Rechnen klicken.“
  - Wandelst du die Notiz in Markdown um, wird der Block ein Codeblock mit `Eingabe  # = Ergebnis` je Zeile.

## Erinnerungen und Erwähnungen
- **Erinnerung:** Glocke in der Notiz-Werkzeugleiste (*Erinnerung setzen*), auch wiederkehrend. Sie kommt als Nextcloud-Benachrichtigung, keine E-Mail.
- **Erwähnung:** `@Name` in geteilten oder Team-Workspaces benachrichtigt die Person, wenn sie die Notiz lesen darf. Offene Erwähnungen zeigt **Erwähnungen** in der Icon-Leiste.

## Export
- Einzelne Notiz: Notiz-Menü → Export (HTML, Markdown, …). Ordner als ZIP: Ordnermenü.
- Alles Eigene: *Einstellungen → Export* → *Strukturierter Export* (Notizen als Dateien in ihren Ordnern) oder *Anhang-Export*. Ein Export darf höchstens 1 GiB groß sein. Die Formate kann Poznote wieder einlesen.
- Eigene Abgaben: in der Ansicht **Aufgaben** (siehe oben).

## Unterschiede zu Poznote
Gegenüber dem Original fehlen bewusst:
- eigene Anmeldung, Benutzerverwaltung und Zwei-Faktor (macht Nextcloud),
- öffentliche Links,
- KI-Assistent und Spracherkennung,
- Offline-Modus und Installation als App (PWA),
- Backup, Wiederherstellung und Import von Poznote-Exporten (einzelne `.md`-, `.html`- oder `.txt`-Dateien per Drag & Drop gehen weiter),
- Git-Synchronisation, Webhooks, MCP-Server, E-Mail-Erinnerungen,
- zusätzliche Themes, eigene Schriften und CSS,
- Speed Dial und die Seite „Erstellen“.

Neu sind Team-Workspaces, Material-Ordner und Aufgaben mit Abgaben.
