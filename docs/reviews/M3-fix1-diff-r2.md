# M3-fix1 Diff-Review r2

VERDICT: FREIGABE

r1-Blocker 1 geschlossen: Die ID wird nur weiterverwendet, wenn der Web Lock `nextnotes-editor-session:<id>` frei ist; jede lebende Top-Level-App-Seite hält ihn (alle Seiten laden den Bootstrap, PageAssets). Ein kopierter Marker spielt keine Rolle mehr. Gleichzeitiges Laden: `ifAvailable` ist pro Origin atomar, genau eine Seite behält die ID. bfcache: Freigabe bei pagehide, `pageshow.persisted` beansprucht neu, und zwar vor dem pageshow-Handler von note-edit-lock (Bootstrap registriert früher), dessen Lock-POST also wartet bzw. ersetzt wird. Timeout bzw. Fehler → neue ID (sicher; ein spät gewährter, verwaister Lock belegt nur die alte ID und ist damit ebenfalls sicher). Fallback ohne Locks = alter Stand (neue ID je Laden außer Reload), sicher.
r1-Blocker 2 geschlossen: E1 (Tab 1 zuerst, dann Kopie) und E2 (beide gleichzeitig) mit über die Dateien-Seite kopierter ID prüfen „genau einer bearbeitbar“; F prüft Zurück/bfcache.
Gate: Ohne Deadlock, weil das Gate durch den 2-s-Timeout immer auflöst, `sessionGate = null` vor dem verzögerten Neuaufruf gesetzt ist und alle Upstream-Aufrufer die ID je Aufruf aus sessionStorage lesen (note-edit-lock, getCurrentEditorSessionId).

Hinweise (kein Blocker):
- XHR und sendBeacon warten nicht auf das Gate. Upstream sendet die Sitzung nur per fetch bzw. per Beacon im pagehide-Notfall. Kritisch wäre nur ein Beacon bzw. eine Lock-Freigabe mit fremder ID im Millisekundenfenster vor der Entscheidung. Praktisch irrelevant.
- Ein fetch mit `keepalive`, der während des Gates in pagehide ausgelöst wird, geht verloren (wird nach dem Entladen nie gesendet). Das betrifft nur die ersten Millisekunden nach dem Laden bzw. der bfcache-Rückkehr. In dieser Zeit sind keine Eingaben möglich.
- Wirft `navigator.locks.request` synchron (nur exotische Kontexte), wird die ID ohne Lock weiterverwendet. Robuster wäre in diesem Fall eine neue ID.
- `swapSession` ersetzt per split/join alle Vorkommen der alten ID im String-Body und ändert keine Request-Objekte und keine URL-Parameter. Für die heutigen Aufrufer reicht das.
- E prüft nicht die Reihenfolge „Kopie zuerst, Tab 1 danach“ aus r1. Das ist durch den symmetrischen Lock gedeckt; ein dritter Schritt E3 wäre billig.
