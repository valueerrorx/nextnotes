# M9-Plan: Import aus Microsoft OneNote über Microsoft Graph

Stand: **r3**, 2026-11-08, Basis Commit `1cbb6623`. **Vom Lead freigegeben** (nach zwei Review-Runden). Offen bei Thomas: Q1–Q4 und die Auskunftsfrage (§7); M9b bleibt bis Q1 gesperrt. Grundlage: ADR 0020 (vorgeschlagen), ADR 0002/0004/0008/0009/0012. Entscheidungen Thomas (2026-11-08):
- Import über Graph.
- Notweg nur als Doku (OneNoteMdExporter).
- Kein `.one`-Parser.
- Schul- und Organisationskonten immer, private Konten optional über die Authority-Einstellung.
- Keine zentrale App.

**r3: Lead-Entscheidungen nach Review r2** (`docs/reviews/M9-plan-r2.md`):
- Blocker 1: Keine Graph- oder Token-Anfrage in einer offenen DB-Transaktion. Transaktion nur für die abschließenden Schreibvorgänge je Seite bzw. Struktur, mit bedingtem Lease-Check als erster Anweisung; Lease-Verlängerung als eigenes Autocommit-UPDATE (§2 Job, §4.4). Done M9b 4b (auch SQLite, `cancel` < 2 s).
- Blocker 2: Wechsel von Client-ID oder Authority → `failed`/`config_changed`, nicht `reauth`; Neustart durch den Benutzer, Items verhindern Duplikate (§4.7, §11). Done M9a 5a, M9b 5a.
- Blocker 3: Graph 403 im Job → überspringen und Bericht wie 404, zählt nicht zur 404-Serie (§4.4.4). Done M9b 12a.
- Hinweise: ein offener Import je uid atomar (Nachzählen nach INSERT, §3), „Abbrechen“ bei `reauth`; `not_found_streak` zurück bei Seiten-Erfolg, Ressourcen-404 zählen nicht; `backoff_level` erst nach angelegter Seite zurück; Token-Refresh auch in `tree`/`select`; Aufräumen läuft bei abgeschalteter Funktion weiter, Team-Schalter aus bricht Team-Auswahlen ab (§4.7); 12-h-Reservierung als R10 mit Neustart-Pfad und Done M9b 4; `purge` entfernt `onenote_*` (Done M9a 6a); Job legt alles mit expliziter uid an (§4.8).

**Änderungen r1 → r2** (Review `docs/reviews/M9-plan-r1.md`):
- B1: Seite wird immer vollständig verarbeitet, gedeckelt je Seite; Laufbudget ist weich, Stundenfenster hart (§4.5). Done M9d 8.
- B2: Lease mit `lease_token`, Verlängerung nach jeder Anfrage, alle Job-Schreibvorgänge bedingt, Item in der Notiz-Transaktion, Prüfung nach jeder Seite (§2 Job, §3, §4.4). Done M9b 4a–4c.
- B3: Konto gelöscht, deaktiviert oder ohne App-Zugriff → Abbruch bzw. `reauth`, Token gelöscht (§4.8). Done M9b 10.
- B4: Profil `msmock` mit `allow_local_remote_servers=true` und Test-CA per `occ security:certificates:import`, Unit-Nachweis für den Schutz (§9 M9a). Done M9a 8, 9.
- B5: M9a umfasst `check-purge.sh`, SPEC §8, `expected-m1.json`, `upgrade-path.sh`, `delete-user-data` („Daten vorhanden“), `export-user` (mit Bericht) und `NextNotesMigrator` (ohne). Done M9a 10–13.
- B6: Callback prüft zuerst `state`; nur mit gültigem `state` wirkt `error=` (§4.2). Done M9a 4, 4a.
- B7: Bei `common` nur Basis-Scopes, Team-Schalter wirkungslos (§2 Admin, §4.1). Done M9a 3a.
- B8: M9b gesperrt bis Q1; in Team-Notizbüchern ist nichts vorausgewählt (§4.3, §8 Q1).
- B9: Grenze je Seite (≤ 100 Ressourcen, ≤ 256 MiB Summe), Quote mit Ressourcensumme, Grenzen nach dem Dekodieren (§6). Done M9d 3, 3a, 5, 7.
- Hinweise: Budget je uid mit gleitendem 60-min-Fenster und 0,6 s Abstand; Erkennung eines anderen MS-Kontos (`tid`/`sub`, dafür `openid`); Workspace-Wiederverwendung nur bei eigenem `kind=normal`; `site:{id}` gestrichen; Token in `select` ≤ 24 h; Widerruf bei Microsoft in der Doku; Wechsel von Client-ID oder Authority → `reauth`.

Nicht in M9: Schreiben nach OneNote, Synchronisation, Aktualisieren bereits importierter Seiten, Ink-Umwandlung (optional M9f), Zertifikat-Anmeldung, nationale Clouds, SharePoint-Site-Notizbücher. M7h und M8 werden nicht berührt.

## 0. Befund im Bestand (gelesen, nichts geändert)
| Stelle | Befund | Folge |
|---|---|---|
| `Service/ImportService.php` | Nur Drop-Import einzelner `md/markdown/txt/html/htm/json`, ≤ 50 Dateien, ≤ 16 MiB je Datei; `.zip` → 400 (ADR 0009) | Notweg: Markdown kommt rein, **Bilder und Anhänge nicht** (relative Pfade) → §10 |
| `Service/AttachmentService::createFromString` | legt Anhang in `IAppData` an, Rollback-sicher; Quote über `QuotaService::quotaForNote`/`enforceQuota` | Ressourcen werden genauso gespeichert wie Base64-Bilder |
| `Service/EmbeddedImageService` | kanonische Bild-URL `/api/v1/notes/{n}/attachments/{a}`, `loading=lazy` | gleiche Form für importierte Bilder |
| `Service/HtmlSanitizer` | `loadHtmlQuiet` Pflicht für jedes `loadHTML` (`check-upstream` h); `style` global erlaubt, nur gefährliche Werte entfernt | Konverter filtert `style` selbst (Positionen, Schriften) vor dem Sanitizer |
| `src/public/js/checklist.js` | Markup `ul.checklist > li.checklist-item[.checklist-item-checked] > input.checklist-checkbox + span.checklist-text` | Ziel für `data-tag="to-do"` |
| `InputValidator::MAX_CONTENT_BYTES` | 16 MiB je Notiz | Grenze je Seite |
| `BackgroundJob/*` | `TimedJob`/`QueuedJob` vorhanden, Budget-Muster (`AssignmentDeadlineService`, 120 s) | gleiches Muster |
| `Service/UserDataService::UID_COLUMNS` | Grundlage der Prüfung „Daten vorhanden“ für `occ nextnotes:delete-user-data`/`export-user` | neue Tabellen eintragen (M9a) |
| `dev/nextcloud/check-purge.sh`, SPEC §8 | prüfen fest **13** `nextnotes_*`-Tabellen | auf 15 anheben (M9a) |
| `tests/nextnotes/schema/expected-m1.json`, `dev/nextcloud/upgrade-path.sh` | Soll-Schema für `check-schema.php`; Upgrade-Nachweis | neue Tabellen und Upgrade auf M9a (M9a) |
| `dev/nextcloud/compose.yaml` | Testumgebungen sqlite/mariadb/pgsql/nginx, Image `nextcloud:35.0.2` | Mock-Dienst als weiteres Profil |
| `docs/adr/0019-*` | Nummer bereits belegt (weiche Seitenwechsel) | neue ADR ist **0020** |

