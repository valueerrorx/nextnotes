/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Rechenblock (M8c, SPEC §4.7.10): Zeilen links, Ergebnisse rechts, optional Graph; Rechenkern NextNotesMath (lazy, eingeschränkt),
// Graph function-plot (lazy, nur fn als JS-Funktion). Einhängen, Modus und Speichern macht nextnotes-blocks.js.
// Leser: nur Graph (Fallback bleibt per <slot> sichtbar), Zeilen bis zur letzten Funktion unter Budget, keine Neuberechnung der Anzeige.
(function () {
	'use strict';

	var SRC_MAX = 20000, LINES_MAX = 200, LINE_MAX = 500, JSON_MAX = 65536, FNS_MAX = 5, DELAY = 300;

	function parse(json) {
		var d;
		try { d = JSON.parse(json); } catch (e) { return null; }
		if (!d || d.v !== 1 || typeof d.src !== 'string' || (d.graph !== 0 && d.graph !== 1)) return null;
		return { src: d.src, graph: d.graph };
	}
	function stringify(d) { return JSON.stringify({ v: 1, src: d.src, graph: d.graph }); }
	// Grenzen wie der Server (BlockMarkup::validCalc): Zeichen = Codepunkte, JSON in Bytes
	function fits(d) {
		var lines = d.src.split('\n');
		return Array.from(d.src).length <= SRC_MAX && lines.length <= LINES_MAX
			&& lines.every(function (l) { return Array.from(l).length <= LINE_MAX; })
			&& new TextEncoder().encode(stringify(d)).length <= JSON_MAX;
	}

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text !== undefined) n.textContent = text;
		return n;
	}

	// Anzeige: Dezimalkomma, ∞, nicht ganzzahlige Zahl zusätzlich als Bruch (Nenner ≤ 1 000, Abweichung < 1e-12)
	function numText(s) { return s.replace(/\./g, ',').replace(/Infinity/g, '∞'); }
	function fraction(v) {
		if (!isFinite(v) || Number.isInteger(v)) return '';
		for (var q = 1; q <= 1000; q++) {
			var p = Math.round(v * q);
			if (Math.abs(v - p / q) < 1e-12) return q === 1 ? '' : p + '/' + q;
		}
		return '';
	}
	var ERR = {
		syntax: ['calcErrSyntax', 'Input not readable'], comma: ['calcErrComma', 'Separate arguments with ;'], string: ['calcErrString', 'Text in quotation marks is not allowed'],
		unknown: ['calcErrUnknown', 'Unknown name'], notAllowed: ['calcErrNotAllowed', 'Not allowed'], blocked: ['calcErrNotAllowed', 'Not allowed'],
		reserved: ['calcErrReserved', 'Name is reserved'], defined: ['calcErrDefined', 'Name is already defined'], heavy: ['calcErrHeavy', 'Too much computation'],
		big: ['calcErrBig', 'Number too large (at most 10,000)'], long: ['calcErrLong', 'Line too long (at most 500 characters)'], args: ['calcErrArgs', 'Wrong number of arguments'],
		type: ['calcErrType', 'Result cannot be shown'],
	};
	// Zeile → {text, err}
	function output(line, t) {
		if (line.res) {
			var r = line.res;
			if (r.kind === 'bool') return { text: r.value ? t.calcTrue || 'true' : t.calcFalse || 'false' };
			var s = numText(r.text), f = r.kind === 'num' ? fraction(r.value) : '';
			return { text: f ? s + ' = ' + f : s };
		}
		if (line.err) {
			var e = ERR[line.err.code];
			var msg = e ? t[e[0]] || e[1] : line.err.arg;
			if (e && line.err.arg && /^(unknown|notAllowed|blocked|reserved|defined|args)$/.test(line.err.code)) msg += ': ' + line.err.arg;
			return { text: (t.calcError || 'Error') + ': ' + msg, err: true };
		}
		if (line.skip) return { text: t.calcSkipped || 'not calculated' };
		return { text: '' };
	}
	function graphFns(res) {
		return res.fns.filter(function (f) { return f.params.length === 1; }).slice(0, FNS_MAX);
	}
	function label(fns) {
		return fns.map(function (f) { return f.name + '(' + f.params[0] + ')'; }).join(', ');
	}

	// Statischer Fallback (gespeichert): je Quelltextzeile ein Absatz, Graph als Hinweiszeile
	function fallback(d, res, t) {
		var frag = document.createDocumentFragment();
		var src = d.src.split('\n');
		src.forEach(function (text, i) {
			var o = res && res.lines[i] ? output(res.lines[i], t) : { text: '' };
			var p = el('p', 'nn-calc-line');
			p.append(el('span', 'nn-calc-in', text), ' ', el('span', 'nn-calc-out' + (o.err ? ' nn-err' : ''), o.text));
			frag.appendChild(p);
		});
		var fns = res ? graphFns(res) : [];
		if (d.graph === 1 && fns.length) frag.appendChild(el('p', 'nn-calc-graph', (t.calcGraphLabel || 'Graph') + ': ' + label(fns)));
		return frag;
	}

	// Graph in zwei Rechenschritten der Warteschlange (je eigene Task): Vorprüfung, dann Zeichnen mit einem Laufbudget für alle
	// Funktionen; Abbruch → Graph weg, Hinweis, keine weiteren Läufe. Über dem Seitenbudget: Knopf zeichnet. Neuer Aufruf verwirft den alten.
	var drawing = new WeakMap();
	function draw(box, fns, t, q, user) {
		var token = {};
		drawing.set(box, token);
		box.replaceChildren();
		if (!fns.length) return;
		var cur = function () { return drawing.get(box) === token; };
		var note = function (key, text) { if (cur()) box.replaceChildren(el('p', 'nn-calc-note', t[key] || text)); };
		var heavy = function () { note('calcGraphHeavy', 'The graph is too complex to draw.'); };
		var over = function () {
			if (!cur()) return;
			var b = el('button', 'nn-calc-btn nn-calc-later', t.calcGraphDeferred || 'Graph not drawn. Click to draw.');
			b.type = 'button';
			b.addEventListener('click', function () { draw(box, fns, t, q, true); });
			box.replaceChildren(b);
		};
		var calls = null;
		var paint = function () {
			if (!cur()) return;
			var target = el('div', 'nn-calc-chart');
			box.replaceChildren(target, el('p', 'nn-calc-legend', label(fns)));
			var width = Math.max(240, Math.min(600, box.clientWidth || 480));
			// Nur fn als Funktion: Text-Ausdrücke kompiliert function-plot per new Function (CSP, M8-Plan R7)
			try {
				window.NextNotesFunctionPlot.functionPlot({
					target: target, width: width, height: Math.round(width * 0.6), grid: true, xAxis: { domain: [-10, 10] },
					tip: { xLine: false, yLine: false },
					data: calls.map(function (fn) { return { fn: fn, graphType: 'polyline', sampler: 'builtIn', nSamples: 300 }; }),
				});
			} catch (e) {
				// Schutz: function-plot 1.26.0 wirft bei entarteten Daten (z. B. nur ±Infinity, plotFn liefert dafür NaN)
				heavy();
			}
		};
		var probe = function () {
			if (!cur()) return;
			calls = window.NextNotesMath.plotFn(fns, heavy);
			if (!calls) heavy(); else q(paint, over, user);
		};
		var fail = function (err) {
			console.error('nextnotes: Graph', err);
			note('calcLoadError', 'The calculation block could not be loaded.');
		};
		// geladen (Einhängen lädt vorab): sofort einreihen, sonst nach dem Laden
		if (window.NextNotesFunctionPlot) q(probe, over, user);
		else window.poznoteEnsureFunctionPlot().then(function () { q(probe, over, user); }, fail);
	}

	// Lesend: nur Graph (gespeicherter Text gilt als fremd: Prüfung und Budget im Rechenkern)
	function view(box, d, t, q) {
		if (d && d.graph === 1) {
			var plot = el('div', 'nn-calc-plot');
			box.appendChild(plot);
			draw(plot, graphFns(window.NextNotesMath.run(d.src, { fnsOnly: true })), t, q, false);
		}
		return { handleEvent: function () {}, focus: function () {}, fallback: function () { return null; }, destroy: function () {} };
	}

	// ctx: {txt, data (JSON), mode ('edit'|'view'), commit(json, fragment), queue(run, over, user)}
	function mount(box, ctx) {
		var t = ctx.txt || {};
		var d = parse(ctx.data);
		var q = ctx.queue || function (run) { run(); };
		if (ctx.mode !== 'edit') return view(box, d, t, q);
		if (!d) {
			box.append(el('p', 'nn-calc-note', t.calcVersion || 'This calculation block can only be viewed.'), document.createElement('slot'));
			return null;
		}
		var res = null, timer = 0, saved = stringify(d), plotKey = null, commitAfter = false, user = false;

		var ui = el('div', 'nn-calc-ui');
		var bar = el('div', 'nn-calc-bar');
		var title = el('span', 'nn-calc-title', t.calc || 'Calculation');
		var bGraph = el('button', 'nn-calc-btn nn-calc-toggle');
		bGraph.type = 'button';
		bar.append(title, bGraph);
		var body = el('div', 'nn-calc-body');
		var ta = el('textarea', 'nn-calc-src');
		ta.spellcheck = false;
		ta.wrap = 'off';
		ta.setAttribute('autocapitalize', 'off');
		ta.setAttribute('autocomplete', 'off');
		ta.setAttribute('aria-label', t.calcInput || 'Calculation, one per line');
		ta.placeholder = t.calcPlaceholder || 'e.g. a = 3';
		ta.value = d.src;
		var outs = el('div', 'nn-calc-res');
		outs.setAttribute('aria-live', 'polite');
		body.append(ta, outs);
		var msg = el('div', 'nn-calc-msg');
		msg.setAttribute('aria-live', 'polite');
		var plot = el('div', 'nn-calc-plot');
		ui.append(bar, body, msg, plot);
		box.appendChild(ui);
		['click', 'dblclick', 'mousedown', 'mouseup', 'pointerdown', 'pointerup', 'contextmenu', 'dragstart', 'drop', 'focusin', 'focusout', 'wheel'].forEach(function (type) {
			box.addEventListener(type, function (e) { e.stopPropagation(); });
		});

		function current() { return { src: ta.value.replace(/\r\n?/g, '\n'), graph: d.graph }; }
		function resize() {
			ta.rows = Math.max(2, ta.value.split('\n').length);
		}
		function evaluate() {
			clearTimeout(timer);
			timer = 0;
			d = current();
			msg.textContent = fits(d) ? '' : t.calcTooLong || 'Too long: at most 200 lines, 500 characters per line and 20,000 characters in total. Not saved.';
			res = window.NextNotesMath.run(d.src);
			outs.replaceChildren();
			d.src.split('\n').forEach(function (_, i) {
				var o = res.lines[i] ? output(res.lines[i], t) : { text: '' };
				var row = el('div', 'nn-calc-out' + (o.err ? ' nn-err' : ''), o.text);
				if (o.text) row.title = o.text;
				outs.appendChild(row);
			});
			var fns = graphFns(res);
			bGraph.hidden = !fns.length;
			bGraph.textContent = d.graph ? t.calcGraphHide || 'Hide graph' : t.calcGraphShow || 'Show graph';
			// Graph nur neu, wenn sich die Zeilen bis zur letzten Funktion geändert haben
			var key = d.graph && fns.length ? d.src.split('\n').slice(0, lastFn(res) + 1).join('\n') : '';
			if (key !== plotKey) {
				plotKey = key;
				if (key) draw(plot, fns, t, q, user); else draw(plot, [], t, q, user);
			}
			if (commitAfter) { commitAfter = false; commit(); }
		}
		function lastFn(r) {
			for (var i = r.lines.length - 1; i >= 0; i--) if (r.lines[i].fn) return i;
			return -1;
		}
		function schedule() {
			clearTimeout(timer);
			timer = setTimeout(evaluate, DELAY);
		}
		function commit() {
			if (timer) evaluate();
			var json = stringify(d);
			if (json === saved || !fits(d)) return;
			saved = json;
			ctx.commit(json, fallback(d, res, t));
		}
		bGraph.addEventListener('click', function () {
			if (timer) evaluate();
			d.graph = d.graph ? 0 : 1;
			plotKey = null;
			evaluate();
			commit();
		});
		ta.addEventListener('blur', commit);

		// Tastatur und Eingabe kommen über nextnotes-blocks.js (Capture am window hält sie von Upstream fern)
		function handleEvent(e) {
			if (e.composedPath()[0] !== ta) return;
			if (e.type === 'input') { resize(); schedule(); }
			else if (e.type === 'keydown' && e.key === 'Enter' && !e.isComposing) commitAfter = true;
			else if (e.type === 'keydown' && e.key === 'Escape') ta.blur();
		}

		resize();
		evaluate();
		user = true; // weitere Auswertungen nur nach Eingabe oder Klick
		return {
			handleEvent: handleEvent,
			focus: function () { ta.focus(); },
			fallback: function () { return fallback(d, res, t); },
			destroy: function () { clearTimeout(timer); },
		};
	}

	// Neuer Block (Vertrag v1): Attributreihenfolge wie der Sanitizer (class, data-nn-calc, contenteditable)
	function host() {
		var d = { src: '', graph: 1 }, h = document.createElement('div');
		h.className = 'nn-calc';
		h.setAttribute('data-nn-calc', stringify(d));
		h.setAttribute('contenteditable', 'false');
		h.appendChild(fallback(d, null, {}));
		return h;
	}

	window.NextNotesCalc = { host: host, parse: parse, mount: mount, fallback: fallback, output: output, fits: fits };
})();
