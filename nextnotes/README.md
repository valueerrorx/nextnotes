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
