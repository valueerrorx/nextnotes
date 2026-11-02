VERDICT: FREIGABE

Geprüft: `nextnotes/**`, `dev/nextcloud/**`, `docs/SPEC.md` §14 gegen `docs/slices/M1-plan.md` (M1a), SPEC §1.1, §2.1, §4.5, §4.6, §8, §13 M1, ADR 0012, `.ref` 35.0.2.

Keine Blocker.

Ergebnis im Einzelnen:
- Migration `nextnotes/lib/Migration/Version000100Date20261102000000.php:25-191`: alle 11 Tabellen stimmen in Spalten, Typen, Länge, Nullbarkeit, Defaults, PK und Indizes (Namen, Spalten, UNIQUE) mit §2.1 überein. `assignments` und `submissions` fehlen wie vorgesehen. Es gibt keinen `hasTable`-Guard, nur `Types::*` und kein Roh-SQL. Alle Namen sind unter 63 Zeichen, auch `oc_nextnotes_mentions_handled_pkey`. Kein NOT NULL hat Default `''` (`MigrationService::ensureOracleConstraints`). UNIQUE auf nullbaren Spalten (`nn_ws_asg`, `nn_note_sub`) ist auf allen drei DBs portabel. `.cache/schema-sqlite.json` zeigt 11 Tabellen.
- Rechte: `PageController.php:28-39` setzt `#[NoAdminRequired]` und `#[NoCSRFRequired]`, kein `#[PublicPage]`. Damit ist die Seite nur angemeldet erreichbar und braucht kein Admin-Recht. NoCSRF steht nur auf GET-Seiten ohne Seiteneffekt (§4.5). Das Template hat kein Inline-Skript (§4.6).
- CSP: `CspFactory.php:16-18` liefert eine unveränderte `ContentSecurityPolicy`, also keine Ausnahme (ADR 0012).
- info.xml: NC 35/35, PHP 8.3/8.5, `AGPL-3.0-or-later`, Navigation und Herkunftssatz sind vorhanden. `validate-info.sh:18-24` prüft das per XPath.
- Testskripte: `lifecycle.sh:21-24` erzwingt eine frische Instanz (down mit Volume-Löschung, dann up, Abbruch bei vorhandenen `oc_nextnotes_*`). `install-app.sh:11-13` kopiert per tar, kein Mount. Überall gilt `set -euo pipefail` mit `inherit_errexit`. Die Probe-Prüfungen brechen über `die` ab.
- OCP-Stichproben sind korrekt: `ITable`/`IColumn` @since 35.0.0, `Types` 21 (JSON deprecated 33), `IOutput` 9.1, `Server::get` 25, `IBootContext` 20, `RENDER_AS_USER` 20.
- Kein Scope-Wachstum gegenüber dem Plan.

Hinweise (nicht blockierend):
1. `dev/nextcloud/lifecycle.sh:51`: `.disabled | has("nextnotes")` bricht mit einem jq-Fehler ab, wenn PHP eine leere Liste als `[]` statt `{}` ausgibt. Das wäre ein falscher Fehlschlag, kein falsches Grün. Robuster ist `(.disabled // {} | type == "object" and has("nextnotes"))` oder ein Vergleich über `keys`.
2. `dev/nextcloud/check-page.sh:15,24` und `up.sh:25`: `curl` läuft ohne `--max-time`. Ein hängender Webserver blockiert das Skript dann unbegrenzt, statt mit Exit ≠ 0 zu enden.
3. `dev/nextcloud/down.sh:10`: `--profile '*'` setzt eine neuere Compose-v2-Version voraus. Die Mindestversion sollte in `dev/nextcloud/README.md` stehen.
4. Der Schema-Vergleich gegen §2.1 (Done 3) liegt laut Plan beim Tester. Dieser Diff zählt nur die Tabellen (`lifecycle.sh:36`). Done 3 gilt erst mit dem Tester-Nachweis.
