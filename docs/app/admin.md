<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Next Notes – Administration

Für Administratorinnen und Administratoren einer Nextcloud-Instanz. Nutzer-Doku: [user.md](user.md), Update: [upgrade.md](upgrade.md).

## 1. Voraussetzungen
- Nextcloud 35 (andere Versionen lehnt die App ab), PHP 8.3–8.5.
- Datenbank: getestet mit MariaDB, PostgreSQL und SQLite.
- Hintergrundjobs per System-Cron (Abschnitt 5).
- Optional **Teams** (App `circles`): nötig für Team-Workspaces, Material-Ordner und Aufgaben. Ohne Teams funktionieren private und geteilte Workspaces.
- Optional **Kontakte** (App `contacts`): Dort legen Benutzer Teams an und verwalten Mitglieder. Nur wenn Kontakte aktiv ist, zeigt Next Notes den Link „Teams verwalten“.
- Rollen im Team: **Lehrkraft** = Moderator oder höher, **Schüler** = einfaches Mitglied.

## 2. Installation
- **App Store:** *Apps → Büro & Text → Next Notes → Herunterladen und aktivieren*.
- **Paket:** `nextnotes-<version>.tar.gz` in ein beschreibbares App-Verzeichnis entpacken (z. B. `custom_apps/`, Eigentümer = Benutzer des Webservers), dann:
  ```
  occ app:enable nextnotes
  ```
- Beim Aktivieren legt die App ihre Tabellen (`oc_nextnotes_*`) und Hintergrundjobs an. Die App erscheint in der Navigation, Startseite ist das Dashboard.
- Nach einer Installation aus dem Paket über bestehende Dateien: [upgrade.md](upgrade.md) beachten (PHP neu starten).

## 3. Einschränkung auf Gruppen
Next Notes lässt sich wie jede App auf Gruppen beschränken: *Apps → Next Notes → Auf Gruppen beschränken* oder
```
occ app:enable nextnotes --groups lehrkraefte --groups schueler
```
- Die Beschränkung regelt nur, wer die App öffnen kann.
- Beim Austeilen von Aufgaben erhalten Team-Mitglieder außerhalb der freigegebenen Gruppen **keine Kopie** und zählen nicht als „noch nicht ausgeteilt“. Werden sie später freigeschaltet, bekommen sie ihre Kopie nachträglich, solange die Frist läuft.
- Teilen-Dialog und Erwähnungen prüfen die Gruppen **nicht**. Deshalb alle Lehrkräfte und Schüler, die Teams mit Next Notes nutzen, in die freigegebenen Gruppen aufnehmen.

## 4. Webserver
- **Apache:** Die von Nextcloud erzeugte `.htaccess` genügt. Für Adressen ohne `/index.php/` (`htaccess.RewriteBase`) gilt die normale Nextcloud-Einrichtung.
- **nginx:** Die Referenzkonfiguration aus dem Nextcloud-Admin-Handbuch genügt. Die Seiten der App heißen wie im Original `…/apps/nextnotes/index.php`, `dashboard.php` usw.; die Regel `rewrite … /index.php$request_uri` im Block `location ~ \.php(?:$|/)` leitet sie an Nextcloud weiter. Eigene Konfigurationen müssen diese Regel enthalten, sonst liefert nginx für diese Seiten 404. Über `/index.php/apps/nextnotes/…` sind die Seiten immer erreichbar.
- **`X-Content-Type-Options` unter nginx:** Die Referenzkonfiguration setzt `add_header X-Content-Type-Options "nosniff" always;`. Next Notes setzt den Header selbst bei Anhängen, Hintergrundbildern und Downloads. Bei diesen Antworten steht er dann doppelt (`nosniff, nosniff`). Das ist unschädlich, Browser werten ihn wie einen einfachen Header. Wer ihn einfach haben will, ergänzt im Block `location ~ \.php(?:$|/)`:
  ```
  fastcgi_hide_header X-Content-Type-Options;
  ```
  Das wirkt nur, solange dieser Block keine eigenen `add_header`-Zeilen hat (sonst erbt er die des `server`-Blocks nicht). Den `add_header` der Referenzkonfiguration **nicht** entfernen, sonst fehlt der Header bei allen anderen Antworten. Unter Apache steht er nur einmal.
- **Teams-Loopback:** Das Löschen eines Teams erfährt Next Notes über ein Ereignis der Teams-App. Im Web wird es über den Loopback der Teams-App verarbeitet. `occ circles:check` muss fehlerfrei durchlaufen. Hinter nginx/PHP-FPM oder einem Proxy erreicht sich Nextcloud oft nicht unter der eigenen Adresse, dann die Loopback-Werte der Teams-App setzen, z. B.:
  ```
  occ config:app:set circles loopback_tmp_scheme --value=https
  occ config:app:set circles loopback_tmp_id --value=cloud.example.org
  occ circles:check
  ```
  Ohne funktionierenden Loopback greift erst der tägliche `CleanupJob` (Abschnitt 6).

## 5. Hintergrundjobs (Cron)
System-Cron einrichten (*Verwaltung → Grundeinstellungen → Hintergrund-Aufgaben → Cron*). Mit „AJAX“ laufen die Jobs nur, solange jemand Nextcloud im Browser offen hat. Erinnerungen kommen dann verspätet, Aufräumarbeiten bleiben liegen. Fristen von Aufgaben gelten trotzdem pünktlich, weil die App sie bei jedem Zugriff selbst prüft.

