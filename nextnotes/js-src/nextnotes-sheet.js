/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Tabellenblock (M8b, SPEC §4.7): Rechenraster im Shadow DOM, Engine HyperFormula (lazy, Whitelist, fest deutsch).
// Einhängen, Modus und Speichern macht nextnotes-blocks.js; Tastatur/Eingabe kommen über handleEvent (Isolation R1).
(function () {
	'use strict';

	var ROWS = 50, COLS = 26, TEXT_MAX = 1000, SIZE_MAX = 262144, UNDO_MAX = 50;
	// Rechenbudget (Bereichszellen je Blatt) und Klammertiefe: Rechenzeit < 1 s, kein Stapelüberlauf (M8b-fix)
	var COST_MAX = 200000, DEPTH_MAX = 64, HEAVY = {}, SHEETREF = {}, LIMIT = {};
	// Whitelist ADR 0018 (Funktions-IDs); MODE.SNGL, INTERCEPT (fehlen in HF) und INT (HF schneidet ab statt abzurunden) als eigene Plugins
	var IDS = ['SUM', 'AVERAGE', 'MIN', 'MAX', 'COUNT', 'COUNTA', 'COUNTIF', 'SUMIF', 'ROUND', 'ROUNDUP', 'ROUNDDOWN', 'ABS', 'MOD',
		'SQRT', 'POWER', 'PI', 'EXP', 'LN', 'LOG', 'LOG10', 'SIN', 'COS', 'TAN', 'ASIN', 'ACOS', 'ATAN', 'RADIANS', 'DEGREES', 'IF', 'AND', 'OR', 'NOT',
		'MEDIAN', 'STDEV.S', 'STDEV.P', 'VAR.S', 'VAR.P', 'QUARTILE.INC', 'CORREL', 'SLOPE', 'BINOM.DIST', 'NORM.DIST', 'NORM.INV', 'TODAY', 'TRUE', 'FALSE'];
	var OWN = { 'MODE.SNGL': 'MODUS.EINF', INTERCEPT: 'ACHSENABSCHNITT', INT: 'GANZZAHL' };
	// Geschützte HF-Funktionen bleiben registriert; BEREICH.VERSCHIEBEN bekommt einen Namen mit ' (Lexer-Muster), den code() immer sperrt
	var NO_OFFSET = "'NN_GESPERRT_OFFSET";
	var PROTECTED = ['VERSION', NO_OFFSET];
	var BLOCKED = '=NN.GESPERRT()';
	var CONFIG = {
		licenseKey: 'gpl-v3', language: 'deDE', decimalSeparator: ',', functionArgSeparator: ';', thousandSeparator: '',
		arrayColumnSeparator: ';', arrayRowSeparator: '|', maxRows: ROWS, maxColumns: COLS, useArrayArithmetic: false,
		evaluateNullToZero: true, dateFormats: ['DD.MM.YYYY', 'DD.MM.YY'], currencySymbol: ['€'], undoLimit: 0,
		useWildcards: false, useRegularExpressions: false, // keine Platzhalter in ZÄHLENWENN/SUMMEWENN (ReDoS, Review M8b B1)
	};
	var ERRORS = { DIV_BY_ZERO: 'errDiv0', NAME: 'errName', VALUE: 'errValue', NUM: 'errNum', REF: 'errRef', NA: 'errNa', CYCLE: 'errCycle' };

	// --- Engine (einmalig) ---
	var HF = null, ALLOWED = null;
	function num(v) {
		if (typeof v === 'number') return v;
		return v && typeof v === 'object' && typeof v.val === 'number' ? v.val : null;
	}
	function ownPlugins(ns) {
		var FP = ns.FunctionPlugin, AT = ns.FunctionArgumentType, CE = ns.CellError, ET = ns.ErrorType;
		// MODUS.EINF: häufigster Wert, bei Gleichstand der zuerst vorkommende, ohne Wiederholung #NV (wie Calc/Excel)
		class ModePlugin extends FP {
			modeSngl(ast, state) {
				return this.runFunction(ast.args, state, this.metadata('MODE.SNGL'), (...args) => {
					var values = this.arithmeticHelper.coerceNumbersExactRanges(args);
					if (values instanceof CE) return values;
					var count = new Map(), max = 1, best = null;
					values.forEach(function (v) { count.set(v, (count.get(v) || 0) + 1); max = Math.max(max, count.get(v)); });
					for (var i = 0; i < values.length && best === null; i++) if (max > 1 && count.get(values[i]) === max) best = values[i];
					return best === null ? new CE(ET.NA) : best;
				});
			}
		}
		ModePlugin.implementedFunctions = { 'MODE.SNGL': { method: 'modeSngl', parameters: [{ argumentType: AT.ANY }], repeatLastArgs: 1 } };
		// ACHSENABSCHNITT(Y; X): Regressionsgerade, nur Zahlenpaare (wie STEIGUNG)
		class InterceptPlugin extends FP {
			intercept(ast, state) {
				return this.runFunction(ast.args, state, this.metadata('INTERCEPT'), function (ys, xs) {
					if (ys.numberOfElements() !== xs.numberOfElements()) return new CE(ET.NA);
					var yi = ys.iterateValuesFromTopLeftCorner(), xi = xs.iterateValuesFromTopLeftCorner(), X = [], Y = [], y, x;
					while ((y = yi.next(), x = xi.next(), !y.done && !x.done)) {
						if (y.value instanceof CE) return y.value;
						if (x.value instanceof CE) return x.value;
						if (num(y.value) !== null && num(x.value) !== null) { Y.push(num(y.value)); X.push(num(x.value)); }
					}
					var n = X.length;
					if (n < 2) return new CE(ET.DIV_BY_ZERO);
					var mx = X.reduce(function (a, b) { return a + b; }, 0) / n, my = Y.reduce(function (a, b) { return a + b; }, 0) / n, sxx = 0, sxy = 0;
					for (var i = 0; i < n; i++) { sxx += (X[i] - mx) * (X[i] - mx); sxy += (X[i] - mx) * (Y[i] - my); }
					return sxx === 0 ? new CE(ET.DIV_BY_ZERO) : my - (sxy / sxx) * mx;
				});
			}
		}
		InterceptPlugin.implementedFunctions = { INTERCEPT: { method: 'intercept', parameters: [{ argumentType: AT.RANGE }, { argumentType: AT.RANGE }] } };
		// GANZZAHL rundet ab wie Calc/Excel (HF 3.5.0: GANZZAHL(-3,7) = -3)
		class IntPlugin extends FP {
			intFloor(ast, state) {
				return this.runFunction(ast.args, state, this.metadata('INT'), Math.floor);
			}
		}
		IntPlugin.implementedFunctions = { INT: { method: 'intFloor', parameters: [{ argumentType: AT.NUMBER }] } };
		return { 'MODE.SNGL': ModePlugin, INTERCEPT: InterceptPlugin, INT: IntPlugin };
	}
	// Whitelist per Plugin-Auswahl (M8-Plan §3); Abweichung → Fehler, Blöcke bleiben statisch
	function setup() {
		if (HF) return HF;
		var ns = window.NextNotesHyperFormula;
		if (!ns) throw new Error('HyperFormula missing');
		var H = ns.HyperFormula;
		if (H.getRegisteredLanguagesCodes().indexOf('deDE') === -1) {
			H.registerLanguage('deDE', Object.assign({}, ns.deDE, { functions: Object.assign({}, ns.deDE.functions, { OFFSET: NO_OFFSET }) }));
		}
		var plugins = IDS.map(function (id) {
			var p = H.getFunctionPlugin(id);
			if (!p) throw new Error('HyperFormula: ' + id + ' missing');
			return [id, p];
		});
		H.unregisterAllFunctions();
		plugins.forEach(function (p) { H.registerFunction(p[0], p[1]); });
		var own = ownPlugins(ns);
		Object.keys(OWN).forEach(function (id) {
			var tr = {}, de = {}, en = {};
			de[id] = OWN[id];
			en[id] = id;
			H.getRegisteredLanguagesCodes().forEach(function (code) { tr[code] = code === 'deDE' ? de : en; });
			H.registerFunction(id, own[id], tr);
		});
		var want = IDS.map(function (id) { return ns.deDE.functions[id]; }).concat(Object.values(OWN), PROTECTED).sort().join('|');
		var have = H.getRegisteredFunctionNames('deDE').slice().sort().join('|');
		if (want !== have) throw new Error('HyperFormula whitelist mismatch: ' + have);
		ALLOWED = new Set(IDS.map(function (id) { return ns.deDE.functions[id]; }).concat(Object.values(OWN)));
		HF = ns;
		return HF;
	}

	// --- Daten ---
	function emptyCells(rows, cols) {
		var out = [];
		for (var r = 0; r < rows; r++) out.push(new Array(cols).fill(null));
		return out;
	}
	function blank() {
		return { v: 1, lang: 'deDE', rows: 5, cols: 5, cells: emptyCells(5, 5), fmt: {} };
	}
	// JSON nur in eigene Objekte übernehmen (kein Prototyp-Zugriff); null = unbekannt/ungültig
	function parse(json) {
		var d;
		try { d = JSON.parse(json); } catch (e) { return null; }
		var has = function (o, k) { return o && typeof o === 'object' && Object.prototype.hasOwnProperty.call(o, k); };
		if (!has(d, 'v') || d.v !== 1 || d.lang !== 'deDE') return null;
		var rows = d.rows, cols = d.cols;
		if (!Number.isInteger(rows) || !Number.isInteger(cols) || rows < 1 || rows > ROWS || cols < 1 || cols > COLS || !Array.isArray(d.cells) || d.cells.length !== rows) return null;
		var cells = [];
		for (var r = 0; r < rows; r++) {
			if (!Array.isArray(d.cells[r]) || d.cells[r].length !== cols) return null;
			cells.push(d.cells[r].map(function (c) {
				if (c === null || typeof c === 'boolean' || typeof c === 'string') return c;
				return typeof c === 'number' && isFinite(c) ? c : null;
			}));
		}
		var fmt = {};
		if (has(d, 'fmt') && d.fmt && typeof d.fmt === 'object') {
			Object.keys(d.fmt).forEach(function (k) {
				var a = addrOf(k), f = d.fmt[k];
				if (!a || a.r >= rows || a.c >= cols || !f || typeof f !== 'object') return;
				var g = {};
				if (has(f, 'b') && f.b === 1) g.b = 1;
				if (has(f, 'p') && f.p === 1) g.p = 1;
				if (has(f, 'd') && Number.isInteger(f.d) && f.d >= 0 && f.d <= 4) g.d = f.d;
				if (Object.keys(g).length) fmt[k] = g;
			});
		}
		return { v: 1, lang: 'deDE', rows: rows, cols: cols, cells: cells, fmt: fmt };
	}
	function name(r, c) { return String.fromCharCode(65 + c) + (r + 1); }
	function addrOf(k) {
		var m = /^([A-Z])([1-9][0-9]?)$/.exec(k);
		return m ? { c: m[1].charCodeAt(0) - 65, r: Number(m[2]) - 1 } : null;
	}
	function clone(d) {
		return { v: 1, lang: 'deDE', rows: d.rows, cols: d.cols, cells: d.cells.map(function (row) { return row.slice(); }), fmt: JSON.parse(JSON.stringify(d.fmt)) };
	}
	// Eingabe → gespeicherter Wert: Zahl als JSON-Zahl, WAHR/FALSCH, sonst Text (Formel mit =)
	function fromInput(s) {
		s = String(s).slice(0, TEXT_MAX);
		if (s.trim() === '') return null;
		var t = s.trim();
		if (/^[+-]?(\d+(,\d*)?|,\d+)$/.test(t)) {
			var n = Number(t.replace(',', '.'));
			if (isFinite(n)) return n;
		}
		if (/^wahr$/i.test(t)) return true;
		if (/^falsch$/i.test(t)) return false;
		return s;
	}
	function toInput(v) {
		if (v === null || v === undefined) return '';
		if (typeof v === 'boolean') return v ? 'WAHR' : 'FALSCH';
		if (typeof v === 'number') return String(v).replace('.', ',');
		return v;
	}

	// --- Formel-Prüfung vor der Engine (Review M8b B2, Ressourcen) ---
	// Zeichenkette und Fehlerwert wie der HF-Lexer; Funktionsname = Namenszeichen vor „(“ (Leerraum dazwischen zählt mit)
	var PLAIN = /[^'!"#]+/y, STR = /"([^"\\]*(\\.[^"\\]*)*)"/y, ERR = /#[A-Za-z0-9\/]+[?!]?/y, CALL = /([A-Za-z\u00C0-\u02AF0-9_.]+)\s*\(/g;
	var CELL_RANGE = /\$?([A-Za-z]+)\$?(\d+)\s*:\s*\$?([A-Za-z]+)\$?(\d+)/g, COL_RANGE = /\$?([A-Za-z]+)\s*:\s*\$?([A-Za-z]+)/g, ROW_RANGE = /\$?(\d+)\s*:\s*\$?(\d+)/g;
	// Formel ohne Zeichenketteninhalt, ein Durchlauf von links; null bei ' oder ! außerhalb (Blattbezug, ein Blatt) oder offener Zeichenkette
	function code(f) {
		var out = '', i = 1, m;
		while (i < f.length) {
			var ch = f[i], re = ch === '"' ? STR : ch === '#' ? ERR : PLAIN;
			if (ch === "'" || ch === '!') return null;
			re.lastIndex = i;
			m = re.exec(f);
			if (!m && re === STR) return null;
			out += !m ? ch : re === STR ? '""' : m[0];
			i += m ? m[0].length : 1;
		}
		return out;
	}
	function calls(f) {
		var out = [], c = code(f), m;
		if (c === null) return null;
		CALL.lastIndex = 0;
		while ((m = CALL.exec(c))) out.push(m[1].toUpperCase());
		return out;
	}
	function depth(c) {
		var d = 0, max = 0;
		for (var i = 0; i < c.length; i++) {
			if (c[i] === '(') max = Math.max(max, ++d);
			else if (c[i] === ')') d--;
		}
		return max;
	}
	function col(s) {
		var n = 0;
		for (var i = 0; i < s.length && n <= COLS; i++) n = n * 26 + s.toUpperCase().charCodeAt(i) - 64;
		return Math.min(n, COLS);
	}
	function span(a, b, max) { return Math.min(max, Math.max(a, b)) - Math.min(max, Math.min(a, b)) + 1; }
	// Rechenaufwand: Zellen aller Bereiche (auf die Blattgröße gekappt) + 1 je Formel
	function cost(v) {
		if (typeof v !== 'string' || v[0] !== '=') return 0;
		var n = 1, c = code(v);
		if (c === null) return n;
		c = c.replace(CELL_RANGE, function (x, c1, r1, c2, r2) {
			n += span(col(c1), col(c2), COLS) * span(Number(r1), Number(r2), ROWS);
			return ' ';
		});
		c = c.replace(COL_RANGE, function (x, c1, c2) { n += span(col(c1), col(c2), COLS) * ROWS; return ' '; });
		c.replace(ROW_RANGE, function (x, r1, r2) { n += span(Number(r1), Number(r2), ROWS) * COLS; return ' '; });
		return n;
	}
	function total(rows) {
		return rows.reduce(function (s, row) { return row.reduce(function (t, v) { return t + cost(v); }, s); }, 0);
	}
	// Funktion außerhalb der Whitelist (englische Namen, VERSION, BEREICH.VERSCHIEBEN …), Matrix-Literal {…} (Überlauf)
	// oder Klammertiefe > 64 (Stapel) → #NAME?
	function guard(v) {
		if (typeof v !== 'string' || v[0] !== '=') return v;
		var c = code(v);
		if (c === null || c.indexOf('{') !== -1 || depth(c) > DEPTH_MAX) return BLOCKED;
		return calls(v).every(function (n) { return ALLOWED.has(n); }) ? v : BLOCKED;
	}
	// Laden: zusätzlich Rechenbudget; Formeln jenseits davon → #NAME? (nur bei fremd erzeugten Daten)
	function limited(rows) {
		var sum = 0;
		return rows.map(function (row) {
			return row.map(function (v) {
				sum += cost(v);
				return sum > COST_MAX ? (cost(v) ? BLOCKED : v) : guard(v);
			});
		});
	}
	// Zweite Linie hinter code(): Blatt größer als maxRows/maxColumns (z. B. Bereich über BEREICH.VERSCHIEBEN) → Engine verwerfen
	function fits(hf) {
		var s = hf.getSheetDimensions(0);
		return s.width <= COLS && s.height <= ROWS;
	}
	function engine(d) {
		var hf = setup().HyperFormula.buildFromArray(limited(d.cells), CONFIG);
		if (fits(hf)) return hf;
		hf.destroy();
		throw LIMIT;
	}

	// --- Anzeige ---
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function numText(n, digits) {
		if (digits !== undefined) return n.toFixed(digits).replace('.', ',').replace(/^-(0,?0*)$/, '$1');
		if (n !== 0 && (Math.abs(n) >= 1e15 || Math.abs(n) < 1e-9)) return n.toExponential(9).replace(/\.?0+e/, 'e').replace('.', ',').toUpperCase();
		var s = String(Number(n.toPrecision(15)));
		if (s.indexOf('e') !== -1) s = n.toFixed(10);
		if (s.indexOf('.') !== -1) s = String(Number(Number(s).toFixed(10)));
		return s.replace('.', ',');
	}
	// Ergebnis einer Zelle: {text, cls, err}
	function display(hf, r, c, f, txt) {
		var addr = { sheet: 0, row: r, col: c };
		var v = hf.getCellValue(addr);
		if (v === null || v === undefined) return { text: '', cls: '' };
		if (typeof v === 'object' && v.type && v.value !== undefined && !(typeof v.val === 'number')) {
			return { text: String(v.value), cls: 'nn-err', err: txt[ERRORS[v.type]] || txt.errError || v.value };
		}
		if (typeof v === 'boolean') return { text: v ? 'WAHR' : 'FALSCH', cls: 'nn-num' };
		if (typeof v === 'string') return { text: v, cls: '' };
		var type = hf.getCellValueDetailedType(addr);
		f = f || {};
		if (f.p || (type === 'NUMBER_PERCENT' && f.d === undefined)) return { text: numText(v * 100, f.d) + ' %', cls: 'nn-num' };
		if (f.d === undefined && (type === 'NUMBER_DATE' || type === 'NUMBER_DATETIME' || type === 'NUMBER_TIME')) {
			var parts = [];
			if (type !== 'NUMBER_TIME') {
				var dt = hf.numberToDate(v);
				parts.push(pad(dt.day) + '.' + pad(dt.month) + '.' + dt.year);
			}
			if (type !== 'NUMBER_DATE') {
				var tm = hf.numberToTime(v - Math.floor(v));
				parts.push(pad(tm.hours) + ':' + pad(tm.minutes));
			}
			return { text: parts.join(' '), cls: 'nn-num' };
		}
		return { text: numText(v, f.d), cls: 'nn-num' };
	}
	// Statischer Fallback (Light DOM): volle Rastergröße, Ergebnisse formatiert, nur Text
	function fallback(d, hf, txt) {
		var table = document.createElement('table');
		table.className = 'nn-sheet-table';
		var body = table.appendChild(document.createElement('tbody'));
		for (var r = 0; r < d.rows; r++) {
			var tr = body.appendChild(document.createElement('tr'));
			for (var c = 0; c < d.cols; c++) {
				var td = tr.appendChild(document.createElement('td'));
				var out = hf ? display(hf, r, c, d.fmt[name(r, c)], txt) : { text: '', cls: '' };
				if (out.cls) td.className = out.cls;
				if (out.text === '') continue;
				if ((d.fmt[name(r, c)] || {}).b) td.appendChild(document.createElement('strong')).textContent = out.text;
				else td.textContent = out.text;
			}
		}
		return table;
	}

	// TSV: Anführungszeichen wie Calc/Excel
	function parseTsv(s) {
		var rows = [[]], cell = '', q = false;
		s = String(s).replace(/\r\n?/g, '\n').replace(/\n$/, '');
		for (var i = 0; i < s.length; i++) {
			var ch = s[i];
			if (q) {
				if (ch === '"' && s[i + 1] === '"') { cell += '"'; i++; } else if (ch === '"') q = false; else cell += ch;
			} else if (ch === '"' && cell === '') q = true;
			else if (ch === '\t') { rows[rows.length - 1].push(cell); cell = ''; }
			else if (ch === '\n') { rows[rows.length - 1].push(cell); rows.push([]); cell = ''; }
			else cell += ch;
		}
		rows[rows.length - 1].push(cell);
		return rows;
	}
	function tsvCell(s) {
		return /[\t\n"]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
	}

	// --- Raster ---
	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text !== undefined) n.textContent = text;
		return n;
	}
	function btn(cls, text, title) {
		var b = el('button', 'nn-sheet-btn ' + cls, text);
		b.type = 'button';
		if (title) { b.title = title; b.setAttribute('aria-label', title); }
		return b;
	}

	// ctx: {txt, data (JSON), commit(json, table)}
	function mount(box, ctx) {
		var t = ctx.txt || {};
		var d = parse(ctx.data), hf = null;
		if (d) {
			try { hf = engine(d); } catch (e) { if (e !== LIMIT) throw e; }
		}
		if (!hf) {
			box.append(el('p', 'nn-sheet-note', d ? t.sheetLimit || 'This table exceeds the size limit and can only be viewed.' : t.sheetVersion || 'This table can only be viewed.'), document.createElement('slot'));
			return null;
		}
		var frozen = null;
		var undo = [], redo = [];
		var sel = { r: 0, c: 0, ar: 0, ac: 0 };
		var editing = null; // {via: 'cell'|'bar'}
		var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

		var ui = el('div', 'nn-sheet-ui');
		var bar = el('div', 'nn-sheet-bar');
		var addr = el('span', 'nn-sheet-addr', 'A1');
		var fx = el('input', 'nn-sheet-fx');
		fx.type = 'text';
		fx.maxLength = TEXT_MAX;
		fx.setAttribute('aria-label', t.sheetFormulaBar || 'Cell content');
		fx.spellcheck = false;
		var tools = el('div', 'nn-sheet-tools');
		var bBold = btn('nn-sheet-bold', 'F', t.sheetBold || 'Bold');
		var bLess = btn('nn-sheet-less', '−,0', t.sheetDecLess || 'Fewer decimal places');
		var bMore = btn('nn-sheet-more', '+,0', t.sheetDecMore || 'More decimal places');
		var bPct = btn('nn-sheet-pct', '%', t.sheetPercent || 'Percent');
		var bRow = btn('nn-sheet-addrow', '+ ' + (t.sheetRow || 'Row'), t.sheetAddRow || 'Add row');
		var bCol = btn('nn-sheet-addcol', '+ ' + (t.sheetCol || 'Column'), t.sheetAddCol || 'Add column');
		var bMenu = btn('nn-sheet-menu-btn', '⋯', t.sheetMore || 'More actions');
		bMenu.setAttribute('aria-haspopup', 'menu');
		tools.append(bBold, bLess, bMore, bPct, bRow, bCol, bMenu);
		bar.append(addr, fx, tools);
		var scroll = el('div', 'nn-sheet-scroll');
		var grid = el('table', 'nn-sheet-grid');
		grid.setAttribute('role', 'grid');
		grid.setAttribute('aria-label', t.sheet || 'Calculation table');
		var editor = el('input', 'nn-sheet-edit');
		editor.type = 'text';
		editor.maxLength = TEXT_MAX;
		editor.spellcheck = false;
		editor.hidden = true;
		// Fokus im Navigationsmodus: Textfeld empfängt Tippen, Kopieren, Einfügen (auf Touch ohne Tastatur)
		var sink = el('textarea', 'nn-sheet-sink');
		sink.setAttribute('aria-roledescription', t.sheet || 'Calculation table');
		sink.spellcheck = false;
		if (coarse) sink.setAttribute('inputmode', 'none');
		var msg = el('div', 'nn-sheet-msg');
		msg.setAttribute('aria-live', 'polite');
		var menu = null;
		scroll.append(grid, editor, sink);
		ui.append(bar, scroll, msg);
		box.appendChild(ui);
		// Zeiger/Klicks nicht an Upstream-Handler im Editor (Ziel wäre der Host)
		['click', 'dblclick', 'mousedown', 'mouseup', 'pointerdown', 'pointerup', 'contextmenu', 'dragstart', 'drop', 'focusin', 'focusout'].forEach(function (type) {
			box.addEventListener(type, function (e) { e.stopPropagation(); });
		});

		function cell(r, c) { return grid.tBodies[0] && grid.tBodies[0].rows[r] ? grid.tBodies[0].rows[r].cells[c + 1] : null; }
		function range() {
			return { r1: Math.min(sel.r, sel.ar), r2: Math.max(sel.r, sel.ar), c1: Math.min(sel.c, sel.ac), c2: Math.max(sel.c, sel.ac) };
		}
		function build() {
			grid.replaceChildren();
			var head = grid.createTHead().insertRow();
			head.appendChild(el('th', 'nn-sheet-corner'));
			for (var c = 0; c < d.cols; c++) head.appendChild(el('th', 'nn-sheet-col', String.fromCharCode(65 + c))).setAttribute('role', 'columnheader');
			var body = grid.createTBody();
			for (var r = 0; r < d.rows; r++) {
				var tr = body.insertRow();
				tr.setAttribute('role', 'row');
				tr.appendChild(el('th', 'nn-sheet-rownum', String(r + 1))).setAttribute('role', 'rowheader');
				for (var k = 0; k < d.cols; k++) {
					var td = tr.appendChild(document.createElement('td'));
					td.setAttribute('role', 'gridcell');
					td.dataset.r = r;
					td.dataset.c = k;
				}
			}
			paint();
		}
		function paint() {
			for (var r = 0; r < d.rows; r++) {
				for (var c = 0; c < d.cols; c++) {
					var td = cell(r, c), f = d.fmt[name(r, c)] || {};
					var out = display(hf, r, c, f, t);
					td.textContent = out.text;
					td.className = (out.cls || '') + (f.b ? ' nn-bold' : '');
					if (out.err) td.title = out.err; else td.removeAttribute('title');
				}
			}
			mark();
		}
		function mark() {
			var g = range();
			for (var r = 0; r < d.rows; r++) {
				for (var c = 0; c < d.cols; c++) {
					var td = cell(r, c), inside = r >= g.r1 && r <= g.r2 && c >= g.c1 && c <= g.c2;
					td.classList.toggle('is-sel', inside && (g.r1 !== g.r2 || g.c1 !== g.c2));
					td.classList.toggle('is-active', r === sel.r && c === sel.c);
					td.setAttribute('aria-selected', inside ? 'true' : 'false');
				}
			}
			var a = name(sel.r, sel.c);
			addr.textContent = g.r1 === g.r2 && g.c1 === g.c2 ? a : name(g.r1, g.c1) + ':' + name(g.r2, g.c2);
			if (!editing || editing.via !== 'bar') fx.value = toInput(d.cells[sel.r][sel.c]);
			var shown = cell(sel.r, sel.c);
			sink.setAttribute('aria-label', a + ': ' + (shown ? shown.textContent : ''));
			place(sink, shown);
			var f = d.fmt[a] || {};
			bBold.setAttribute('aria-pressed', f.b ? 'true' : 'false');
			bPct.setAttribute('aria-pressed', f.p ? 'true' : 'false');
			bRow.disabled = d.rows >= ROWS;
			bCol.disabled = d.cols >= COLS;
		}
		function place(node, td) {
			if (!td) return;
			node.style.top = td.offsetTop + 'px';
			node.style.left = td.offsetLeft + 'px';
			node.style.width = td.offsetWidth + 'px';
			node.style.height = td.offsetHeight + 'px';
		}
		// Hinweis in der Meldungszeile des Blocks (aria-live)
		function say(text) {
			msg.textContent = text || '';
		}
		// Engine über der Grenze: verwerfen, Block statisch mit Hinweis; Light DOM behält den letzten Fallback
		function kill() {
			var h = box.getRootNode().host, last = h && h.querySelector('table');
			frozen = last ? last.cloneNode(true) : fallback(d, null, t);
			editing = null;
			closeMenu();
			if (hf) hf.destroy();
			hf = null;
			box.replaceChildren(el('p', 'nn-sheet-note', t.sheetLimit || 'This table exceeds the size limit and can only be viewed.'), document.createElement('slot'));
		}

		// Änderung: Schnappschuss, ausführen, Größe prüfen, speichern
		function change(fn, keepRedo) {
			if (!hf) return false;
			var before = clone(d), heavy = false, ref = false;
			try { fn(); } catch (e) {
				if (e === LIMIT) { kill(); return false; }
				if (e === SHEETREF) ref = true; else if (e === HEAVY) heavy = true; else throw e;
			}
			if (!ref && !fits(hf)) { kill(); return false; }
			// Bezugsverschiebung (Zeile/Spalte) kann Bereiche vergrößern und Formeln verlängern
			heavy = heavy || (total(d.cells) > COST_MAX && total(d.cells) > total(before.cells));
			var json = JSON.stringify(d);
			// Server entfernt das Attribut bei Zelltext > 1000 Zeichen
			var long = d.cells.some(function (row) { return row.some(function (v) { return typeof v === 'string' && v.length > TEXT_MAX; }); });
			if (ref || heavy || long || new TextEncoder().encode(json).length > SIZE_MAX) {
				restore(before);
				say(ref ? t.sheetRef || 'Apostrophes and exclamation marks are only allowed inside closed quotation marks. The change was not applied.'
					: heavy ? t.sheetHeavy || 'The table contains too many cell references. The change was not applied.'
					: long ? t.sheetCellLong || 'A cell would exceed 1,000 characters. The change was not applied.'
						: t.sheetTooBig || 'The table is too large. The change was not applied.');
				return false;
			}
			undo.push(before);
			if (undo.length > UNDO_MAX) undo.shift();
			if (!keepRedo) redo = [];
			ctx.commit(json, fallback(d, hf, t));
			return true;
		}
		function restore(snap) {
			d = clone(snap);
			if (hf) hf.destroy();
			hf = null;
			try { hf = engine(d); } catch (e) { if (e !== LIMIT) throw e; kill(); return; }
			sel.r = Math.min(sel.r, d.rows - 1);
			sel.c = Math.min(sel.c, d.cols - 1);
			sel.ar = sel.r;
			sel.ac = sel.c;
			build();
		}
		function history(from, to) {
			if (!from.length) return;
			var snap = from.pop();
			to.push(clone(d));
			restore(snap);
			if (hf) ctx.commit(JSON.stringify(d), fallback(d, hf, t));
		}
		// Rechenbudget vor der Engine prüfen (sonst rechnet sie erst und friert ein); Abbruch über change()
		function setCells(r0, c0, values) {
			// Formel mit ' oder ! außerhalb einer Zeichenkette: Bearbeiten ablehnen (beim Laden #NAME?)
			if (values.some(function (row) { return row.some(function (v) { return typeof v === 'string' && v[0] === '=' && code(v) === null; }); })) throw SHEETREF;
			var was = total(d.cells);
			for (var i = 0; i < values.length; i++) {
				for (var j = 0; j < values[i].length; j++) d.cells[r0 + i][c0 + j] = values[i][j];
			}
			var now = total(d.cells);
			if (now > COST_MAX && now > was) throw HEAVY;
			hf.setCellContents({ sheet: 0, row: r0, col: c0 }, values.map(function (row) { return row.map(guard); }));
			if (!fits(hf)) throw LIMIT;
			paint();
		}

		// Zeilen/Spalten: Engine verschiebt Bezüge, Formeln zurücklesen, Format mitschieben
		function structure(kind, at, delta) {
			change(function () {
				if (kind === 'row') {
					if (delta > 0) { hf.addRows(0, [at, 1]); d.cells.splice(at, 0, new Array(d.cols).fill(null)); d.rows++; }
					else { hf.removeRows(0, [at, 1]); d.cells.splice(at, 1); d.rows--; }
				} else if (delta > 0) {
					hf.addColumns(0, [at, 1]); d.cells.forEach(function (row) { row.splice(at, 0, null); }); d.cols++;
				} else {
					hf.removeColumns(0, [at, 1]); d.cells.forEach(function (row) { row.splice(at, 1); }); d.cols--;
				}
				var fmt = {};
				Object.keys(d.fmt).forEach(function (k) {
					var a = addrOf(k), r = a.r, c = a.c;
					if (kind === 'row' && r >= at) { if (delta < 0 && r === at) return; r += delta; }
					if (kind === 'col' && c >= at) { if (delta < 0 && c === at) return; c += delta; }
					fmt[name(r, c)] = d.fmt[k];
				});
				d.fmt = fmt;
				for (var r = 0; r < d.rows; r++) {
					for (var c = 0; c < d.cols; c++) {
						var pos = { sheet: 0, row: r, col: c }, f = hf.doesCellHaveFormula(pos) ? hf.getCellFormula(pos) : null;
						// gesperrte Formel behält den eigenen Text (Bezüge darin verschieben sich nicht)
						if (f !== null && f !== BLOCKED) d.cells[r][c] = f;
					}
				}
				sel.r = Math.min(sel.r, d.rows - 1);
				sel.c = Math.min(sel.c, d.cols - 1);
				sel.ar = sel.r;
				sel.ac = sel.c;
				build();
			});
		}
		function addRow(at) { if (d.rows < ROWS) structure('row', at, 1); }
		function addCol(at) { if (d.cols < COLS) structure('col', at, 1); }
		function delRow() { if (d.rows > 1) structure('row', sel.r, -1); }
		function delCol() { if (d.cols > 1) structure('col', sel.c, -1); }

		function eachSelected(fn) {
			var g = range();
			for (var r = g.r1; r <= g.r2; r++) for (var c = g.c1; c <= g.c2; c++) fn(name(r, c));
		}
		function format(key, fn) {
			change(function () {
				eachSelected(function (a) {
					var f = Object.assign({}, d.fmt[a]);
					fn(f);
					Object.keys(f).forEach(function (k) { if (f[k] === undefined || (k !== 'd' && !f[k])) delete f[k]; });
					if (Object.keys(f).length) d.fmt[a] = f; else delete d.fmt[a];
				});
				paint();
			});
		}
		var active = function () { return d.fmt[name(sel.r, sel.c)] || {}; };
		function toggleBold() { var on = !active().b; format('b', function (f) { f.b = on ? 1 : 0; }); }
		function togglePct() { var on = !active().p; format('p', function (f) { f.p = on ? 1 : 0; }); }
		function decimals(step) {
			var cur = active().d;
			if (cur === undefined) {
				var v = hf.getCellValue({ sheet: 0, row: sel.r, col: sel.c });
				var s = typeof v === 'number' ? numText(v) : '';
				cur = s.indexOf(',') === -1 ? 0 : Math.min(4, s.length - s.indexOf(',') - 1);
			}
			var next = Math.max(0, Math.min(4, cur + step));
			format('d', function (f) { f.d = next; });
		}
		function clearRange() {
			var g = range(), values = [];
			for (var r = g.r1; r <= g.r2; r++) values.push(new Array(g.c2 - g.c1 + 1).fill(null));
			change(function () { setCells(g.r1, g.c1, values); });
		}

		// Auswahl
		function select(r, c, extend) {
			sel.r = Math.max(0, Math.min(d.rows - 1, r));
			sel.c = Math.max(0, Math.min(d.cols - 1, c));
			if (!extend) { sel.ar = sel.r; sel.ac = sel.c; }
			mark();
			var td = cell(sel.r, sel.c);
			if (td && td.scrollIntoView) td.scrollIntoView({ block: 'nearest', inline: 'nearest' });
		}
		function focusGrid() {
			sink.value = '';
			sink.focus({ preventScroll: true });
		}

		// Bearbeiten in der Zelle oder (Touch) in der Formelzeile
		function startEdit(text, via) {
			via = via || (coarse ? 'bar' : 'cell');
			editing = { via: via, from: toInput(d.cells[sel.r][sel.c]) };
			if (via === 'bar') {
				if (text !== undefined) fx.value = text;
				fx.focus();
				return;
			}
			editor.hidden = false;
			place(editor, cell(sel.r, sel.c));
			editor.value = text !== undefined ? text : editing.from;
			editor.focus({ preventScroll: true });
			editor.setSelectionRange(editor.value.length, editor.value.length);
		}
		function endEdit(apply) {
			if (!editing) return;
			var via = editing.via;
			var text = via === 'bar' ? fx.value : editor.value;
			var from = editing.from;
			editing = null;
			editor.hidden = true;
			if (apply && text !== from) {
				if (String(text).length > TEXT_MAX) say(t.sheetTextLong || 'Cell text was shortened.');
				var r = sel.r, c = sel.c;
				change(function () { setCells(r, c, [[fromInput(text)]]); });
			} else {
				fx.value = toInput(d.cells[sel.r][sel.c]);
			}
		}

		// Zwischenablage als TSV
		function copyText() {
			var g = range(), lines = [];
			for (var r = g.r1; r <= g.r2; r++) {
				var row = [];
				for (var c = g.c1; c <= g.c2; c++) row.push(tsvCell(toInput(d.cells[r][c])));
				lines.push(row.join('\t'));
			}
			return lines.join('\n');
		}
		function paste(text) {
			var rows = parseTsv(text);
			var r0 = sel.r, c0 = sel.c, width = Math.max.apply(null, rows.map(function (x) { return x.length; }));
			var needR = Math.min(ROWS, r0 + rows.length), needC = Math.min(COLS, c0 + width);
			var cut = r0 + rows.length > ROWS || c0 + width > COLS, long = false;
			var values = [];
			for (var i = 0; i < needR - r0; i++) {
				var row = [];
				for (var j = 0; j < needC - c0; j++) {
					var s = rows[i][j] === undefined ? '' : rows[i][j];
					if (s.length > TEXT_MAX) long = true;
					row.push(fromInput(s));
				}
				values.push(row);
			}
			var ok = change(function () {
				if (needR > d.rows) { hf.addRows(0, [d.rows, needR - d.rows]); while (d.rows < needR) { d.cells.push(new Array(d.cols).fill(null)); d.rows++; } }
				if (needC > d.cols) { hf.addColumns(0, [d.cols, needC - d.cols]); d.cells.forEach(function (row) { while (row.length < needC) row.push(null); }); d.cols = needC; }
				build();
				setCells(r0, c0, values);
				sel.ar = r0 + values.length - 1;
				sel.ac = c0 + values[0].length - 1;
				mark();
			});
			if (ok && cut) say(t.sheetCut || 'Pasted data was cut to 50 rows × 26 columns.');
			else if (ok && long) say(t.sheetTextLong || 'Cell text was shortened.');
		}

		// Menü (Zeilen/Spalten); auch Kontextmenü
		function closeMenu() { if (menu) { menu.remove(); menu = null; } }
		function openMenu(x, y) {
			closeMenu();
			menu = el('div', 'nn-sheet-menu');
			menu.setAttribute('role', 'menu');
			[
				[t.sheetRowAbove || 'Insert row above', function () { addRow(sel.r); }, d.rows >= ROWS],
				[t.sheetRowBelow || 'Insert row below', function () { addRow(sel.r + 1); }, d.rows >= ROWS],
				[t.sheetColLeft || 'Insert column left', function () { addCol(sel.c); }, d.cols >= COLS],
				[t.sheetColRight || 'Insert column right', function () { addCol(sel.c + 1); }, d.cols >= COLS],
				[t.sheetDelRow || 'Delete row', delRow, d.rows <= 1],
				[t.sheetDelCol || 'Delete column', delCol, d.cols <= 1],
				[t.sheetBold || 'Bold', toggleBold, false, 'nn-sheet-narrow'],
				[t.sheetDecLess || 'Fewer decimal places', function () { decimals(-1); }, false, 'nn-sheet-narrow'],
				[t.sheetDecMore || 'More decimal places', function () { decimals(1); }, false, 'nn-sheet-narrow'],
				[t.sheetPercent || 'Percent', togglePct, false, 'nn-sheet-narrow'],
				[t.sheetAddRow || 'Add row', function () { addRow(d.rows); }, d.rows >= ROWS, 'nn-sheet-narrow'],
				[t.sheetAddCol || 'Add column', function () { addCol(d.cols); }, d.cols >= COLS, 'nn-sheet-narrow'],
			].forEach(function (it) {
				var b = btn('nn-sheet-menu-item ' + (it[3] || ''), it[0]);
				b.setAttribute('role', 'menuitem');
				b.disabled = !!it[2];
				b.addEventListener('click', function () { closeMenu(); it[1](); focusGrid(); });
				menu.appendChild(b);
			});
			ui.appendChild(menu);
			var box2 = ui.getBoundingClientRect();
			menu.style.left = Math.max(0, Math.min(x - box2.left, ui.clientWidth - menu.offsetWidth)) + 'px';
			menu.style.top = (y - box2.top) + 'px';
			menu.querySelector('button:not([disabled])').focus();
		}

		// Zeiger
		var dragging = false, downActive = false;
		grid.addEventListener('pointerdown', function (e) {
			var td = e.target.closest('td');
			if (!td || e.button > 0) return;
			e.preventDefault();
			if (editing) endEdit(true);
			closeMenu();
			downActive = td.classList.contains('is-active') && !e.shiftKey;
			select(Number(td.dataset.r), Number(td.dataset.c), e.shiftKey);
			dragging = !coarse;
			focusGrid();
		});
		grid.addEventListener('pointermove', function (e) {
			if (!dragging || !(e.buttons & 1)) return;
			var td = e.composedPath()[0].closest && e.composedPath()[0].closest('td');
			if (td && grid.contains(td)) select(Number(td.dataset.r), Number(td.dataset.c), true);
		});
		var stopDrag = function () { dragging = false; };
		window.addEventListener('pointerup', stopDrag);
		// Touch: zweites Antippen bearbeitet (Formelzeile); Maus: Doppelklick
		grid.addEventListener('click', function (e) {
			if (coarse && downActive && e.target.closest('td')) startEdit();
			downActive = false;
		});
		grid.addEventListener('dblclick', function (e) {
			if (!coarse && e.target.closest('td')) startEdit();
		});
		grid.addEventListener('contextmenu', function (e) {
			var td = e.target.closest('td');
			if (!td) return;
			e.preventDefault();
			if (!td.classList.contains('is-sel')) select(Number(td.dataset.r), Number(td.dataset.c), false);
			openMenu(e.clientX, e.clientY);
		});
		bMenu.addEventListener('click', function () {
			if (menu) { closeMenu(); return; }
			var b = bMenu.getBoundingClientRect();
			openMenu(b.left, b.bottom + 4);
		});
		[[bBold, toggleBold], [bPct, togglePct], [bLess, function () { decimals(-1); }], [bMore, function () { decimals(1); }],
			[bRow, function () { addRow(d.rows); }], [bCol, function () { addCol(d.cols); }]].forEach(function (p) {
			p[0].addEventListener('mousedown', function (e) { e.preventDefault(); });
			p[0].addEventListener('click', function () { if (editing) endEdit(true); p[1](); focusGrid(); });
		});
		fx.addEventListener('focus', function () { if (!editing) editing = { via: 'bar', from: toInput(d.cells[sel.r][sel.c]) }; });
		fx.addEventListener('blur', function () { if (editing && editing.via === 'bar') endEdit(true); });
		editor.addEventListener('blur', function () { if (editing && editing.via === 'cell') endEdit(true); });
		ui.addEventListener('pointerdown', function (e) { if (menu && !menu.contains(e.target) && e.target !== bMenu) closeMenu(); });

		// Tastatur und Eingabe (über nextnotes-blocks.js, Ereignis erreicht sonst niemanden)
		function onKey(e, target) {
			var k = e.key, mod = e.ctrlKey || e.metaKey;
			if (menu && k === 'Escape') { e.preventDefault(); closeMenu(); focusGrid(); return; }
			if (target === editor || target === fx) {
				if (k === 'Enter' || k === 'Tab') {
					e.preventDefault();
					endEdit(true);
					if (k === 'Enter') select(sel.r + (e.shiftKey ? -1 : 1), sel.c); else select(sel.r, sel.c + (e.shiftKey ? -1 : 1));
					focusGrid();
				} else if (k === 'Escape') {
					e.preventDefault();
					endEdit(false);
					focusGrid();
				}
				return;
			}
			if (target !== sink || e.isComposing) return;
			var move = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] }[k];
			if (move && !mod) { e.preventDefault(); select(sel.r + move[0], sel.c + move[1], e.shiftKey); return; }
			if (k === 'Enter' && coarse) { e.preventDefault(); startEdit(undefined, 'bar'); return; }
			if (k === 'Enter') { e.preventDefault(); select(sel.r + (e.shiftKey ? -1 : 1), sel.c); return; }
			if (k === 'Tab') { e.preventDefault(); select(sel.r, sel.c + (e.shiftKey ? -1 : 1)); return; }
			if (k === 'F2') { e.preventDefault(); startEdit(); return; }
			if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); clearRange(); return; }
			if (mod && !e.altKey) {
				var lk = k.toLowerCase();
				if (lk === 'z' && !e.shiftKey) { e.preventDefault(); history(undo, redo); }
				else if (lk === 'y' || (lk === 'z' && e.shiftKey)) { e.preventDefault(); history(redo, undo); }
				else if (lk === 'b') { e.preventDefault(); toggleBold(); }
				else if (lk === 'a') { e.preventDefault(); sel.ar = 0; sel.ac = 0; select(d.rows - 1, d.cols - 1, true); }
			}
		}
		function handleEvent(e) {
			if (!hf) return;
			var target = e.composedPath()[0];
			if (e.type === 'keydown') { onKey(e, target); return; }
			if (target !== sink) return;
			if (e.type === 'input' && !e.isComposing) {
				var text = sink.value;
				sink.value = '';
				if (text) startEdit(text);
			} else if (e.type === 'copy' || e.type === 'cut') {
				e.preventDefault();
				e.clipboardData.setData('text/plain', copyText());
				if (e.type === 'cut') clearRange();
			} else if (e.type === 'paste') {
				e.preventDefault();
				paste(e.clipboardData.getData('text/plain'));
			}
		}

		build();
		return {
			handleEvent: handleEvent,
			focus: function () { if (hf) { mark(); focusGrid(); } },
			fallback: function () { return hf ? fallback(d, hf, t) : frozen.cloneNode(true); },
			destroy: function () {
				window.removeEventListener('pointerup', stopDrag);
				if (hf) hf.destroy();
				hf = null;
			},
		};
	}

	// Werte ohne Raster (Tests, Referenzblatt): Matrix aus Rohwerten bzw. {error}
	function evaluate(json) {
		var d = parse(json), hf, out = [];
		if (!d) return null;
		try { hf = engine(d); } catch (e) { if (e === LIMIT) return null; throw e; }
		for (var r = 0; r < d.rows; r++) {
			var row = [];
			for (var c = 0; c < d.cols; c++) {
				var v = hf.getCellValue({ sheet: 0, row: r, col: c });
				row.push(v && typeof v === 'object' && v.type && !(typeof v.val === 'number') ? { error: v.value } : num(v) !== null ? num(v) : v);
			}
			out.push(row);
		}
		hf.destroy();
		return out;
	}

	// Neuer Block (Vertrag v1): Attributreihenfolge wie der Sanitizer (class, data-nn-sheet, contenteditable)
	function host() {
		var d = blank(), h = document.createElement('div');
		h.className = 'nn-sheet';
		h.setAttribute('data-nn-sheet', JSON.stringify(d));
		h.setAttribute('contenteditable', 'false');
		h.appendChild(fallback(d, null));
		return h;
	}

	window.NextNotesSheet = {
		host: host, parse: parse, mount: mount, evaluate: evaluate, fallback: fallback, setup: setup,
		// erreichbare Namen (Whitelist); registeredNames = alles, was HF kennt (inkl. geschützter)
		functionNames: function () { setup(); return Array.from(ALLOWED).sort(); },
		registeredNames: function () { return setup().HyperFormula.getRegisteredFunctionNames('deDE').slice().sort(); },
		calls: calls,
	};
})();
