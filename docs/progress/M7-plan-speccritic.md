# M7-plan speccritic
Auftrag r1: docs/slices/M7-plan.md (r1) prüfen → docs/reviews/M7-plan-r1.md (fertig, BLOCKER 2 + 7 Hinweise)
Auftrag r2: Plan r2 gegen r1-Befunde, neue Lücken → docs/reviews/M7-plan-r2.md
## Erledigt
- r1: siehe docs/reviews/M7-plan-r1.md
- r2: Plan r2 gelesen; B1 (a–d), B2, H1–H7 abgeglichen
- r2: .ref geprüft: IUserConfig getKeys/deleteUserConfig/deleteApp @since 32; IAppConfig::deleteApp; IJobList::remove; AppManager::isInstalled = Schlüssel 'enabled' in appconfig
- r2: Review geschrieben docs/reviews/M7-plan-r2.md (FREIGABE mit 3 Hinweisen)
## Nächster Schritt
- keiner
## Offen
- core app:remove-Befehl nicht in .ref vorhanden (Verhalten bei fehlendem 'enabled' nur über AppManager abgeleitet)
Status: fertig
