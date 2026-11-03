# ADR 0012: CSP-Ausnahmen

Status: entschieden (Lead, 2026-11-02)

## Kontext
Grundlage ADR 0006. Vorgabe: Ausnahmen nur minimal, begründet, per ADR. Verfügbare Methoden (`EmptyContentSecurityPolicy`, `.ref` v35): `addAllowedWorkerSrcDomain` (@since 15), `allowEvalWasm` (@since 28), `addAllowedFrameDomain` (@since 8.1), `addAllowedMediaDomain`/`addAllowedImageDomain` (@since 8.1), `useStrictDynamic` (@since 24). App-weite Defaults über `OCP\Security\CSP\AddContentSecurityPolicyEvent` (@since 17) – wird **nicht** genutzt; Ausnahmen hängen nur an den eigenen Responses.

## Optionen
1. Keine Ausnahmen; Excalidraw ohne Worker/WASM, keine iframes.
2. **Minimale, routengebundene Ausnahmen** (unten).
3. Globale Lockerung (`unsafe-eval`, `unsafe-inline`) – in v35 nicht verfügbar bzw. unzulässig.

## Empfehlung (Option 2)
| Ausnahme | Route(n) | Grund | Bedingung |
|---|---|---|---|
| `frame-src 'self'` (`addAllowedFrameDomain("'self'")`) | Ansichten mit Editor/Notizanzeige | Audio-Player-iframe (`audio_player.php`), in gespeicherten Notizen vorhanden | immer |
| `frame-src <Domain>` je Eintrag der Admin-Liste „erlaubte Embed-Domains“ (Upstream `ALLOWED_IFRAME_DOMAINS`) | dto. | Video-Embeds in Notizen | nur vom Admin eingetragene Domains, Default leer |
| `worker-src 'self' blob:` | nur `excalidraw_editor.php` | pica-Worker | nur wenn M4-Test zeigt, dass der Fallback nicht reicht |
| `'wasm-unsafe-eval'` (`allowEvalWasm()`) | nur `excalidraw_editor.php` | pica-WASM | dto. |
| `worker-src data:` | – | nicht vorgesehen; erst mit eigener Nachtrags-ADR | – |

Nicht erlaubt: `unsafe-eval`, `unsafe-inline` für Skripte, externe Skript-/Font-/Connect-Domains (Excalidraw-Assets kommen aus dem App-Pfad; Bibliotheks-Import von libraries.excalidraw.com bleibt blockiert).

## Konsequenzen
- CSP wird in einer Fabrik `Http\CspFactory` gebaut; jede Abweichung ist dort sichtbar und getestet.
- Playwright prüft je Ansicht: keine CSP-Verletzung; auf `excalidraw_editor.php` zusätzlich, dass Bilder einfügen funktioniert.

## Nachtrag M3 (2026-11-03)
PDF-Anhänge (`GET …/attachments/{id}`, inline) erhalten `frame-ancestors 'self'` (`EmptyContentSecurityPolicy::addAllowedFrameAncestorDomain`, @since 13). Die leere Standard-CSP von Nicht-Template-Antworten setzt `'none'` und blockiert die PDF-Vorschau im iFrame der Notiz (`attachments.js`). `'self'` entspricht dem NC-Standard für Seiten und dem `X-Frame-Options: SAMEORIGIN` des Webservers; `frame-src` der Seiten bleibt unverändert. Übrige Anhänge behalten `sandbox` (SPEC B22).

## Nachtrag M3c1 (2026-11-03)
Ansicht `attachments` (Anhang-Seite) erhält nur `frame-src 'self'` (`addAllowedFrameDomain("'self'")`), keine Admin-Embed-Domains. Zweck: PDF-Vorschau im iFrame (Upstream-Patch P13 statt `<embed>`, das `object-src` bräuchte). Eingebettet wird dort nur der eigene Anhang-GET; Embed-Domains sind für Notizinhalt gedacht und entfallen hier. Umsetzung: eigene Liste `SELF_FRAME_VIEWS` in `CspFactory`.
