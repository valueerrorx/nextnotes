# M3b2 – Tester
Auftrag: Slice-Abschluss M3b2 (sqlite+nginx, E2E hell+dunkel; Anhang-Header kurz auf Apache; PHPUnit voll sqlite). Neue E2E attachments-search.mjs aus Proben, Fremdzugriff, stripped KNOWN_MISSING, bestehende Specs + check-upstream.
Status: fertig
## Erledigt
- build rc0; up+install+enable sqlite (8081) rc0, nginx (8084) rc0
- SearchService-Diff: kein neues SQL (nutzt findList/tagsFor) → keine mariadb/pgsql-Läufe
- tests/nextnotes/e2e/lib/docker.mjs: ncContainer/shareRead über veröffentlichten Port + depends_on (robust bei mehreren Umgebungen); editor.mjs nutzt es
- tests/nextnotes/e2e/attachments-search.mjs (A–K inkl. Header B22, Fremdzugriff, Lesefreigabe 403); nginx hell: grün 118 (nach 2 Testkorrekturen: Dateiname serverseitig bereinigt, nosniff doppelt „nosniff, nosniff“)
- stripped nginx hell (vor Fix): grün 113; Kanban nur noch 404 (status+console), kein CSP → KNOWN_MISSING-Text aktualisiert
- Lead: Review-Nacharbeit fertig (neues SQL idsMatchingAll, MAX_TERMS, P1) → bisherige Läufe = vor Fix
- tests/nextnotes/php/Search/PageSearchTest.php (Mehrwort, Phrase, Tags UND, kombiniert ODER, Datum, %/_ wörtlich, >MAX_TERMS, fremd)
- attachments-search.mjs: J Markierung im Baum, L (Mehrwort/% per UI, Listenklick, Aufgabe Enter ohne Navigation)
- PHPUnit voll sqlite (vor Fix): OK 235/235, rc0, 10:00 min
- NACH FIX: build rc0, check-upstream grün; reinstall sqlite/nginx (idsMatchingAll im Container geprüft)
- PageSearchTest+UnifiedSearchTest: sqlite OK 3/118 (nach Testkorrektur: '%' allein trifft wörtlich '50%'), mariadb OK, pgsql OK; mariadb/pgsql down rc0
- nginx alle 7 Specs hell+dunkel: alle grün außer attachments-search hell (Testfehler L: Klick auf in Suche ausgefilterte Notiz → korrigiert); Nachlauf hell grün 137; dunkel grün 137; budget 21 ohne unbekannte Duplikate; stripped 113 je Theme
- sqlite/Apache hell: attachments-search 137 (inkl. Header C) + editor 118 grün
- curl Header: Apache nosniff einfach; nginx doppelt (Webserver add_header + App), harmlos
- down.sh all rc0; nc35-gftest unberührt
## Nächster Schritt
- keiner
## Offen
- nginx: X-Content-Type-Options doppelt (Testsetup-nginx-Konfiguration + App), Info, kein Spec-Verstoß