## 1. Belegte Fakten (Microsoft Learn, gelesen 2026-11-08)
| # | Fakt | Quelle |
|---|---|---|
| F1 | OneNote-API unterstützt **keine App-only-Anmeldung**: „will no longer support app-only authentication effective March 31, 2025“. Delegiert ist weiter der einzige Weg. | learn.microsoft.com/graph/integrate-with-onenote; …/graph/api/resources/onenote-api-overview (ms.date 2025-06-30) |
| F2 | Drosselung OneNote, delegiert, **je App je Benutzer**: 120 Anfragen/min **und 400/h**, 5 gleichzeitig. Die OneNote-Ressourcen liefern bei 429 **kein `Retry-After`**. | …/graph/throttling-limits § „OneNote service limits“ |
| F3 | Allgemein: Ohne `Retry-After` soll exponentiell gewartet werden. Wiederholte Anfragen zählen ins Limit. | …/graph/throttling § „Best practices“ |
| F4 | `Notes.Read` (delegiert): „Read user OneNote notebooks“, AdminConsentRequired **No**, auch für private Microsoft-Konten. `Notes.Read.All` (delegiert): Notizbücher, auf die der Benutzer in der Organisation Zugriff hat, AdminConsentRequired **No**. `Team.ReadBasic.All` delegiert **No**. `GroupMember.Read.All` delegiert **Yes**. | …/graph/permissions-reference |
| F5 | Seiten und Ressourcen: kleinste Berechtigung `Notes.Read`, sowohl bei Arbeits- und Schulkonten als auch bei privaten Konten. `GET /me/joinedTeams` geht mit `Team.ReadBasic.All`, für private Konten „Not supported“. | …/graph/api/page-get, …/resource-get, …/user-list-joinedteams |
| F6 | Wurzeln: `/me/onenote`, `/users/{id}/onenote`, `/groups/{id}/onenote`, `/sites/{id}/onenote`. M9 nutzt nur `me` und `groups`. | onenote-api-overview |
| F7 | Seitenlisten: Standard 20, `$top` höchstens 100, `@odata.nextLink`. `pagelevel=true` liefert `level` und `order`. `$expand=sections,sectionGroups(levels=max;…)` holt die Struktur in einem Aufruf. Seiten je Abschnitt abrufen, nicht über `/pages` (Fehler 20266). | …/graph/onenote-get-content, …/section-list-pages, …/onenote-best-practices |
| F8 | Fehler 10008/10013: Bibliothek > 5000 OneNote-Elemente bzw. > 20.000 Elemente → nicht abfragbar | …/graph/onenote-error-codes |
| F9 | Ausgabe-HTML: `body[data-absolute-enabled=true]`, direkte Kinder `div/img/object` mit `style="position:absolute;left;top;width"`. `img` mit `src`/`data-fullres-src` = `https://graph.microsoft.com/v1.0/…/onenote/resources/{id}/$value`; geteilte Bilder (`data-index`) werden nach y, dann x sortiert. `object[data, data-attachment, type]`; Video-`iframe[data-original-src]`. `data-tag="to-do"` / `"to-do:completed"`. Stile kommen inline auf `span`/`li`. | …/graph/onenote-input-output-html, …/onenote-abs-pos, …/onenote-note-tags |
| F10 | `includeInkML` kommt in keiner der gelesenen Learn-Seiten vor (v1.0 und beta `page-get`, `onenote-get-content`). Für v1 ist es nicht belegt. | Suche in den Seiten oben |
| F11 | Code-Flow: `tenant` = `common` / `organizations` / `consumers` / Tenant-ID. PKCE „recommended for all application types, both public and confidential“. `client_secret` ist „required for confidential web apps“; öffentliche Clients dürfen keine Secrets nutzen. `refresh_token` gibt es nur mit `offline_access`. `response_mode` `query` ist Standard. Der Redirect muss exakt übereinstimmen und HTTPS sein (außer localhost). | …/entra/identity-platform/v2-oauth2-auth-code-flow, …/reply-url |
| F12 | Zugriffstoken lebt 60–90 min. Refresh-Token: 90 Tage (Web), SPA 24 h. Er ersetzt sich bei jeder Nutzung, der alte soll gelöscht werden. Widerruf ist jederzeit möglich. | …/access-tokens, …/refresh-tokens |
| F13 | Kontotypen: `AzureADMyOrg`, `AzureADMultipleOrgs`, `AzureADandPersonalMicrosoftAccount`. Mit privaten Konten höchstens **2 Client-Secrets**, keine nationalen Clouds. `common` = Organisationen + private Konten, `organizations` = nur Organisationen. | …/supported-accounts-validation, …/howto-convert-app-to-be-multi-tenant, …/v2-protocols |
| F14 | Seit 11/2020 gilt bei aktivierter risikobasierter Zustimmung: Benutzer **anderer** Tenants können neu registrierten, **nicht verifizierten** Mehrmandanten-Apps mit Rechten über Anmeldung und Profil hinaus nicht zustimmen. Microsoft-Standardrichtlinie `microsoft-user-default-low`: Benutzerzustimmung nur für verifizierte Herausgeber **und Apps, die im eigenen Tenant registriert sind**. Ein Admin kann Benutzerzustimmung ganz abschalten, dann ist Admin-Zustimmung nötig. Publisher Verification braucht ein Microsoft-Partner-Konto (PGA). Für private Konten nennt Learn keine Sperre, die Zustimmungsseite zeigt dann „Unverified“. | …/publisher-verification-overview, …/configure-user-consent, …/howto-convert-app-to-be-multi-tenant, …/application-consent-experience |
| F15 | OneNoteMdExporter: GPL-3.0, Windows 10+, OneNote 2013+ (nicht die Store-Version), Word 2013+, Interop → DOCX → Pandoc. Ausgabe: Ordner je Abschnitt, `.md` je Seite, Ressourcenordner. Freihand geht verloren, Links zu anderen Notizbüchern werden entfernt. | github.com/alxnbl/onenote-md-exporter (README) |
| F16 | ID-Token (v2): `sub` ist unveränderlich und paarweise je App, ohne Zusatz-Scope. `oid` braucht den Scope `profile`. `tid` ist die Tenant-ID, für private Konten `9188040d-6c67-4c5b-b112-36a304b66dad`. `aud` ist die Client-ID und soll geprüft werden. Empfohlen zur Identifikation: `sub` bzw. `oid`, mit `tid`. | …/entra/identity-platform/id-token-claims-reference |
| F17 | Admins widerrufen Admin-Zustimmungen im Entra Admin Center (Unternehmensanwendungen → Berechtigungen). Benutzerzustimmungen lassen sich dort nicht per Portal widerrufen, nur per Graph/PowerShell. Widerruf verhindert keine erneute Zustimmung. | …/entra/identity/enterprise-apps/manage-application-permissions |

**Folgerungen**
- Aus F2 und F12: Eine Seite braucht 1 + Bilder + Dateien Anfragen. Bei 350 Anfragen/h (Sicherheitsabstand zu 400) dauern 300 Seiten mit 600 Bildern ≈ 2,6 h. Der Import läuft also im Hintergrund, setzt fort und braucht den **Refresh-Token**.
- Aus F11: Plattform „Web“ mit Secret + PKCE. „SPA“ hieße Token im Browser und 24 h.
- Aus F14: Single-Tenant ist der Normalfall. `organizations`/`common` verlangen in fremden Tenants meist die Zustimmung des dortigen Admins.
- Aus F16: Scope `openid` liefert ein ID-Token mit `tid` und `sub`. Damit erkennt die App eine Neuanmeldung mit einem anderen Microsoft-Konto, ohne `profile` oder `User.Read`.

## 2. Architektur
- **PHP** (`lib/OneNote/`, eigener Namensraum, keine Upstream-Datei):
  - `OneNoteConfig`: Admin-Werte, Scopes, Konfigurations-Fingerabdruck (Client-ID + Authority).
  - `OAuthService`: Autorisierungs-URL, Code einlösen, Refresh, ID-Token-Felder (`aud`, `exp`, `tid`, `sub`).
  - `GraphClient`: feste Hosts, Budget je uid, Fehlerabbildung, Lease-Verlängerung nach jeder Anfrage.
  - `ImportService` (Klassenname `OneNoteImportService`): Zustandsautomat und Schritte.
  - `PageConverter`: OneNote-HTML → Next-Notes-HTML, rein, ohne I/O.
  - `ResourceFetcher`: Ressourcen in Temp-Dateien holen, Grenzen je Ressource und je Seite.
  - `TokenVault`: `ICrypto` verschlüsselt und entschlüsselt.
  - Mapper `OneNoteImportMapper`, `OneNoteItemMapper` (`QBMapper`).
- **Controller** `OneNoteApiController`, alle `#[NoAdminRequired]`, CSRF aktiv außer beim Callback:
  - `POST /api/onenote/connect`: neue Import-Zeile oder Neuanmeldung für vorhandenen Import (`import_id`), liefert `authorize_url`.
  - `GET /onenote/callback`: `#[NoCSRFRequired]`, Seite. Ablauf §4.2, antwortet mit 303 auf `onenote.php?import={id}` bzw. `onenote.php?msg={code}`.
  - `GET /api/onenote/imports/{id}/tree`: Notizbücher und erste Ebene (Abschnitte und Gruppen), eigene plus Teams, wenn Teams erlaubt sind (§4.1).
  - `POST /api/onenote/imports/{id}/start`: Auswahl als Positivliste (§4.3).
  - `GET /api/onenote/imports/{id}`: Status, Zähler, Restzeit-Schätzung, Bericht.
  - `POST /api/onenote/imports/{id}/cancel`: Status `cancelled`, Token und Lease löschen. Bereits angelegte Notizen bleiben.
  - `GET /api/onenote/imports`: eigene Importe der letzten 30 Tage.
  - Ratenlimits: `#[UserRateLimit]` connect 10/min, start 5/min.
- **Ansicht** `onenote.php` (ADR 0004, eigenes Template + `js-src/nextnotes-onenote.js`, NC-Vue-freie Vanilla-Logik wie `nextnotes-assignments.js`, l10n über `t('nextnotes', …)`). Einstieg: Karte „Import aus OneNote“ in Einstellungen → Workspaces (App-Template), nur sichtbar, wenn der Admin konfiguriert hat. Kein Patch in `src/`.
- **Job** `OneNoteImportJob` (`TimedJob`, 300 s): Importe mit Status `queued`/`running` und `next_run_at <= now`, reihum nach `next_run_at`. Laufzeitziel 120 s je Lauf; eine begonnene Seite wird aber zu Ende geführt (§4.5).
  - **Claim**: `UPDATE … SET status='running', lease_token=:neu, lease_until=now+300 WHERE id=? AND status IN ('queued','running') AND (lease_until IS NULL OR lease_until < now)`; Zeilenzahl = 1, sonst überspringen (DB-neutral). `lease_token` = 16 Byte `ISecureRandom`.
  - **Keine Netz-I/O in Transaktionen**: Graph- und Token-Anfragen laufen nie bei offener DB-Transaktion. Eine Transaktion umfasst nur die abschließenden Schreibvorgänge eines Schritts (Seite: Notiz, Anhänge aus Temp-Dateien, Item, Cursor/Zähler; Struktur: Workspace/Ordner, Items, Cursor) und dauert damit Millisekunden bis Sekunden, auch unter SQLite.
  - **Verlängerung**: Nach jeder Graph-Anfrage als eigenes Autocommit-UPDATE außerhalb jeder Transaktion: `UPDATE … SET lease_until=now+300 WHERE id=? AND lease_token=? AND status='running'`. Zeilenzahl 0 → Lauf sofort beenden, Temp-Dateien löschen, Token im Speicher verwerfen.
  - **Bedingte Schreibvorgänge**: Jede Job-Transaktion beginnt mit dem bedingten Lease-Check `UPDATE … SET updated_at=now WHERE id=? AND uid=? AND lease_token=? AND status='running'`; Zeilenzahl 0 → Rollback, Lauf endet ohne Wirkung. Einzelne Schreibvorgänge außerhalb einer Seite (Token nach Refresh, Budget, Status) tragen dieselbe Bedingung als Autocommit-UPDATE. Zeilenzahl 0 heißt Abbruch, Löschung oder fremder Lauf.
  - **Ohne Sitzung**: Der Job legt Workspace, Ordner, Notizen und Anhänge mit expliziter uid an (Service-Methoden mit uid-Parameter), nie über `IUserSession`.
