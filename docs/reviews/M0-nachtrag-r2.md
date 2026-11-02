VERDICT: BLOCKER

Die Blocker 1–4 aus r1 sind gelöst. (1) `nextnotes_teams` ist jetzt die einzige Quelle für den Zustand und die Frist, auch für Teams ohne Workspace und für umgewandelte Workspaces; M6 #8 deckt das ab. (2) Die UID-Spalten werden bei Kontolöschung NULL, `created_by_name` ist ergänzt, und M6 #9 enthält den Test „UID neu anlegen“. (3) Systemverwaltete Ordner und Workspaces sind für alle Benutzer gesperrt (403), „Papierkorb leeren“ überspringt Abgaben; M6 #10 deckt das ab. (4) `visibleAssignmentIds`, die Matrix in §9.7, der Systempfad `transferOwnership` und die Zielprüfung bei `occ` sind ergänzt.

Neuer Blocker:

1. **Der Übergang setzt beim Löschen eines Teams `owner_uid` auf NULL. Dann kann in der 30-Tage-Frist niemand mehr exportieren** (SPEC §5 `CleanupJob`, §8 „Mitglied tritt aus“/„Rolle herabgestuft“, §9.7 „Übergang“; ADR 0008 c). Thomas verlangt, dass bis zum Ende der Frist ein ZIP-Export möglich ist. Laut §9.7 dürfen im verwaisten Team nur O (`owner_uid`) lesen und exportieren. `transferOwnership` wird aber ausgelöst, sobald `owner_uid` im Team scheinbar Level < 4 hat. Das passiert in zwei Fällen:
   - **Verpasstes Lösch-Event.** Das Team ist gelöscht, steht in `nextnotes_teams` aber noch auf `active`, weil `orphan-check` zwei Läufe braucht. Die tägliche Owner-Prüfung im selben `CleanupJob` liefert Level 0. Es gibt kein Ziel, also wird `owner_uid` NULL.
   - **Austritts-Events beim Löschen.** Wenn Circles beim Auflösen eines Teams Austritts-Events (`MembershipsRemovedEvent`) für alle Mitglieder auslöst, bevor `CircleDestroyedEvent` den Zustand setzt, passiert dasselbe.

   Das widerspricht auch der Regel in §3, dass „nicht gefunden“ oder ein Circles-Fehler nie gespeicherten Zustand ändern. Nötig ist: `transferOwnership` (Event und Tagesprüfung) ändert `owner_uid` nur, wenn `getTeam()` im selben Lauf das Team fehlerfrei als vorhanden bestätigt und es eine Ziel-Lehrkraft gibt. Fehlt das Ziel bei Austritt oder Herabstufung, bleibt `owner_uid` unverändert, denn im aktiven Team gibt er ohnehin keine Rechte. NULL ist nur bei Kontolöschung erlaubt. Dazu ein M6-Testfall: Team löschen, dabei Austritts-Events bzw. einen verpassten Lösch-Event simulieren → `owner_uid` bleibt, und Export ist in der Frist möglich.

Hinweise (keine Blocker):
- In §3 nennen die Zeilen „Notiz `role=submission`“ und „… gelöschtes Schülerkonto“ weiter „Lehrkräfte des Teams bzw. `owner_uid`“ ohne Bezug auf den Team-Zustand. Laut §9.7 zählt `owner_uid` aber nur bei verwaistem oder eingefrorenem Team. Besser „wie die Aufgabe (§9.7)“ schreiben.
- §8, Zeile „Teams-App deaktiviert“, sagt „lesbar für Ersteller“. Für Aufgaben gilt laut §9.7 aber `owner_uid`. Der Begriff sollte angeglichen werden.
- Done-Kriterium 4 ist unverändert: Die Inventur-Zeilen stehen noch auf „offen (ADR)“. Das ist nur eine Formalie.
