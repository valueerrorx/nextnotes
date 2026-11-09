/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Nach nextnotes-app.js: poznoteAssetUrl zurück auf den App-Pfad (SPEC §4.3.9), Ergänzungen nach dem App-Bundle.
(function () {
	'use strict';

	var config = (window.NEXTNOTES && window.NEXTNOTES.config) || {};

	// globals.js (im App-Bundle) überschreibt poznoteAssetUrl seitenrelativ: Adapter-Fassung aus nextnotes-bootstrap.js zurück
	if (window.NEXTNOTES && window.NEXTNOTES.assetUrl) window.poznoteAssetUrl = window.NEXTNOTES.assetUrl;

	// Schnellknöpfe der Ordnerzeile (M3-ui1): Enter/Leertaste löst den Knopf aus, nicht die Baum-Tastatur (tree-keyboard-nav.js)
	document.addEventListener('keydown', function (e) {
		var t = e.target;
		if ((e.key === 'Enter' || e.key === ' ') && t && t.closest && t.closest('.nn-folder-quick')) e.stopPropagation();
	}, true);

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

	// Gewählten Workspace als zuletzt geöffneten merken (wie die Hauptansicht), best effort; überlebt die folgende Navigation
	function rememberWorkspace(ws) {
		if (!ws) return;
		fetch('api/v1/settings/last_opened_workspace', {
			method: 'PUT', credentials: 'same-origin', keepalive: true,
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
			body: JSON.stringify({ value: ws })
		}).catch(function () {});
	}

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
		var item = target.closest('a[data-workspace]');
		if (item) rememberWorkspace(item.getAttribute('data-workspace'));
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

	// Dashboard (UI-dashws): Workspace-Kopf, Brotkrumen und Kennzeichen führen in die Hauptansicht des Workspace; jeder Wechsel wird gemerkt
	function dashWsUrl(ws) {
		return 'index.php?' + new URLSearchParams({ workspace: ws }).toString();
	}
	function dashLinkify() {
		Array.prototype.forEach.call(document.querySelectorAll('#dashboardGrid .dash-group-title > .dash-group-name'), function (name) {
			var a = document.createElement('a');
			a.className = 'nn-dash-ws-link';
			a.href = dashWsUrl(name.textContent);
			var title = name.parentNode;
			var mark = name.previousElementSibling;
			title.insertBefore(a, mark || name);
			if (mark) a.appendChild(mark);
			a.appendChild(name);
		});
		var crumb = document.querySelector('#dashboardBreadcrumb span.bc-workspace');
		if (crumb) {
			var link = document.createElement('a');
			link.className = 'bc-workspace nn-dash-ws-link';
			while (crumb.firstChild) link.appendChild(crumb.firstChild);
			link.href = dashWsUrl(link.textContent);
			crumb.replaceWith(link);
		}
	}
	function dashInit() {
		if (!document.querySelector('#nextnotes-root.dashboard-page')) return;
		var observer = new MutationObserver(dashLinkify);
		['dashboardGrid', 'dashboardBreadcrumb'].forEach(function (id) {
			var el = document.getElementById(id);
			if (el) observer.observe(el, { childList: true });
		});
		dashLinkify();
		// Kennzeichen auf Karten (Fußzeile, Ordnerkarte): Klick öffnet den Workspace statt der Karte; nicht im Auswahlmodus
		document.addEventListener('click', function (e) {
			var badge = e.target.closest && e.target.closest('#dashboardGrid .board-card-ws, #dashboardGrid .dash-card-ws');
			var bar = document.getElementById('dashboardSelectionBar');
			if (!badge || e.button !== 0 || (bar && !bar.hidden)) return;
			var ws = (badge.querySelector('.dash-ws-name') || badge).textContent;
			e.preventDefault();
			e.stopPropagation();
			rememberWorkspace(ws);
			if (e.ctrlKey || e.metaKey) window.open(dashWsUrl(ws), '_blank', 'noopener'); else window.location.href = dashWsUrl(ws);
		}, true);
		// Jeder Link vom Board in die Hauptansicht (Karte, Kopf, „Im Editor öffnen“) merkt dessen Workspace; nach den Upstream-Handlern (Modal, Auswahl)
		window.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('#dashboardGrid a[href], #dashboardBreadcrumb a[href], #dashboardNoteModalOpen');
			if (!a || e.button !== 0 || (e.defaultPrevented && a.id !== 'dashboardNoteModalOpen')) return;
			var url = new URL(a.href, window.location.href);
			if (url.origin === window.location.origin && /\/index\.php$/.test(url.pathname)) rememberWorkspace(url.searchParams.get('workspace'));
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', dashInit); else dashInit();

	// Mobil (UI-nav-mobil): Zurück des Browsers auf eine URL ohne Notiz/Kanban zeigt wieder die Liste (Upstream: Wischen über body.scrollLeft)
	window.addEventListener('popstate', function () {
		if (window.innerWidth > 800 || !document.body.classList.contains('note-open') || typeof window.scrollToLeftColumn !== 'function') return;
		var params = new URLSearchParams(window.location.search);
		if (!params.get('note') && !params.get('kanban')) window.scrollToLeftColumn();
	});

	// Excalidraw-Editor (UI-excal, ADR 0017 Nachtrag): NC-Kern-Regeln, die in .excalidraw greifen (Formulare inkl. Hover/Fokus, Überschriften,
	// kbd, Grund-Reset mit cursor, box-sizing) erhalten je Selektor :where(:not(.excalidraw *)); Spezifität und Wirkung außerhalb unverändert.
	// CSS-Gegenregeln reichen nicht: sie setzen nur auf Browser-Standard zurück, nicht auf den Excalidraw-Wert (z. B. Farbfeld beim Hover).
	var EXCAL_GUARD = ':where(:not(.excalidraw *))';
	// Teil-Selektoren aus core/css (inputs.scss, apps.scss, styles.scss), die nachweislich Excalidraw-Elemente treffen (E2E excalidraw-toolbar)
	var EXCAL_NC_PARTS = [
		/(^|[\s>+~(])(button|input|select|textarea|label|kbd|h[2-6]|em|dl|dt|dd)(?![\w-])/i, // Formulare, Überschriften, kbd
		/(^|[\s>+~(])(div|span|section|footer|a|p|ul|li|form|fieldset|legend)(?![\w-])/i, // Grund-Reset (cursor: default …)
		/\*$/, // #content[class*=app-] * (box-sizing), button * (cursor)
		/:focus-visible/ // Fokusring
	];
	var EXCAL_PSEUDO_ELEMENT = /^(::|:(before|after|first-line|first-letter)\b)/i;
	var excalGuardSelector = function (sel) {
		var out = [], depth = 0, quote = '', start = 0, pe = -1, changed = false;
		for (var i = 0; i <= sel.length; i++) {
			var c = sel.charAt(i);
			if (quote) {
				if (c === '\\') i++; else if (c === quote) quote = '';
				continue;
			}
			if (c === '\\') i++;
			else if (c === '"' || c === "'") quote = c;
			else if (c === '(' || c === '[') depth++;
			else if (c === ')' || c === ']') depth--;
			else if (depth === 0 && c === ':' && pe < 0 && EXCAL_PSEUDO_ELEMENT.test(sel.slice(i, i + 14))) pe = i;
			else if (depth === 0 && (c === ',' || i === sel.length)) {
				var cut = pe < 0 ? i : pe, part = sel.slice(start, cut).trim();
				var hit = part.indexOf(EXCAL_GUARD) < 0 && EXCAL_NC_PARTS.some(function (re) { return re.test(part); });
				out.push((part + (hit ? EXCAL_GUARD : '') + sel.slice(cut, i)).trim());
				changed = changed || hit;
				start = i + 1;
				pe = -1;
			}
		}
		return changed ? out.join(', ') : sel;
	};
	var excalGuarded = new WeakSet();
	var excalGuardRules = function (rules) {
		for (var k = 0; k < rules.length; k++) {
			var rule = rules[k];
			if (rule.selectorText) {
				var next = excalGuardSelector(rule.selectorText);
				if (next !== rule.selectorText) rule.selectorText = next;
			} else if (rule.cssRules) excalGuardRules(rule.cssRules);
		}
	};
	// nur NC-Kern-Stylesheets (core/css), jedes einmal; fehlend, nicht geladen oder fremder Ursprung → übersprungen
	var excalGuardSheets = function () {
		Array.prototype.forEach.call(document.styleSheets, function (sheet) {
			if (excalGuarded.has(sheet) || !sheet.href || !/\/core\/css\//.test(sheet.href)) return;
			var rules;
			try { rules = sheet.cssRules; } catch (e) { return; }
			excalGuarded.add(sheet);
			excalGuardRules(rules);
		});
	};
	// erst, wenn ein Excalidraw-Editor gemountet wird
	var excalRoot = document.querySelector('#nextnotes-root.nextnotes-excalidraw');
	if (excalRoot) {
		var excalStart = function () {
			excalGuardSheets();
			window.addEventListener('load', excalGuardSheets);
		};
		if (excalRoot.querySelector('.excalidraw')) excalStart();
		else {
			var excalObserver = new MutationObserver(function () {
				if (!excalRoot.querySelector('.excalidraw')) return;
				excalObserver.disconnect();
				excalStart();
			});
			excalObserver.observe(excalRoot, { childList: true, subtree: true });
		}
	}
})();