- **Benachrichtigungen** (`Notifier`, Objekttyp `onenote_import`): `onenote_done` (mit Zählern), `onenote_reauth` („Erneut bei Microsoft anmelden“, mit Grund), `onenote_failed`. Der Notifier verwirft sie, wenn der Import nicht mehr existiert oder der Benutzer keinen App-Zugriff hat.
- **Admin** (`AdminSettings`-Abschnitt Next Notes, Karte „OneNote-Import“):
  - Authority (Tenant-ID-GUID oder Domain | `organizations` | `common`), Client-ID (GUID).
  - Secret: nur schreibbar, Anzeige „gesetzt/nicht gesetzt“, `IAppConfig::setValueString(..., lazy: true, sensitive: true)`.
  - Schalter Team-Notizbücher. Bei `common` ist er deaktiviert mit Hinweis „Team- und Klassennotizbücher nur mit Authority für Organisationskonten“ und wirkt nicht, auch wenn er gespeichert ist (B7).
  - Redirect-URI zum Kopieren (`IURLGenerator::linkToRouteAbsolute`).
  - Ohne Client-ID und Secret ist die Funktion aus. Eingaben werden geprüft: GUID per Regex, Domain als Hostname. Andere Authority-Werte → 400.
  - Ändert der Admin Client-ID oder Authority, gilt §4.7.

## 3. Datenmodell (Migration `Version000700Date2026111x…`, Nummer bei Umsetzung gegen parallele Slices prüfen)
**`nextnotes_onenote_imports`**
| Spalte | Typ | Null | Zweck |
|---|---|---|---|
| id | bigint AI | nein | |
| uid | string(64) | nein | Besitzer; jeder Zugriff `WHERE uid = ?` |
| status | string(16) | nein | `auth` \| `select` \| `queued` \| `running` \| `reauth` \| `done` \| `failed` \| `cancelled` \| `expired` |
| authority, client_id | string(64) | nein | Konfiguration zum Zeitpunkt der Anmeldung (§4.7) |
| team_scopes | smallint | nein 0 | 1, wenn mit Team-Scopes angemeldet (§4.7) |
| ms_account | string(64) | ja | sha256(`tid` + ':' + `sub`) aus dem ID-Token der ersten Anmeldung (§4.2) |
| state_hash | string(64) | ja | sha256(state), nur bei `auth`/Neuanmeldung |
| pkce_enc | text | ja | Verifier (ICrypto), nur bei `auth`/Neuanmeldung |
| redirect_uri | string(1000) | ja | beim Einlösen identisch verwenden |
| auth_expires_at | bigint | ja | state + Verifier gelten 10 min |
| access_enc, refresh_enc | text | ja | Token (ICrypto); NULL außerhalb `select/queued/running` |
| access_expires_at | bigint | ja | |
| selection | text | ja | JSON: Positivliste je Notizbuch mit Wurzel (`me` \| `group:{id}`): ganzes Notizbuch oder einzelne Elemente erster Ebene |
| cursor | text | ja | JSON: Warteschlange offener Abschnitte, aktueller Abschnitt, nächster `nextLink` |
| pages_total, pages_done, pages_skipped, resources_done, resources_failed | int | nein 0 | Zähler |
| report | text | ja | JSON, ≤ 200 Einträge: Seitentitel gekürzt + Grund (zu groß, Quote, Ressource fehlt) |
| rate_buckets | text | ja | JSON, Anfragen je Minute der letzten 60 min (gleitendes Fenster, §4.5) |
| rate_version | int | nein 0 | optimistische Sperre für `rate_buckets` |
| backoff_level, not_found_streak | smallint | nein 0 | |
| next_run_at, lease_until | bigint | ja | |
| lease_token | string(32) | ja | Besitz des laufenden Jobs (§2) |
| error_code | string(64) | ja | z. B. `quota`, `invalid_client`, `graph_10008`, `config_changed`, `user_disabled`, `app_disabled`, `account_mismatch` |
| created_at, updated_at, finished_at | bigint | nein/nein/ja | |

Indizes: `nn_oni_uid`(uid, status), `nn_oni_run`(status, next_run_at), `nn_oni_state`(state_hash).
- **Ein offener Import je uid** (`auth/select/queued/running/reauth`), atomar durch Nachzählen (DB-neutral, ohne Sperrdienst):
  - `connect` ohne `import_id`, wenn ein offener Import in `auth`/`select` besteht: Dieser wird gelöscht (samt Token) und ersetzt.
  - Steht er in `queued/running/reauth`: 409.
  - Nach dem INSERT (eigene Transaktion, Commit) liest der Service alle offenen Zeilen der uid. Ist die eigene nicht die mit der kleinsten `id`, löscht er sie und antwortet 409. Zwei parallele `connect` hinterlassen so genau eine offene Zeile.
  - Die Ansicht bietet in `select` und `reauth` „Abbrechen“ an, damit ein hängender Import neue nicht blockiert.
- **Budget je uid**: Weil es nur einen offenen Import gibt, liegt das Budget auf dessen Zeile. Eine neue Zeile übernimmt `rate_buckets` der jüngsten Zeile derselben uid. So zählen `tree`-Aufrufe und Job zusammen.

**`nextnotes_onenote_items`** (Idempotenz)
| Spalte | Typ | Null | Zweck |
|---|---|---|---|
| id | bigint AI | nein | |
| uid | string(64) | nein | Besitzer |
| source_hash | string(64) | nein | sha256(Typ + ':' + Graph-ID) |
| kind | string(16) | nein | `notebook` \| `section_group` \| `section` \| `page` |
| target_id | bigint | nein | Workspace-, Ordner- oder Notiz-ID |
| source_modified_at | bigint | ja | `lastModifiedDateTime` |
| import_id | bigint | nein | |
| created_at | bigint | nein | |

UNIQUE `nn_oni_src`(uid, source_hash); `nn_oni_tgt`(kind, target_id).
- **Schreiben**: Das Item entsteht in **derselben Transaktion** wie Notiz bzw. Workspace oder Ordner. Eine UNIQUE-Verletzung führt zum Rollback der ganzen Seite und zählt als übersprungen.
- **Seite**: Existiert die Zielnotiz eines Items (auch im Papierkorb), wird nicht neu angelegt (`pages_skipped`). Ist sie gelöscht oder purgiert, wird neu angelegt und das Item bedingt ersetzt: `UPDATE … SET target_id=:neu WHERE uid=? AND source_hash=? AND target_id=:alt`. Zeilenzahl 0 → Rollback.
- **Notizbuch und Ordner**: Wiederverwendet wird ein Ziel nur, wenn der Workspace existiert, `owner_type=user`, `owner_id=uid` und `kind=normal` hat. Bei Ordnern muss er zusätzlich in einem solchen Workspace liegen. Sonst entsteht ein neuer Workspace bzw. Ordner, und das Item wird wie oben bedingt ersetzt. Team-Workspaces sind nie Ziel, auch nicht nach Umwandlung.
- **Lebenszyklus**:
  - Kontolöschung (`UserDataService`, sofortige Phase **vor** dem Löschen der Inhalte) und `occ nextnotes:delete-user-data`: Zeilen beider Tabellen per `uid` löschen. `UID_COLUMNS` erhält `nextnotes_onenote_imports.uid` und `nextnotes_onenote_items.uid` („Daten vorhanden“ auch bei unbekannter UID).
  - `occ nextnotes:purge`: beide Tabellen und die App-Config-Schlüssel `onenote_*` löschen.
  - Auskunft und Umzug: §7.
  - `OneNoteImportJob` räumt auf, auch wenn die Funktion abgeschaltet ist:
    - `auth` älter als 10 min → löschen.
    - `select` 24 h ohne Änderung → Token NULL, `expired`.
    - `reauth` 7 Tage ohne Neuanmeldung → `expired` (Token sind dort schon NULL).
    - `queued`/`running` 7 Tage ohne Fortschritt → `failed`, Token NULL.
    - Abgeschlossene Importe älter als 30 Tage → löschen. Items bleiben.

## 4. Ablauf
1. **Verbinden**: `connect` erzeugt `state` (32 Byte `ISecureRandom`) und Verifier (64 Zeichen). Gespeichert werden sha256(state), der verschlüsselte Verifier sowie `authority` und `client_id` der aktuellen Konfiguration. Antwort ist `https://login.microsoftonline.com/{authority}/oauth2/v2.0/authorize?client_id&response_type=code&redirect_uri&response_mode=query&scope&state&code_challenge&code_challenge_method=S256&prompt=select_account`. Das Frontend navigiert top-level dorthin.
   - **Scopes**: Basis `openid offline_access Notes.Read`. Zusätzlich `Notes.Read.All Team.ReadBasic.All` nur, wenn der Schalter an ist **und** die Authority nicht `common` ist. Team- und Klassennotizbücher gibt es nur für Organisationskonten. Mit Team-Scopes gilt `team_scopes=1`.
