/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Adapter vor allen Upstream-Skripten (SPEC §4.3): Konfiguration, URL-Präfix, CSRF, Beacon, 401, i18n, Hell/Dunkel.
(function () {
	'use strict';

	// 1. Konfiguration aus dem InitialState (Base64-JSON, core/templates/layout.initial-state.php)
	function readState(key) {
		var el = document.getElementById('initial-state-nextnotes-' + key);
		if (!el) return null;
		try {
			var bin = atob(el.value);
			var bytes = new Uint8Array(bin.length);
			for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
			return JSON.parse(new TextDecoder().decode(bytes));
		} catch (e) {
			console.warn('nextnotes: InitialState ' + key + ' unlesbar', e);
			return null;
		}
	}

	var config = readState('config') || {};
	var baseUrl = String(config.baseUrl || '/index.php/apps/nextnotes/').replace(/\/?$/, '/');
	var apiBase = String(config.apiBase || baseUrl + 'api/v1').replace(/\/$/, '');
	var appPath = baseUrl.replace(/\/index\.php\//, '/');
	// Seiten unter /index.php/apps/… lösen relative URLs (api_note_info.php …) dorthin auf, auch bei hübschen URLs
	var indexPath = appPath.replace(/\/apps\/([^/]+)\/$/, '/index.php/apps/$1/');
	var origin = window.location.origin;

	var i18n = window.__NEXTNOTES_I18N__ || { lang: 'en', strings: {} };
	window.POZNOTE_I18N = { lang: i18n.lang, strings: i18n.strings };
	window.ALLOWED_IFRAME_DOMAINS = Array.isArray(config.allowedIframeDomains) ? config.allowedIframeDomains : [];
	window.EXCALIDRAW_ASSET_PATH = config.excalidrawAssetPath || '';
	window.__POZNOTE_HIDDEN_UI_ELEMENTS__ = Array.isArray(config.hiddenUiElements) ? config.hiddenUiElements : [];
	window.__POZNOTE_GLOBAL_HIDDEN_UI_ELEMENTS__ = Array.isArray(config.globalHiddenUiElements) ? config.globalHiddenUiElements : [];
	var prefetched = config.prefetchedSettings && typeof config.prefetchedSettings === 'object' ? config.prefetchedSettings : {};

	// JSON-Inseln der Seite (Templates, SPEC §4.6) statt Upstream-Inline-Skripte
	function readIsland(id) {
		var el = document.getElementById(id);
		if (!el) return null;
		try {
			return JSON.parse(el.textContent || 'null');
		} catch (e) {
			console.warn('nextnotes: JSON-Insel ' + id + ' unlesbar', e);
			return null;
		}
	}

	// Globals der Upstream-Inline-Skripte (index.php, icon_sidebar.php); nur bekannte Namen
	var GLOBALS = ['DEFAULT_NOTE_TITLES', 'PoznoteMentionMembers', 'TAG_COLORS', 'NOTE_COLOR_PALETTE', 'PoznoteActiveAccountIsOwn', 'calendarTranslations', 'NOTIFICATIONS_TXT'];
	var pageGlobals = readIsland('nextnotes-globals') || {};
	GLOBALS.forEach(function (name) {
		if (Object.prototype.hasOwnProperty.call(pageGlobals, name)) window[name] = pageGlobals[name];
	});

	// <body>-Klassen/-Attribute und frühe Zustände (Upstream index.php:392-425, 635; icon_sidebar.php:480-499)
	function applyPageState(state) {
		if (!state || !document.body) return;
		var body = document.body;
		var root = document.documentElement;
		(state.classes || []).forEach(function (c) { body.classList.add(c); });
		(state.htmlClasses || []).forEach(function (c) { root.classList.add(c); });
		Object.keys(state.data || {}).forEach(function (k) { body.setAttribute('data-' + k, String(state.data[k])); });
		Object.keys(state.style || {}).forEach(function (k) { body.style.setProperty(k, String(state.style[k])); });
		Object.keys(state.rootStyle || {}).forEach(function (k) { root.style.setProperty(k, String(state.rootStyle[k])); });
		try {
			var stored = localStorage.getItem('outlineCollapsed');
			if (window.innerWidth > 800 && (stored === null || stored === 'true')) root.classList.add('outline-collapsed');
			if (localStorage.getItem('iconSidebarCollapsed') === 'true') body.classList.add('icon-sidebar-collapsed');
			var focusParam = new URLSearchParams(window.location.search).get('focus');
			var focus = focusParam === '1' || focusParam === '0' ? focusParam : localStorage.getItem('focusMode');
			if (focus === '1' || focus === '2' || focus === 'true') root.classList.add('focus-mode');
		} catch (e) { /* ohne localStorage Standardzustand */ }
		// Serverseitig gewählter Workspace in die URL (Skripte lesen location.search)
		if (state.replaceWorkspace) {
			try {
				var url = new URL(window.location.href);
				url.searchParams.set('workspace', state.replaceWorkspace);
				history.replaceState(history.state, '', url);
			} catch (e) { /* URL bleibt */ }
		}
	}
	applyPageState(readIsland('nextnotes-body'));

	// 2. URL-Präfix für Upstream-Pfade
	function rewrite(url) {
		if (typeof url !== 'string') return url;
		var u = url;
		if (u.indexOf(origin + '/') === 0) u = u.slice(origin.length);
		var m = /^(?:\.\/)?\/?api\/v1(?=[/?#]|$)(.*)$/.exec(u);
		if (m) return apiBase + m[1];
		if (/^\/audio_player\.php(?=[?#]|$)/.test(u)) return baseUrl + u.slice(1);
		return url;
	}

	// Gleicher Ursprung und App-Pfad → braucht requesttoken
	function isAppCall(url) {
		try {
			var target = new URL(url, window.location.href);
			if (target.origin !== origin) return false;
			return target.pathname.indexOf(baseUrl) === 0 || target.pathname.indexOf(appPath) === 0 || target.pathname.indexOf(indexPath) === 0;
		} catch (e) {
			return false;
		}
	}

	function isI18nCall(url) {
		try {
			return new URL(url, window.location.href).pathname === apiBase + '/system/i18n';
		} catch (e) {
			return false;
		}
	}

	// Vom Server mitgelieferte Einstellung (InitialState) → kein GET beim Seitenaufbau
	function prefetchedKey(url) {
		try {
			var path = new URL(url, window.location.href).pathname;
			var prefix = apiBase + '/settings/';
			var key = path.indexOf(prefix) === 0 ? path.slice(prefix.length) : '';
			return key !== '' && Object.prototype.hasOwnProperty.call(prefetched, key) ? key : null;
		} catch (e) {
			return null;
		}
	}

	function token() {
		return (document.head && document.head.dataset.requesttoken) || (window.OC && window.OC.requestToken) || '';
	}

	// 3. 401 → einmal neu laden (NC-Login mit Rücksprung); < 30 s nach dem letzten Reload nicht erneut
	var RELOAD_KEY = 'nextnotes-401-reload';
	function on401() {
		var last = 0;
		try { last = Number(sessionStorage.getItem(RELOAD_KEY)) || 0; } catch (e) { last = 0; }
		if (Date.now() - last < 30000) {
			var msg = typeof window.t === 'function' ? window.t('errors.session_expired', null, 'Session expired. Please reload the page.') : 'Session expired. Please reload the page.';
			if (typeof window.showNotificationPopup === 'function') window.showNotificationPopup(msg, 'error');
			return;
		}
		try { sessionStorage.setItem(RELOAD_KEY, String(Date.now())); } catch (e) { /* ohne Speicher trotzdem neu laden */ }
		window.location.reload();
	}

	// 4. fetch
	var KEEPALIVE_LIMIT = 65536;
	var nativeFetch = window.fetch;
	window.fetch = function (input, init) {
		var isRequest = typeof Request !== 'undefined' && input instanceof Request;
		var url = isRequest ? input.url : (input instanceof URL ? input.href : String(input));
		var target = rewrite(url);
		if (isI18nCall(target)) {
			var body = JSON.stringify({ success: true, lang: i18n.lang, strings: i18n.strings });
			return Promise.resolve(new Response(body, { status: 200, headers: { 'Content-Type': 'application/json' } }));
		}
		var method = String((init && init.method) || (isRequest ? input.method : 'GET')).toUpperCase();
		var settingKey = prefetchedKey(target);
		if (settingKey && method === 'GET') {
			var answer = JSON.stringify({ success: true, key: settingKey, value: prefetched[settingKey] });
			return Promise.resolve(new Response(answer, { status: 200, headers: { 'Content-Type': 'application/json' } }));
		}
		if (!isAppCall(target)) {
			return nativeFetch.call(this, target === url ? input : target, init);
		}
		var opts = Object.assign({}, init || {});
		// keepalive verwirft Bodies ab 64 KiB (Notfallspeichern großer Notizen, B30): dann normaler Request
		if (opts.keepalive && typeof opts.body === 'string' && new Blob([opts.body]).size >= KEEPALIVE_LIMIT) opts.keepalive = false;
		var headers = new Headers(opts.headers || (isRequest ? input.headers : undefined));
		if (!headers.has('requesttoken')) headers.set('requesttoken', token());
		if (!headers.has('X-Requested-With')) headers.set('X-Requested-With', 'XMLHttpRequest');
		opts.headers = headers;
		var req = isRequest ? new Request(target, input) : target;
		return nativeFetch.call(this, req, opts).then(function (resp) {
			if (resp.status === 401) on401();
			if (settingKey && method === 'PUT' && resp.ok) {
				resp.clone().json().then(function (data) {
					if (data && data.success) prefetched[settingKey] = data.value;
				}).catch(function () { delete prefetched[settingKey]; });
			}
			return resp;
		});
	};

	// 5. XMLHttpRequest
	// Header nur setzen, wenn weder Aufrufer noch NC-Kern (core/src/utils/xhr-request.js) es taten: XHR hängt sonst an
	var nativeOpen = XMLHttpRequest.prototype.open;
	var nativeSend = XMLHttpRequest.prototype.send;
	var nativeSetHeader = XMLHttpRequest.prototype.setRequestHeader;
	XMLHttpRequest.prototype.setRequestHeader = function (name) {
		if (this.__nnHeaders) this.__nnHeaders[String(name).toLowerCase()] = true;
		return nativeSetHeader.apply(this, arguments);
	};
	XMLHttpRequest.prototype.open = function (method, url) {
		var args = Array.prototype.slice.call(arguments);
		args[1] = rewrite(String(url));
		this.__nnApp = isAppCall(args[1]);
		this.__nnHeaders = {};
		return nativeOpen.apply(this, args);
	};
	XMLHttpRequest.prototype.send = function () {
		if (this.__nnApp) {
			var set = this.__nnHeaders || {};
			if (!set.requesttoken) this.setRequestHeader('requesttoken', token());
			if (!set['x-requested-with']) this.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
			this.addEventListener('loadend', function () {
				if (this.status === 401) on401();
			});
		}
		return nativeSend.apply(this, arguments);
	};

	// 6. sendBeacon: Token im Formular oder in der URL (Request::passesCSRFCheck)
	if (navigator.sendBeacon) {
		var nativeBeacon = navigator.sendBeacon.bind(navigator);
		navigator.sendBeacon = function (url, data) {
			var target = rewrite(String(url));
			if (isAppCall(target)) {
				if (typeof FormData !== 'undefined' && data instanceof FormData) {
					if (!data.has('requesttoken')) data.append('requesttoken', token());
				} else {
					target += (target.indexOf('?') === -1 ? '?' : '&') + 'requesttoken=' + encodeURIComponent(token());
				}
			}
			return nativeBeacon(target, data);
		};
	}

	// 6a. requesttoken nie in die Adresszeile (Such-Formular → history.pushState, unified-search.js)
	['pushState', 'replaceState'].forEach(function (name) {
		var native = history[name];
		history[name] = function (state, title, url) {
			if (url !== undefined && url !== null) {
				try {
					var u = new URL(String(url), window.location.href);
					if (u.searchParams.has('requesttoken')) {
						u.searchParams.delete('requesttoken');
						url = u.href;
					}
				} catch (e) { /* URL unverändert */ }
			}
			return native.call(history, state, title, url);
		};
	});

	// 7. window.open
	var nativeWindowOpen = window.open;
	window.open = function (url) {
		var args = Array.prototype.slice.call(arguments);
		if (typeof url === 'string') args[0] = rewrite(url);
		return nativeWindowOpen.apply(window, args);
	};

	// 8. Sicherheitsnetz für src/href in JS-erzeugtem Markup (SPEC §4.3.4)
	function fixAttr(el, name) {
		var v = el.getAttribute(name);
		if (!v) return;
		var r = rewrite(v);
		if (r !== v) el.setAttribute(name, r);
	}
	function fixTree(node) {
		if (node.nodeType !== 1) return;
		if (node.hasAttribute('src')) fixAttr(node, 'src');
		if (node.hasAttribute('href')) fixAttr(node, 'href');
		var list = node.querySelectorAll('[src^="/api/v1/"],[src^="/audio_player.php"],[href^="/api/v1/"],[href^="/audio_player.php"]');
		for (var i = 0; i < list.length; i++) {
			fixAttr(list[i], 'src');
			fixAttr(list[i], 'href');
		}
	}
	new MutationObserver(function (records) {
		for (var i = 0; i < records.length; i++) {
			var r = records[i];
			if (r.type === 'attributes') fixAttr(r.target, r.attributeName);
			else for (var j = 0; j < r.addedNodes.length; j++) fixTree(r.addedNodes[j]);
		}
	}).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['src', 'href'] });

	// 9. Hell/Dunkel nach NC-Theme (ADR 0016); theme-manager.js wendet es über die Shims an
	var darkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
	function ncMode() {
		var themes = ((document.body && document.body.getAttribute('data-themes')) || '').split(',');
		if (themes.indexOf('dark') !== -1 || themes.indexOf('dark-highcontrast') !== -1) return 'dark';
		if (themes.indexOf('light') !== -1 || themes.indexOf('light-highcontrast') !== -1) return 'light';
		return darkQuery && darkQuery.matches ? 'dark' : 'light';
	}
	window.__poznoteThemeStorage = { get: ncMode, set: function () {} };
	window.__poznoteThemeList = [
		{ id: 'light', mode: 'light', icon: 'lucide-sun' },
		{ id: 'dark', mode: 'dark', icon: 'lucide-moon' },
	];
	function applyMode() {
		var mode = ncMode();
		if (typeof window.applyTheme === 'function') {
			window.applyTheme(mode, false);
			return;
		}
		var root = document.documentElement;
		root.setAttribute('data-theme', mode);
		root.classList.toggle('theme-dark', mode === 'dark');
		root.classList.toggle('theme-light', mode !== 'dark');
	}
	applyMode();
	if (document.body) {
		new MutationObserver(function () {
			if (document.documentElement.getAttribute('data-theme') !== ncMode()) applyMode();
		}).observe(document.body, { attributes: true });
	}
	if (darkQuery && darkQuery.addEventListener) darkQuery.addEventListener('change', applyMode);

	// 10. Neue Editor-Sitzung je Laden außer Reload (B13: duplizierte Tabs = eigene Sitzung)
	try {
		var nav = performance.getEntriesByType('navigation')[0];
		if (!nav || nav.type !== 'reload') sessionStorage.removeItem('poznote_editor_session_id');
	} catch (e) { /* ohne sessionStorage nutzt Upstream eine Fallback-ID */ }

	// 11. P2: Upstream-Inline-Handler (onclick/onchange) sind data-action; feste Zuordnung Aktion → Upstream-Funktion
	function idArg(v) {
		return v === undefined || v === '' || v === 'null' || v === 'undefined' ? null : Number(v);
	}
	function call(name, args) {
		var fn = window[name];
		return typeof fn === 'function' ? fn.apply(window, args) : undefined;
	}
	function task(name, withEvent) {
		return function (el, e) {
			var args = [idArg(el.dataset.taskId), idArg(el.dataset.noteId)];
			if (withEvent) args.push(e);
			return call(name, args);
		};
	}
	function subtask(name) {
		return function (el) {
			return call(name, [idArg(el.dataset.taskId), idArg(el.dataset.subtaskId), idArg(el.dataset.noteId)]);
		};
	}
	// Aufgabenlisten werden im Notizinhalt gerendert (Ausnahme vom Klick-Schutz, B26); change = Checkbox
	var CONTENT_ACTIONS = Object.create(null);
	CONTENT_ACTIONS['task-toggle'] = { type: 'change', run: task('toggleTask') };
	CONTENT_ACTIONS['task-edit'] = { type: 'click', run: task('editTask') };
	CONTENT_ACTIONS['task-delete'] = { type: 'click', run: task('deleteTask') };
	CONTENT_ACTIONS['task-move'] = { type: 'click', run: task('openMoveTaskModal') };
	CONTENT_ACTIONS['task-subtask'] = { type: 'click', run: task('openSubtaskInput', true) };
	CONTENT_ACTIONS['task-important'] = { type: 'click', run: task('toggleImportant') };
	CONTENT_ACTIONS['task-menu'] = { type: 'click', run: task('openTaskActionsMenu', true) };
	CONTENT_ACTIONS['task-due'] = { type: 'click', run: task('openTaskDueDatePicker', true) };
	CONTENT_ACTIONS['subtask-toggle'] = { type: 'change', run: subtask('toggleSubtask') };
	CONTENT_ACTIONS['subtask-edit'] = { type: 'click', run: subtask('editSubtask') };
	CONTENT_ACTIONS['subtask-delete'] = { type: 'click', run: subtask('deleteSubtask') };
	CONTENT_ACTIONS['task-link'] = { type: 'click', run: function (el, e) { return call('handleTaskLinkClick', [e]); } };
	// Außerhalb des Notizinhalts (Modals, Anhangliste, Kanban-Fehler)
	var UI_ACTIONS = Object.create(null);
	UI_ACTIONS['close-input-modal'] = { type: 'click', run: function () { return call('closeInputModal', []); } };
	UI_ACTIONS['confirm-input-modal'] = { type: 'click', run: function () { return call('executeInputModalAction', []); } };
	UI_ACTIONS['close-image-link-modal'] = { type: 'click', run: function () { return call('closeImageLinkModal', []); } };
	UI_ACTIONS['close-kanban-view'] = { type: 'click', run: function () { return call('closeKanbanView', []); } };
	UI_ACTIONS['delete-attachment'] = { type: 'click', run: function (el) { return call('deleteAttachment', [el.dataset.attachmentId]); } };
	UI_ACTIONS['transcribe-attachment'] = { type: 'click', run: function (el) { return call('transcribeAttachmentFromList', [el.dataset.attachmentId]); } };
	// Capture auf document: nach dem Klick-Schutz (window), vor Upstream-Delegation; nur das innerste data-action zählt
	['click', 'change'].forEach(function (type) {
		document.addEventListener(type, function (e) {
			var el = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
			if (!el) return;
			var action = el.getAttribute('data-action');
			var inContent = !!el.closest('.noteentry');
			var map = inContent ? CONTENT_ACTIONS : UI_ACTIONS;
			if (!Object.hasOwn(map, action) || map[action].type !== type) return;
			if (map[action].run(el, e) === false) e.preventDefault();
		}, true);
	});

	// 12. Klicks auf eingeschleuste App-Bedienelemente im Notizinhalt erreichen die Upstream-Delegation nicht (B26)
	var GENERIC_CLASS = /^(lucide(-.*)?|cursor-pointer|hidden|initially-hidden|active|selected|show|open|disabled|is-.*|has-.*)$/;
	function injectedControl(target) {
		var entry = target && target.closest ? target.closest('.noteentry') : null;
		if (!entry) return false;
		for (var el = target; el && el !== entry; el = el.parentElement) {
			var action = el.getAttribute('data-action');
			// data-action entfernt der Sanitizer aus Inhalten: belegte Inhaltsaktion = von der App gerendert (Aufgabenliste)
			if (action !== null) return !Object.hasOwn(CONTENT_ACTIONS, action);
			if (el.id && document.querySelectorAll('[id="' + CSS.escape(el.id) + '"]').length > 1) return true;
			for (var i = 0; i < el.classList.length; i++) {
				var c = el.classList[i];
				if (!GENERIC_CLASS.test(c) && document.querySelector('.' + CSS.escape(c) + ':not(.noteentry, .noteentry *)')) return true;
			}
		}
		return false;
	}
	['click', 'dblclick', 'auxclick'].forEach(function (type) {
		window.addEventListener(type, function (e) {
			if (injectedControl(e.target)) e.stopImmediatePropagation();
		}, true);
	});

	// 13. Inhalt erst nach dem Start zeigen (gegen Flackern; CSS-Rückfall nach 2 s)
	document.addEventListener('DOMContentLoaded', function () {
		document.documentElement.classList.add('nextnotes-ready');
	});

	window.NEXTNOTES = {
		config: config,
		url: rewrite,
		requestToken: token,
		contentActions: CONTENT_ACTIONS,
	};
})();
