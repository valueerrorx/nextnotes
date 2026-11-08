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

	// Workspace-Auswahl im Seitentitel (UI-ws): Liste wie das Menü der Hauptansicht (GET api/v1/workspaces), Wechsel bleibt auf der Seite
	var wsMenu = null;
	var wsList = null;
	function wsChipItem(tag, cls, label) {
		var node = document.createElement(tag);
		node.className = 'poznote-page-title-workspace-item' + (cls ? ' ' + cls : '');
		var name = document.createElement('span');
		name.className = 'poznote-page-title-workspace-item-name';
		name.textContent = label;
		node.appendChild(name);
		return node;
	}
	function wsChipIcon(name) {
		var i = document.createElement('i');
		i.className = 'lucide ' + name;
		i.setAttribute('aria-hidden', 'true');
		return i;
	}
	function wsChipRender(btn, list, state) {
		var current = btn.getAttribute('data-nn-ws-chip');
		var teamLabel = ((config.teams || {}).txt || {}).teamWorkspace || '';
		wsMenu.textContent = '';
		if (state) {
			wsMenu.appendChild(wsChipItem('span', '', btn.getAttribute(state === 'error' ? 'data-txt-error' : 'data-txt-loading')));
			return;
		}
		list.forEach(function (ws) {
			var isCurrent = ws.name === current;
			var a = wsChipItem('a', isCurrent ? 'poznote-page-title-workspace-item-current' : '', ws.name);
			var url = new URL(window.location.pathname, window.location.origin);
			url.searchParams.set('workspace', ws.name);
			a.href = url.toString();
			a.setAttribute('role', 'menuitemradio');
			a.setAttribute('aria-checked', isCurrent ? 'true' : 'false');
			a.setAttribute('data-workspace', ws.name);
			// wie das Menü der Hauptansicht: Farbpunkt, sonst Ebenen-Symbol
			var lead = wsChipIcon('lucide-layers');
			if (ws.color_hex) {
				lead = document.createElement('span');
				lead.className = 'poznote-page-title-workspace-dot';
				lead.style.backgroundColor = ws.color_hex;
			}
			a.insertBefore(lead, a.firstChild);
			if (ws.team) {
				var mark = document.createElement('span');
				mark.className = 'nn-team-mark';
				mark.title = teamLabel;
				mark.appendChild(wsChipIcon('lucide-users'));
				mark.appendChild(document.createTextNode(ws.team));
				a.appendChild(mark);
			}
			if (isCurrent) a.appendChild(wsChipIcon('lucide-check'));
			wsMenu.appendChild(a);
		});
		var sep = document.createElement('span');
		sep.className = 'poznote-page-title-workspace-menu-sep';
		sep.setAttribute('role', 'separator');
		wsMenu.appendChild(sep);
		var manage = wsChipItem('a', 'poznote-page-title-workspace-item-manage', btn.getAttribute('data-txt-manage'));
		manage.insertBefore(wsChipIcon('lucide-settings'), manage.firstChild);
		manage.href = 'settings.php?' + new URLSearchParams({ workspace: current }).toString() + '#section=settings-workspaces-section-grid';
		manage.setAttribute('role', 'menuitem');
		wsMenu.appendChild(manage);
	}
	function wsChipPosition(btn) {
		var r = btn.getBoundingClientRect();
		var box = wsMenu.getBoundingClientRect();
		var left = Math.max(8, Math.min(r.left, window.innerWidth - box.width - 8));
		var top = r.bottom + 4;
		if (top + box.height > window.innerHeight - 8 && r.top - 4 - box.height >= 8) top = r.top - 4 - box.height;
		wsMenu.style.left = Math.round(left) + 'px';
		wsMenu.style.top = Math.round(top) + 'px';
	}
	function wsChipClose(btn, refocus) {
		if (!wsMenu || !wsMenu.classList.contains('poznote-page-title-workspace-menu-open')) return;
		wsMenu.classList.remove('poznote-page-title-workspace-menu-open');
		btn.setAttribute('aria-expanded', 'false');
		if (refocus) btn.focus();
	}
	function wsChipOpen(btn) {
		if (!wsMenu) {
			wsMenu = document.createElement('div');
			wsMenu.className = 'poznote-page-title-workspace-menu';
			wsMenu.id = 'nnWorkspaceChipMenu';
			wsMenu.setAttribute('role', 'menu');
			wsMenu.setAttribute('aria-label', btn.getAttribute('data-txt-menu') || '');
			document.body.appendChild(wsMenu);
			btn.setAttribute('aria-controls', wsMenu.id);
		}
		wsMenu.classList.add('poznote-page-title-workspace-menu-open');
		btn.setAttribute('aria-expanded', 'true');
		var show = function () {
			wsChipPosition(btn);
			var first = wsMenu.querySelector('.poznote-page-title-workspace-item-current') || wsMenu.querySelector('a');
			if (first) first.focus();
		};
		if (wsList) {
			wsChipRender(btn, wsList);
			show();
			return;
		}
		wsChipRender(btn, null, 'loading');
		wsChipPosition(btn);
		fetch('api/v1/workspaces', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data || !data.success || !Array.isArray(data.workspaces)) throw new Error('bad response');
				wsList = data.workspaces;
				if (btn.getAttribute('aria-expanded') !== 'true') return;
				wsChipRender(btn, wsList);
				show();
			})
			.catch(function () {
				wsChipRender(btn, null, 'error');
				wsChipPosition(btn);
			});
	}
	document.addEventListener('click', function (e) {
		var btn = document.querySelector('[data-nn-ws-chip]');
		if (!btn) return;
		var target = e.target;
		if (target && target.closest && target.closest('[data-nn-ws-chip]')) {
			e.preventDefault();
			if (btn.getAttribute('aria-expanded') === 'true') wsChipClose(btn, false); else wsChipOpen(btn);
			return;
		}
		if (!wsMenu || !wsMenu.contains(target)) {
			wsChipClose(btn, false);
			return;
		}
		// Wahl als zuletzt geöffneten Workspace merken (wie die Hauptansicht), best effort
		var item = target.closest('a[data-workspace]');
		if (item) {
			fetch('api/v1/settings/last_opened_workspace', {
				method: 'PUT', credentials: 'same-origin', keepalive: true,
				headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
				body: JSON.stringify({ value: item.getAttribute('data-workspace') })
			}).catch(function () {});
		}
	});
	document.addEventListener('keydown', function (e) {
		var btn = document.querySelector('[data-nn-ws-chip]');
		if (!btn || !wsMenu || btn.getAttribute('aria-expanded') !== 'true') return;
		if (e.key === 'Escape') {
			e.preventDefault();
			wsChipClose(btn, true);
		} else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
			e.preventDefault();
			var items = Array.prototype.slice.call(wsMenu.querySelectorAll('a'));
			if (!items.length) return;
			var i = items.indexOf(document.activeElement);
			items[e.key === 'ArrowDown' ? (i + 1) % items.length : (i <= 0 ? items.length - 1 : i - 1)].focus();
		} else if (e.key === 'Tab') {
			wsChipClose(btn, false);
		}
	});
	['resize', 'scroll'].forEach(function (type) {
		window.addEventListener(type, function (e) {
			var btn = document.querySelector('[data-nn-ws-chip]');
			if (btn && wsMenu && e.target !== wsMenu && btn.getAttribute('aria-expanded') === 'true') wsChipPosition(btn);
		}, true);
	});

	window.poznoteEnsureScript = ensureScript;
	window.poznoteEnsureMermaid = function () {
		return typeof window.mermaid !== 'undefined' ? Promise.resolve() : ensureScript('js/mermaid/mermaid.min.js');
	};
	window.poznoteEnsureKatex = function () {
		return typeof window.katex !== 'undefined' ? Promise.resolve() : ensureScript('js/katex/katex.min.js');
	};

	// Mobil (UI-nav-mobil): Zurück des Browsers auf eine URL ohne Notiz/Kanban zeigt wieder die Liste (Upstream: Wischen über body.scrollLeft)
	window.addEventListener('popstate', function () {
		if (window.innerWidth > 800 || !document.body.classList.contains('note-open') || typeof window.scrollToLeftColumn !== 'function') return;
		var params = new URLSearchParams(window.location.search);
		if (!params.get('note') && !params.get('kanban')) window.scrollToLeftColumn();
	});
})();