2. **Callback** (Reihenfolge fest):
   1. `state` prüfen: Zeile über `state_hash`, `uid` = angemeldeter Benutzer, `auth_expires_at > now`, `hash_equals`. Fehlt `state` oder passt er nicht → 404-Seite ohne Details, **keine Zustandsänderung**, auch wenn `error=` gesetzt ist.
   2. Mit gültigem `state` und `error=`:
      - Neue Anmeldung: Zeile löschen. Neuanmeldung: state und Verifier löschen, Zeile bleibt `reauth`.
      - 303 auf `onenote.php?msg=…` mit fester Liste: `access_denied` → „Anmeldung abgebrochen“; `consent_required`, `interaction_required` → „Zustimmung fehlt, bitte Admin fragen“; `invalid_scope`, `unauthorized_client` → „Berechtigungen in der App-Registrierung prüfen (Admin)“; sonst „Anmeldung fehlgeschlagen“.
      - `error_description` wird weder angezeigt noch geloggt, nur der Fehlercode.
   3. Token holen: `POST …/{authority}/oauth2/v2.0/token` mit `grant_type=authorization_code`, `code`, `redirect_uri`, `code_verifier`, `client_id`, `client_secret`, `scope`.
   4. ID-Token aus der direkten TLS-Antwort lesen (keine Signaturprüfung nötig, OIDC Core 3.1.3.7): `aud` = Client-ID, `exp` > now, `tid` und `sub` vorhanden. Sonst Fehlerseite, Token verwerfen. Daraus `ms_account`.
   5. Neuanmeldung mit anderem `ms_account` als gespeichert → Token verwerfen, Zeile bleibt `reauth` mit `error_code=account_mismatch`, Meldung „Bitte mit demselben Microsoft-Konto anmelden wie beim Start“.
   6. Token verschlüsselt speichern, state und Verifier löschen. Status wird `select`, bei Neuanmeldung `queued`.
   7. 303 auf die Ansicht, damit der Code nicht in der History bleibt.
3. **Auswahl** (`tree`, nur im Status `select`):
   - `GET /me/onenote/notebooks?$select=id,displayName,createdDateTime,lastModifiedDateTime&$expand=sections($select=id,displayName),sectionGroups($select=id,displayName)`
   - Nur wenn Teams erlaubt sind (Schalter an, Authority ≠ `common`): zusätzlich `GET /me/joinedTeams?$select=id,displayName` und je Team `GET /groups/{id}/onenote/notebooks?$select=…`, höchstens 20 Teams je Aufruf, Rest „weitere laden“. 403 oder 404 je Team wird still übersprungen.
   - **Vorauswahl**: Eigene Notizbücher (`me`) sind ausgewählt. In Team-Notizbüchern (`group:`) ist **nichts** vorausgewählt, weil Graph Schülergruppen nicht kennzeichnet. Wer importieren will, wählt Notizbuch oder einzelne Elemente erster Ebene ausdrücklich an.
   - `start` speichert die Auswahl als Positivliste (IDs nach §6 geprüft, sonst 400; höchstens 200 Einträge). Graph liefert mit dem Token des Benutzers ohnehin nur, was er sehen darf. Status `queued`, `next_run_at = now`.
   - Alle Aufrufe zählen ins Budget der uid. Vor jeder Graph-Anfrage gilt die Refresh-Regel aus §4.6, auch in `select`.
4. **Job-Schritte** (je Import, Cursor-basiert). Je Schritt erst alle Graph-Anfragen ohne Transaktion, dann eine kurze Transaktion für die Schreibvorgänge, die mit dem bedingten Lease-Check beginnt (§2 Job):
   1. Vor jedem Lauf und **nach jeder Seite**: Status neu lesen (`cancelled` oder Zeile weg → Ende), Kontoprüfung (§4.8), Konfigurationsprüfung (§4.7).
   2. Struktur je Notizbuch: `GET {root}/onenote/notebooks/{id}?$expand=sections,sectionGroups(levels=max;$expand=sections)`. Nach dem Abruf in einer Transaktion: Workspace und Ordner anlegen bzw. per Items wiederfinden (Regel §3), ausgewählte Abschnitte in die Warteschlange.
   3. Seitenliste je Abschnitt: `GET {root}/onenote/sections/{id}/pages?pagelevel=true&$select=id,title,createdDateTime,lastModifiedDateTime,level,order&$top=100`, `nextLink` folgen, nach `order` sortieren. `pages_total` hochzählen.
   4. Je Seite:
      - Steht sie schon in Items und das Ziel existiert → überspringen.
      - Sonst ohne Transaktion: Inhalt holen, Ressourcen zählen, Budget prüfen (§4.5), Ressourcen in Temp-Dateien holen (§6), Quote mit der Summe prüfen. Dann in **einer** Transaktion: Lease-Check, Notiz, Anhänge, Item, Cursor und Zähler. Temp-Dateien werden im `finally` gelöscht.
      - Graph 404 für Notizbuch, Abschnitt oder Seite → überspringen, Bericht, `not_found_streak++`. Ab 20 in Folge: `failed`, `error_code=graph_not_found`, Token löschen. Jede angelegte oder übersprungene vorhandene Seite setzt `not_found_streak` auf 0. Ressourcen-404 zählen nicht, sie werden Platzhalter.
      - Graph 403 für Notizbuch, Abschnitt oder Seite (z. B. Team verlassen, Zustimmung entzogen) → überspringen, Bericht mit Grund „kein Zugriff“, eigener Zähler im Bericht. 403 zählt nicht zu `not_found_streak` und nicht zu den Fehlversuchen. Ressourcen-403 → Platzhalter.
   5. Ende: `done`, Token löschen, Benachrichtigung.
5. **Budget und Drosselung** (je uid):
   - **Stundenfenster hart**: Gleitendes Fenster über `rate_buckets` (Minuten der letzten 60 min), Summe ≤ 350. Ist das Fenster voll, gilt `next_run_at` = Zeitpunkt, an dem genug Minuten herausfallen.
   - **Abstand** ≥ 0,6 s zwischen zwei Anfragen. Das ergibt ≤ 100/min (Limit 120) und keine Parallelität.
   - **Laufbudget weich**: Eine neue Seite beginnt nur, wenn im Lauf weniger als 50 Anfragen verbraucht sind und die Laufzeit unter 120 s liegt. Eine **begonnene Seite wird vollständig verarbeitet**, auch über 50 Anfragen oder 120 s hinaus.
   - **Seitenstart**: Nach dem Inhaltsabruf ist die Ressourcenzahl `n` bekannt (gedeckelt auf 100, §6). Ist der Rest im Stundenfenster kleiner als `n`, endet der Lauf vor den Ressourcen. Der nächste Lauf holt den Inhalt erneut (1 Anfrage mehr). Weil `1 + n ≤ 101 < 350`, passt jede Seite in ein leeres Fenster; es gibt keinen Livelock.
   - **Entscheidung B1** (Seite atomar statt Ressourcen-Fortschritt über Läufe): Fortschritt innerhalb einer Seite bräuchte Zwischenspeicher für bereits geholte Ressourcen über Läufe hinweg (Staging in `IAppData`, eigene Quote, eigene Lösch- und Purge-Pfade, Aufräumen verwaister Teile). Die atomare Seite braucht nichts davon und lässt bei Abbruch keine Reste. Die Kosten sind ein längerer Einzellauf; die Grenze je Seite (§6) begrenzt ihn auf ≤ 101 Anfragen und ≤ 15 min Wanduhrzeit.
   - **Fehler**: Bei 429 gilt `next_run_at = now + Retry-After` bzw. `5 min × 2^backoff_level`, höchstens 60 min, und `backoff_level++`. Erst eine angelegte Seite (bzw. ein abgeschlossener Strukturschritt) setzt ihn auf 0 zurück, einzelne erfolgreiche Anfragen nicht. 429 mitten in einer Seite verwirft die Seite (Temp-Dateien weg), der nächste Lauf beginnt sie neu.
   - 5xx und Netzfehler zählen wie 429. Bei `backoff_level` 10 (10 Fehlversuche ohne angelegte Seite): `failed`, Token löschen. Damit endet auch eine Seite, die immer an derselben Stelle abbricht.
6. **Token**:
   - Vor jedem Lauf, vor jeder Graph-Anfrage im Job und in `tree` (Status `select`) wird erneuert, wenn `access_expires_at < now + 300`. Der Refresh läuft nie in einer offenen Transaktion: `grant_type=refresh_token` mit `client_secret` und gleichem Scope. Der neue Refresh-Token ersetzt den alten (bedingter Schreibvorgang; Zeilenzahl 0 → neue Token verwerfen).
   - `invalid_grant`: Status `reauth`, Token NULL, Benachrichtigung. Der Benutzer meldet sich über `connect` mit `import_id` neu an und der Lauf setzt fort. In `select` (noch keine Auswahl) wird die Zeile stattdessen gelöscht; die Ansicht bietet „Neu verbinden“.
   - `invalid_client`: `failed`, `error_code=invalid_client`, Warnung ins Log für den Admin.
   - Eine Graph-Antwort 401 führt einmal zu Refresh und Wiederholung, dann `reauth`.
   - `ICrypto::decrypt`-Fehler (z. B. nach geändertem `secret` in `config.php`) → `reauth`.
7. **Konfigurationswechsel** (geprüft vor jedem Lauf, nach jeder Seite und in jeder Route):
   - Weichen `client_id` oder `authority` der Zeile von der aktuellen Admin-Konfiguration ab: `select/queued/running/reauth` → `failed`, `error_code=config_changed`, Token NULL, Benachrichtigung; `auth` → löschen. Grund: `sub` ist paarweise je App (F16) und kann sich auch beim Authority-Wechsel ändern, eine Neuanmeldung würde an `account_mismatch` scheitern. Der Benutzer startet einen neuen Import; die Items (Schlüssel: uid + Graph-ID, unabhängig vom MS-Konto-Hash) verhindern Duplikate bereits importierter Seiten.
   - Team-Schalter aus (oder Authority wird `common`, s. o.): Importe mit `team_scopes=1` in `select`, sowie offene Importe, deren Auswahl eine `group:`-Wurzel enthält → `failed`/`config_changed`, Token NULL, Benachrichtigung. Importe ohne Team-Bezug laufen weiter.
   - Funktion abgeschaltet (Client-ID oder Secret leer): Importe pausieren ohne Statuswechsel, keine Graph-Anfrage. Das Aufräumen (§3, 24 h/7 Tage, Token NULL) läuft trotzdem weiter.
   - Ein neues Secret bei gleicher Client-ID ändert nichts.