| Job | Intervall | Aufgabe |
|---|---|---|
| `ReminderJob` | 5 min | fällige Erinnerungen als Benachrichtigung senden |
| `AssignmentDeadlineJob` | 5 min | abgelaufene Fristen abschließen, eine Sammelmeldung an die Lehrkräfte, Kopien an nachträglich beigetretene Schüler austeilen |
| `LockCleanupJob` | 1 h | abgelaufene Bearbeitungssperren löschen |
| `ExportTempCleanupJob` | 1 h | Reste abgebrochener ZIP-Exporte im Temp-Verzeichnis löschen |
| `SnapshotRetentionJob` | täglich | alte automatische Versionen von Notizen ausdünnen |
| `CleanupJob` | täglich | gelöschte Teams erkennen und nach Frist aufräumen, verwaiste Anhangsdateien und Zeichnungs-Vorschauen löschen, Rollenwechsel bei Aufgaben nachziehen |
| `DeletedWorkspaceSweepJob` | täglich | Inhalte gelöschter Konten nachräumen, falls der einmalige Löschjob abbrach |
| `OneNoteImportJob` | 5 min | OneNote-Importe fortsetzen, abgelaufene Anmeldungen und alte Importe aufräumen (Abschnitt 13) |

Dazu kommt bei jeder Kontolöschung ein einmaliger `UserDataDeletionJob`.

## 6. Teams: Löschen, Fristen, Prüfung
- Wird ein Team gelöscht, gelten seine Team-Workspaces, Aufgaben und Abgaben als **verwaist**. Die Ersteller der Team-Workspaces und die verantwortlichen Lehrkräfte der Aufgaben erhalten sofort und 7 Tage vor der Löschung eine Benachrichtigung. Nach **30 Tagen** löscht der `CleanupJob` alles, nie früher als 7 Tage nach dem zweiten Hinweis. In der Frist können Ersteller einen Team-Workspace in einen privaten umwandeln und Lehrkräfte die Abgaben als ZIP herunterladen.
- Verpasst Next Notes das Lösch-Ereignis (Loopback), prüft der `CleanupJob` täglich alle Teams. Ein Team gilt erst als gelöscht, wenn es in zwei Läufen hintereinander fehlt.
- **Massenschutz:** Fehlen in einem Lauf mehr als die Hälfte von über 10 Teams oder alle (mindestens 2), markiert der Job nichts und benachrichtigt die Admins. Erst `occ circles:check` und `occ nextnotes:team:orphan-check --dry-run` prüfen. Wurden die Teams wirklich gelöscht (z. B. Schuljahresende): `occ nextnotes:team:orphan-check --force`.
- Kann ein Team wiederholt nicht geprüft werden (z. B. föderiertes Team), überspringt der Job nur dieses Team. Nach drei Läufen in Folge erhalten die Admins eine Benachrichtigung.
- Ist die Teams-App deaktiviert, sind Team-Workspaces und Aufgaben nur lesbar („eingefroren“). Es läuft keine Frist und nichts wird gelöscht. Nach dem Reaktivieren gilt wieder der normale Zustand.

## 7. Admin-Einstellungen
*Verwaltung → Next Notes*:
- **Erlaubte Embed-Domains:** ein Host je Zeile (z. B. `www.youtube-nocookie.com`), höchstens 100. Nur genau diese Hosts, keine Platzhalter, keine IP-Adressen, keine Ports. Ab Werk leer. Eingebettete Frames anderer Hosts werden beim Speichern einer Notiz dauerhaft entfernt. Eingebettete Inhalte lädt der Browser direkt beim Drittanbieter, der dabei Daten der Leser erhält; datensparsame Hosts bevorzugen.
- **Maximale Dateigröße (MiB):** je Anhang, Standard 20, erlaubt 1–2048. Die PHP-Grenzen (`upload_max_filesize`, `post_max_size`) und die des Webservers müssen mindestens so groß sein.
- **OneNote-Import:** Authority, Client-ID, Client-Geheimnis und Team-Notizbücher, siehe Abschnitt 13.
- **Speicherkontingent für Anhänge je Benutzer (MiB):** Standard 500, erlaubt 1–1048576. Zählt die Anhänge aller Workspaces eines Besitzers, auch Uploads von Personen, mit denen geteilt wurde. Jedes Team hat ein eigenes Kontingent in derselben Höhe (Summe aller Team-Workspaces, inklusive ausgeteilter Kopien).

## 8. Speicher, Grenzen, Temp-Platz
- Notizen, Ordner, Tags, Versionen, Aufgaben und Abgaben liegen in der Datenbank (`oc_nextnotes_*`). Anhänge und Hintergrundbilder liegen in den App-Daten (`<datadirectory>/appdata_<instanceid>/nextnotes/`). Beides zählt **nicht** zum Nextcloud-Speicherkontingent der Benutzer, nur zum Kontingent aus Abschnitt 7.
- Hintergrundbilder: je Workspace und Benutzer ein Bild bis 5 MiB, ohne Kontingent.
- ZIP-Exporte: höchstens 1 GiB, höchstens einer je Benutzer gleichzeitig, 10 Exporte pro Minute. Die Gleichzeitigkeitssperre braucht Datei-Locking (`filelocking.enabled`, Standard an; ohne Locking-Memcache über die Datenbank).
- Temp-Platz: Ein Export braucht im Temp-Verzeichnis (`tempdirectory`) kurzzeitig bis etwa das Doppelte seiner Größe. Bei vielen gleichzeitigen großen Exporten genug Platz einplanen.
- Der Zähler „Erwähnungen“ in der Icon-Leiste wird je Benutzer 60 s im verteilten Cache gehalten (`memcache.distributed`, sonst lokaler Cache).
- Der Papierkorb wird nicht automatisch geleert. Automatische Versionen einer Notiz werden nach den Einstellungen des Besitzers ausgedünnt (Standard 30 Tage).

## 9. occ-Befehle
Alle Befehle laufen nur bei aktivierter App und nicht im Wartungsmodus. `occ <befehl> --help` zeigt die Optionen.

