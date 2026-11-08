# Demo-Instanz (Thomas)

Compose-Projekt `nextnotes-demo`, http://localhost:8090. Nur Thomas; Agenten und Tests fassen sie nicht an.

- Aktualisieren: `dev/demo/update.sh` (idempotent, legt fehlende Konten und Teams an)
- Konten: `admin`/`admin`, `thomas`/`thomas-demo-2026`, `schueler1` und `schueler2`/`schueler-demo-2026`
- Teams (nur mit aktiver Teams-App `circles`): „Klasse 4a“ (Owner thomas, Mitglieder schueler1, schueler2), „Fachgruppe Mathe“ (Owner admin, thomas Moderator)

## macOS

Voraussetzungen wie in `dev/nextcloud/README.md` („macOS“: Docker Desktop, `brew install bash gnu-tar node`, `npm ci --prefix nextnotes/build`). Danach wie unter Linux: `dev/demo/update.sh` legt Instanz, Konten und Teams an (Port 8090 frei halten; `dev/upstream` nutzt denselben Port).
