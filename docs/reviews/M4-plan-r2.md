VERDICT: FREIGABE

Review M4-plan r2 (speccritic, 2026-11-03) – Grundlage: docs/slices/M4-plan.md (r2), SPEC, ADR 0008/0012, STATUS; r1-Blocker gegengeprüft. Q1/Q2 nicht bewertet (Lead-Vorgabe).

## r1-Blocker
1. Export-Rechte: geschlossen (§1.3 „Referenzen“, B47; M4c.5, M4d.4, M4i Krit. 5 mit Byte-Suche und Abfragezähler).
2. ZIP-Speicher/Platte: geschlossen (Stream → Temp → `addFile`, `finally`, offene Datei nach Löschen, `ExportTempCleanupJob`, 1 GiB vorab aus DB; M4d.6–8). OCP geprüft: `StreamResponse` nimmt Ressourcen; Temp-Namen `oc_tmp_<rand>.nextnotes-export` passen zum Job-Muster; `IFunctionBuilder::octetLength`/`sum` vorhanden (SQLite `LENGTH(CAST(.. AS BLOB))`).
3. Parser-DoS: geschlossen (1 MiB je Umwandlung, Rückfall 64 KiB, Budget 1 MiB/Request, Korpus ≤ 1 s, M4b.2/3, M4f.5). Upstream-ZIP-Exporte rendern nicht (nur `api_export_note.php`), Budget dort nicht nötig.

## Hinweise (kein Blocker)
- §1.3 R3: Platte je Benutzer nur über Rate-Limit begrenzt (10 parallele Exporte ≈ 20 GiB). Empfehlung: höchstens ein laufender ZIP-Export je Benutzer (`IMemcache::add`-Sperre → 429), sonst README-Betriebshinweis wie geplant.
- Auslieferung per Ressource: `Content-Length` aus `fstat` setzen (sonst kein Fortschritt/kein Abbruch-Erkennen im Browser).
- M4b Z. 95 „Notizinhalt im Export > 1 MiB → 413“: auf Formate mit Umwandlung beschränken (html/html_embedded/Druck einer Markdown-Notiz, Markdown aus HTML-Notiz); Markdown-Export einer Markdown-Notiz und ZIPs ohne Render nicht blockieren. Ein Fall in M4c.1 ergänzen.
- Embed exakt ohne PSL: tragfähig, da kein Wildcard entsteht (CSP `https://co.uk` ist nur exakter Host). Folge: beim Speichern entfernt der Sanitizer iframes nicht gelisteter Hosts dauerhaft; Admin-Hinweistext sollte das nennen.
- Cleanup-Job 1 h: Schwelle ≥ `max_execution_time` halten (laufende Exporte nicht löschen); im Slice festhalten.