8. **Konto-Zustände** (vor jedem Lauf und nach jeder Seite, B3):
   - Konto gelöscht (`IUserManager::get(uid)` null, oder die Zeile fehlt schon): Lauf endet ohne Schreibvorgang, verbliebene Zeilen der uid werden gelöscht.
   - Konto deaktiviert (`IUser::isEnabled()` false): `reauth`, `error_code=user_disabled`, Token NULL. Keine Graph-Anfrage mehr.
   - Kein App-Zugriff (`IAppManager::isEnabledForUser('nextnotes', $user)` false, Gruppenbeschränkung): `reauth`, `error_code=app_disabled`, Token NULL.
   - Nach Reaktivierung bzw. Freischaltung meldet sich der Benutzer neu an und der Import setzt fort. Sonst greift die 7-Tage-Regel (§3).
   - Alle Anlagen des Jobs laufen mit der uid der Import-Zeile als Besitzer, nie über `IUserSession` (§2 Job).
9. **Restzeit-Schätzung**: (offene Seiten × mittlere Anfragen je bisheriger Seite) / 350 h. Die Anzeige rundet auf 10 min und hängt von Cron ab (Hinweis in der Ansicht).

## 5. Abbildung (`PageConverter`, rein)
| OneNote | Next Notes |
|---|---|
| Notizbuch | eigener neuer Workspace `kind=normal`, `owner_type=user`. Name = `displayName`, bei Kollision „Name (OneNote)“, dann „(2)“ … (Regeln `WorkspaceService`). Kein Import in Team-Workspaces (Datenschutz, §8). |
| Abschnittsgruppe, Abschnitt | Ordner, verschachtelt wie im Notizbuch; Name gekürzt auf 255 |
| Seite | Notiz `type=note`, `heading` = Titel (leer → l10n „Ohne Titel“), `created_at`/`updated_at` = `createdDateTime`/`lastModifiedDateTime` (UTC → Unix), `created_by`/`updated_by` = uid, `display_order` = `order` |
| Unterseite (`level` 1, 2) | bleibt flach direkt hinter der Elternseite, Titelpräfix „› “ je Ebene |
| `body`-Kinder absolut positioniert | stabil sortiert nach (top, left) in px (fehlend = 120/48 wie OneNote-Standardlage, Learn abs-pos; M9c), dann nacheinander ausgegeben. `position/left/top/width` entfallen. |
| `data-tag="to-do"`, `"to-do:completed"`, `to-do-priority-1/2` und alle weiteren Kontrollkästchen-Kennzeichen (`discuss-with-*`, `schedule-meeting`, `call-back`, `client-request`; M9c) | aufeinanderfolgende Elemente → eine Upstream-Checkliste (`ul.checklist`…, `checked` und `checklist-item-checked`) |
| andere Tags `important`, `question`, `critical`, `idea` | Präfix ⭐ ❓ ❗ 💡. Übrige Tags entfallen, Text bleibt. |
| Stile | erlaubt: `color`, `background-color`, `font-weight`, `font-style`, `text-decoration`, `text-align`, `list-style-type`. Alles andere entfällt (Calibri 11pt, Ränder, Positionen). |
| Tabellen | `table/tr/td/th` mit `colspan`/`rowspan`. Rahmen und Breiten entfallen, Upstream-CSS übernimmt. |
| `img` | Ressourcen-ID aus `data-fullres-src`, sonst aus `src` → Anhang (§6), `src="/api/v1/notes/{n}/attachments/{a}"`, `width`/`height`/`alt` bleiben. Geteilte Bilder in Lage-/Dokumentreihenfolge (`data-index` laut Learn unzuverlässig; M9c). Fehler → Platzhalter „[Bild nicht übernommen]“. |
| `object` | Anhang + Link `<a href="/api/v1/notes/{n}/attachments/{a}">{data-attachment}</a>`. Fehler → Platzhalter mit Dateinamen. |
| `iframe` (Video) | Link auf `data-original-src` (nur `https:`), Text „Video: …“ |
| Links | `http/https/mailto` bleiben. `onenote:` und andere Schemata → nur Text. |
| Freihand | nicht im HTML (F10). Bericht und Doku nennen es, Platzhalter nur mit M9f. |
| Ergebnis | `HtmlSanitizer::sanitizeHtml`, `search_text` wie `NoteService`. Über 16 MiB: Notiz mit Hinweis statt Inhalt, Bericht. |

Der Konverter erzeugt das HTML ausschließlich per DOM (`HtmlSanitizer::loadHtmlComplete`, nie still gekürzt; Umsetzung SPEC §4.8), kein `preg_*` direkt (`Pcre`, `check-upstream` g/h). Er liefert zusätzlich die Liste der Ressourcen-IDs in Dokumentreihenfolge (für §4.5).

## 6. Sicherheit
- **Hosts fest** (Konstanten): `https://login.microsoftonline.com/{authority}/oauth2/v2.0/{authorize|token}` und `https://graph.microsoft.com/v1.0/`. Die Authority ist geprüft (GUID, Hostname, `organizations`, `common`).
- **IDs**: Graph-IDs (Notizbuch, Abschnitt, Seite, Ressource, Gruppe) müssen auf `^[A-Za-z0-9!._,-]{1,512}$` passen und werden `rawurlencode`t. Sonst wird übersprungen und der Bericht vermerkt es.
- **`@odata.nextLink`**: nur, wenn er mit `https://graph.microsoft.com/v1.0/` beginnt, keine `@`/Backslash/`..`.
- **Ressourcen-URLs aus dem HTML**: Nur die ID wird entnommen (`/onenote/resources/{id}/$value`), die URL wird aus der Notizbuch-Wurzel neu gebaut. Fremde Hosts (auch `www.onenote.com`) → Platzhalter.
- **`IClientService`**: `allow_redirects => false`, Timeout 30 s, `stream => true`. Die App setzt **keine** Option `nextcloud.allow_local_address`; der Local-Address-Schutz bleibt an (Unit-Nachweis M9a). Der `Authorization`-Header geht nur an `graph.microsoft.com`.
- **Größengrenzen** (alle auf **dekodierte** Bytes, gezählt beim Lesen des Body-Streams; `IClient` sendet `Accept-Encoding: gzip`):
  - je Ressource ≤ `min(attachment_max_file_mb, 100 MiB)`, Abbruch beim Lesen;
  - **je Seite** ≤ 100 Ressourcen und ≤ 256 MiB Summe; was darüber liegt, wird Platzhalter (Bericht). Wanduhrzeit je Seite ≤ 15 min, danach werden offene Ressourcen Platzhalter;
  - Seiten-HTML ≤ 16 MiB, JSON-Antworten ≤ 4 MiB.
- **Speicher**: Ressourcen landen gestreamt in Temp-Dateien (`ITempManager::getTemporaryFile`), nicht im Speicher. In der Transaktion wird je Anhang nur eine Datei gelesen (≤ 100 MiB). Temp-Dateien werden im `finally` gelöscht.
- **Typen**: Bilder nur `image/png|jpeg|gif|webp|bmp` laut Inhalt (`finfo`), SVG nie als Bild. Dateien mit erkanntem MIME, Auslieferung wie bisher (`sandbox`, SPEC B22).
- **Quote**: Vor dem Inhaltsabruf: freier Platz ≤ 0 → `failed`/`quota`. Nach dem Holen der Ressourcen: `QuotaService::quotaForNote` mit **Summe der Ressourcen + HTML-Größe**, bevor die Transaktion beginnt. Bei Überschreitung: `failed`, `error_code=quota`, Temp-Dateien weg. Bereits importierte Seiten bleiben.
- **Rechte**: Jede Route lädt den Import über `uid`, fremde ID → 404. Callback nur für den Besitzer. Ziel immer ein eigener Workspace (`owner_type=user`, `owner_id=uid`, `kind=normal`), neu angelegt oder aus den Items (§3).
- **CSRF**: POST-Routen mit NC-CSRF. Den Callback schützt `state` mit `uid`-Bindung (auch gegen Login-CSRF mit fremdem MS-Konto); bei Neuanmeldung zusätzlich `ms_account`.
- **CSP**: keine Ausnahme (ADR 0012). Die Ansicht lädt nur App-Assets.
- **Logs** (`LoggerInterface`): Import-ID, HTTP-Status, Graph-Fehlercode, `request-id`. Nie Token, ID-Token, Code, Secret, Titel, Inhalt, `error_description` oder Query-Strings.

## 7. Datenschutz (für admin.md §10 und den ADR-0008-Nachtrag)
| Was | Wo | Dauer |
|---|---|---|
| Zugriffs- und Refresh-Token | `nextnotes_onenote_imports`, `ICrypto` (Schlüssel aus `config.php` `secret`) | nur während `select` (≤ 24 h) und `queued/running` (≤ 7 Tage ohne Fortschritt) |
| state, PKCE-Verifier | dto. | ≤ 10 min, Einmalgebrauch |
| `ms_account` (Hash aus `tid` und `sub`) | dto. | wie die Zeile |
| Auswahl, Cursor, Zähler, Bericht (gekürzte Seitentitel) | dto. | 30 Tage nach Abschluss |
| Graph-IDs (gehasht) → Ziel | `nextnotes_onenote_items` | bis Kontolöschung, `delete-user-data` oder `purge` |
| importierte Inhalte | normale Notizen und Anhänge | wie alle Notizen (Export, Löschung, ADR 0008) |
| Client-Secret | `IAppConfig` sensitiv | bis der Admin es ändert, `purge` |

