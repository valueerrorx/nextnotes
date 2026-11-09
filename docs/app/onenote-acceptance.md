<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# OneNote-Import – Abnahme mit echtem Tenant

Für Thomas (Schuladmin). Instanz: Demo `http://localhost:8090`. Anleitung im Detail: [admin.md §13](admin.md#13-onenote-import). Ergebnis bitte in `docs/progress/M9e-abnahme.md` festhalten (je Punkt ok/nicht ok, Auffälligkeiten). Keine Passwörter oder Geheimnisse dort eintragen.

## 1. Registrierung in Entra
- [ ] Eigene Test-Registrierung anlegen, nicht die spätere für den Betrieb (Microsoft empfiehlt getrennte Registrierungen für Test und Produktion).
- [ ] Name z. B. „Next Notes Test localhost“, Kontotyp **Nur Konten in diesem Organisationsverzeichnis**.
- [ ] Umleitungs-URI, Plattform **Web**: `http://localhost:8090/apps/nextnotes/onenote/callback`
  - Die Route ist `/onenote/callback` (`nextnotes/appinfo/routes.php`); die Demo nutzt das Apache-Image mit `htaccess.RewriteBase` = `/`, daher ohne `/index.php/`. Maßgeblich ist der Wert in der Admin-Karte (Schritt 2); weicht er ab, diesen nehmen.
  - Die Demo immer über `localhost` öffnen, nicht `127.0.0.1`: Die URI entsteht aus der aufgerufenen Adresse, und Entra nimmt `http://127.0.0.1` nicht im Portal an.
  - Beleg, dass `http://localhost` bei Web-Apps erlaubt ist: Microsoft Learn, „Redirect URI (reply URL) best practices and limitations“, Abschnitt *Supported schemes*: „The HTTP scheme (`http://`) is supported only for localhost URIs“, Beispiel `http://localhost/abc` = *Valid*; Abschnitt *Localhost exceptions*: Bei localhost wird der Port beim Vergleich ignoriert (`:8090` stört also nicht).
- [ ] Client-Geheimnis anlegen (kurze Laufzeit genügt), **Wert** kopieren.
- [ ] Delegierte Berechtigungen: `openid`, `offline_access`, `Notes.Read`, `Notes.Read.All`, `Team.ReadBasic.All`.
- [ ] Zunächst **ohne** Administratorzustimmung: zeigt die Anmeldung des Testkontos eine Zustimmungsseite? Danach Zustimmung erteilen und erneut prüfen.

## 2. Einstellungen in Next Notes (als `admin`)
- [ ] *Verwaltung → Next Notes → OneNote-Import*: angezeigte Umleitungs-URI = Wert aus 1.
- [ ] Authority = Mandanten-ID, Client-ID, Geheimnis, **Team- und Klassennotizbücher erlauben** an, speichern. Das Feld zeigt danach nur „gesetzt“.
- [ ] Hintergrundjobs: Die Demo hat einen Container `cron` (alle 5 min, Modus „Cron“ unter *Verwaltung → Grundeinstellungen*). Zum sofortigen Anstoßen: `docker compose -p nextnotes-demo exec -T -u www-data nc php -f /var/www/html/cron.php`
- [ ] Als Testbenutzer (z. B. `thomas`): *Einstellungen* zeigt die Karte „Import aus OneNote“.

## 3. Anmeldung
- [ ] *Mit Microsoft verbinden* → Microsoft-Anmeldung mit dem Test-Schulkonto → zurück auf der Importseite mit Notizbuchliste.
- [ ] Abbruch bei Microsoft („Abbrechen“ auf der Anmeldeseite) → Meldung „Anmeldung abgebrochen.“
- [ ] Eigene Notizbücher vorausgewählt, Team-/Klassennotizbücher sichtbar und **nichts** ausgewählt, Hinweis auf Inhalte anderer Personen.

## 4. Testinhalte und erwartetes Ergebnis
Ein Notizbuch mit allen Inhalten (Abschnittsgruppe mit Abschnitten, 30–50 Seiten) importieren.

| ✓ | Inhalt in OneNote | Erwartet in Next Notes |
|---|---|---|
| [ ] | Notizbuch | neuer eigener Workspace mit dem Namen des Notizbuchs (bei gleichem Namen „Name OneNote“) |
| [ ] | Abschnittsgruppe mit Abschnitten | verschachtelte Ordner, gleiche Reihenfolge |
| [ ] | Text mit Überschriften, Listen, fett/kursiv/unterstrichen, Farbe, Markierung | Formatierung da, keine fremde Schriftart oder -größe; schwarzer Text im Dunkelmodus lesbar |
| [ ] | Tabelle mit verbundenen Zellen | Tabelle, verbundene Zellen erhalten |
| [ ] | To-do-Kästchen, offen und abgehakt; „Wichtig“, „Frage“ | Checkliste, Zustand stimmt, im Editor abhakbar und nach Speichern erhalten; ⭐ bzw. ❓ vor dem Text |
| [ ] | Unterseite (Ebene 1 und 2) | eigene Notiz direkt hinter der Elternseite, Titel mit „› “ bzw. „› › “ |
| [ ] | Bilder (eins, mehrere, ein sehr großes) | Anhänge, in der Notiz sichtbar, Reihenfolge stimmt |
| [ ] | PDF als Dateianlage | Anhang mit Link, Download öffnet die PDF |
| [ ] | PDF als Dateiausdruck | Seitenbilder als Bild-Anhänge, die PDF selbst als Anhang mit Link (falls OneNote sie mitspeichert); **notieren** |
| [ ] | Freihand (Zeichnung, Handschrift) | fehlt bzw. „[Freihand nicht übernommen]“; **notieren, was genau erscheint** |
| [ ] | Formel (Formel-Editor von OneNote) | Formel (Σ) oder Text; **notieren, was erscheint** |
| [ ] | Frei platzierte Textblöcke nebeneinander und übereinander | untereinander in Lesereihenfolge (oben → unten, links → rechts) |
| [ ] | Seite ohne Titel, Seite mit sehr langem Titel | „Unbenannte Notiz“; Titel auf 200 Zeichen gekürzt |
| [ ] | Erstell- und Änderungsdatum | in der Notiz-Info wie in OneNote |
| [ ] | 30–50 Seiten insgesamt | Fortschritt und Restzeit auf der Importseite; am Ende Benachrichtigung „OneNote-Import abgeschlossen“ mit Zahlen; Bericht leer oder nachvollziehbar |

Zu notieren: Gesamtdauer, Zahl der Cron-Läufe, Auffälligkeiten im Bericht, Einträge mit `nextnotes` im Nextcloud-Log (dürfen keine Token oder Titel enthalten).

## 5. Fortsetzen, Abbrechen, erneuter Import
- [ ] Während des Imports die Importseite schließen und später wieder öffnen: Stand läuft weiter.
- [ ] Während ein Import läuft, lässt sich kein zweiter starten (Importseite zeigt den laufenden; bei einem Versuch „Ein anderer OneNote-Import ist noch offen …“).
- [ ] *Import abbrechen* mitten im Lauf: Status „Abgebrochen“, bis dahin importierte Notizen bleiben, kein weiterer Zuwachs.
- [ ] Dasselbe Notizbuch erneut importieren: nur die beim Abbruch fehlenden Seiten kommen dazu, die übrigen zählen als „Übersprungen (bereits importiert)“.
- [ ] Noch einmal importieren: 0 neue Notizen.
- [ ] Eine importierte Notiz endgültig löschen (Papierkorb leeren), erneut importieren: genau diese wird neu angelegt.

## 6. Klassennotizbuch
- [ ] Als Lehrkraft: Klassennotizbuch unter *Team: …* sichtbar, nichts vorausgewählt.
- [ ] Nur den Abschnitt eines Schülers (Testkonto) auswählen und importieren: landet im eigenen Workspace der Lehrkraft, nicht in einem Team-Workspace.
- [ ] Notieren: Reichen die Berechtigungen (falls nicht: Meldung und Bericht notieren).

## 7. Optional: privates Microsoft-Konto
- [ ] Zweite Test-Registrierung mit Kontotyp **Konten in einem beliebigen Organisationsverzeichnis und persönliche Microsoft-Konten**, Authority `common`.
- [ ] Admin-Karte: Schalter für Team-Notizbücher deaktiviert mit Hinweis.
- [ ] Anmeldung mit privatem Konto: Zustimmungsseite (notieren, ob „nicht verifiziert“ erscheint und ob die Anmeldung gelingt); Import eines kleinen Notizbuchs; keine Team-Notizbücher in der Auswahl.

## 8. Widerruf und Aufräumen
- [ ] <https://myapps.microsoft.com> mit dem Testkonto: Ist die App dort aufgeführt und entfernbar? (Mit Administratorzustimmung erwartet: nicht durch den Benutzer.)
- [ ] Entra → Unternehmensanwendungen → App → Berechtigungen: Zustimmung widerrufen. Danach in Next Notes neu verbinden: Zustimmungsseite erscheint wieder.
- [ ] Client-ID in Next Notes ändern (z. B. auf die zweite Registrierung): ein offener Import endet mit „Die Administration hat die OneNote-Konfiguration geändert …“.
- [ ] Zum Schluss: Test-Registrierungen in Entra löschen oder Geheimnisse entfernen.
