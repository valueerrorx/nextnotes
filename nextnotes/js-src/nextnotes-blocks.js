/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Blöcke in Richtext-Notizen (SPEC §4.7, M8-Plan §1): Einträge im Slash-Menü (Hook P38) und im Werkzeugleisten-Menü,
// Einfügen, Modus (schreibbar/lesend), Speichern über das input-Ereignis. Block-UIs laden lazy (nextnotes-formula/-sheet/-calc.js).
// Hosts werden am Attribut erkannt (data-math, data-nn-sheet, data-nn-calc), nie an der Klasse. Laufzeit-UI der Blöcke im Shadow DOM (M8-Plan §1.1).
(function () {
	'use strict';

	var cfg = (window.NEXTNOTES && window.NEXTNOTES.config && window.NEXTNOTES.config.blocks) || {};
	var txt = cfg.txt || {};
	function tr(key, fallback) { return String(txt[key] || fallback); }

	var HOST = '[data-math]';
	var MATH_MAX = 10000;
	// Einträge: a = Block-Attribut (Shadow-DOM-Block), sonst Formel (b = als Block)
	var ITEMS = [
		{ id: 'nn-formula', icon: 'lucide-sigma', label: tr('formula', 'Formula') },
		{ id: 'nn-formula-block', icon: 'lucide-sigma', label: tr('formulaBlock', 'Formula block'), b: true },
		{ id: 'nn-sheet', icon: 'lucide-table', label: tr('sheet', 'Calculation table'), a: 'data-nn-sheet' },
		{ id: 'nn-calc', icon: 'lucide-calculator', label: tr('calc', 'Calculation'), a: 'data-nn-calc' },
	];
	function run(item, entry, range) {
		if (item.a) insertBlock(entry, range, item.a); else insertFormula(entry, range, !!item.b);
	}
	// Blöcke mit Laufzeit-UI im Shadow DOM: Attribut → Name (Modul js/nextnotes-<n>.js, Text <n>LoadError), Bibliothek, Global;
	// view: auch lesend einhängen (Graph); plot: Graph-Bibliothek vorab, wenn view und fn (Funktionsdefinition) passen.
	// fn: Namenszeichen wie math.js parse.isAlpha (Latein, Griechisch, Buchstabensymbole, mathematische Alphanumerik)
	var BLOCKS = {
		'data-nn-sheet': { n: 'sheet', lib: 'poznoteEnsureHyperFormula', api: 'NextNotesSheet' },
		'data-nn-calc': { n: 'calc', lib: 'poznoteEnsureMathjs', api: 'NextNotesCalc', view: /"graph":1[,}]/, plot: 'poznoteEnsureFunctionPlot', fn: /[\w$À-ʯͰ-Ͽ℀-⅏\uDC00-\uDFFF]\s*\([^()]*\)\s*=(?!=)/ },
	};
	function script(def) { return window.poznoteEnsureScript('js/nextnotes-' + def.n + '.js'); }
	var BLOCK_SELECTOR = Object.keys(BLOCKS).map(function (a) { return '[' + a + ']'; }).join(',');
	// Upstream-Meldung (ui.js); ohne sie bleibt nur die Konsole, Tabellen-Hinweise stehen im Block
	function notify(text, type) {
		if (typeof window.showNotificationPopup === 'function') window.showNotificationPopup(text, type);
		else console.warn('nextnotes:', text);
	}

	// --- Editor und Modus ---
	function entryOf(node) {
		var el = node && (node.nodeType === 1 ? node : node.parentElement);
		return el ? el.closest('.noteentry') : null;
	}
	function isRich(entry) {
		return !!entry && entry.getAttribute('data-note-type') === 'note';
	}
	// schreibbar ⇔ editierbar, nicht nur lesend (Template), keine fremde Sperre (note-edit-lock.js)
	function writable(entry) {
		return isRich(entry) && entry.isContentEditable && !entry.hasAttribute('data-nextnotes-readonly')
			&& !(typeof window.isNoteEditingLocked === 'function' && window.isNoteEditingLocked(entry.getAttribute('data-note-id')));
	}

	// letzte Einfügemarke in einer schreibbaren Notiz (Werkzeugleiste nimmt den Fokus)
	var lastRange = null;
	document.addEventListener('selectionchange', function () {
		var sel = window.getSelection();
		if (!sel || sel.rangeCount === 0) return;
		var r = sel.getRangeAt(0);
		var entry = entryOf(r.startContainer);
		if (entry && writable(entry)) lastRange = r.cloneRange();
	});
	function currentRange(entry) {
		var sel = window.getSelection();
		if (sel && sel.rangeCount > 0 && entry.contains(sel.getRangeAt(0).startContainer)) return sel.getRangeAt(0).cloneRange();
		if (lastRange && entry.contains(lastRange.startContainer)) return lastRange.cloneRange();
		var r = document.createRange();
		r.selectNodeContents(entry);
		r.collapse(false);
		return r;
	}
	function activeEntry() {
		var sel = window.getSelection();
		var entry = sel && sel.rangeCount > 0 ? entryOf(sel.getRangeAt(0).startContainer) : null;
		if (!entry && lastRange) entry = entryOf(lastRange.startContainer);
		if (!entry) entry = document.querySelector('#right_col .noteentry');
		return writable(entry) ? entry : null;
	}

	function changed(entry) {
		entry.dispatchEvent(new Event('input', { bubbles: true }));
	}
	function caretAfter(node) {
		var sel = window.getSelection();
		if (!sel || !node.parentNode) return;
		var r = document.createRange();
		r.setStartAfter(node);
		r.collapse(true);
		sel.removeAllRanges();
		sel.addRange(r);
	}

	// Kind von entry, das node enthält
	function topOf(entry, node) {
		var n = node && node.nodeType === 1 ? node : node && node.parentNode;
		while (n && n.parentNode !== entry) n = n.parentNode;
		return n && n.parentNode === entry ? n : null;
	}
	function emptyBlock(el) {
		return !el.textContent.replace(/​/g, '').trim() && !el.querySelector('img, iframe, video, audio, [data-math], ' + BLOCK_SELECTOR + ', table, hr');
	}
	function newParagraph() {
		var p = document.createElement('p');
		p.appendChild(document.createElement('br'));
		return p;
	}

	// Block auf oberster Ebene: Absatz an der Marke teilen, in Liste/Tabelle/Callout dahinter einfügen
	function placeBlock(entry, range, block) {
		var top = topOf(entry, range.startContainer);
		if (!top) {
			range.insertNode(block);
		} else if (/^(P|H[1-6])$/.test(top.tagName) || (top.tagName === 'DIV' && !top.className && !top.hasAttribute('data-math') && !blockAttr(top))) {
			var tail = document.createRange();
			tail.setStart(range.startContainer, range.startOffset);
			tail.setEnd(top, top.childNodes.length);
			var rest = top.cloneNode(false);
			rest.appendChild(tail.extractContents());
			top.after(block);
			if (!emptyBlock(rest)) block.after(rest);
			if (emptyBlock(top)) top.remove();
		} else {
			top.after(block);
		}
		if (!block.nextElementSibling) block.after(newParagraph());
	}

	function hostFor(block, latex) {
		var host = document.createElement(block ? 'div' : 'span');
		host.className = block ? 'math-block' : 'math-inline';
		host.setAttribute('contenteditable', 'false');
		host.setAttribute('data-math', latex);
		host.textContent = latex;
		return host;
	}

	function insertFormula(entry, range, block) {
		range = range || currentRange(entry);
		var host = hostFor(block, '');
		if (block) {
			placeBlock(entry, range, host);
		} else {
			range.deleteContents();
			range.insertNode(host);
			// Marke hinter einer Formel am Zeilenende braucht einen Textknoten
			if (!host.nextSibling || host.nextSibling.nodeType !== 3) host.after(document.createTextNode('​'));
		}
		editFormula(entry, host, true);
	}

	// Übernehmen: Vertrag schreiben, KaTeX neu, Speichern; leer = Löschen; Inline↔Block tauscht den Host
	function commitFormula(entry, host, latex, block) {
		latex = String(latex || '').trim();
		// Server-Grenze (SPEC §4.7): längere Formel würde als Text gespeichert
		if (Array.from(latex).length > MATH_MAX) {
			notify(txt.formulaTooLong, 'error');
			if (!host.getAttribute('data-math')) host.remove();
			return;
		}
		if (!latex) {
			var prev = host.previousSibling;
			host.remove();
			if (prev && prev.parentNode) caretAfter(prev);
			changed(entry);
			return;
		}
		var target = host;
		if (block !== (host.tagName === 'DIV')) {
			target = hostFor(block, latex);
			if (block) {
				var r = document.createRange();
				r.setStartBefore(host);
				host.remove();
				placeBlock(entry, r, target);
			} else {
				var p = document.createElement('p');
				p.appendChild(target);
				host.replaceWith(p);
			}
		}
		target.setAttribute('data-math', latex);
		target.textContent = latex;
		if (typeof window.renderMathInElement === 'function') window.renderMathInElement(target);
		caretAfter(target);
		changed(entry);
	}

	// --- Popover (lazy) ---
	var openState = null;
	function editFormula(entry, host, isNew) {
		if (openState) return;
		var state = openState = { entry: entry, host: host, isNew: isNew };
		var fail = function (err) {
			console.error('nextnotes: Formeleditor', err);
			if (openState === state) openState = null;
			if (isNew) host.remove();
			notify(tr('loadError', 'The formula editor could not be loaded.'), 'error');
		};
		Promise.all([window.poznoteEnsureScript('js/nextnotes-formula.js'), window.poznoteEnsureMathLive()]).then(function () {
			if (openState !== state) return null;
			return window.NextNotesFormula.open({ latex: host.getAttribute('data-math') || '', block: host.tagName === 'DIV', anchor: host, txt: txt, isNew: isNew });
		}).then(function (result) {
			if (openState !== state || !result) return;
			openState = null;
			if (result.action === 'apply') commitFormula(entry, host, result.latex, result.block);
			else if (result.action === 'delete' || isNew) commitFormula(entry, host, '', false);
			else caretAfter(host);
		}, fail);
	}
	// Sperre verloren / nur lesend: offenes Popover schließen; neue/entfernte Hosts und Moduswechsel: Blöcke neu einhängen
	new MutationObserver(function () {
		if (openState && !writable(openState.entry) && window.NextNotesFormula) window.NextNotesFormula.close();
		scheduleScan();
	}).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['contenteditable', 'data-nextnotes-readonly'] });

	// --- Blöcke mit Shadow DOM (M8-Plan §1.1) ---
	// Light DOM = gespeicherter Vertrag (JSON-Attribut + statischer Fallback); UI nur im Shadow Root, nie im Inhalt.
	// schreibbar: Modul + Bibliothek lazy, UI ohne <slot>; sonst nur <slot> (Fallback sichtbar), keine Bibliothek.
	// Rechenwarteschlange (M8c-fix): jeder Rechenschritt eines Blocks (Einhängen, Vorprüfung, Zeichnen) in einer eigenen Task.
	// Folgeschritte (eingereiht während eines Schritts) laufen vor dem nächsten Block, damit fertige Blöcke vollständig sind.
	// Automatische Schritte teilen sich ein Seitenbudget (PAGE_MS); darüber zeigt der Block over() (Klick rechnet).
	// Das Budget gilt je angezeigter Notiz (budgetKey = Note-IDs, scan) und beginnt nur bei Notizwechsel oder Klick neu,
	// nicht bei leerer Schlange, Sperr-/Moduswechsel oder spät geladener Bibliothek. user: vom Nutzer ausgelöst, immer.
	var PAGE_MS = 1000;
	var jobs = [], next = null, spent = 0, pumping = false, budgetKey = null;
	function enqueue(job) {
		if (next) { next.push(job); return; }
		jobs.push(job);
		if (!pumping) { pumping = true; setTimeout(pump, 0); }
	}
	function pump() {
		var job = jobs.shift();
		if (!job) { pumping = false; return; }
		var t = performance.now();
		next = [];
		try {
			if (job.user || spent < PAGE_MS) job.run(); else job.over();
		} catch (e) {
			console.error('nextnotes: Block', e);
		}
		jobs = next.concat(jobs);
		next = null;
		spent += performance.now() - t;
		setTimeout(pump, 0);
	}

	var live = new Map();
	var fresh = new WeakSet(); // gerade eingefügt: nach dem Einhängen fokussieren
	var scanQueued = false;
	function scheduleScan() {
		if (scanQueued) return;
		scanQueued = true;
		Promise.resolve().then(function () { scanQueued = false; scan(); });
	}
	function blockAttr(host) {
		for (var a in BLOCKS) if (host.hasAttribute(a)) return a;
		return null;
	}
	function scan() {
		// Notizwechsel (andere Note-IDs im DOM) → neues Budget; kurz ohne Notiz (Neuladen) zählt nicht
		var ids = [];
		document.querySelectorAll('.noteentry').forEach(function (entry) { ids.push(String(entry.getAttribute('data-note-id'))); });
		var key = ids.sort().join(',');
		if (key && key !== budgetKey) { budgetKey = key; spent = 0; }
		live.forEach(function (rec, host) {
			if (!host.isConnected || !blockAttr(host) || entryOf(host) !== rec.entry) unmount(host);
		});
		document.querySelectorAll('.noteentry').forEach(function (entry) {
			if (!isRich(entry)) return;
			entry.querySelectorAll(BLOCK_SELECTOR).forEach(function (host) {
				// Host im Fallback eines anderen Hosts entfernt der Server; im Client nie einhängen
				if (host.parentElement.closest(BLOCK_SELECTOR)) return;
				var mode = writable(entry) ? 'edit' : 'view';
				var rec = live.get(host);
				if (rec && rec.mode === mode) return;
				if (rec) unmount(host);
				mount(host, entry, mode);
			});
		});
	}
	function unmount(host) {
		var rec = live.get(host);
		if (!rec) return;
		live.delete(host);
		if (rec.obs) rec.obs.disconnect();
		if (rec.inst) { try { rec.inst.destroy(); } catch (e) { console.error('nextnotes: Block', e); } }
		if (host.shadowRoot) host.shadowRoot.replaceChildren(document.createElement('slot'));
	}
	function mount(host, entry, mode) {
		var attr = blockAttr(host), def = BLOCKS[attr];
		var rec = { entry: entry, mode: mode, inst: null, obs: null, focus: fresh.has(host), user: fresh.has(host) };
		live.set(host, rec);
		fresh.delete(host);
		var edit = mode === 'edit';
		var json = host.getAttribute(attr);
		var plot = !!(def.view && def.view.test(json));
		if (!edit && !plot) {
			if (host.shadowRoot) host.shadowRoot.replaceChildren(document.createElement('slot'));
			return;
		}
		var fail = function (err) {
			console.error('nextnotes: Block', err);
			if (live.get(host) !== rec) return;
			// aufräumen, Eintrag bleibt (kein neuer Versuch bis zum Moduswechsel)
			unmount(host);
			live.set(host, rec);
			rec.inst = rec.obs = null;
			notify(txt[def.n + 'LoadError'], 'error');
		};
		// Bibliotheken vorab (auch die des Graphen), damit alle Rechenschritte ohne Netz in der Schlange liegen
		Promise.all([script(def), window[def.lib](), plot && def.plot && def.fn.test(json) ? window[def.plot]() : null]).then(function () {
			if (live.get(host) !== rec) return;
			enqueue({
				user: rec.user,
				run: function () { if (live.get(host) === rec) { try { attach(host, rec, def, attr); } catch (e) { fail(e); } } },
				over: function () { if (live.get(host) === rec) deferred(host, rec, def, attr, fail); },
			});
		}).catch(fail);
	}
	function stylesheet() {
		var link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = window.poznoteAssetUrl('css/nextnotes-blocks-shadow.css');
		return link;
	}
	// Über dem Seitenbudget: Fallback bleibt, Knopf rechnet diesen Block (vom Nutzer) und gibt der Seite neues Budget
	function deferred(host, rec, def, attr, fail) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'nn-block-deferred';
		b.textContent = tr('blockDeferred', 'Not calculated. Click to calculate.');
		['mousedown', 'pointerdown', 'dblclick'].forEach(function (type) { b.addEventListener(type, function (e) { e.stopPropagation(); }); });
		b.addEventListener('click', function (e) {
			e.stopPropagation();
			b.disabled = true;
			rec.user = true;
			spent = 0;
			enqueue({ user: true, run: function () { if (live.get(host) === rec) { try { attach(host, rec, def, attr); } catch (err) { fail(err); } } }, over: function () {} });
		});
		var root = host.shadowRoot || host.attachShadow({ mode: 'open' });
		root.replaceChildren(stylesheet(), document.createElement('slot'), b);
	}
	function attach(host, rec, def, attr) {
		var edit = rec.mode === 'edit', entry = rec.entry;
		// Erst das Modul (wirft es, bleibt der Host ohne Shadow Root bzw. mit <slot>: Fallback sichtbar)
		var box = document.createElement('div');
		box.className = 'nn-block-ui';
		box.hidden = true;
		rec.inst = window[def.api].mount(box, {
			txt: txt,
			mode: rec.mode,
			data: host.getAttribute(attr),
			commit: function (json, fallback) { write(host, rec, json, fallback); changed(entry); },
			// Rechenschritt in eigener Task: run(), über dem Seitenbudget over(); user = vom Nutzer ausgelöst
			queue: function (run, over, user) {
				enqueue({ user: !!user || rec.user, run: function () { if (live.get(host) === rec) run(); }, over: function () { if (live.get(host) === rec) over(); } });
			},
		});
		var root = host.shadowRoot || host.attachShadow({ mode: 'open' });
		// Bis das Stylesheet geladen ist, bleibt der Fallback sichtbar (<slot>), die UI verborgen; lesend bleibt er immer
		var link = stylesheet();
		var slot = document.createElement('slot');
		root.replaceChildren(link, slot, box);
		link.addEventListener('load', function () {
			if (edit) slot.remove();
			box.hidden = false;
			if (rec.focus && rec.inst) rec.inst.focus();
		});
		// Fremdänderung am Light DOM (Suchen/Ersetzen, Markierung): Fallback aus dem Block neu, anderes JSON → neu einhängen
		if (!edit) return;
		rec.obs = new MutationObserver(function (records) {
			if (live.get(host) !== rec) return;
			if (records.some(function (r) { return r.type === 'attributes'; })) { unmount(host); scheduleScan(); return; }
			if (rec.inst) write(host, rec, null, rec.inst.fallback());
		});
		rec.obs.observe(host, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: Object.keys(BLOCKS) });
	}
	// Light DOM schreiben (eigene Änderung, nicht beobachtet)
	function write(host, rec, json, fallback) {
		if (json !== null) host.setAttribute(blockAttr(host), json);
		host.replaceChildren(fallback);
		if (rec.obs) rec.obs.takeRecords();
	}
	// Tastatur/Eingabe aus einer Block-UI (Capture am window, vor Upstream; nextnotes-bootstrap.js): nur der Block sieht sie;
	// Zeiger und Klicks hält das Block-Modul an seiner UI an
	window.poznoteBlockEvent = function (e) {
		var path = e.composedPath ? e.composedPath() : [];
		for (var i = 0; i < path.length; i++) {
			var rec = i > 0 && path[i].nodeType === 1 && path[i].shadowRoot ? live.get(path[i]) : null;
			if (rec && rec.inst && path[i].shadowRoot.contains(path[0])) {
				rec.inst.handleEvent(e);
				return true;
			}
		}
		return false;
	};
	scheduleScan();

	// Host (leerer Block mit Fallback) baut das Modul; Einfügen danach an der gemerkten Marke
	function insertBlock(entry, range, attr) {
		var def = BLOCKS[attr];
		range = range || currentRange(entry);
		script(def).then(function () {
			var host = window[def.api].host();
			fresh.add(host);
			placeBlock(entry, range, host);
			changed(entry);
		}, function () { notify(txt[def.n + 'LoadError'], 'error'); });
	}

	function hostIn(entry, target) {
		var host = target && target.closest ? target.closest(HOST) : null;
		return host && entry.contains(host) && host !== entry ? host : null;
	}
	document.addEventListener('click', function (e) {
		var entry = entryOf(e.target);
		var host = entry && hostIn(entry, e.target);
		if (!host || !writable(entry)) return;
		e.preventDefault();
		editFormula(entry, host, false);
	});
	// Enter auf einer ausgewählten Formel öffnet das Popover (vor den Upstream-Tastenhandlern)
	window.addEventListener('keydown', function (e) {
		if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.altKey || e.metaKey || e.isComposing) return;
		var sel = window.getSelection();
		if (!sel || sel.rangeCount === 0) return;
		var r = sel.getRangeAt(0);
		var entry = entryOf(r.startContainer);
		if (!entry || !writable(entry)) return;
		var node = r.startContainer.nodeType === 1 && r.endContainer === r.startContainer && r.endOffset === r.startOffset + 1 ? r.startContainer.childNodes[r.startOffset] : null;
		var host = (node && node.nodeType === 1 && node.matches(HOST) && node) || hostIn(entry, r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentElement);
		if (!host) return;
		e.preventDefault();
		e.stopPropagation();
		editFormula(entry, host, false);
	}, true);

	// --- Slash-Menü (Hook P38) ---
	window.poznoteSlashInsertItems = function () {
		var entry = activeEntry();
		if (!entry) return [];
		return ITEMS.map(function (item) {
			return { id: item.id, icon: item.icon, label: item.label, noRefocus: true, action: function () { run(item, entry, currentRange(entry)); } };
		});
	};

	// --- Werkzeugleiste: Knopf .btn-nn-blocks (note_display.php) öffnet ein Menü ---
	var menu = null;
	function closeMenu() {
		if (menu) { menu.remove(); menu = null; }
	}
	function openMenu(button) {
		closeMenu();
		var entry = activeEntry() || writableNear(button);
		if (!entry) return;
		var range = currentRange(entry);
		menu = document.createElement('div');
		menu.className = 'nn-blocks-menu';
		menu.setAttribute('role', 'menu');
		ITEMS.forEach(function (item) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'nn-blocks-menu-item';
			b.setAttribute('role', 'menuitem');
			b.setAttribute('data-nn-block', item.id);
			var i = document.createElement('i');
			i.className = 'lucide ' + item.icon;
			i.setAttribute('aria-hidden', 'true');
			b.append(i, document.createTextNode(' ' + item.label));
			b.addEventListener('click', function () { closeMenu(); run(item, entry, range); });
			menu.appendChild(b);
		});
		document.body.appendChild(menu);
		var rect = button.getBoundingClientRect();
		if (!rect.width) rect = (button.closest('.note-edit-toolbar') || entry).getBoundingClientRect();
		menu.style.top = Math.round(rect.bottom + 4) + 'px';
		menu.style.left = Math.round(Math.max(8, Math.min(rect.left, window.innerWidth - menu.offsetWidth - 8))) + 'px';
		menu.querySelector('button').focus();
	}
	function writableNear(button) {
		var card = button.closest('.notecard');
		var entry = card && card.querySelector('.noteentry');
		return writable(entry) ? entry : null;
	}
	document.addEventListener('mousedown', function (e) {
		if (e.target.closest && e.target.closest('[data-action="nn-blocks-menu"]')) e.preventDefault();
	}, true);
	document.addEventListener('click', function (e) {
		var button = e.target.closest && e.target.closest('[data-action="nn-blocks-menu"]');
		if (button) {
			if (menu) closeMenu(); else openMenu(button);
		} else if (menu && !menu.contains(e.target)) {
			closeMenu();
		}
	});
	document.addEventListener('keydown', function (e) {
		if (menu && e.key === 'Escape') { closeMenu(); e.stopPropagation(); }
	}, true);
	window.addEventListener('resize', closeMenu);

	window.NextNotesBlocks = { writable: writable, editFormula: editFormula, scan: scan, queue: enqueue, PAGE_MS: PAGE_MS };
})();
