# ADR 0006: Excalidraw, Mermaid, CodeMirror unter der Nextcloud-CSP (Auftragsfrage 5)

Status: entschieden (Lead, 2026-11-02)

## Kontext
Geprüft in `.ref` (v35.0.2):
- `OC\AppFramework\Middleware\Security\CSPMiddleware` setzt bei CSP3-Browsern eine Nonce. `EmptyContentSecurityPolicy::buildPolicy()` entfernt dann `'self'` aus `script-src` → `script-src 'nonce-…'`; zusätzlich `script-src-elem 'strict-dynamic' 'nonce-…'` (Default `strictDynamicAllowedOnScripts = true`).
- `lib/private/Template/functions.php::emit_script_tag`: jede per `Util::addScript` geladene `.js`-Datei wird als **klassisches Skript mit `defer` und Nonce** ausgegeben; nur `.mjs` wird `type="module"` (`JSResourceLocator` sucht zuerst `.mjs`, dann `.js`).
- Defaults `ContentSecurityPolicy`: `style-src 'self' 'unsafe-inline'`, `img-src 'self' data: blob:`, `font-src 'self' data:`, `connect-src 'self'`, `media-src 'self'`, `frame-src` leer, `worker-src` leer (Fallback auf `script-src`, d. h. nur Nonce → Worker blockiert), `form-action 'self'`.
- Kein `unsafe-eval` mehr in der API; nur `allowEvalWasm()` (@since 28) → `'wasm-unsafe-eval'`.

## Befund je Bibliothek (Inventur B 4.3)
| Teil | Läuft ohne Ausnahme? | Bemerkung |
|---|---|---|
| CodeMirror 6 (IIFE) | ja | injiziert `<style>`, erlaubt durch `style-src 'unsafe-inline'` |
| Mermaid 11 | ja | `Function("return this")` nur Fallback ohne `globalThis`; Nachladen per `<script>` aus `lazy-libs.js` ist durch `strict-dynamic` gedeckt (ladendes Skript hat Nonce) |
| KaTeX, highlight.js, Sortable | ja | Fonts von `'self'` |
| Excalidraw 0.17.6 Kern | ja, wenn `EXCALIDRAW_ASSET_PATH` auf App-Pfad zeigt | sonst unpkg-Fallback → blockiert; Bibliotheks-Download (externe Domain) bleibt blockiert |
| Excalidraw/pica (Bildskalierung) | nein | `new Worker` aus `blob:`/`data:`, `WebAssembly.compile` → braucht `worker-src` und `wasm-unsafe-eval`, sonst JS-Fallback (zu testen) |
| Upstream-Inline-Skripte, `on…=`-Handler, `new Function` (`events-drag-drop.js:181`), `javascript:`-URLs | nein | müssen weg (Templates, Adapter, Änderungen in `src/` laut SPEC §11.1) |
| Audio-Player-iframe (`audio_player.php`), erlaubte Embed-Domains | nein | `frame-src` leer → ADR 0012 |

## Empfehlung
- Upstream-JS als klassische `.js`-Bundles (nie `.mjs`) per `Util::addScript`; Globals bleiben wie Upstream.
- Logik „vor dem ersten Paint“ läuft wegen `defer` später: Startzustand per CSS-Default und `IInitialState`, Flackern in M3 prüfen.
- Ausnahmen nur gemäß ADR 0012, für Excalidraw nur auf der Excalidraw-Route.

## Konsequenzen
- M3/M4 Playwright-Smoke prüft `securitypolicyviolation`-Events = 0.
- Ohne `wasm-unsafe-eval` läuft Excalidraw, nur Bildskalierung ggf. langsamer.
