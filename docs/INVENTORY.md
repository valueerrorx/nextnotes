# Inventur Poznote → Next Notes (Übersicht)

Stand: M0, 2026-11-02, Upstream `6.136.2` / `7e2e0b6a`. Details:
- [Teil A – Backend](inventory/A-backend.md): REST-API v1, Einzelskripte, Tabellen, Jobs, Dateispeicher, Auth
- [Teil B – Frontend](inventory/B-frontend.md): Seiten/PHP-Einstiegspunkte, JS-Aufrufe, Inline-Code/CSP, Vendor, Tokens, i18n

Zielbild und Entscheidungen: [SPEC](SPEC.md), [ADRs](adr/).

## Zählung je Status

| Teil | übernehmen | ersetzen | streichen | offen (ADR) | Summe |
|---|---|---|---|---|---|
| A Backend | 132 | 48 | 128 | 30 | 338 |
| B Frontend | 63 | 51 | 64 | 13 | 191 |
| **Gesamt** | **195** | **99** | **192** | **43** | **529** |

Zählweise wie in den Teilen (A: alle nummerierten Zeilen #/T/J/F/A; B: Abschnitte 1–6 laut Tabelle „Zählung je Status“). Einträge überschneiden sich zwischen A und B (z. B. `api_*.php` erscheint als Endpunkt in A und als Einstiegspunkt in B); die Summe ist daher eine Zeilenzahl, keine Zahl verschiedener Funktionen.

## Offene Punkte „offen (ADR)“ und ihre ADR

| Inventurzeilen | Gegenstand | ADR | Vorschlag |
|---|---|---|---|
| A #65–68, #96–99 | Öffentliche Notiz-/Ordner-Links (API) | [0011](adr/0011-oeffentliche-links.md) | streichen (v1) |
| A #129, #130 | `/shared`, `/shared/with-me` | 0011 | Route bleibt, liefert `[]` |
| A #178–184 | `/public/*` (Bearbeitung über Link) | 0011 | streichen (v1) |
| A #238, #240, #241, #242 | `shared.php`, `public_note.php`, `public_folder.php`, `public_slug.php` | 0011 | streichen (v1) |
| A T5, T6, T12 | Tabellen `shared_notes`, `shared_folders`, `shared_links` | 0011 | streichen (v1) |
| A A18 | Token+Passwort in Session für Links | 0011 | streichen (v1) |
| B #24, #25, #26, #27 | Seiten öffentliche Links | 0011 | streichen (v1) |
| B 2.2 #42 | Navigation `shared.php` | 0011 | streichen (v1) |
| A #206 | `api_ai_chat.php` | [0010](adr/0010-ai-und-transkription.md) | streichen (v1) |
| A #207 | `api_transcribe.php` | 0010 | streichen (v1), später TaskProcessing |
| A #253, #254 | AI-/STT-Einstellungsseiten | 0010 | streichen (v1) |
| A T8 | Tabelle `ai_conversations` | 0010 | streichen (v1) |
| B #51, #52, #53, #54 | AI-/STT-Einstellungen (Admin/persönlich) | 0010 | streichen (v1) |
| B #76, #77 | `api_ai_chat.php`, `api_transcribe.php` | 0010 | streichen (v1) |
| B 2.1 #16, #17 | JS-Aufrufe AI/STT | 0010 | streichen (v1) |

Summe: A 30 Zeilen, B 13 Zeilen – alle einer ADR zugeordnet. Die Zeilen behalten bis zur Freigabe der ADRs den Status „offen (ADR)“.

## Weitere ADR-Bezüge in der Inventur

| Inventurzeilen | Gegenstand | ADR |
|---|---|---|
| A #50–54, #110–114, #191, #208; F1, F2, F4, F9 | Speicherort Inhalt, Anhänge, Snapshots, Hintergründe | [0002](adr/0002-speicherort-notizen.md) (Snapshots abweichend in DB-Tabelle) |
| A #215, T14; B 2.1 #12; B #16 | Workspace-Teilen, Erwähnungen | [0014](adr/0014-workspace-teilen.md), [0003](adr/0003-team-eigentum.md) |
| A #28–31, T15, J17 | Bearbeitungssperre | [0005](adr/0005-bearbeitungssperre.md) |
| B §3, §4 | Inline-Code, CSP, Vendor | [0006](adr/0006-editoren-unter-csp.md), [0012](adr/0012-csp-ausnahmen.md) |
| B §1, A #214–236 | Ablösung der Seiten | [0004](adr/0004-seiten-abloesung.md) |
| A #196–198, #199; B #79–81; A T20, B #41 | Export, Aktivitätslog | [0008](adr/0008-export-und-loeschung.md) |
| A #199, J9, J12 | Import | [0009](adr/0009-import-poznote.md) |
| A #25–27, #42, #92, #243, #244; B #61–64 | Offline/PWA (laut Auftrag gestrichen) | [0015](adr/0015-offline-pwa.md) |
| A #209–211; B #73–75, 2.1 #7–9, §5 Themes, Inter | Themes, Schriften, Logo | [0016](adr/0016-themes-und-schriften.md) (entschieden) |
| B §6, A #128 | i18n | SPEC §1.4 |

## Korrekturen in M0 (vermerkt in den Teilen)
- A T20 `activity_log` und B #41 `admin/activity-log.php`: ersetzen → streichen (ADR 0008). Legende A: Besitzer über `owner_type`/`owner_id` (SPEC §2).
- A #237 `storage-stats-user.php`: streichen → ersetzen (Widerspruch zu B #23; App-Speicher zählt nicht zur NC-Quota, ADR 0002).
- B #73–75, 2.1 #7–9, Inter: ersetzen → streichen (Entscheidung Thomas, ADR 0016).
- B #79–81: streichen → übernehmen, B 2.1 #21–23: streichen → ersetzen (Widerspruch zu A #196–198; Benutzer-Export, ADR 0008).
- B 4.4: Skript-Ladeart geklärt (`.js` klassisch mit `defer` + Nonce, `.mjs` Modul; `lib/private/Template/functions.php`).
- Namen nach ADR 0001: `oc_poznote_*` → `oc_nextnotes_*`, `/apps/poznote/` → `/apps/nextnotes/`.
- Auftrag nennt „74 PHP-Seiten“; gefunden wurden 89 direkt aufrufbare Dateien, davon 50 HTML-Seiten/Fragmente (B §1).