### `occ nextnotes:team:orphan-check [--dry-run] [--force]`
Prüft alle von Next Notes genutzten Teams und listet Team, Name, Zustand, Ergebnis und Aktion.
- `--dry-run`: nur auflisten, nichts ändern.
- Ohne `--dry-run`: fehlende Teams werden **sofort** als gelöscht markiert (die 30-Tage-Frist beginnt), wieder gefundene zurückgeholt. Gelöscht wird nie direkt.
- Greift der Massenschutz (Abschnitt 6), endet der Befehl mit Exit 2 ohne Änderung. `--force` übergeht ihn.
- Teams-App nicht verfügbar oder alle Abfragen gescheitert: Exit 1, nichts geändert.

### `occ nextnotes:assignment:transfer <aufgaben-id> <uid> [--force]`
### `occ nextnotes:assignment:transfer --all-unowned <uid> [--force]`
Macht `<uid>` zur verantwortlichen Lehrkraft einer Aufgabe bzw. aller Aufgaben ohne Verantwortliche. Aufgaben sind gesperrt, wenn das Konto ihrer Lehrkraft gelöscht wurde und es im Team keine andere Lehrkraft gab.
- Aktives Team: `<uid>` muss dort Moderator oder höher sein, sonst Fehler (auch mit `--force`).
- Gelöschtes, eingefrorenes oder nicht bestätigtes Team: jeder existierende Benutzer, nur mit `--force`. Die Verantwortliche kann dann die Abgaben lesen und exportieren, bei gelöschtem Team auch die Aufgabe löschen.
- Konnte die Teams-App das Team nicht bestätigen, gibt der tägliche `CleanupJob` die Aufgabe automatisch an eine Lehrkraft des Teams zurück, sobald das Team wieder als aktiv gilt.
- Jeder Übertrag wird im Nextcloud-Log protokolliert. Exit 1, wenn eine Aufgabe nicht übertragen wurde.

### `occ nextnotes:export-user <uid> <ziel.zip>`
Auskunft und Datenübertragbarkeit (DSGVO Art. 15/20) für ein Konto.
- Inhalt: je eigenem Workspace ein strukturierter Export (`workspaces/…zip`, ohne Papierkorb) und je eigener Abgabe ein Export (`submissions/…zip`, Feedback und Punkte erst nach der Rückgabe). Keine fremden Daten, keine Team-Workspaces, keine Vorlagen.
- `<ziel.zip>` darf nicht existieren. Ohne Daten: Hinweis, Exit 0, keine Datei.
- Überschreitet ein Teil die Exportgrenze von 1 GiB, bricht der Befehl ab (Exit 1, keine Datei).
- Hat das Konto gespeicherte OneNote-Importe (offene und bis 30 Tage nach Abschluss), enthält die Datei zusätzlich `onenote-imports.json` (Status, Zeitpunkte, Zähler, Fehlercode und Bericht mit Seitentiteln; keine Anmeldedaten). Der Export der App User migration enthält sie nicht.
- Für bereits gelöschte Konten liefert der Befehl nichts: Abgaben gelöschter Konten sind nur noch über die frühere UID gekennzeichnet und gehören den Lehrkräften.
- Der Befehl enthält auch Abgaben aus Teams, die der Schüler verlassen hat (er selbst sieht sie nicht mehr).
- Ist die App **User migration** installiert, enthält deren Export dieselben Teile unter `nextnotes/`, aber nur Abgaben, die der Benutzer selbst noch öffnen kann. Der Import überspringt Next Notes (kein Import in Next Notes).

### `occ nextnotes:delete-user-data <uid> [--user-deleted]`
Löscht die Next-Notes-Daten eines Kontos **genau wie eine Kontolöschung**, ohne das Konto selbst zu löschen.
- **Nur für deaktivierte oder gelöschte Konten.** Bei einem aktiven Konto bricht der Befehl ab (Exit 1), nichts wird geändert. Vorher `occ user:disable <uid>`.
- Gelöscht werden sofort: private Workspaces mit Notizen, Ordnern, Tags, Anhängen und Versionen, Freigaben an und vom Konto, Erinnerungen, Sperren, Erwähnungen, Hintergrundbilder, App-Einstellungen und Benachrichtigungen des Kontos.
- Erhalten bleiben: **Abgaben** (für die Lehrkräfte, mit Name und früherer UID), Notizen in Team-Workspaces (ohne Verfasser). Aufgaben einer Lehrkraft gehen an eine andere Lehrkraft des Teams oder werden gesperrt (dann `assignment:transfer`).
- **LDAP/SAML:** Findet das Benutzer-Backend die UID nicht, gibt es aber noch Daten, verlangt der Befehl eine Bestätigung (Eingabe der UID oder `--user-deleted`). Grund: Ein nicht erreichbares LDAP- oder SAML-Backend meldet auch ein aktives Konto als „nicht vorhanden“. Vorher sicherstellen, dass das Konto wirklich gelöscht ist.
- **Reaktivierung:** Wird das Konto danach wieder aktiviert, bleiben die Daten gelöscht und die alten Abgaben dem Konto nicht mehr zugeordnet. Ist es noch Mitglied eines Teams, hat es wieder Zugriff auf die Team-Workspaces und erhält neue Kopien offener Aufgaben.
- **Neu vergebene UID:** Nextcloud kann eine UID nach dem Löschen neu vergeben. Der Befehl wirkt immer auf die UID, also auf das neue Konto; ist es aktiv, bricht er ab.
- Ein deaktiviertes Konto erhält beim Austeilen keine Kopien und wird nie automatisch verantwortliche Lehrkraft.
- Mehrfach ausführbar. Unbekannte UID ohne Daten: Hinweis, Exit 0.

