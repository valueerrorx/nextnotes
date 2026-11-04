/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Nach nextnotes-app.js: Asset-Helfer auf den App-Pfad (SPEC §4.3.8); Upstream setzt sie seitenrelativ.
(function () {
	'use strict';

	var config = (window.NEXTNOTES && window.NEXTNOTES.config) || {};
	var assetBase = String(config.assetBase || '').replace(/\/$/, '');
	var version = '';
	try {
		var src = document.currentScript && document.currentScript.src;
		version = src ? new URL(src, window.location.href).searchParams.get('v') || '' : '';
	} catch (e) {
		version = '';
	}

	// Upstream-Vendorpfade liegen im Build unter js/vendor/
	var VENDOR = ['js/codemirror-dist/', 'js/excalidraw-dist/', 'js/mermaid/', 'js/katex/', 'js/highlight/', 'js/Sortable.min.js'];

	function appPath(path) {
		var p = path.replace(/^\.?\//, '');
		for (var i = 0; i < VENDOR.length; i++) {
			if (p.indexOf(VENDOR[i]) === 0) return 'js/vendor/' + p.slice(3);
		}
		return p;
	}

	window.poznoteAssetUrl = function (path) {
		path = String(path);
		if (/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(path)) return path;
		if (path.charAt(0) === '/' && path.indexOf('/js/') !== 0) return path;
		var url = assetBase + '/' + appPath(path);
		if (!version || url.indexOf('?') !== -1) return url;
		return url + '?v=' + encodeURIComponent(version);
	};

	var pending = {};
	function ensureScript(path) {
		if (pending[path]) return pending[path];
		pending[path] = new Promise(function (resolve, reject) {
			var script = document.createElement('script');
			script.src = window.poznoteAssetUrl(path);
			script.onload = function () { resolve(); };
			script.onerror = function () {
				delete pending[path];
				reject(new Error('Failed to load ' + path));
			};
			document.head.appendChild(script);
		});
		return pending[path];
	}

	// Schnellknöpfe der Ordnerzeile (M3-ui1): Enter/Leertaste löst den Knopf aus, nicht die Baum-Tastatur (tree-keyboard-nav.js)
	document.addEventListener('keydown', function (e) {
		var t = e.target;
		if ((e.key === 'Enter' || e.key === ' ') && t && t.closest && t.closest('.nn-folder-quick')) e.stopPropagation();
	}, true);

	// Karten der Seite create.php (Upstream-Inline-Skript): Typ wählen, dann executeCreateAction aus utils-note-create.js
	document.addEventListener('click', function (e) {
		var card = e.target && e.target.closest ? e.target.closest('.home-card[data-create-type]') : null;
		if (!card || typeof window.executeCreateAction !== 'function') return;
		e.preventDefault();
		var type = card.dataset.createType;
		window.noteCreationTriggerElement = card;
		window.selectedCreateType = type;
		window.targetFolderId = null;
		window.targetFolderName = null;
		window.isCreatingInFolder = false;
		window.selectedWorkspace = document.body.dataset.workspace || '';
		if (['html', 'markdown', 'list', 'diary'].indexOf(type) !== -1 && typeof window.showNoteCreationLoading === 'function') {
			window.showNoteCreationLoading(card);
			window.setTimeout(window.executeCreateAction, 80);
			return;
		}
		window.executeCreateAction();
	});

	// Papierkorb „Mehr laden“ (M4e-fix): nächste Karten ab dem Cursor als Fragment anhängen, ohne Neuladen
	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest ? e.target.closest('.trash-load-more-btn') : null;
		if (!btn || btn.disabled) return;
		e.preventDefault();
		btn.disabled = true;
		var query = new URLSearchParams();
		['workspace', 'sort', 'search', 'after'].forEach(function (k) {
			if (btn.dataset[k]) query.set(k, btn.dataset[k]);
		});
		fetch('trash.php?' + query.toString(), {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Poznote-Fragment': 'trash_cards' }
		}).then(function (response) {
			if (!response.ok) throw new Error('HTTP ' + response.status);
			return response.text();
		}).then(function (html) {
			var fragment = new DOMParser().parseFromString(html, 'text/html').getElementById('trashCardsFragment');
			var box = btn.closest('.trash-load-more');
			if (!fragment || !box) throw new Error('trash fragment missing');
			var nodes = Array.prototype.slice.call(fragment.children);
			box.replaceWith.apply(box, nodes);
			nodes.forEach(function (card) {
				// wie initTrashPreviews (trash.js), nur für die neuen Karten; 190 = TRASH_PREVIEW_MAX_HEIGHT
				var preview = card.querySelector && card.querySelector('.trash-note-preview');
				var toggle = card.querySelector && card.querySelector('.trash-expand-btn');
				if (!preview || !toggle || preview.scrollHeight <= 190 + 40) return;
				preview.classList.add('is-collapsed');
				toggle.hidden = false;
				toggle.addEventListener('click', function () { preview.classList.toggle('is-collapsed'); });
			});
			// eingetippter Filter (noch nicht abgeschickt) gilt auch für die neuen Karten
			var input = document.getElementById('searchInput');
			if (input && input.value !== (btn.dataset.search || '')) input.dispatchEvent(new Event('input'));
		}).catch(function (error) {
			console.error('trash: load more failed', error);
			btn.disabled = false;
			if (typeof window.showNotificationPopup === 'function') {
				window.showNotificationPopup(window.t ? window.t('common.network_error', null, 'Network error') : 'Network error', 'error');
			}
		});
	});

	window.poznoteEnsureScript = ensureScript;
	window.poznoteEnsureMermaid = function () {
		return typeof window.mermaid !== 'undefined' ? Promise.resolve() : ensureScript('js/mermaid/mermaid.min.js');
	};
	window.poznoteEnsureKatex = function () {
		return typeof window.katex !== 'undefined' ? Promise.resolve() : ensureScript('js/katex/katex.min.js');
	};
})();
