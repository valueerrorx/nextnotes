<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Next Notes – Update

Ergänzung zur [Admin-Doku](admin.md).

## Vor dem Update
- **Datensicherung:** Datenbank (mindestens `oc_nextnotes_*`) und `appdata_<instanceid>/nextnotes/` im Datenverzeichnis, zusammen sichern. Updates können das Datenbankschema ändern.
- **Kein Downgrade:** Der App Store spielt keine ältere Version über eine neuere ein. Eine ältere Version **nicht** von Hand einspielen: Das Datenbankschema bliebe auf dem neueren Stand und passt nicht mehr zum alten Code. Zurück geht es nur über die Datensicherung (App-Dateien **und** Datenbank des alten Stands).
- Neue Versionen setzen weiterhin Nextcloud 35 und PHP 8.3–8.5 voraus.

## Weg 1: Über die Weboberfläche (App Store)
*Apps → Updates → Next Notes aktualisieren*. Nextcloud tauscht die Dateien und führt die Datenbank-Migrationen aus. Weitere Schritte sind nicht nötig.

## Weg 2: Paket von Hand
1. Ordner `nextnotes` im App-Verzeichnis (z. B. `custom_apps/nextnotes`) vollständig durch den Inhalt des neuen Pakets ersetzen (alten Ordner löschen, nicht darüber entpacken, damit keine alten Dateien liegen bleiben). Eigentümer = Benutzer des Webservers.
2. `occ upgrade`: schaltet selbst in den Wartungsmodus, führt die Migrationen aus und schaltet ihn wieder ab. War der Wartungsmodus vorher schon an, bleibt er an (`occ maintenance:mode --off`).
3. **PHP neu starten:** PHP-FPM bzw. Apache (mit `mod_php`) neu starten, z. B. `systemctl restart php8.3-fpm` oder `systemctl restart apache2`. Grund: `occ` läuft auf der Kommandozeile und leert den OPcache des Webservers nicht. Ohne Neustart nutzt der Webserver bis zu `opcache.revalidate_freq` (oft 60 s) oder bei `opcache.validate_timestamps=0` dauerhaft alte Klassen der App, das führt zu Fehlern (HTTP 500).
4. `occ status` prüfen: `needsDbUpgrade: false`, `maintenance: false`.

`occ app:update nextnotes` lädt die neue Version aus dem App Store und ist eine Alternative zu Weg 1; danach ebenfalls PHP neu starten.

## Von Testversionen 0.x
Vor der ersten veröffentlichten Version gab es Testversionen 0.1.0 bis 0.3.3. Getestet ist das Update von 0.1.0, 0.1.2 und 0.3.3 auf die aktuelle Version mit MariaDB, PostgreSQL und SQLite: Schema danach identisch zu einer frischen Installation, vorhandene Notizen, Anhänge, Ordner, Tags, Workspaces, Team-Workspaces, Material, Aufgaben und Abgaben bleiben lesbar, alle Hintergrundjobs sind registriert. Es gilt Weg 2.

## Nach dem Update
- Browser laden Skripte und Stile der neuen Version automatisch (Adresse enthält die App-Version). Bleibt eine Seite fehlerhaft, einmal neu laden.
- Bei Problemen: Nextcloud-Log (*Verwaltung → Protokollierung* oder `data/nextcloud.log`) prüfen, Fehler unter <https://github.com/valueerrorx/nextnotes/issues> melden.