### `occ nextnotes:purge [--force] [--confirm=nextnotes]`
Löscht **alle** Daten der App aller Benutzer und Teams. Nur für die Deinstallation (Abschnitt 11).
- Ohne `--force`: zählt nur (Zeilen je Tabelle, App-Daten, Benutzer mit Einstellungen, Jobs), keine Änderung.
- Mit `--force`: fragt nach der App-ID `nextnotes`. Ohne Rückfrage (`--no-interaction`) nur mit `--confirm=nextnotes`. Falsche oder fehlende Eingabe: Exit 1, nichts gelöscht.
- Gelöscht werden alle Tabellen-Inhalte (`oc_nextnotes_*`), **auch alle Abgaben trotz Aufbewahrungsfrist**, alle App-Daten (Anhänge, Hintergrundbilder), Benachrichtigungen und Hintergrundjobs der App, die Next-Notes-Einstellungen aller Benutzer und die Einstellungen aus Abschnitt 7. Nicht umkehrbar.
- **Vorher:** Aufbewahrung der Abgaben mit der Schule klären und Abgaben exportieren (Lehrkräfte: ZIP je Aufgabe).
- occ-Befehle der App laufen nicht im Wartungsmodus. Damit niemand während des Löschens schreibt, die App vorher auf Admins beschränken: `occ app:enable nextnotes --groups admin`.
- Muss **vor** `occ app:remove` laufen; nach dem Entfernen gibt es den Befehl nicht mehr.

## 10. Datenschutz
<!-- Q3: von Thomas gegenzulesen -->
> Hinweis: Dieser Abschnitt beschreibt das Verhalten der App. Er ist keine Rechtsberatung. Rechtsgrundlagen und Fristen legt die Schule fest.

**Welche Daten:** Inhalte der Notizen, Anhänge und Zeichnungen, Ordner, Tags, Versionen, Erinnerungen, Erwähnungen, Freigaben, persönliche Einstellungen; bei Teams zusätzlich Aufgaben, Abgaben, Feedback und Punkte. Gespeichert werden UIDs und, wo Namen nach einer Kontolöschung sichtbar bleiben müssen, Anzeigenamen (Schüler in Abgaben, Lehrkraft bei Aufgabe und Feedback, Verfasser einer Erwähnung).

**Wo:** nur in der Datenbank und im Datenverzeichnis dieser Nextcloud (Abschnitt 8). Die App sendet keine Daten an Dritte und führt kein eigenes Aktivitätsprotokoll. Zwei Ausnahmen liegen in der Hand der Administration: eingebettete Inhalte von erlaubten Embed-Domains (Abschnitt 7) lädt der Browser der Leser direkt beim Anbieter; der OneNote-Import (Abschnitt 13) meldet Benutzer bei Microsoft an und liest dort ihre Notizbücher, schickt aber keine Inhalte aus Next Notes hin. Die App enthält keine KI-Funktionen.

**OneNote-Import:** Was dabei gespeichert wird und wie lange: Abschnitt 13.6.

**Auskunft und Datenübertragbarkeit:** Benutzer exportieren ihre Workspaces selbst (*Einstellungen → Export*) und ihre Abgaben in der Ansicht „Aufgaben“. Admins nutzen `occ nextnotes:export-user` oder die App User migration (Abschnitt 9).

**Löschung eines Kontos:** Kontolöschung in Nextcloud oder `occ nextnotes:delete-user-data` für deaktivierte Konten (Abschnitt 9). Gelöscht werden private Inhalte, Freigaben, Erinnerungen, Einstellungen und Benachrichtigungen. Erhalten bleiben Abgaben und Notizen in Team-Workspaces (siehe unten).

**Aufbewahrung von Abgaben:**
- Abgaben bestehen aus der Kopie-Notiz des Schülers mit Anhängen und Versionen, Status, Abgabezeit, Feedback und Punkten.
- Sie bleiben vollständig erhalten, wenn der Schüler das Team verlässt (die Lehrkräfte sehen und exportieren sie weiter; der Schüler selbst sieht seine Aufgaben und Abgaben nicht mehr, nach einem Wiedereintritt wieder), wenn das Schülerkonto gelöscht wird (dann nur noch mit Anzeigename und früherer UID, nur für die Lehrkräfte lesbar) und wenn das Konto der Lehrkraft gelöscht wird (die Aufgabe geht an eine andere Lehrkraft des Teams oder wird gesperrt).
- Gelöscht werden Abgaben nur auf vier Wegen:
  1. **Die Lehrkraft löscht die Aufgabe.** Das löscht die Aufgabe mit allen Abgaben, Kopien und Anhängen. Einzelne Abgaben lassen sich nicht löschen.
  2. **Die Lehrkraft löscht den letzten Workspace eines Teams** und bestätigt das. Das löscht auch die Aufgaben dieses Workspace mit allen Abgaben (der Dialog nennt ihre Zahl). Gibt es einen anderen Workspace des Teams, ziehen die Aufgaben dorthin um.
  3. **Das Team wird gelöscht.** 30 Tage danach löscht die App alle Aufgaben und Abgaben des Teams (Abschnitt 6).
  4. **Die Administration führt `occ nextnotes:purge` aus** (nur bei Deinstallation).
- Die App setzt **keine Aufbewahrungsfrist** durch. Die Aufbewahrung nach Kontolöschung oder Austritt schränkt das Recht auf Löschung ein. Sie braucht eine Rechtsgrundlage, z. B. eine schulrechtliche Aufbewahrungspflicht nach Landesrecht (DSGVO Art. 17 Abs. 3 lit. b) oder die Geltendmachung von Rechtsansprüchen (lit. e). Die **Schule legt die Frist fest** und dokumentiert sie im **Verzeichnis von Verarbeitungstätigkeiten**. Nach Ablauf der Frist löschen die Lehrkräfte die Aufgaben selbst. Die Schule sollte das den Lehrkräften vorgeben, z. B. zum Schuljahresende.