- **Auskunft** (`occ nextnotes:export-user`, Art. 15): Enthält `onenote-imports.json` mit je Import Status, Zeitpunkte, Zähler, `error_code` und Bericht. Der Bericht enthält Seitentitel und gehört damit zur Auskunft. Nicht enthalten: Token, state, Verifier, `ms_account`, Cursor, Auswahl-IDs.
- **Items** sind weder in der Auskunft noch im Umzug: Sie sind technische Hilfsdaten ohne Inhalt (Hash einer Microsoft-ID → interne Ziel-ID). Die Zielnotizen selbst sind in Auskunft und Umzug enthalten.
- **Umzug** (`NextNotesMigrator`): ohne beide Tabellen. Ein Import ist an Microsoft-Konto und Instanz gebunden und läuft auf dem Ziel nicht weiter.
- Der Auth-Code steht einmal in der Webserver-Logzeile des Callbacks. Er ist nutzlos ohne Verifier und Secret und gilt nur Minuten (Doku-Hinweis).
- Microsoft protokolliert die Anmeldung in den Entra-Sign-in-Logs des Tenants.
- Es gibt keine weiteren Empfänger.
- **Widerruf** (Doku, F17): Abbrechen in Next Notes löscht die Token sofort. Die Zustimmung bei Microsoft bleibt bestehen; Benutzer widerrufen sie über ihr Microsoft-Konto (Organisationskonto: Portal „Meine Apps“; privates Konto: Seite „Apps und Dienste“ des Microsoft-Kontos), Admins im Entra Admin Center (Unternehmensanwendungen → App → Berechtigungen) bzw. per Graph/PowerShell für Benutzerzustimmungen. Die genauen Benutzer-URLs prüft M9e gegen die Microsoft-Hilfe.

## 8. Fragen an Thomas
- **Q1 [THOMAS] Klassen- und Team-Notizbücher** (sperrt M9b):
  - Lehrkraft: Sie sieht im Klassennotizbuch die Abschnitte aller Schüler. Darf sie diese in ihren privaten Workspace importieren?
  - Schüler: Sie sehen im Klassennotizbuch neben dem eigenen Abschnitt den **Collaboration Space** (Inhalte anderer Schüler) und die Inhaltsbibliothek. Dürfen sie diese importieren?
  - Vorschlag: Ja für beide, Import nur in einen eigenen Workspace. In Team-Notizbüchern ist nichts vorausgewählt, die Person wählt ausdrücklich aus. Die Ansicht weist darauf hin, dass Inhalte anderer Personen übernommen werden. Alternative: Team-Notizbücher erst nach Abnahmetest freischalten.
- **Q2 [THOMAS] Erneuter Import**: Bereits importierte Seiten werden übersprungen. Reicht das, oder soll „neue Kopie“ bzw. „Aktualisieren“ wählbar sein (späteres Slice)?
- **Q3 [THOMAS] Notweg Bilder**: Der vorhandene Drop-Import nimmt nur einzelne `.md`/`.html`-Dateien, Bilder aus OneNoteMdExporter gehen verloren. Reicht der Doku-Hinweis, oder soll ein Slice M9g „Ordner/ZIP mit Markdown und Bildern“ kommen? Das widerspricht ADR 0009 und braucht eine neue Entscheidung.
- **Q4 [THOMAS] Abnahmetest**: Schulkonto und Admin-Zugang zum Schul-Tenant für die Registrierung, Termin.

## 9. Slices
Reihenfolge M9a → M9c → M9b → M9d → M9e. M9f ist optional.
- **M9b ist gesperrt, bis Thomas Q1 entschieden hat.** M9c (Konverter, rein) wird deshalb vorgezogen.
- speccritic prüft vorher und nachher bei M9a, M9b und M9d (Rechte, Token, SSRF, Sperre), bei M9c und M9e nur den Diff.

### M9a: Admin-Einstellungen, OAuth, Migration, Bestandsprüfungen, Mock-Server (Umfang L)
Dateien:
- `lib/OneNote/{OneNoteConfig,OAuthService,TokenVault,GraphClient}.php`, `lib/Db/OneNoteImport*.php`, `lib/Db/OneNoteItem*.php`, Migration.
- `OneNoteApiController` (connect, callback, show, cancel, list), Admin-Karte (`AdminSettings`, `AdminSettingsController`), Routen.
- Löschung und Auskunft: `Service/UserDataService.php` (`UID_COLUMNS`, sofortige Phase), `Command/DeleteUserData.php`, `Listener/UserDeletedListener`, `Command/Purge.php`, `Service/UserExportService.php` (`onenote-imports.json`), `UserMigration/NextNotesMigrator.php` (Nachweis „nicht enthalten“).
- Bestandsprüfungen: `dev/nextcloud/check-purge.sh` (13 → 15 Tabellen), `docs/SPEC.md` §8 (Tabellenzahl, Lebenszyklus der zwei Tabellen), `tests/nextnotes/schema/expected-m1.json`, `dev/nextcloud/upgrade-path.sh` (Upgrade vom letzten Stand auf M9a).
- Mock: `dev/nextcloud/` Profil `msmock` + `scripts/msmock/` (Node, ohne Abhängigkeiten).
  - Docker-Netzaliase `login.microsoftonline.com` und `graph.microsoft.com` zeigen auf den Mock.
  - Test-CA und ein Serverzertifikat mit beiden Namen (SAN). Die CA wird im NC-Container per `occ security:certificates:import` eingespielt, weil `IClient` das NC-Bundle nutzt.
  - Nur in diesem Profil: `occ config:system:set allow_local_remote_servers --value=true --type=boolean`.
  - Mock-Steuerung (`/__ctl`): Zähler je Pfad, Fehler einspeisen, Antwort anhalten und freigeben.

Done:
1. Schema auf sqlite, mariadb und pgsql gleich (`run-m1.sh`, `check-schema.php` gegen das erweiterte `expected-m1.json`). `check-forbidden-sql.sh` ist grün.
2. Admin speichert Authority, Client-ID und Secret. Das Secret kommt in keiner Antwort und keinem HTML vor. Bei ungültiger Authority oder Client-ID → 400. Die Redirect-URI wird angezeigt.
3. connect → authorize-URL mit `S256` und `response_mode=query`. Scopes bei Tenant-ID mit Schalter an: `openid offline_access Notes.Read Notes.Read.All Team.ReadBasic.All`. Callback mit gültigem state legt die Token verschlüsselt ab (DB-Wert ≠ Klartext), setzt `ms_account` und antwortet mit 303.
   - 3a. Authority `common` mit gespeichertem Schalter an: Scopes genau `openid offline_access Notes.Read`; die Admin-Karte zeigt den Schalter deaktiviert mit Hinweis.
4. Ohne oder mit ungültigem state → 404 ohne Zustandsänderung: fehlender, falscher, abgelaufener oder wiederverwendeter state, fremde `uid`, jeweils auch zusammen mit `error=access_denied` (Zeile unverändert).
   - 4a. Gültiger state mit `error=access_denied`, `consent_required` bzw. `invalid_scope`: Zeile verworfen (bzw. bei Neuanmeldung state/Verifier gelöscht), Ansicht zeigt die jeweilige Meldung aus §4.2. `error_description` steht weder in der Antwort noch im Log.
   - 4b. Neuanmeldung mit anderem Mock-Konto (`sub` abweichend) → `account_mismatch`, keine Token gespeichert. ID-Token mit falschem `aud` → Fehlerseite, keine Token.
5. Refresh (Mock `expires_in=60`) ersetzt beide Token. `invalid_grant` → `reauth`. `invalid_client` → `failed`.
   - 5a. Admin ändert Client-ID (bzw. in einem zweiten Fall nur die Authority) → offene Importe in `select/queued/reauth` werden `failed`/`config_changed`, Token NULL, Benachrichtigung; `auth`-Zeilen gelöscht. Danach `connect` ohne `import_id` mit der neuen Konfiguration, Mock liefert ein anderes `sub` → neue Zeile in `select`, kein `account_mismatch`.
6. cancel, Kontolöschung, `delete-user-data` und `purge` löschen Token bzw. Zeilen. Log-Suche (`nextcloud.log`) nach Token, ID-Token, Code und Secret des Mocks ergibt 0 Treffer.
   - 6a. Nach `occ nextnotes:purge` liefert `occ config:list nextnotes --private` keinen Schlüssel `onenote_*`, und der Secret-Wert kommt in `oc_appconfig` nicht mehr vor.
7. GraphClient: Redirect, `nextLink` auf fremden Host und Ressourcen-Host `evil.example` werden nicht abgerufen (Mock-Zähler 0). 429 ohne `Retry-After` → Backoff 5 min, mit `Retry-After: 120` → 120 s. Gleitendes Fenster: 350 Anfragen in 60 min werden nicht überschritten, auch nicht über eine Fenstergrenze hinweg (Zeit per `ITimeFactory` gestellt); Abstand ≥ 0,6 s.
8. Unit-Nachweis Local-Address-Schutz: `GraphClient` und `OAuthService` übergeben `IClient` keine Option `nextcloud` → `allow_local_address` (gefälschter `IClientService` zeichnet alle Optionen auf).
9. `allow_local_remote_servers` ist nur im Profil `msmock` gesetzt; `run-m1.sh` prüft, dass es in sqlite/mariadb/pgsql nicht gesetzt ist.
10. `check-purge.sh` grün mit 15 Tabellen; SPEC §8 nennt 15.
11. `upgrade-path.sh`: Upgrade vom letzten Commit auf M9a legt beide Tabellen an, Bestandsdaten bleiben (vorher/nachher-Zählung).
12. `occ nextnotes:delete-user-data <uid>` für eine gelöschte UID, die nur noch Zeilen in `nextnotes_onenote_*` hat, erkennt „Daten vorhanden“ und löscht sie.
13. `occ nextnotes:export-user` enthält `onenote-imports.json` mit Bericht, aber ohne Token, state, `ms_account` und Cursor; keine Items. Der `NextNotesMigrator`-Export enthält keine `onenote`-Datei und keine Token-Werte (Suche im Archiv).
14. Zwei parallele `connect` desselben Benutzers (zwei Requests gleichzeitig, je Datenbank) → genau eine offene Zeile, die andere Antwort ist 409.

