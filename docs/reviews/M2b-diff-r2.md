VERDICT: FREIGABE

Blocker 1 (Anhang-Auslieferung) erledigt: Plan §2 M2b nennt die Inline-Liste, `nosniff`, die CSP mit `frame-ancestors 'self'` sowie PDF ohne `sandbox` und begründet es. Done M2b.2 prüft jetzt je Typ (PNG, PDF, SVG, HTML, .txt, `?download=1`). B22 und `AttachmentsApiController` stimmen damit überein.
Blocker 2 (Sperre ohne Sitzung) erledigt: B13 und Plan M2b beschreiben jetzt beide Fälle (ohne Sitzungs-ID, mit Sitzungs-ID ohne bestehende Sperre). Der Code in `LockService::assertCanSave` passt dazu.

Quoten-Sperre (`WorkspaceMapper::lockOwner`):
- Portabel: `set('owner_id','owner_id')` wird zu `owner_id = owner_id` (NC `QueryBuilder::set` setzt Spaltennamen in Anführungszeichen), über den Index `nn_ws_owner`.
- MariaDB/PostgreSQL: NC arbeitet mit READ COMMITTED. Ein UPDATE ohne Wertänderung sperrt die Zeilen trotzdem. Die Summe danach sieht fremde Commits, also ist die Prüfung korrekt.
- `assertQuota` ist in store, move und Duplizieren jeweils der erste Schreibzugriff, die Reihenfolge ist überall dieselbe. Damit sehe ich zwischen Uploads keinen Deadlock.
- Seltenes Restrisiko: Ein Workspace-Reorder desselben Besitzers in anderer Zeilenreihenfolge kann einen Deadlock auslösen. Die DB erkennt ihn und bricht einen der beiden Requests ab, Daten bleiben konsistent.
- SQLite: Die Transaktion liest zuerst (`find`, beim Duplizieren `bytesOf`) und schreibt dann. Bei parallelen Uploads im WAL-Modus kann das SQLITE_BUSY auslösen: Fehler und Rollback, kein Hängen, kein Überschreiten der Quote.
Verknüpfungen: `index` und `download` gehen über `holder()` → `requireNote(Ziel, read)`. Ohne Leserecht oder bei Ziel im Papierkorb gibt es 404. Upload auf eine Verknüpfung → 400. Korrekt.
Snapshot-Aufbewahrung: Sie richtet sich beim Speichern und im Job nach der Einstellung des Workspace-Besitzers (Team-Workspaces und gelöschte Besitzer: Standard 30). `autoNoteOwnersBefore` ist portables SQL.
Quote gegen den Workspace-Besitzer (Thomas) ist konsistent in SPEC §2.2, ADR 0002 und Code.

Hinweise:
- SQLite, optional: Workspace und Bytes vor der Transaktion lesen, dann `lockOwner` als erste Anweisung.
- SPEC §2.2 schreibt „Sperre der Besitzer-Zeile“, gesperrt werden aber alle Workspace-Zeilen des Besitzers. Nur ungenaue Formulierung.
- Die Tests zu Done M2b.3–5 bleiben eine Gate-Prüfung für den Lead.
