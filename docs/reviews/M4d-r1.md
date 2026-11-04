# M4d Diff-Review r1 (speccritic)

VERDICT: BLOCKER

## Blocker
1. `nextnotes/lib/Service/ZipExportService.php:385` – Sperrschlüssel `'nextnotes/export/' . $uid` (17 + bis 64 Zeichen). Ohne Memcache-Locking nutzt NC `DBLockingProvider` (`.ref/.../lib/private/Server.php:880`), Schlüssel landet in `oc_file_locks.key` (string(64), Kernmigration). Bei UID > 47 Zeichen (NC erlaubt 64; LDAP/SAML/OIDC) schlägt `insertIgnoreConflict` auf MariaDB (strict) und PostgreSQL fehl → jeder ZIP-Export 500; SQLite nicht betroffen, daher in der Matrix unsichtbar. Fix: Schlüssel fester Länge, z. B. `'nextnotes/export/' . md5($uid)`; Test mit 64-Zeichen-UID auf mariadb/pgsql (oder Unit-Test der Schlüssellänge ≤ 64). Testskript `ExportZipTest.php:311` entsprechend anpassen.

## Hinweise (wichtig, kein Blocker)
1. `ExportTempCleanupJob.php:202` – Lead-Auflage r2 „max(1 h, max_execution_time + Reserve)“ ist im Cron-CLI wirkungslos (dort 0 → immer 1 h); Schutz laufender Exporte beruht allein auf der mtime-Heuristik (`lastChange`). Praktisch tragfähig (Staging und libzip-Temp schreiben fortlaufend; Löschung mitten im Bau → nur Fehler, kein Datenleck), aber Abweichung vom Wortlaut der Auflage – Lead bitte ausdrücklich annehmen. Alternative: Web-`max_execution_time` beim Export in `IAppConfig` merken.
2. `ExportArchive.php:121–136` / `ZipExportService.php:287` – `addStream` nutzt `unique()` nicht. Ordner „attachments“ auf oberster Ebene (strukturiert/Ordner-Export) mit Notiz „5“ (Markdown) und Anhang 5 mit `.md` → gleicher Pfad `attachments/5.md`, `ZipArchive` überschreibt still (B57 nicht ganz geschlossen). Abhilfe: Anhänge vor den Notizen reservieren oder Notizpfade gegen `attachments/` prüfen.
3. `ILockingProvider` mit `filelocking.enabled=false` → `NoopLockingProvider`: keine Ein-Export-Grenze (nur Rate-Limit 10/min). SPEC §4.5 nennt „Memcache oder DB“; Betriebshinweis ergänzen.
4. Widerspruch ADR 0008 Z. 15 („Umfang: eigene private Workspaces, eigene Abgaben (inkl. Feedback), Anhänge“) vs. Umsetzung/Auftrag (Abgaben über `role='note'` ausgeschlossen, `NoteMapper.php:76`, `AccessService::inWorkspaces`). Für M4d akzeptabel, wenn Abgaben-Export in M6/IMigrator kommt – im Plan/ADR vermerken. Ordner `kind=assignments` erscheinen im strukturierten Export als leere `.gitkeep`-Ordner (eigene Daten, kein Leck).
5. Plan M4d „Notizlisten-Menü ‚Alle exportieren‘ (`utils-note-create.js:570`) sichtbar“: `startDownload` hat im Upstream keinen UI-Aufrufer (nur `main.js:357`); Punkt ist gegenstandslos – im Plan streichen statt offen lassen.

## Geprüft ohne Befund
- Rechte: Ordner `requireFolder(read)` + Workspace-Abgleich (404); `?workspace=` über `resolveOptional(read)`; „alle“/Anhänge nur `findOwned` (owner_type=user); Papierkorb über `applyFilter` (trashed_at NULL) auch im Anhang-Export (B45); Vorlagen/Abgaben über `role='note'`; linked-Ziele über einen `filterReadable` (+ nicht Papierkorb, nicht linked), sonst leere Hülle; Anhänge nur `forNotes(source-IDs)`, fremde URLs bleiben Text (`zipPaths`).
- ZIP-Pfade: `zipName` ersetzt `/ \ :` usw., Steuerzeichen, trimmt `.`/Leerzeichen (`..` → `Untitled`), Anhänge `<id>.<ext>` mit `[a-z0-9]{1,10}`; kein Zip-Slip/absoluter Pfad. B57 `unique()` case-insensitiv mit ID-Suffix.
- Content-Disposition: ASCII-Ersatz `[^A-Za-z0-9 ._()-]`→`_`, `filename*` per `rawurlencode`; keine CR/LF/`"`-Injektion.
- Grenze 1 GiB vor `build()` (kein Temp-Ordner); Lock-Freigabe im `finally`, bei Fatal/Timeout `releaseAll` per Shutdown (`lib/OC.php:935`), TTL max(3600, max_execution_time); `TooManyRequestsException` 429; Rate-Limit-Attribute an allen vier Routen; Temp-Ordner im `finally` gelöscht, Auslieferung aus offener Datei, `Content-Length` aus `fstat`.
- Cleanup-Job: nur `oc_tmp_*.nextnotes-export` (Schema `TempManager::generateTemporaryPath` geprüft), Symlinks übersprungen, flaches Löschen.
- M4c-r2-Nacharbeit: kein `flush()` mehr außerhalb des äußersten Commits; neue Dateien nur per `Transaction::onRollback` (Ebene wandert bei innerem Commit nach außen), committete Dateien unberührt.
- `info.xml` 0.1.2, Job registriert; OCP-Angaben §14 (`ILockingProvider` 8.1, `octetLength`, `ITempManager`) stichprobenartig bestätigt.
