# ADR 0015: Offline-Modus und PWA

Status: entschieden (Lead, 2026-11-02)

## Kontext
Upstream hat Service Worker (`sw.js`), Manifest, Offline-Seiten, Offline-Markierung je Notiz/Ordner, Web-Push und Web Share Target (A #25–27, #42, #92, #243, #244; B #61–64, 2.1 #25/#26). Der Auftrag streicht PWA für v1. Unter NC: Service Worker im App-Pfad bräuchte `worker-src`/Scope-Freigaben, eigene Login-Behandlung und kollidiert mit der NC-Sitzung; NC liefert selbst ein Manifest.

## Optionen
1. **Streichen**, Offline-Funktionen ausblenden.
2. Nur Lese-Cache im Browser (`localStorage`) ohne Service Worker.
3. Volle PWA.

## Empfehlung
Option 1. Kein `sw.js`, keine Offline-Bundles (`offline-*.js`, `pwa/*`) im Build; Offline-Spalten nicht ins Datenmodell; Endpunkte `/offline/*`, `PUT …/offline` liefern 404; Schaltflächen per Adapter ausgeblendet. Online-Erkennung (`api_health.php`) entfällt.

## Konsequenzen
- Unterwegs-Nutzung über NC-Mobil-Apps/Browser mit Verbindung.
- Autosave-Notfallpfad (`sendBeacon`) bleibt (SPEC §4.2).
