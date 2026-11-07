<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Next Notes

Native Nextcloud-App (App-ID `nextnotes`) für Notizen, Ordner und geteilte Workspaces; Nextcloud 35.
Basiert auf [Poznote](https://github.com/timothepoznanski/poznote) von Timothé Poznanski (MIT).
Lizenz: App AGPL-3.0-or-later (`COPYING`), übernommene Poznote-Teile MIT (`LICENCE`). Doku: `../docs/`.

## Betrieb
- ZIP-Exporte: höchstens einer je Benutzer gleichzeitig, nur mit aktivem Datei-Locking (`filelocking.enabled`, Standard an; ohne Locking-Memcache über die DB). Ohne Datei-Locking gilt nur das Rate-Limit von 10 Exporten pro Minute.
- Temp-Platz: Ein Export bis 1 GiB braucht im Temp-Verzeichnis (`tempdirectory`) kurzzeitig bis etwa das Doppelte. Bei vielen gleichzeitigen großen Exporten genug Platz einplanen. Reste nach Abbrüchen räumt ein stündlicher Hintergrundjob (Cron nötig).
- Hintergrundbilder (Hauptansicht, Speed Dial) liegen in den App-Daten und zählen zu keiner Quote: je Workspace und Benutzer bis 2 Bilder à 5 MiB.
- Zähler „Erwähnungen“ in der Icon-Leiste wird je Benutzer 60 s im verteilten Cache gehalten (`memcache.distributed`, sonst lokaler Cache).
- Teams (Circles): Next Notes erfährt das Löschen eines Teams über ein Circles-Event, im Web über den Circles-Loopback. `occ circles:check` muss grün sein (bei nginx/fpm oder Proxy ggf. `loopback_tmp_*` setzen), sonst greift erst der tägliche `CleanupJob` (zwei Läufe mit „Team fehlt“). Verwaiste Team-Workspaces werden 30 Tage nach dem Löschen des Teams gelöscht, frühestens 7 Tage nach dem Hinweis an die Ersteller.
- `occ nextnotes:team:orphan-check --dry-run` listet alle von Next Notes genutzten Teams mit Ergebnis und geplanter Aktion, ohne etwas zu ändern. Ohne `--dry-run` werden fehlende Teams sofort als gelöscht markiert (Frist beginnt), wieder gefundene zurückgeholt; gelöscht wird nie direkt.
- Massenschutz: Fehlen mehr als die Hälfte von über 10 Teams oder alle (mindestens 2), markiert weder der Job noch der Befehl etwas; Admins erhalten eine Benachrichtigung, der Befehl endet mit Exit 2. Erst `occ circles:check` und `--dry-run` prüfen; wurden die Teams wirklich gelöscht (z. B. Schuljahresende), `occ nextnotes:team:orphan-check --force`.
- Der `CleanupJob` entfernt außerdem Anhangsdateien ohne Datenbankzeile und nicht mehr verwendete Excalidraw-Vorschauen (jeweils erst nach 24 h); Budget 300 s je Lauf, der Rest folgt am nächsten Tag.
- Kann ein Team wiederholt nicht geprüft werden (z. B. föderiert), überspringt der Job nur dieses Team; nach drei Läufen in Folge erhalten Admins eine Benachrichtigung, `orphan-check` zeigt es mit Ergebnis `error`.