**Weitere Aufbewahrung:**
- Notizen, die jemand in einem Team-Workspace geschrieben hat, gehören dem Team und bleiben nach Austritt oder Kontolöschung erhalten (ohne Verfasser).
- Notizen in Workspaces anderer Personen, mit denen geteilt wurde, gehören deren Besitzer und bleiben ebenfalls.
- Datensicherungen der Nextcloud enthalten gelöschte Daten, bis die Sicherungen nach dem Sicherungskonzept der Schule überschrieben sind.

**Deinstallation:** `occ app:remove` löscht keine Daten (Nextcloud-Standard). Wer die Daten löschen will, führt vorher `occ nextnotes:purge` aus (Abschnitt 11).

## 11. Deaktivieren und Entfernen
- `occ app:disable nextnotes`: Daten bleiben, Hintergrundjobs ruhen. Nach `app:enable` geht es weiter wie zuvor.
- `occ app:remove nextnotes`: entfernt die App-Dateien. Tabellen und App-Daten **bleiben** (Nextcloud-Standard). Eine spätere Installation übernimmt sie.
- **Vollständig entfernen**, in dieser Reihenfolge:
  1. Abgaben nach Vorgabe der Schule exportieren (Abschnitt 10).
  2. `occ app:enable nextnotes --groups admin` (niemand sonst schreibt mehr).
  3. `occ nextnotes:purge` (Zählung prüfen), dann `occ nextnotes:purge --force`.
  4. `occ app:remove nextnotes`.

## 12. Datensicherung
Zu sichern sind die Tabellen `oc_nextnotes_*` und der Ordner `appdata_<instanceid>/nextnotes/` im Datenverzeichnis, beide zusammen (Anhänge gehören zu Tabellenzeilen). Die normale Nextcloud-Sicherung (Datenbank + Datenverzeichnis) deckt beides ab.

