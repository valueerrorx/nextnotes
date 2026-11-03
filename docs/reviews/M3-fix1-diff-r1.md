# M3-fix1 Diff-Review r1

VERDICT: BLOCKER

1. Datenverlust-Pfad, neu durch den Fix (nextnotes-bootstrap.js §10, SPEC §4.3/8): Der Marker fehlt, sobald der Tab auf einer gleich-originären Seite ohne Bootstrap steht (andere NC-Apps: Dateien, Dashboard …). Ablauf: Tab 1 Notiz (ID X) → Tab 1 wechselt zu NC-Dateien (pagehide entfernt Marker) → „Tab duplizieren“ (sessionStorage mit X, ohne Marker kopiert) → Tab 2 öffnet Next Notes: kein Marker, behält X, erhält Sperre → Tab 1 „Zurück“ bzw. öffnet Next Notes: kein Marker, behält X → Server meldet `holder_is_current_editor_session` → zwei Tabs bearbeiten mit derselben Sitzung (P7/ADR 0005 verletzt). Der alte Stand (neue ID je Laden) war hier sicher. Mit bfcache (Tab 1 zurück auf eine gecachte Notizseite) gilt dasselbe. Nötig: ein Mechanismus, der eine *aktuell lebende* Seite mit gleicher ID erkennt statt eines kopierbaren Markers, z. B. Web Locks (`navigator.locks.request('nextnotes-editor-'+id, {ifAvailable:true})`, wird bei Entladen und Absturz freigegeben) oder BroadcastChannel-Abfrage „wer hält X?“ vor der Wiederverwendung; sonst neue ID.
2. E2E lock-return.mjs deckt Fall 1 nicht ab: Gegenprobe D kopiert nur aus einer lebenden Notizseite. Fehlender Fall: Kopie (window.open von bzw. Navigation über eine Seite ohne Bootstrap) → beide Tabs öffnen die Notiz → genau einer bearbeitbar.

Hinweise (kein Blocker):
- Reihenfolge pagehide (alte Seite) vs. Bootstrap (neue Seite) ist nur in Chromium per Test belegt; läuft pagehide nach dem Bootstrap (Prozesswechsel), löscht die alte Seite den Marker der neuen. Falls der Marker bleibt: Token je Seite und nur eigenes Token entfernen.
- Tab-Absturz/Sitzungswiederherstellung: Marker bleibt stehen → neue ID → bis Sperrablauf Nur-Lesen. Sicher, nur Komfortverlust; in SPEC nennen.
- Positiv: iframes ausgenommen, Reload/Navigation im selben Tab behält ID, window.open/Duplikat aus lebender Seite neue ID, fremde Benutzer unverändert gesperrt (LockService prüft uid und Sitzung), A–C weisen den Rückkehr-Fall mit spätem Lock nach.
