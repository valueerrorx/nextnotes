VERDICT: BLOCKER

Diff ed41a3b9..Arbeitsbaum (M2b), geprüft gegen M2-plan §1/§2 M2b/§5, SPEC §2/§11.2/§13/§14, ADR 0002/0005/0012. `src/` unverändert (git diff/status leer). Rechte, DB, Jobs im Code ohne Befund; beide Blocker sind nur Text in Plan bzw. SPEC.

1. Widerspruch zwischen Anhang-Auslieferung und Done-Kriterium. Fundstellen: M2-plan §2 M2b `AttachmentsApiController` („`attachment` außer image/png|jpeg|gif|webp“), Done M2b.2 („Header wie oben“), SPEC B22 (nennt nur SVG), dazu `AttachmentsApiController::presentation()`. Der Code liefert PDF, alle `image/*` außer SVG, Audio, Video und Textformate (als `text/plain`) inline aus. Forderung: Die umgesetzte Regel in Plan §2 M2b und in B22 eintragen (inline-Liste, SVG immer `attachment`, `nosniff` überall, CSP `sandbox` außer bei PDF mit Begründung). Done M2b.2 muss dagegen prüfbar sein (PDF, PNG, SVG, HTML, .txt).
2. Sperre beim Speichern ohne Editor-Sitzung ist nicht beschrieben. Fundstellen: SPEC B13 und Plan §2 M2b („Speichern … mit gültiger Sperre einer anderen Sitzung → 423“), dazu `LockService::assertCanSave`. Ohne Sitzungs-ID blockiert nur die Sperre eines anderen Kontos. Mit Sitzungs-ID ohne bestehende Sperre wird die Sperre beim Speichern erworben. Forderung: beide Regeln als Satz in B13 aufnehmen.

Bewertung der Abweichungen
1. Duplizieren mit 200 (Notiz) und 201 (Ordner): akzeptabel. Die Codes entsprechen den Upstream-Fixtures, das Frontend bleibt unverändert.
2. Sperre: akzeptabel und mit ADR 0005 und §13 M2.6 vereinbar. Fremde Konten und fremde Sitzungen erhalten 423, beim Erwerb 409. Übernahme, Heartbeat und Release sind an (uid, Sitzung) gebunden, `takeover` wird ignoriert. Ohne Sitzung kann nur das eigene Konto speichern; der offene Tab schützt sich über `if_version`. Fehlt nur die Doku (Blocker 2).
3. Inline-Auslieferung: technisch akzeptabel. Der MIME-Typ kommt aus `detectContent` auf dem Server, nicht vom Client. SVG wird am MIME-Typ und an der Endung erkannt. HTML und alle übrigen Typen gehen als `attachment` mit `sandbox`/`nosniff` raus. Ein PDF entsteht nur bei echtem PDF-Inhalt und bekommt die NC-Standard-CSP (Browser-PDF-Viewer laufen nicht unter `sandbox`). Fehlt nur die Doku (Blocker 1).
4. SPEC §2.2 (Snapshots ohne Tags, keine Anhang-Aufbewahrung, `render=1` erst M4): akzeptabel, weil dokumentiert. `render=1` nutzt nur `revisions-page.js`, also eine Seite aus M3/M4. Folge für Nutzer: Nach dem Löschen eines Anhangs zeigt ein wiederhergestellter Snapshot ein kaputtes Bild. Das ist in Ordnung, wenn Thomas es so will.
5. B21: akzeptabel und konsistent mit B17. Der Code prüft `manage` auf der Quelle in `AttachmentService::move` und `DuplicateService::duplicateNote` (Ordner-Duplikat nur im selben Workspace). B22: akzeptabel, aber unvollständig (Blocker 1).
6. Quote je Workspace-Besitzer: konsistent mit ADR 0002 („Summe der eigenen Workspaces“) und SPEC §2.2. Team-Workspaces zählen bis M5 einzeln (Kommentar im Code). Folge: Ein Freigabe-Empfänger belegt die Quote des Besitzers.

Hinweise
- CSP der Anhänge (außer PDF) ersetzt die NC-Standard-CSP und hat kein `frame-ancestors` mehr. Vorschlag: `frame-ancestors 'self'` ergänzen. Die CSP ist strenger, keine Ausnahme nach ADR 0012.
- Tests zu Done M2b.3 (`LockServiceTest`), M2b.4 (NotesProvider/OCS) und M2b.5 (purge, Rollback, Jobs) fehlen noch im Baum. Die Isolation für `move` muss auch die Richtung „eigene B-Notiz → A-Notiz“ abdecken (Prüfung durch Tester/Lead).
- Kleinere Punkte ohne Sicherheitswirkung:
  - Die Quotenprüfung läuft außerhalb der Transaktion (Wettlauf).
  - `store` schließt das `fopen`-Handle nicht.
  - Bei Verknüpfungen zeigt `index` die eigenen Anhänge, `download` dagegen die des Ziels.
  - `SnapshotRetentionJob` dünnt mit dem Standardwert 30 statt mit der Benutzereinstellung aus.
