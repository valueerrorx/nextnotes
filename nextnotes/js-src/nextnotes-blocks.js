/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Blöcke in Richtext-Notizen (SPEC §4.7, M8-Plan §1): Einträge im Slash-Menü (Hook P38) und im Werkzeugleisten-Menü,
// Einfügen, Modus (schreibbar/lesend), Speichern über das input-Ereignis. Block-UIs laden lazy (nextnotes-formula.js).
// Hosts werden am Attribut erkannt (data-math), nie an der Klasse.
(function () {
	'use strict';

	var cfg = (window.NEXTNOTES && window.NEXTNOTES.config && window.NEXTNOTES.config.blocks) || {};
	var txt = cfg.txt || {};
	function tr(key, fallback) { return String(txt[key] || fallback); }

	var HOST = '[data-math]';
	var ITEMS = [
		{ id: 'nn-formula', icon: 'lucide-sigma', label: tr('formula', 'Formula'), run: function (entry, range) { insertFormula(entry, range, false); } },
		{ id: 'nn-formula-block', icon: 'lucide-sigma', label: tr('formulaBlock', 'Formula block'), run: function (entry, range) { insertFormula(entry, range, true); } },
	];

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
		return !el.textContent.replace(/​/g, '').trim() && !el.querySelector('img, iframe, video, audio, [data-math], table, hr');
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
		} else if (/^(P|H[1-6])$/.test(top.tagName) || (top.tagName === 'DIV' && !top.className && !top.hasAttribute('data-math'))) {
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
			if (typeof window.showNotification === 'function') window.showNotification(tr('loadError', 'The formula editor could not be loaded.'), 'error');
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
	// Sperre verloren / nur lesend: offenes Popover schließen
	new MutationObserver(function () {
		if (openState && !writable(openState.entry) && window.NextNotesFormula) window.NextNotesFormula.close();
	}).observe(document.documentElement, { subtree: true, attributes: true, attributeFilter: ['contenteditable', 'data-nextnotes-readonly'] });

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
			return { id: item.id, icon: item.icon, label: item.label, noRefocus: true, action: function () { item.run(entry, currentRange(entry)); } };
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
			b.addEventListener('click', function () { closeMenu(); item.run(entry, range); });
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

	window.NextNotesBlocks = { writable: writable, editFormula: editFormula };
})();
