# M3-fix4 speccritic
Auftrag: Diff-Review Risikoteil (Sperre, Feature-Policy, speech-to-text-Bündel) gegen SPEC §4.3/B13/B53, ADR 0005/0010/0012.
Erledigt:
- git diff HEAD (Lock, CSP, Bundle, Docs) gelesen
- LockService/LockMapper/NoteService update+beacon/Excalidraw-Save: requireNote write vor assertCanSave unverändert
- Tests LockHeartbeatTest/LockServiceTest gelesen (nicht ausgeführt, keine Docker-Instanz)
- OCP FeaturePolicy + FeaturePolicyManager::mergePolicies geprüft (microphone default leer -> 'none')
- speech-to-text.js: Transkription über speechToText=false gesperrt, keine Route api_transcribe
Ergebnis: FREIGABE, 2 MINOR (SPEC §4.3 Pkt 8 Halbsatz veraltet, B13-Zeile nicht nachgezogen)
Status: fertig
