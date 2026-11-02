# ADR 0011: Öffentliche Links

Status: entschieden (Lead, 2026-11-02)

## Kontext
Upstream: Token-Links für Notizen und Ordner mit Passwort, Ablauf, Modus (lesen/bearbeiten), Benutzerbeschränkung, hübschen Slugs, Bearbeitung über öffentliche Endpunkte (A #65–68, #96–99, #129–130, #178–184, #238, #240–242, T5, T6, T12, A18; B #24–27, 2.2 #42). Auftrag: in v1 streichen, außer Nextcloud-Shares lassen sich einfach anbinden.

Geprüft in `.ref`: `OCP\Share\IManager` (@since 9) und `IShare` arbeiten auf Dateisystem-Knoten (`Node`); Typen `TYPE_LINK=3`, `TYPE_CIRCLE=7`, `TYPE_DECK=12` usw. Es gibt keinen öffentlichen Weg, einen eigenen Share-Typ für DB-Objekte zu registrieren (Deck hat einen fest eingebauten Typ). `AppFramework\PublicShareController`/`AuthPublicShareController` (@since 14), `PublicTemplateResponse` (@since 14) und `Security\Bruteforce\IThrottler` (@since 25) erlauben eigene Token-Seiten.

## Optionen
1. **In v1 streichen.**
2. NC-Shares: würde Notizen als Dateien verlangen (widerspricht ADR 0002) → nicht einfach.
3. Eigene Token-Tabelle + `AuthPublicShareController` + `IThrottler` (Nachbau des Upstream-Features, CSP-Fragen für öffentliche Seiten).

## Empfehlung
Option 1. Option 3 als späteres Feature (v1.x), weil NC-Shares nicht einfach anbindbar sind.

## Konsequenzen
- Alle genannten Inventurzeilen → streichen (v1). Keine Tabellen `shared_*`.
- Teilen-Schaltflächen per Adapter ausgeblendet; Endpunkte `/notes/{id}/share`, `/folders/{id}/share`, `/shared*` liefern 404 bzw. `[]` für `/shared`, `/shared/with-me` (Frontend-Listen bleiben leer statt Fehler).
- Teilen innerhalb NC läuft über Workspaces (ADR 0014) und Teams (ADR 0003).
