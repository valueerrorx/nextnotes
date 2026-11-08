/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Formel-Popover (M8a, SPEC §4.7): MathLive-Feld am body, lazy aus nextnotes-blocks.js. Liefert das Ergebnis als Promise,
// das Ändern des Inhalts macht nextnotes-blocks.js. Tastenereignisse verlassen das Popover nicht (Upstream-Menüs, Autosave).
(function () {
	'use strict';

	var STOP = ['keydown', 'keyup', 'keypress', 'beforeinput', 'input', 'paste', 'copy', 'cut', 'compositionend', 'drop'];
	var current = null;

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined) node.textContent = text;
		return node;
	}
	function button(className, text, icon) {
		var b = el('button', 'nn-formula-btn ' + className);
		b.type = 'button';
		if (icon) {
			var i = el('i', 'lucide ' + icon);
			i.setAttribute('aria-hidden', 'true');
			b.appendChild(i);
		}
		b.appendChild(document.createTextNode(text));
		return b;
	}

	// keyup landete sonst in der Notiz (Upstream-Autosave markiert sie als geändert)
	function swallowKeyup(key) {
		var h = function (e) {
			if (e.key !== key) return;
			e.stopPropagation();
			window.removeEventListener('keyup', h, true);
		};
		window.addEventListener('keyup', h, true);
		setTimeout(function () { window.removeEventListener('keyup', h, true); }, 1000);
	}

	function close(result) {
		if (!current) return;
		var c = current;
		current = null;
		document.removeEventListener('pointerdown', c.outside, true);
		window.removeEventListener('resize', c.place);
		if (window.mathVirtualKeyboard) window.mathVirtualKeyboard.removeEventListener('geometrychange', c.place);
		try { if (window.mathVirtualKeyboard) window.mathVirtualKeyboard.hide(); } catch (e) { /* ohne Tastatur */ }
		c.root.remove();
		c.resolve(result || { action: 'cancel' });
	}

	function open(opts) {
		close();
		var t = opts.txt || {};
		return new Promise(function (resolve) {
			var root = el('div', 'nn-formula-popover');
			root.setAttribute('role', 'dialog');
			root.setAttribute('aria-label', t.editFormula || 'Edit formula');
			var mf = new window.MathfieldElement();
			mf.className = 'nn-formula-field';
			mf.setAttribute('math-virtual-keyboard-policy', 'auto');
			mf.setAttribute('aria-label', t.editFormula || 'Edit formula');

			var bar = el('div', 'nn-formula-actions');
			var toggle = el('label', 'nn-formula-toggle');
			var check = el('input');
			check.type = 'checkbox';
			check.checked = !!opts.block;
			toggle.append(check, document.createTextNode(' ' + (t.asBlock || 'On its own line')));
			var spacer = el('span', 'nn-formula-spacer');
			var del = button('nn-formula-delete', t.delete || 'Delete', 'lucide-trash-2');
			var cancel = button('nn-formula-cancel', t.cancel || 'Cancel');
			var apply = button('nn-formula-apply primary', t.apply || 'Apply');
			bar.append(toggle, spacer);
			if (!opts.isNew) bar.appendChild(del);
			bar.append(cancel, apply);
			root.append(mf, bar);

			var doApply = function () { close({ action: 'apply', latex: mf.getValue('latex-expanded'), block: check.checked }); };
			apply.addEventListener('click', doApply);
			cancel.addEventListener('click', function () { close({ action: 'cancel' }); });
			del.addEventListener('click', function () { close({ action: 'delete' }); });

			// Enter übernimmt, Esc bricht ab (vor MathLive); danach nichts nach außen, auch nicht das keyup nach dem Schließen
			root.addEventListener('keydown', function (e) {
				var enter = e.key === 'Enter' && !e.shiftKey && e.composedPath().indexOf(mf) !== -1;
				if (e.key !== 'Escape' && !enter) return;
				e.preventDefault();
				e.stopPropagation();
				swallowKeyup(e.key);
				if (enter) doApply(); else close({ action: 'cancel' });
			}, true);
			STOP.forEach(function (type) { root.addEventListener(type, function (e) { e.stopPropagation(); }); });

			// Klick außerhalb (nicht Bildschirmtastatur/MathLive-Menü) = Übernehmen
			var outside = function (e) {
				var path = e.composedPath();
				for (var i = 0; i < path.length; i++) {
					var n = path[i];
					if (n === root || (n.classList && (n.classList.contains('ML__keyboard') || n.classList.contains('ui-menu-container')))) return;
				}
				doApply();
			};
			// Bildschirmtastatur unten: Blatt darüber, Popover nur im freien Bereich
			var keyboard = function () {
				var kb = window.mathVirtualKeyboard;
				return kb && kb.visible ? Math.round(kb.boundingRect.height) : 0;
			};
			var place = function () {
				var sheet = window.innerWidth <= 800;
				var inset = keyboard();
				root.classList.toggle('is-sheet', sheet);
				if (sheet) {
					root.style.top = root.style.left = '';
					root.style.bottom = inset + 'px';
					return;
				}
				root.style.bottom = '';
				var a = opts.anchor && opts.anchor.isConnected ? opts.anchor.getBoundingClientRect() : { top: 80, bottom: 80, left: 80 };
				var h = root.offsetHeight;
				var top = a.bottom + 6 + h > window.innerHeight - inset ? Math.max(8, Math.min(a.top, window.innerHeight - inset) - h - 6) : a.bottom + 6;
				root.style.top = Math.round(top) + 'px';
				root.style.left = Math.round(Math.max(8, Math.min(a.left, window.innerWidth - root.offsetWidth - 8))) + 'px';
			};
			current = { root: root, resolve: resolve, outside: outside, place: place };
			document.body.appendChild(root);
			mf.value = opts.latex || '';
			place();
			document.addEventListener('pointerdown', outside, true);
			window.addEventListener('resize', place);
			if (window.mathVirtualKeyboard) window.mathVirtualKeyboard.addEventListener('geometrychange', place);
			// Fokus nach dem Einhängen von MathLive (connectedCallback) und nach Klick-Handlern des Auslösers
			setTimeout(function () { if (current && current.root === root) mf.focus(); }, 0);
		});
	}

	window.NextNotesFormula = { open: open, close: function () { close({ action: 'cancel' }); } };
})();
