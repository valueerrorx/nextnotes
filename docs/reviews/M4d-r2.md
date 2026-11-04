# M4d Diff-Review r2 (speccritic)

VERDICT: FREIGABE

## Geprüft
1. r1-Blocker Lock-Key (`nextnotes/lib/Service/ZipExportService.php:386-388`): `'nextnotes/export/' . md5($uid)` = 49 Zeichen fest, passt in `oc_file_locks.key` (64). Alle vier Exporte (Z. 76/110/139/169) laufen über `locked()`; `acquireLock` in eigenem try (nur LockedException → 429), Freigabe im `finally` (Z. 403-406) nur nach erfolgreichem Erwerb. Test `ExportZipTest.php:372-403`: UID `nn-` + 52 + `-` + 8 = 64 Zeichen (alter Schlüssel wäre 81), Sperre im Nebenprozess, Prüfung der Zeile in `file_locks` (-1), 429 per HTTP beweist gleichen Schlüssel in Service und Test, danach erneuter Export 200. Belastbar, sofern die Matrix MariaDB/PostgreSQL ohne Locking-Memcache fährt (Test erzwingt das über die `file_locks`-Assertion).
2. B57 (`nextnotes/lib/Service/ExportArchive.php:93-166`, `ZipExportService.php:262-266`): Anhangpfade werden vor allen Notizen reserviert; `addString` claimt ohne Reservierungsrecht → weicht auf `_<id>` aus; `addStream` gibt Reservierung frei und claimt über `unique()` → auch Anhänge können nicht mehr überschreiben (doppelter Anhang ergäbe `_dup`, kein Überschreiben). Vergleich case-insensitiv. Suffix ist Notiz-ID (int) oder `dup`, Pfadbestandteile unverändert → kein neues Traversal.
3. Fehlerseite (`nextnotes/lib/Middleware/ApiExceptionMiddleware.php:32-101`, `nextnotes/templates/export_error.php`): nur `CompatController` + sechs Export/Download-Methoden (alle existieren, `CompatController.php:131-206`), nur bei `Accept: text/html` ohne XHR; nur Status 429/413/404 aus ApiException/DoesNotExist, sonst JSON-Pfad unverändert. Texte fest übersetzt (de/de_DE vorhanden), keine Exception-Texte/Pfade; alle Ausgaben über `p()`; `RENDER_AS_GUEST` (OCP-Signatur `TemplateResponse::__construct` geprüft) ohne Nutzerdaten, Zurück-Link aus `linkToRoute('nextnotes.page.index')`. Test `ExportZipTest.php:335-362` deckt 404 (fremder Ordner, kein Leak des Ordnernamens), XHR/fetch → JSON, 413 und andere Routen → JSON.

## Hinweise (kein Blocker)
- 400/403 bei Navigation bleiben JSON (bewusst, `default => null`); für Links praktisch nur bei Fehlbedienung relevant.
- r1-Hinweise 1, 3–5 (Cleanup-Heuristik, Noop-Locking, ADR 0008 Abgaben-Umfang, Plan-Punkt „Alle exportieren“) nicht erneut geprüft; Doku nur oberflächlich gesichtet.