## 13. OneNote-Import
Benutzer können ihre OneNote-Notizbücher aus Microsoft 365 in eigene Workspaces importieren (Bedienung: [user.md](user.md#aus-onenote-importieren)). Dafür braucht die Schule eine eigene **App-Registrierung in Microsoft Entra**. Es gibt keine zentrale App von Next Notes, weil jede Nextcloud eine eigene Rücksprung-Adresse hat. Ohne Registrierung ist der Import aus und Benutzer sehen ihn nicht.

### 13.1 Was die App macht und was nicht
- **Macht:** Der Benutzer meldet sich mit seinem Microsoft-Konto an (im Browser direkt bei Microsoft, Next Notes sieht kein Passwort). Er wählt Notizbücher oder Abschnitte. Ein Hintergrundjob liest sie über Microsoft Graph und legt je Notizbuch einen neuen eigenen Workspace an. Bereits importierte Seiten werden beim nächsten Import übersprungen.
- **Macht nicht:** nichts nach OneNote zurückschreiben, nicht synchronisieren, importierte Seiten nicht aktualisieren, keine Notizbücher anderer Benutzer ohne deren Anmeldung lesen, nicht in Team-Workspaces importieren, keine SharePoint-Website-Notizbücher.
- **Die Registrierung legt der Admin bei Microsoft an**; Next Notes registriert nichts selbst und ändert nichts im Tenant.
- Der Server ruft nur `https://login.microsoftonline.com` und `https://graph.microsoft.com` auf (ausgehend, Port 443). Ein Proxy aus `config.php` (`proxy`) gilt auch hier. Nationale Clouds (z. B. 21Vianet) werden nicht unterstützt.

### 13.2 Kontotyp wählen
Bei der Registrierung fragt Microsoft, wer die App nutzen darf. In Next Notes trägt man dazu passend die **Authority** ein:

| Kontotyp bei Microsoft | Authority in Next Notes | Wann |
|---|---|---|
| Nur Konten in diesem Organisationsverzeichnis (Single-Tenant) | **Verzeichnis-(Mandanten-)ID** (GUID) oder die Domain des Tenants (z. B. `schule.example`) | **Empfohlen.** Nur Konten der eigenen Schule. Keine Probleme mit der Zustimmung. |
| Konten in einem beliebigen Organisationsverzeichnis (Mehrmandanten) | `organizations` | Mehrere Schulen bzw. Tenants nutzen dieselbe Nextcloud. In den anderen Tenants muss meist deren Admin zustimmen, weil die App nicht von Microsoft verifiziert ist. |
| Konten in einem beliebigen Organisationsverzeichnis und persönliche Microsoft-Konten | `common` | Zusätzlich private Konten (Outlook.com, Hotmail). **Team- und Klassennotizbücher gibt es damit nicht**, der Schalter dafür wirkt nicht. Private Konten sehen auf der Zustimmungsseite „nicht verifiziert“. |

Authority und Kontotyp müssen zusammenpassen, sonst lehnt Microsoft die Anmeldung ab. Nur private Konten (`consumers`) bietet Next Notes nicht an.

### 13.3 App registrieren (Microsoft Entra Admin Center)
Die Bezeichnungen folgen der deutschen Oberfläche, in Klammern die englische.
1. In Next Notes *Verwaltung → Next Notes → OneNote-Import* öffnen und die **Umleitungs-URI für die App-Registrierung** kopieren. Sie sieht etwa so aus: `https://cloud.schule.example/apps/nextnotes/onenote/callback` (je nach Webserver mit `/index.php/` vor `apps`, bei Nextcloud in einem Unterordner mit diesem davor). Immer den angezeigten Wert nehmen, nicht abtippen.
   - Die Adresse kommt aus der Adresse, unter der man die Verwaltung gerade geöffnet hat. Die Seite deshalb über die offizielle Adresse der Nextcloud öffnen (die auch die Benutzer verwenden).
   - Microsoft verlangt `https://`; nur `http://localhost` ist für Tests erlaubt.
2. Im [Microsoft Entra Admin Center](https://entra.microsoft.com) anmelden (mindestens Rolle „Anwendungsentwickler“; für Schritt 7 eine Administratorrolle, die Zustimmungen erteilen darf).
3. *Entra ID → App-Registrierungen (App registrations) → Neue Registrierung (New registration)*:
   - **Name:** z. B. „Next Notes OneNote-Import“. Benutzer sehen ihn auf der Anmeldeseite.
   - **Unterstützte Kontotypen (Supported account types):** wie in 13.2.
   - **Umleitungs-URI (Redirect URI):** Plattform **Web** (nicht „Single-Page-Anwendung“, nicht „Öffentlicher Client“), Wert = die kopierte Adresse, exakt (Groß-/Kleinschreibung, kein Schrägstrich am Ende).
   - *Registrieren*.
4. Auf der Übersichtsseite die **Anwendungs-ID (Client-ID)** kopieren, bei Single-Tenant auch die **Verzeichnis-ID (Mandanten-ID)**.
5. *Zertifikate & Geheimnisse (Certificates & secrets) → Geheime Clientschlüssel → Neuer geheimer Clientschlüssel*:
   - Beschreibung (z. B. „Next Notes 2027“) und Ablauf wählen.
   - Den **Wert** (nicht die „Geheimnis-ID“) sofort kopieren; Microsoft zeigt ihn nur einmal.
   - **Ablaufdatum im Kalender notieren** und einige Wochen vorher eine Erinnerung setzen (Abschnitt 13.5).
6. *API-Berechtigungen (API permissions) → Berechtigung hinzufügen → Microsoft Graph → Delegierte Berechtigungen*:
   - Immer: `openid`, `offline_access`, `Notes.Read`.
   - Nur wenn Team- und Klassennotizbücher erlaubt werden sollen (nicht bei `common`): zusätzlich `Notes.Read.All` und `Team.ReadBasic.All`.
   - `User.Read`, das Microsoft bei neuen Registrierungen vorgibt, braucht Next Notes nicht. Es kann bleiben oder entfernt werden.
   - Keine Anwendungsberechtigungen (Application permissions) hinzufügen; Next Notes nutzt sie nicht.
7. **Administratorzustimmung für <Schule> erteilen** (Grant admin consent) und bestätigen. Microsoft verlangt sie für diese Berechtigungen nicht, sie ist aber **empfohlen**: Ohne sie sieht jeder Schüler beim ersten Mal eine Zustimmungsseite, und ist die Benutzerzustimmung im Tenant abgeschaltet, geht die Anmeldung ohne sie gar nicht.

### 13.4 In Next Notes eintragen
*Verwaltung → Next Notes → OneNote-Import*:
- **Authority:** wie in 13.2 (Mandanten-ID, Domain, `organizations` oder `common`).
- **Anwendungs-ID (Client-ID):** aus Schritt 4.
- **Client-Geheimnis:** der Wert aus Schritt 5. Das Feld zeigt danach nur „gesetzt“; der Wert wird nie wieder angezeigt. Leer lassen = unverändert, *Gespeichertes Client-Geheimnis entfernen* schaltet den Import ab.
- **Team- und Klassennotizbücher erlauben:** ab Werk aus. Nur mit Mandanten-ID, Domain oder `organizations` und den Berechtigungen aus Schritt 6. Team-Notizbücher können Inhalte anderer Personen enthalten (z. B. Abschnitte aller Schüler im Klassennotizbuch einer Lehrkraft); importiert wird nur, was der Benutzer ausdrücklich auswählt, und nur in seinen eigenen Workspace.
- *Speichern*. Sind Client-ID und Geheimnis gesetzt, erscheint bei den Benutzern unter *Einstellungen* die Karte „Import aus OneNote“.

### 13.5 Betrieb
- **Cron ist Pflicht.** Der Import läuft nur im Hintergrundjob `OneNoteImportJob` (alle 5 min, Abschnitt 5). Mit „AJAX“ läuft er nur, solange jemand Nextcloud im Browser offen hat, und lange Läufe brechen ab. Es geht dabei nichts kaputt, der Import wird nur sehr langsam.
- **Drosselung durch Microsoft:** höchstens 400 Anfragen pro Stunde je Benutzer und App. Next Notes nutzt davon etwa **350 pro Stunde** und hält mindestens 0,6 s Abstand. Eine Seite braucht eine Anfrage plus eine je Bild oder Datei.
- **Dauer:** Ein Notizbuch mit 300 Seiten und 600 Bildern braucht rund **2,5–3 Stunden**, 50 Seiten mit wenigen Bildern etwa 15–30 Minuten. Mehrere Benutzer können gleichzeitig importieren, jeder mit eigenem Kontingent bei Microsoft. Der Job arbeitet aber je Lauf nur etwa 2 Minuten und teilt sie reihum auf; bei mehr als etwa drei gleichzeitigen Importen wird jeder einzelne langsamer. Die Importseite zeigt eine geschätzte Restzeit, am Ende kommt eine Nextcloud-Benachrichtigung.
- **Grenzen je Seite:** höchstens 100 Bilder und Dateien, zusammen höchstens 256 MiB, 15 min. Je Datei gilt die *Maximale Dateigröße* aus Abschnitt 7, höchstens aber 100 MiB. Was darüber liegt, wird ein Platzhalter und steht im Bericht. Importierte Anhänge zählen zum Speicherkontingent des Benutzers (Abschnitt 7).
- **Temp-Platz:** Bilder und Dateien einer Seite liegen kurz im Temp-Verzeichnis (bis 256 MiB je laufendem Import). Reste nach einem harten Abbruch löscht der Job nach 1 h.
- **Client-Geheimnis erneuern:** Vor Ablauf in Entra ein neues Geheimnis anlegen, in Next Notes eintragen, danach das alte in Entra löschen. Ein neues Geheimnis bei gleicher Client-ID ändert an laufenden Importen nichts.
- **Abgelaufenes Geheimnis:** Microsoft lehnt die Anmeldung der App ab. Laufende Importe enden mit „Die OneNote-Konfiguration ist ungültig“ (`invalid_client`), im Nextcloud-Log steht eine Warnung „OneNote client ID or secret rejected by Microsoft“. Neues Geheimnis eintragen; die Benutzer starten den Import neu, bereits importierte Seiten werden übersprungen.
- **Konfiguration ändern:** Ändert der Admin Client-ID oder Authority, enden alle offenen Importe mit „Konfiguration geändert“ (`config_changed`), ihre Anmeldedaten werden gelöscht. Die Benutzer starten neu, bereits importierte Seiten werden übersprungen. Wird der Schalter für Team-Notizbücher ausgeschaltet, enden ebenso alle offenen Importe, die Team-Notizbücher enthalten; die anderen laufen weiter.
- **Import ausschalten:** Client-Geheimnis entfernen. Offene Importe ruhen dann ohne Anfrage an Microsoft; ihre Anmeldedaten werden trotzdem nach den Fristen in 13.6 gelöscht.
- **Job hängt nach Serverneustart:** Bricht ein Cron-Prozess hart ab (Neustart, Speicherfehler), hält Nextcloud den Job bis zu **12 Stunden** als „reserviert“ und alle Importe ruhen so lange. Es gehen keine Daten verloren. Sofort weiter geht es mit:
  ```
  occ background-job:list --class='OCA\NextNotes\BackgroundJob\OneNoteImportJob'
  occ background-job:execute <ID> --force-execute
  ```
  `--force-execute` hebt die Reservierung auf und führt einen Lauf aus; danach übernimmt der normale Cron wieder.

### 13.6 Datenschutz
| Was | Wo | Wie lange |
|---|---|---|
| Zugriffs- und Aktualisierungs-Token von Microsoft | Tabelle `oc_nextnotes_onenote_imports`, verschlüsselt mit dem `secret` aus `config.php` | **nur während des Imports**: in der Auswahl höchstens 24 h, beim Importieren bis zum Ende; gelöscht bei Ende, Abbruch, Fehler, Konfigurationswechsel, deaktiviertem Konto, entzogenem App-Zugriff und nach 7 Tagen ohne Fortschritt |
| Einmalwerte der Anmeldung (state, PKCE) | dto. | höchstens 10 min, einmal verwendbar |
| Kennung des Microsoft-Kontos (Hash aus Tenant- und Benutzerkennung, kein Name, keine E-Mail) | dto. | wie der Import; nur um eine Neuanmeldung mit einem anderen Konto zu erkennen |
| Auswahl, Fortschritt, Zähler, Bericht (gekürzte Seitentitel mit Grund) | dto. | 30 Tage nach Abschluss |
| Zuordnung „Microsoft-Element → importierte Notiz“ (Hash der Microsoft-ID, keine Inhalte) | `oc_nextnotes_onenote_items` | bis zur Kontolöschung, `delete-user-data` oder `purge`; verhindert doppelte Importe |
| Importierte Notizen und Anhänge | wie alle Notizen | wie alle Notizen (Abschnitt 10) |
| Client-Geheimnis | App-Einstellungen, als vertraulich markiert | bis der Admin es ändert oder `purge` |

- **Auskunft:** `occ nextnotes:export-user` enthält `onenote-imports.json` mit Status, Zeitpunkten, Zählern, Fehlercode und dem Bericht (Seitentitel). Nicht enthalten: Token, Einmalwerte, Kontokennung, Auswahl und die Zuordnungstabelle (technische Hilfsdaten ohne Inhalt). Die importierten Notizen selbst sind wie alle Notizen im Export.
- **Umzug** (App User migration): ohne Importe und Zuordnungen; ein Import ist an die Instanz und das Microsoft-Konto gebunden.
- **Löschung:** Kontolöschung und `occ nextnotes:delete-user-data` löschen beide Tabellen des Kontos, `occ nextnotes:purge` alles einschließlich der Einstellungen `onenote_*` und des Client-Geheimnisses.
- **Logs:** Next Notes schreibt nie Token, Geheimnis, Seitentitel oder Inhalte ins Log, nur Import-ID, HTTP-Status und Fehlercode. Der Webserver protokolliert einmal die Rücksprung-Adresse mit dem einmaligen Anmeldecode; er ist ohne Geheimnis nutzlos und nach Minuten verfallen.
- **Bei Microsoft:** Die Anmeldungen erscheinen in den Anmeldeprotokollen (Sign-in logs) des Tenants. Weitere Empfänger gibt es nicht.
- Klassennotizbücher enthalten Inhalte von Schülern. Ob Lehrkräfte sie in Next Notes übernehmen dürfen, regelt die Schule (Verzeichnis von Verarbeitungstätigkeiten, Abschnitt 10).

### 13.7 Widerruf bei Microsoft
- In Next Notes löscht *Import abbrechen* die Token sofort. Die **Zustimmung bei Microsoft bleibt** bestehen, damit eine spätere Anmeldung ohne erneute Rückfrage geht.
- **Admins:** Entra Admin Center → *Entra ID → Unternehmensanwendungen (Enterprise applications)* → App wählen → *Berechtigungen (Permissions)* → Administratorzustimmung widerrufen. Zustimmungen einzelner Benutzer lassen sich dort nicht per Klick widerrufen, nur mit Microsoft Graph oder PowerShell. Ein Widerruf verhindert keine neue Zustimmung; soll niemand mehr importieren, das Client-Geheimnis in Next Notes entfernen oder die Registrierung löschen.
- **Benutzer mit Schulkonto:** im Portal „Meine Apps“ (<https://myapps.microsoft.com>) die App entfernen. Hat der Admin für alle zugestimmt (Schritt 7), kann nur der Admin widerrufen.
- **Benutzer mit privatem Konto** (`common`): im Microsoft-Konto unter „Apps und Dienste“ die App entfernen.

### 13.8 Fehlerbilder
| Benutzer sieht | Code | Ursache | Abhilfe |
|---|---|---|---|
| Fehlerseite bei Microsoft (z. B. `AADSTS50011`) statt Rücksprung | – | Umleitungs-URI passt nicht, Kontotyp passt nicht zum Konto, falsche Client-ID | URI exakt aus der Admin-Karte übernehmen (13.3), Kontotyp und Authority prüfen (13.2) |
| „Anmeldung abgebrochen.“ | – | Benutzer hat bei Microsoft abgebrochen oder nicht zugestimmt | erneut verbinden |
| „Anmeldung fehlgeschlagen.“ | – | sonstiger Fehler beim Rücksprung oder Token-Abruf | erneut verbinden; bleibt es, Nextcloud-Log prüfen (Fehlercode, keine Token) |
| „Die Zustimmung fehlt. Bitte frage deine Administration.“ | `consent` | Benutzerzustimmung im Tenant abgeschaltet oder fremder Tenant ohne Admin-Zustimmung | Administratorzustimmung erteilen (13.3, Schritt 7) |
| „Berechtigungen in der App-Registrierung prüfen (Administration).“ | `permissions` | Berechtigung fehlt in der Registrierung oder Client nicht zugelassen | Berechtigungen aus Schritt 6 ergänzen, Zustimmung erneuern |
| „Die OneNote-Konfiguration ist ungültig …“ | `invalid_client` | Geheimnis abgelaufen oder falsch, Client-ID gelöscht | neues Geheimnis (13.5), Benutzer starten neu |
| „Die Administration hat die OneNote-Konfiguration geändert …“ | `config_changed` | Client-ID, Authority oder Team-Schalter geändert | Benutzer starten neu; nichts doppelt |
| „Pausiert: erneut bei Microsoft anmelden“ (mit Benachrichtigung) | `reauth` | Microsoft-Anmeldung abgelaufen (Passwortwechsel, bedingter Zugriff, Widerruf) | Benutzer meldet sich auf der Importseite neu an, der Import setzt fort. Ohne Neuanmeldung nach 7 Tagen „Abgelaufen“ |
| „Bitte melde dich mit demselben Microsoft-Konto an wie beim Start.“ | `account_mismatch` | Neuanmeldung mit anderem Konto | mit dem ursprünglichen Konto anmelden oder abbrechen und neu starten |
| „Dein Konto ist deaktiviert.“ / „Du hast keinen Zugriff mehr auf Next Notes.“ | `user_disabled` / `app_disabled` | Nextcloud-Konto deaktiviert oder App-Gruppen geändert (Abschnitt 3) | nach Freischaltung neu anmelden, sonst nach 7 Tagen „Abgelaufen“ |
| „Nicht genug Speicherplatz für Anhänge.“ | `quota` | Speicherkontingent für Anhänge voll | Kontingent erhöhen (Abschnitt 7) oder Platz schaffen, neu starten |
| „Die Notizbuch-Bibliothek ist zu groß für Microsoft Graph …“ | `graph_10008` | Eine Dokumentbibliothek des Benutzers oder Teams hat mehr als 5000 OneNote-Elemente bzw. 20.000 Elemente; Microsoft liefert dann nichts | Bibliothek in OneDrive/SharePoint verkleinern; sonst nicht lösbar |
| „Microsoft konnte das Notizbuch nicht liefern.“ | `graph_not_found` | 20 Elemente hintereinander bei Microsoft nicht gefunden (gelöscht, verschoben) | neu starten |
| „Microsoft hat wiederholt nicht geantwortet …“ | `graph_unavailable` | 10 Fehlversuche hintereinander (Störung, Netz, Proxy) | Erreichbarkeit der beiden Hosts prüfen (13.1), später neu starten |
| „Der Import kam 7 Tage lang nicht voran …“ | `stalled` | Cron läuft nicht oder Job reserviert | Cron prüfen, 13.5 „Job hängt“ |
| „Der OneNote-Import ist auf diesem Server nicht eingerichtet.“ | `not_configured` | Client-ID oder Geheimnis fehlt | 13.4 |

Einzelne Seiten, die Microsoft verweigert (403) oder nicht findet (404), werden übersprungen und stehen im Bericht; der Import läuft weiter.

### 13.9 Vor dem Rollout testen
1. Mit einem **Testkonto** der Schule (kein Admin) ein kleines Notizbuch mit Text, Tabelle, To-do, Bild und PDF importieren.
2. Ist der Team-Schalter an: ein Klassennotizbuch als Lehrkraft-Testkonto, nur einen Abschnitt auswählen.
3. Prüfen: Benachrichtigung am Ende, Bericht, Workspace mit Ordnern, Bilder sichtbar, PDF herunterladbar.
4. *Import abbrechen* während eines Laufs testen; danach lässt sich ein neuer Import starten.
5. Erst dann die Benutzer informieren (Dauer, Datenschutzhinweis aus 13.6).