Testnachweis:
- PHPUnit `tests/nextnotes/php/OneNote/{OAuth,TokenVault,GraphClient,ImportRights,Lifecycle}Test.php` auf sqlite, mariadb und pgsql.
- `check-purge.sh`, `upgrade-path.sh`, `run-m1.sh`.
- E2E `onenote-connect.mjs` (hell und dunkel): Playwright leitet `login.microsoftonline.com` per `page.route` auf den Mock um.

### M9b: Auswahl, Job, Struktur, Fortsetzen (Umfang L) – **gesperrt bis Q1**
Dateien: `OneNoteImportService`, `OneNoteImportJob` (+ `info.xml` `<background-jobs>`), Controller `tree`/`start`, `templates/onenote.php`, `js-src/nextnotes-onenote.js`, `css-src`, Einstellungen-Karte, `Notifier` (3 Subjects), l10n.

Done:
1. `tree` zeigt eigene Notizbücher und bei erlaubten Teams Team-Notizbücher. 403 je Team wird übersprungen. Bei `common` gibt es keinen `joinedTeams`-Aufruf (Mock-Zähler 0).
   - 1a. `tree` mit abgelaufenem Zugriffstoken (Mock `expires_in=60`, Zeit gestellt) erneuert erst den Token und liefert dann die Liste; `invalid_grant` in `select` löscht die Zeile.
2. Eigene Notizbücher sind vorausgewählt, Team-Notizbücher haben nichts ausgewählt. Nur ausdrücklich gewählte Elemente werden importiert; ungültige IDs oder mehr als 200 Einträge → 400.
3. Job legt Workspace und Ordner an und übernimmt Seitenlisten mit Paging (> 100 Seiten im Mock).
   - 3a. Job über `occ background-job:execute` ohne Sitzung: Workspace `owner_id`, Notiz `created_by` und Anhänge gehören der uid der Import-Zeile.
4. Abbruch mitten im Lauf (Job-Prozess mit SIGKILL beenden, ohne `--force-execute` gestartet über `php cron.php`) → `reserved_at` des Jobs in `oc_jobs` um 12 h zurückstellen (simuliert die 12-h-Reservierung, `JobList::getNext`) und `lease_until` ablaufen lassen; der nächste normale `cron.php`-Lauf setzt fort, ohne Duplikate und ohne Temp-Reste.
   - 4a. Zwei parallele `occ background-job:execute --force-execute`: Nur einer übernimmt den Import. Jede Seite wird genau einmal abgerufen (Mock-Zähler), keine doppelte Notiz.
   - 4b. Auf sqlite, mariadb und pgsql: `cancel` während der Mock eine Ressource anhält antwortet in < 2 s (keine Sperre durch den Job). Dann Freigabe: Der Job schreibt nichts mehr (kein neuer Workspace, keine neue Notiz nach dem Abbruch), Token-Spalten bleiben NULL, keine weiteren Graph-Anfragen. Unter SQLite gelingt während des Anhaltens zusätzlich ein Notiz-Speichern eines anderen Benutzers in < 2 s.
   - 4c. Kontolöschung bzw. `delete-user-data` während der Mock anhält, dann Freigabe und Löschjob: keine Zeile in `nextnotes_onenote_*`, kein Workspace und keine Notiz der uid, keine Token.
   - 5a. Nach `config_changed` (neue Client-ID, Mock-`sub` anders) startet der Benutzer neu und wählt dasselbe Notizbuch: 0 neue Notizen, bereits importierte Seiten übersprungen.
5. Erneuter Import desselben Notizbuchs legt 0 neue Notizen an. Nach Purge einer Zielnotiz wird genau diese neu angelegt. Ein Ziel-Workspace, der inzwischen einem Team gehört oder `kind≠normal` hat, wird nicht genutzt (neuer Workspace).
6. Ein zweiter offener Import desselben Benutzers in `queued/running/reauth` wird mit 409 abgelehnt; ein offener `select` wird ersetzt und sein Budget übernommen. Fremde Import-IDs → 404 bei allen Routen.
7. Budget: Mock zählt ≤ 350 Anfragen je gleitende 60 min und uid, einschließlich `tree`. 429 im Mock → `next_run_at` gesetzt, kein Folgeaufruf im selben Lauf. Laufbudget: Nach 50 Anfragen beginnt keine neue Seite.
8. Benachrichtigungen `done`/`reauth`/`failed` erscheinen. Neuanmeldung nach `reauth` setzt fort.
9. Ansicht zeigt Status, Zähler und Restzeit, CSP ohne Verletzung (`check-csp.sh`). In `select` und `reauth` gibt es „Abbrechen“; danach ist ein neuer `connect` möglich.
10. Konto-Zustände: Konto deaktiviert → `reauth`/`user_disabled`, Token NULL, Mock-Zähler danach 0. Gruppenbeschränkung entzieht den App-Zugriff → `reauth`/`app_disabled`, Token NULL. Konto gelöscht, während der Job die Zeile geladen hat → keine Schreibwirkung.
11. Aufräumen: `select` nach 24 h → `expired`, Token NULL; `reauth` nach 7 Tagen → `expired` (Zeit gestellt).
   - 11a. Dasselbe bei abgeschalteter Funktion (Secret leer): Aufräumen läuft, Mock-Zähler 0.
   - 11b. Team-Schalter aus: Import in `select` mit `team_scopes=1` und `queued`-Import mit `group:`-Auswahl → `failed`/`config_changed`, Token NULL; ein Import nur mit `me` läuft weiter.
12. 20 Graph-404 in Folge → `failed`/`graph_not_found`, Token NULL. 19 Seiten-404, eine angelegte Seite, 19 Seiten-404 → kein `failed`. Ressourcen-404 erhöhen den Zähler nicht.
   - 12a. Graph 403 für einen Abschnitt und eine Seite → beide übersprungen, Bericht „kein Zugriff“, übrige Seiten angelegt, `not_found_streak` unverändert, kein Backoff, Import endet `done`.

Testnachweis:
- PHPUnit `OneNote/{ImportJob,Idempotence,Budget,Concurrency,AccountState}Test.php`, Job per `occ background-job:execute --force-execute`.
- E2E `onenote-import.mjs` (hell und dunkel, Mobil 390 px).

### M9c: Seiten-Konverter (Umfang M, vor M9b möglich)
Dateien: `lib/OneNote/PageConverter.php`, Fixtures `tests/nextnotes/fixtures/onenote/*.html` (Beispiele aus Learn-Seiten F9, Code-Beispiele unter MIT, Quelle je Datei vermerkt) + erwartete Ausgaben.

Done:
1. Lesereihenfolge (top, left) bei 3 Blöcken über Kreuz.
2. To-do offen und erledigt → Checkliste. Sie lässt sich im Editor abhaken und bleibt nach dem Speichern erhalten (E2E, nach M9b).
3. Tabelle mit colspan bleibt erhalten.
4. Stile reduziert, kein `position`, keine `font-family`.
5. Video wird zum Link. `onenote:`-Link wird zu Text.
6. Unterseiten-Präfix, Daten aus Graph in Liste und Info-Dialog.
7. Ausgabe ist ein Fixpunkt des Sanitizers: `sanitize(x) == x`.
8. 16 MiB-Seite → Hinweisnotiz.
9. Ressourcenliste in Dokumentreihenfolge, gedeckelt auf 100; ab der 101. Ressource Platzhalter.

Testnachweis: PHPUnit `OneNote/PageConverterTest.php` (Fixture-Paare), E2E-Abschnitt in `onenote-import.mjs` nach M9b.

### M9d: Ressourcen (Umfang M)
Dateien: `lib/OneNote/ResourceFetcher.php`, Anschluss an `AttachmentService`/`QuotaService`, `ITempManager`.

Done:
1. PNG und JPEG werden zu Anhängen und erscheinen in der Notiz. Geteiltes Bild in richtiger Reihenfolge.
2. PDF-`object` wird zu einem Anhang mit Link, der sich öffnet.
3. Eine Ressource über `attachment_max_file_mb` wird abgebrochen (Mock liefert endlos, gelesene Bytes ≤ Grenze + 64 KiB) → Platzhalter, Bericht.
   - 3a. gzip-Bombe (Mock: 1 MiB komprimiert, 1 GiB entpackt) → Abbruch an der Grenze der dekodierten Bytes, Platzhalter.
4. SVG oder ein falscher Inhaltstyp gibt keinen Bild-Anhang.
5. Quote: Ressourcensumme + HTML übersteigt den freien Platz → `failed`/`quota` vor der Transaktion. Frühere Seiten bleiben, die laufende Seite hinterlässt weder Anhang noch Temp-Datei.
6. Ressourcen-URL mit fremdem Host oder `www.onenote.com` gibt keinen Abruf (Mock-Zähler) und einen Platzhalter.
7. Grenze je Seite: 101 Ressourcen → 100 Anhänge, 1 Platzhalter. Summe über 256 MiB → die überschießenden Ressourcen werden Platzhalter, Seite wird angelegt.
8. Seite mit **80 Ressourcen** bei vollem Laufbudget: wird in einem Lauf vollständig angelegt (81 Anfragen > 50), danach beginnt keine neue Seite im Lauf. Variante mit nur 60 freien Anfragen im Stundenfenster: Lauf endet nach dem Inhaltsabruf ohne Anhänge; der nächste Lauf nach Freiwerden legt die Seite vollständig an; keine Duplikate, keine Temp-Reste.
9. 429 mitten in einer Seite → Seite verworfen, keine Reste; nächster Lauf legt sie vollständig an. `backoff_level` bleibt bis zur angelegten Seite erhalten; eine Seite, die zehnmal an derselben Stelle 429/5xx bekommt, führt zu `failed`.

Testnachweis: PHPUnit `OneNote/ResourceFetcherTest.php`, `OneNote/PageBudgetTest.php`, E2E-Abschnitt Bilder und PDF.

