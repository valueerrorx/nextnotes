// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Graph im Rechenblock (M8c): function-plot 1.26.0 (MIT, d3) als Global NextNotesFunctionPlot.functionPlot.
// Nur mit fn als JS-Funktion nutzen (NextNotesMath.plotFn); Text-Ausdrücke kompiliert math-codegen per new Function (CSP).
import fp from 'function-plot';

// CommonJS-Paket: der Standard-Import liefert je nach Interop die Funktion oder das exports-Objekt
const functionPlot = typeof fp === 'function' ? fp : fp.default;
export { functionPlot };
