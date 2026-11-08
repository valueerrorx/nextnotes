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
