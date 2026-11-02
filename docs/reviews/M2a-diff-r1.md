VERDICT: BLOCKER

Geprüft: Arbeitsbaum M2a (nextnotes/lib, appinfo/routes.php, l10n, img, dev/nextcloud, dev/upstream, tests/nextnotes) gegen M2-plan r2 (§1, M2a), SPEC §2/§3/§4/§11.2/§13 M2/§14. `src/` unverändert. OCP-Stichprobe (35.0.2): `Middleware::afterException(Controller, string, Exception)`, `IDBConnection::insertIgnoreConflict` (16), `escapeLikeParameter` (9), `IFactory::getUserLanguage` (20), `IUserManager::getDisplayName` (25), `NotModifiedMiddleware` (ETag/304) stimmen. Gepinnte Image-Tags haben dieselben Image-IDs wie die alten Tags, Versionen stimmen (docker inspect).

Rechte: Alle 53 Routen laden über `requireNote`/`requireFolder`/`requireWorkspace`/`resolveKey`. Body- und Query-IDs (`workspace`, `folder_id`, `parent_id`, `new_parent_folder_id`, `target_workspace`, `source/target_folder_id`, `note_id`/`target_note_id`, `linked_note_id`, `folders[].parent_id`, `notes[].id` in `/folders/restore`) sind geprüft, fremde IDs ergeben 404. Listen sind auf `visibleWorkspaceIds` beschränkt, `role=note` steht fest im Mapper. Workspace-Wechsel nur mit `manage` auf der Quelle (B17). Die Middleware wirft fremde Ausnahmen weiter. Kein `NoCSRFRequired`/`PublicPage`. SQL nur über QueryBuilder, kein Fang von DB-Fehlern in Transaktionen, Tags per `insertIgnoreConflict`.

## Blocker
1. `lib/Service/WorkspaceService.php` `delete()` (Abweichung 1): Notizen und Ordner wandern in den alphabetisch ersten eigenen Workspace. Das kann ein geteilter Workspace oder „Archives“ sein. Folge: Inhalte (auch von Gästen verfasste) werden ohne Zutun des Besitzers einem anderen Personenkreis sichtbar. Der Plan r2 sah eine Kaskade vor. Forderung: Ziel nur ein eigener `kind=normal` ohne `ws_shares` und nicht „Archives“; gibt es keinen, wird ein neuer Standard-Workspace angelegt. Alternativ Kaskade in den Papierkorb, Entscheidung durch Thomas (Datenschutz). Die gewählte Regel in SPEC §11.2 eintragen.

## Abweichungen
1. WS löschen → verschieben: nicht akzeptabel in dieser Form (Blocker 1).
2. Ordner löschen → Papierkorb + `/folders/restore`: akzeptabel. Restore prüft Workspace (write), Eltern-IDs und Notiz-IDs gegen den Ziel-Workspace, alles in einer Transaktion.
3. `GET /notes/{id}` ohne `attachments`: akzeptabel. Die Upstream-Fixture hat das Feld nicht. Plan §M2a („`attachments`=[]“) nachziehen.
4. Zusätzliches `message` bei Workspaces/Tags: akzeptabel (Obermenge, Upstream nutzt `message`).
5. Markup → 400: akzeptabel, aber in §11.2 aufnehmen (B16 deckt nur `icon_color`). `tagName`/`workspaceTags` lehnen auch `'` ab. Das bricht Tags wie „l'été“ (fr/it). Prüfen, ob `'` erlaubt werden kann (Frontend escapt?).
6. B17: akzeptabel und konsistent umgesetzt (Notiz-Update, `moveToFolder`, `folders/move`, `move-files`, Archiv).
7. Testgerüst vom Architekten: akzeptabel, aber der Tester muss Szenario und Fixture-Herkunft von `capture.php` selbst nachvollziehen. `tests/nextnotes/php/` ist leer, Done 2–9 sind noch unbelegt.
8. `docker tag`: akzeptabel (IDs identisch, Versionen geprüft).
9. `{id}` nur `\d+`: akzeptabel, das Frontend nutzt `/notes/resolve?reference=`. Toter Zweig in `NoteService::show()` (nicht-numerische ID) kann weg.
10. Lokalisierter Standardname: akzeptabel, entspricht SPEC §2.1 („in Benutzersprache“).

## Hinweise
- H1 `HtmlSanitizer::cleanAttributes`: Ein Schema mit Steuerzeichen (`java&#9;script:`) wird nicht erkannt (wie Upstream; CSP von NC mildert). Für `href`/`src` vor der Prüfung Whitespace/Steuerzeichen entfernen, als B-Eintrag führen.
- H2 Für M6 vormerken: `NoteMapper::moveFolderNotesToWorkspace`/`moveWorkspaceNotes` filtern nicht nach `role`, und Ordner-Löschen prüft `assignment_id` nur am Wurzelordner, nicht im Teilbaum.
- H3 `openedNoteIds()` hat noch keinen Aufrufer. M2c (`/settings/recently_opened_notes`) muss darüber filtern (Done 9).
- H4 `NoteService::beacon` schreibt auch Notizen im Papierkorb (`trashed=null`). Falls nicht gewollt, `false` setzen.
