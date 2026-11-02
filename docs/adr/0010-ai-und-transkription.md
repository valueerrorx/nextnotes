# ADR 0010: AI-Assistent und Transkription

Status: entschieden (Thomas, 2026-11-02): wie vorgeschlagen angenommen.

## Kontext
Upstream proxyt AI-Chat (`api_ai_chat.php`, OpenAI-kompatibel, mit Werkzeugen, die Notizen lesen/ändern: `lib/ai-tools.php`) und Transkription (`api_transcribe.php`) an konfigurierbare Server; Verläufe in `ai_conversations` (T8). Einstellungen A #253/#254, B #51–54. Nextcloud bietet `OCP\TaskProcessing\IManager` (@since 30; `getAvailableTaskTypes` 30, Parameter `userId` 31.0.7, `isInternal` 33.0.0; `scheduleTask`/`runTask` 30) mit Typen `TextToTextChat`, `TextToText`, `AudioToText` (@since 30). Ausgeführt wird von Providern (z. B. Assistant/LocalAI), die der Admin wählt.

## Optionen
1. **Beides in v1 streichen**, UI ausblenden.
2. Transkription über `AudioToText`, AI-Chat streichen.
3. Beides über TaskProcessing (Chat ohne Upstream-Werkzeuge oder mit nachgebauten Werkzeugen).

## Entscheidung
v1: Option 1. Danach (v1.x) Option 2, dann Chat ohne Schreibwerkzeuge.
Begründung: Der Chat-Werkzeugteil greift tief in Notizen ein (Rechte, Sperren, Abgaben-Status); Schülerdaten an externe Modelle brauchen eine Datenschutzentscheidung der Schule. Transkription ist technisch klein (Audio-Anhang → `scheduleTask`, Ergebnis in Notiz) und kann folgen, wenn ein lokaler Provider verfügbar ist (`getAvailableTaskTypeIds()` enthält `core:audio2text`).

## Konsequenzen
- Inventurzeilen (vormals ADR-offen) → streichen (v1, umgestellt im M0-Nachtrag): A #206, #207, #253, #254, T8; B #51–54, #76, #77, 2.1 #16, #17.
- Adapter blendet AI-/Mikrofon-Schaltflächen per CSS/`__POZNOTE_HIDDEN_UI_ELEMENTS__` aus; kein Upstream-Edit.