### M9e: Doku, SPEC, Abnahme (Umfang S)
Dateien:
- `docs/app/admin.md`: neuer Abschnitt aus §11, Datenschutz §10 ergänzt.
- `docs/app/user.md`: Import, Dauer, Grenzen, Freihand, Widerruf bei Microsoft, Notweg OneNoteMdExporter; nur ein offener Import gleichzeitig, „Abbrechen“ auch bei „Erneut anmelden“; nach „Konfiguration geändert“ neu starten.
- `docs/SPEC.md`: §2.1 Tabellen, §4 Routen, §5 Job, §6 Subjects, §14 OCP (§8 schon in M9a).
- ADR 0020 → entschieden, ADR-0008-Nachtrag (Auskunft, Umzug, Löschung laut §7), CHANGELOG.

Done:
1. Doku deckt jede Admin-Einstellung und jeden Fehlercode (`invalid_client`, `quota`, `graph_10008`, `graph_not_found`, `consent`, `config_changed`, `user_disabled`, `app_disabled`, `account_mismatch`) mit Abhilfe ab.
2. Widerrufswege bei Microsoft für Benutzer und Admin stehen in user.md bzw. admin.md, URLs gegen die Microsoft-Hilfe geprüft.
3. `run-m5.sh` grün (Meilenstein-Ende).
4. Protokoll Abnahmetest `docs/progress/M9e-abnahme.md` mit Ergebnis je Punkt.

Abnahmetest [THOMAS] (Schulkonto, echter Tenant, eigene Test-Instanz, nicht die Demo):
- Registrierung nach Anleitung.
- Import eines privaten Notizbuchs (~20 Seiten mit Bildern, PDF, Tabelle, To-do, Unterseiten).
- Import eines Klassennotizbuchs als Lehrkraft (nach Q1).
- Zu notieren:
  - Dauer und Anzahl der 429.
  - Ob `Notes.Read` für `/groups/{id}/onenote` genügt (sonst bleibt `Notes.Read.All` im Schalter).
  - Wie Gleichungen und Freihand im HTML erscheinen.
  - Verhalten mit `common` und einem privaten Konto, falls vorhanden (nur Basis-Scopes).

### M9f (optional, nach Abnahme): Freihand-Hinweis (Umfang S–M)
Nur, wenn der Abnahmetest zeigt, dass `GET …/content?includeInkML=true` eine dokumentierbare `multipart`-Antwort mit InkML liefert. Dann gilt:
- Platzhalter „✎ Freihand-Zeichnung nicht übernommen“ je Seite mit Ink.
- Die Umwandlung in ein Bild bekommt eine eigene Entscheidung.

Sonst entfällt das Slice.

## 10. Notweg ohne Admin (Doku-Entwurf für user.md)
1. Windows-PC mit OneNote-Desktop (2013 oder neuer, nicht die Store-App) und Word. Das Notizbuch ist geöffnet und synchronisiert, kennwortgeschützte Abschnitte sind entsperrt.
2. OneNoteMdExporter (GPL-3.0) von GitHub laden, Format „Markdown“ wählen.
3. Ergebnis: ein Ordner je Abschnitt, eine `.md` je Seite.
4. In Next Notes Ziel-Workspace und Ordner wählen, dann die `.md`-Dateien (≤ 50 je Vorgang, ≤ 16 MiB je Datei) in die Seitenleiste ziehen.
5. Grenzen:
   - Bilder und Anhänge kommen **nicht** mit.
   - Freihand geht verloren, die Ordnerstruktur legt man von Hand an.
   - Erstell- und Änderungsdaten gehen verloren.

## 11. Entwurf Admin-Anleitung (für admin.md, Abschnitt „OneNote-Import“)
1. **Kontotyp wählen**:
   - Nur Konten der eigenen Schule → „Nur Konten in diesem Organisationsverzeichnis“, Authority = Tenant-ID. Empfohlen: keine Zustimmungsprobleme und keine Publisher-Verifizierung nötig.
   - Mehrere Schulen bzw. Tenants → „Konten in einem beliebigen Organisationsverzeichnis“, Authority `organizations`. Admins der anderen Tenants müssen meist zustimmen, weil die App nicht verifiziert ist.
   - Zusätzlich private Microsoft-Konten → „… und persönliche Microsoft-Konten“, Authority `common`. Höchstens 2 Client-Secrets; private Konten sehen „nicht verifiziert“ auf der Zustimmungsseite. **Team- und Klassennotizbücher sind mit `common` nicht verfügbar.**
2. **Entra Admin Center** → Identität → Anwendungen → App-Registrierungen → Neue Registrierung:
   - Name „Next Notes OneNote-Import“, Kontotyp wie oben.
   - Umleitungs-URI Plattform **Web**, Wert = die in Next Notes angezeigte Redirect-URI. Sie muss exakt passen, mit HTTPS.
3. Übersicht: **Anwendungs-(Client-)ID** und **Verzeichnis-(Mandanten-)ID** kopieren.
4. Zertifikate & Geheimnisse → Neuer geheimer Clientschlüssel. Laufzeit wählen, **Wert** sofort kopieren (er wird nur einmal angezeigt) und den Ablauf im Kalender notieren.
5. API-Berechtigungen → Microsoft Graph → Delegierte Berechtigungen:
   - `openid`, `offline_access`, `Notes.Read`.
   - Für Team- bzw. Klassennotizbücher (nur Tenant-ID oder `organizations`) zusätzlich `Notes.Read.All` und `Team.ReadBasic.All`.
   - Dann „Administratorzustimmung für <Schule> erteilen“: Microsoft verlangt sie nicht, aber Schüler sehen sonst eine Zustimmungsseite, und bei abgeschalteter Benutzerzustimmung geht es ohne sie nicht.
6. **Next Notes** → Verwaltung → Next Notes → OneNote-Import: Authority, Client-ID und Secret eintragen, Team-Notizbücher nach Bedarf, speichern.
7. **Betrieb**:
   - System-Cron (alle 5 min) ist nötig, Microsoft erlaubt ≈ 400 Anfragen/h je Benutzer.
   - Ein großes Notizbuch braucht Stunden.
   - Läuft das Secret ab, stoppen Importe mit „Konfiguration ungültig“. Dann neues Secret erzeugen und eintragen.
   - Ein Wechsel von Client-ID oder Authority beendet offene Importe mit „Konfiguration geändert“ (`config_changed`). Die Benutzer starten den Import neu; bereits importierte Seiten werden übersprungen.
   - Wird der Schalter Team-Notizbücher ausgeschaltet, enden offene Importe mit Team-Notizbüchern ebenso; andere laufen weiter.
   - Bleiben Client-ID oder Secret leer, ruht der Import; Token werden trotzdem nach den Fristen gelöscht.
   - Ein Cron-Prozess, der hart abbricht (z. B. Neustart des Servers), hält den Job in Nextcloud bis zu 12 h reserviert. Neustart-Pfad: `occ background-job:list` → ID von `OneNoteImportJob` → `occ background-job:execute <id> --force-execute`.
8. **Widerruf**: Admin-Zustimmung im Entra Admin Center → Unternehmensanwendungen → App → Berechtigungen widerrufen. Benutzerzustimmungen nur per Graph/PowerShell (F17). Die App selbst löscht Token bei Abbruch, Ende, Fehler und Fristablauf.
9. **Datenschutz** siehe §7. Der Server ruft nur `login.microsoftonline.com` und `graph.microsoft.com` auf, bei einem Proxy über `proxy` in `config.php`.

## 12. Risiken
| # | Risiko | Gegenmaßnahme |
|---|---|---|
| R1 | Microsoft ändert Limits oder kündigt die OneNote-API weiter ab | Limits als Konstanten an einer Stelle, Mock-Tests. Abkündigung beobachten (Learn-Seiten F1/F2 im Release-Check). |
| R2 | Ausgabe-HTML weicht von der Doku ab (Gleichungen, Ink, Stile) | Abnahmetest mit echtem Notizbuch, Fixtures daraus anonymisiert nachziehen |
| R3 | Lange Importe ohne Cron; Cron-Modus AJAX bricht lange Läufe ab | Hinweis in Ansicht und Admin-Doku. Kein Import-Schritt im Web-Request. Abgebrochene Läufe hinterlassen dank atomarer Seite keine Reste. |
| R4 | Schul-Tenants blockieren Benutzerzustimmung | Admin-Zustimmung in der Anleitung. Callback zeigt `consent_required` verständlich an. |
| R5 | Bedingter Zugriff (Anmeldehäufigkeit) verkürzt den Refresh-Token | `reauth`-Pfad mit Benachrichtigung, Fortsetzen ohne Duplikate |
| R6 | Migrationsnummer kollidiert mit parallelen Slices | Nummer erst bei Umsetzung festlegen |
| R7 | Klassennotizbuch-Inhalte Dritter in privaten Workspaces | Q1; in Team-Notizbüchern nichts vorausgewählt; M9b gesperrt bis Entscheidung |
| R8 | Einzelner Job-Lauf dauert bei großen Seiten bis 15 min | Grenze je Seite, Lease-Verlängerung als Autocommit; keine Transaktion über Netz-I/O, die DB bleibt schreibbar; andere Jobs laufen in eigenen Cron-Prozessen weiter |
| R9 | `memory_limit` kleiner als die größte Ressource | Temp-Dateien statt Speicher; `createFromString` liest je Anhang höchstens `min(attachment_max_file_mb, 100 MiB)`; Admin-Doku nennt den Zusammenhang |
| R10 | Hart abgebrochener Cron-Prozess lässt `OneNoteImportJob` in `oc_jobs` 12 h reserviert (`JobList::getNext`); Importe ruhen so lange | Lease läuft nach 5 min ab, Daten bleiben konsistent (atomare Seite). Neustart-Pfad in der Admin-Doku (§11.7: `occ background-job:execute <id> --force-execute`); Restzeit-Anzeige weist auf Cron hin. Done M9b 4 stellt den Fall nach. |
