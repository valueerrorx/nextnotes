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
- Teilen-Dialog, Erwähnungen und das Austeilen von Aufgaben prüfen die Gruppen **nicht**: Ein Team-Mitglied außerhalb der Gruppen erhält beim Austeilen trotzdem eine Kopie (belegt Speicher des Teams), kann sie aber nicht öffnen und nicht abgeben. Deshalb alle Lehrkräfte und Schüler, die Teams mit Next Notes nutzen, in die freigegebenen Gruppen aufnehmen.

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
- Für bereits gelöschte Konten liefert der Befehl nichts: Abgaben gelöschter Konten sind nur noch über die frühere UID gekennzeichnet und gehören den Lehrkräften.
- Ist die App **User migration** installiert, enthält deren Export dieselben Teile unter `nextnotes/`. Der Import überspringt Next Notes (kein Import in Next Notes).

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

**Wo:** nur in der Datenbank und im Datenverzeichnis dieser Nextcloud (Abschnitt 8). Die App sendet keine Daten an Dritte und führt kein eigenes Aktivitätsprotokoll. Ausnahme liegt in der Hand der Administration: eingebettete Inhalte von erlaubten Embed-Domains (Abschnitt 7) lädt der Browser der Leser direkt beim Anbieter. Die App enthält keine KI-Funktionen.

**Auskunft und Datenübertragbarkeit:** Benutzer exportieren ihre Workspaces selbst (*Einstellungen → Export*) und ihre Abgaben in der Ansicht „Aufgaben“. Admins nutzen `occ nextnotes:export-user` oder die App User migration (Abschnitt 9).

**Löschung eines Kontos:** Kontolöschung in Nextcloud oder `occ nextnotes:delete-user-data` für deaktivierte Konten (Abschnitt 9). Gelöscht werden private Inhalte, Freigaben, Erinnerungen, Einstellungen und Benachrichtigungen. Erhalten bleiben Abgaben und Notizen in Team-Workspaces (siehe unten).

**Aufbewahrung von Abgaben:**
- Abgaben bestehen aus der Kopie-Notiz des Schülers mit Anhängen und Versionen, Status, Abgabezeit, Feedback und Punkten.
- Sie bleiben vollständig erhalten, wenn der Schüler das Team verlässt (er kann seine Abgabe weiter lesen), wenn das Schülerkonto gelöscht wird (dann nur noch mit Anzeigename und früherer UID, nur für die Lehrkräfte lesbar) und wenn das Konto der Lehrkraft gelöscht wird (die Aufgabe geht an eine andere Lehrkraft des Teams oder wird gesperrt).
- Gelöscht werden Abgaben nur auf drei Wegen:
  1. **Die Lehrkraft löscht die Aufgabe.** Das löscht die Aufgabe mit allen Abgaben, Kopien und Anhängen. Einzelne Abgaben lassen sich nicht löschen.
  2. **Das Team wird gelöscht.** 30 Tage danach löscht die App alle Aufgaben und Abgaben des Teams (Abschnitt 6).
  3. **Die Administration führt `occ nextnotes:purge` aus** (nur bei Deinstallation).
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
