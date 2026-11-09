# M9a speccritic (Diff-Review r1)
Auftrag: Diff-Review M9a (OneNote-Grundlage) gegen M9-plan r3 §M9a Done 1-14, ADR 0020. Ergebnis docs/reviews/M9a-r1.md.
## Erledigt
- Plan §2-§6, §9 M9a, ADR-Nachtrag, SPEC-Diff gelesen
- Code: ConnectService, OneNoteApiController, HttpGate, OAuthService, GraphClient, RateBudget, OneNoteConfig, TokenVault, Mapper, Migration, AdminSettings(+Controller, Template, JS), UserDataService, Purge, ExportUser, Notifier, msmock.sh/compose/run-m1/check-purge/upgrade-path
- Tests überflogen (OAuth, ImportRights, Lifecycle, GraphClient, MockIntegration, PurgeTest)
- OCP/NC35: ClientService nutzt CurlHandler (kein StreamHandler), Guzzle aus Image nextcloud:35.0.2-apache per `docker image save` gelesen (kein Container gestartet); ensureNamingConstraints max 63
- docs/reviews/M9a-r1.md geschrieben: FREIGABE, 7 Hinweise
## Status: fertig
