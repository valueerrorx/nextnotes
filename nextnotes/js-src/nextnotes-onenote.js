// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// OneNote-Import (templates/onenote.php, Plan M9): Verbinden (Top-Level-Navigation zu Microsoft), Auswahl als Positivliste, Fortschritt, Abbrechen; POST mit requesttoken.
(function () {
	'use strict';

	var MAX_SELECTION = 200;
	var POLL_MS = 15000;

	function init() {
		var root = document.getElementById('nextnotes-onenote');
		if (!root) return;
		var errorBox = document.getElementById('nextnotes-onenote-error');
		var id = root.dataset.importId;
		var importUrl = root.dataset.importsUrl + '/' + encodeURIComponent(id);
		// Notizbuch-Schlüssel → {nb, box, items: [{item, box}]}
		var notebooks = [];
		var teamsNext = 0;

		function msg(code) {
			var el = root.querySelector('#nextnotes-onenote-messages [data-code="' + code + '"]');
			return el ? el.textContent : '';
		}

		function token() {
			return (document.head && document.head.dataset.requesttoken) || (window.OC && window.OC.requestToken) || '';
		}

		function request(method, url, body) {
			var opts = {
				method: method,
				credentials: 'same-origin',
				headers: { Accept: 'application/json', requesttoken: token() }
			};
			if (method === 'POST') {
				opts.headers['Content-Type'] = 'application/json';
				opts.body = JSON.stringify(body || {});
			}
			return fetch(url, opts).then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (data) {
					return { status: r.status, data: data || {} };
				});
			});
		}

		function fail(res) {
			var code = res && res.data && res.data.code;
			errorBox.textContent = (code && msg(code)) || msg('error');
		}

		function busy(on) {
			root.querySelectorAll('button[data-action]').forEach(function (b) { b.disabled = on; });
			if (!on) updateStart();
		}

		function reload() {
			window.location.assign(window.location.pathname + '?import=' + encodeURIComponent(id));
		}

		// ---------- Auswahl ----------

		function selection() {
			var out = [];
			notebooks.forEach(function (n) {
				if (n.box.checked) {
					out.push({ root: n.nb.root, notebook: n.nb.id, name: n.nb.name, items: null });
					return;
				}
				var items = n.items.filter(function (i) { return i.box.checked; }).map(function (i) {
					return { kind: i.item.kind, id: i.item.id };
				});
				if (items.length) out.push({ root: n.nb.root, notebook: n.nb.id, name: n.nb.name, items: items });
			});
			return out;
		}

		function count(sel) {
			return sel.reduce(function (sum, e) { return sum + 1 + (e.items ? e.items.length : 0); }, 0);
		}

		function updateStart() {
			var start = root.querySelector('button[data-action="start"]');
			if (!start) return;
			var sel = selection();
			start.disabled = sel.length === 0 || count(sel) > MAX_SELECTION;
			errorBox.textContent = count(sel) > MAX_SELECTION ? msg('too_many') : '';
		}

		// Notizbuch-Häkchen = ganzes Notizbuch; einzelne Elemente nur ohne Häkchen am Notizbuch
		function syncNotebook(n) {
			n.items.forEach(function (i) {
				i.box.disabled = n.box.checked;
				if (n.box.checked) i.box.checked = true;
			});
			n.box.indeterminate = !n.box.checked && n.items.some(function (i) { return i.box.checked; });
		}

		function checkbox(idText, label, checked) {
			var wrap = document.createElement('div');
			wrap.className = 'nn-onenote-check';
			var box = document.createElement('input');
			box.type = 'checkbox';
			box.className = 'checkbox';
			box.id = idText;
			box.checked = checked;
			var lab = document.createElement('label');
			lab.htmlFor = idText;
			lab.textContent = label;
			wrap.appendChild(box);
			wrap.appendChild(lab);
			return { wrap: wrap, box: box };
		}

		function group(title) {
			var key = 'nn-on-g-' + title;
			var section = document.getElementById(key);
			if (section) return section.querySelector('ul');
			section = document.createElement('section');
			section.id = key;
			section.className = 'nn-onenote-group';
			var h = document.createElement('h3');
			h.textContent = title;
			var ul = document.createElement('ul');
			section.appendChild(h);
			section.appendChild(ul);
			document.getElementById('nextnotes-onenote-tree').appendChild(section);
			return ul;
		}

		function renderNotebook(nb) {
			var index = notebooks.length;
			var ul = group(nb.team === null ? msg('mine') : msg('team').replace('%s', nb.team));
			var li = document.createElement('li');
			li.className = 'nn-onenote-notebook';
			li.dataset.root = nb.root;
			li.dataset.notebook = nb.id;
			var main = checkbox('nn-on-nb-' + index, nb.name || nb.id, !!nb.preselected);
			main.box.title = msg('whole');
			li.appendChild(main.wrap);
			var n = { nb: nb, box: main.box, items: [] };
			if (nb.items.length) {
				var toggle = document.createElement('button');
				toggle.type = 'button';
				toggle.className = 'nn-onenote-toggle';
				toggle.textContent = msg('show_items');
				toggle.setAttribute('aria-expanded', 'false');
				var sub = document.createElement('ul');
				sub.className = 'nn-onenote-items';
				sub.hidden = true;
				nb.items.forEach(function (item, k) {
					var row = document.createElement('li');
					var label = item.kind === 'section_group' ? (item.name || item.id) + ' (' + msg('group') + ')' : (item.name || item.id);
					var c = checkbox('nn-on-it-' + index + '-' + k, label, !!nb.preselected);
					row.dataset.kind = item.kind;
					row.dataset.item = item.id;
					row.appendChild(c.wrap);
					sub.appendChild(row);
					n.items.push({ item: item, box: c.box });
					c.box.addEventListener('change', function () {
						syncNotebook(n);
						updateStart();
					});
				});
				toggle.addEventListener('click', function () {
					sub.hidden = !sub.hidden;
					toggle.setAttribute('aria-expanded', sub.hidden ? 'false' : 'true');
				});
				li.appendChild(toggle);
				li.appendChild(sub);
			}
			main.box.addEventListener('change', function () {
				if (!main.box.checked) n.items.forEach(function (i) { i.box.checked = false; });
				syncNotebook(n);
				updateStart();
			});
			notebooks.push(n);
			syncNotebook(n);
			ul.appendChild(li);
			if (nb.team !== null) document.getElementById('nextnotes-onenote-team-hint').hidden = false;
		}

		function loadTree(offset) {
			var loading = document.getElementById('nextnotes-onenote-loading');
			var more = document.getElementById('nextnotes-onenote-more-teams');
			loading.hidden = false;
			more.hidden = true;
			return request('GET', importUrl + '/tree' + (offset ? '?teams_offset=' + offset : '')).then(function (res) {
				loading.hidden = true;
				if (res.status !== 200) {
					fail(res);
					return;
				}
				(res.data.notebooks || []).forEach(renderNotebook);
				if (!notebooks.length) loading.hidden = false, loading.textContent = msg('empty');
				teamsNext = res.data.teams_next || 0;
				more.hidden = !res.data.teams_more;
				updateStart();
			}).catch(function () {
				loading.hidden = true;
				fail(null);
			});
		}

		// ---------- Fortschritt ----------

		function etaText(seconds) {
			if (seconds === null || seconds === undefined || seconds === '') return '';
			var min = Math.max(10, Math.round(Number(seconds) / 60));
			var h = Math.floor(min / 60);
			var text = h > 0 ? msg('eta_h').replace('%1$s', h).replace('%2$s', min % 60) : msg('eta_m').replace('%s', min);
			return msg('eta').replace('%s', text);
		}

		function showProgress(imp) {
			['pages_done', 'pages_total', 'pages_skipped', 'resources_failed'].forEach(function (f) {
				var el = root.querySelector('[data-field="' + f + '"]');
				if (el) el.textContent = String(imp[f]);
			});
			var eta = document.getElementById('nextnotes-onenote-eta');
			if (eta) eta.textContent = etaText(imp.eta_seconds);
		}

		function poll() {
			request('GET', importUrl).then(function (res) {
				if (res.status !== 200 || !res.data.import) return;
				if (res.data.import.status !== root.dataset.status) {
					reload();
					return;
				}
				showProgress(res.data.import);
				window.setTimeout(poll, POLL_MS);
			}).catch(function () {
				window.setTimeout(poll, POLL_MS);
			});
		}

		// ---------- Aktionen ----------

		root.addEventListener('click', function (ev) {
			var button = ev.target.closest('button[data-action]');
			if (!button) return;
			var action = button.dataset.action;
			if (action === 'more-teams') {
				loadTree(teamsNext);
				return;
			}
			errorBox.textContent = '';
			busy(true);
			var req;
			if (action === 'connect' || action === 'reauth') {
				req = request('POST', root.dataset.connectUrl, action === 'reauth' ? { import_id: Number(id) } : {}).then(function (res) {
					if (res.status === 200 && res.data.authorize_url) {
						window.location.assign(res.data.authorize_url);
						return;
					}
					fail(res);
					busy(false);
				});
			} else if (action === 'start') {
				req = request('POST', importUrl + '/start', { selection: selection() }).then(function (res) {
					if (res.status === 200) {
						reload();
						return;
					}
					fail(res);
					busy(false);
				});
			} else if (action === 'cancel') {
				req = request('POST', importUrl + '/cancel').then(function (res) {
					if (res.status === 200) {
						reload();
						return;
					}
					fail(res);
					busy(false);
				});
			} else {
				busy(false);
				return;
			}
			req.catch(function () {
				fail(null);
				busy(false);
			});
		});

		var status = root.dataset.status;
		if (status === 'select') loadTree(0);
		var eta = document.getElementById('nextnotes-onenote-eta');
		if (eta) eta.textContent = etaText(eta.dataset.eta);
		if (status === 'queued' || status === 'running') window.setTimeout(poll, POLL_MS);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
