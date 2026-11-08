/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Aufgaben-Seite (M6g, SPEC §9.6, Plan M6 §2.6): Liste mit Status und Frist, Erstellen-Dialog (Notiz-Suche, ?create=<Notiz> aus dem Notiz-Menü),
// Lehrkraft-Ansicht mit Austeilen, Frist, Schließen, ZIP, Abgabenliste und Lesemodus (Druckansicht im iFrame) mit Feedback, Rückgabe, Wiederöffnen.
// Daten nur über die Aufgaben-API (Rechte serverseitig); Texte aus der Insel nextnotes-assignments (NC-l10n). Schüler-Ansicht folgt M6g2.
(function () {
	'use strict';

	var root = document.querySelector('#nextnotes-root .nn-asg');
	var islandEl = document.getElementById('nextnotes-assignments');
	if (!root || !islandEl) return;
	var island = {};
	try {
		island = JSON.parse(islandEl.textContent || '{}');
	} catch (e) {
		island = {};
	}
	var config = (window.NEXTNOTES && window.NEXTNOTES.config) || {};
	var baseUrl = String(config.baseUrl || '');
	var apiBase = String(config.apiBase || 'api/v1').replace(/\/$/, '');
	var txt = island.txt || {};
	var teacherTeams = Array.isArray(island.teams) ? island.teams : [];
	var listEl = document.getElementById('nnAsgList');
	var detailEl = document.getElementById('nnAsgDetail');
	var statusEl = document.getElementById('nnAsgStatus');
	var teamSelect = document.getElementById('nnAsgTeam');
	var params = new URLSearchParams(window.location.search);
	var state = { list: [], team: params.get('team') || '', current: null, subs: [], pending: null };
	var dateFmt = new Intl.DateTimeFormat(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' });

	function t(key, vars) {
		var s = String(txt[key] || key);
		Object.keys(vars || {}).forEach(function (k) { s = s.split('{' + k + '}').join(String(vars[k])); });
		return s;
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined && text !== null) node.textContent = String(text);
		return node;
	}

	function button(label, className, onClick, icon) {
		var b = el('button', className || 'btn-secondary');
		b.type = 'button';
		if (icon) {
			var i = el('i', 'lucide ' + icon);
			i.setAttribute('aria-hidden', 'true');
			b.appendChild(i);
		}
		b.appendChild(el('span', '', label));
		b.addEventListener('click', onClick);
		return b;
	}

	function api(method, path, body) {
		var init = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
		if (body !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(body);
		}
		return fetch('api/v1/' + path, init).then(function (resp) {
			return resp.json().catch(function () { return {}; }).then(function (data) {
				return { status: resp.status, ok: resp.ok && data.success !== false, data: data || {} };
			});
		}, function () {
			return { status: 0, ok: false, data: {} };
		});
	}

	function fmtDate(ts) {
		return ts ? dateFmt.format(new Date(ts * 1000)) : '';
	}

	function fmtBytes(n) {
		var mb = n / 1048576;
		return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : (mb >= 10 ? Math.round(mb) : mb.toFixed(1)) + ' MB';
	}

	function now() {
		return Math.floor(Date.now() / 1000);
	}

	// datetime-local ↔ Unix-Sekunden (Ortszeit des Browsers)
	function toLocalInput(ts) {
		var d = new Date(ts * 1000);
		var pad = function (v) { return String(v).padStart(2, '0'); };
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}

	function fromLocalInput(value) {
		var d = new Date(value);
		return isNaN(d.getTime()) ? null : Math.floor(d.getTime() / 1000);
	}

	function defaultDue() {
		var d = new Date();
		d.setDate(d.getDate() + 7);
		d.setHours(23, 59, 0, 0);
		return Math.floor(d.getTime() / 1000);
	}

	function say(message, isError) {
		statusEl.textContent = message || '';
		statusEl.classList.toggle('nn-asg-status-error', !!isError);
	}

	function errorText(r) {
		var code = r.data && r.data.code;
		if (code === 'quota_exceeded' && r.data.needed !== undefined) {
			return t('quotaExceeded', { need: fmtBytes(r.data.needed || 0), available: fmtBytes(r.data.available || 0) });
		}
		var map = { deadline_passed: 'deadlinePassed', source_not_allowed: 'sourceNotAllowed', student_deleted: 'studentDeleted',
			invalid_transition: 'invalidTransition', invalid_state: 'invalidTransition', quota_exceeded: 'storageTooSmall' };
		if (map[code]) return t(map[code]);
		return (r.data && r.data.error) ? t('actionFailed') + ' (' + r.data.error + ')' : t('actionFailed');
	}

	function chip(kind, label) {
		return el('span', 'nn-asg-chip nn-asg-chip-' + kind, label);
	}

	var SUB_LABEL = { assigned: 'stAssigned', submitted: 'stSubmitted', returned: 'stReturned', missed: 'stMissed', reopened: 'stReopened' };

	function assignmentChip(a) {
		if (a.team_state === 'orphaned' || a.team_state === 'frozen') return chip('warning', a.team_state === 'orphaned' ? t('orphaned', { date: fmtDate(a.deletion_at) }) : t('frozen'));
		if (!a.can.review && a.submission) return chip(a.submission.status, t(SUB_LABEL[a.submission.status] || 'stAssigned'));
		return chip(a.status, t(a.status));
	}

	// ---------- Modal (Upstream-Stil .modal/.modal-content) ----------

	function modal(title, body, actions) {
		var overlay = el('div', 'modal nn-asg-modal');
		overlay.style.display = 'flex';
		var content = el('div', 'modal-content');
		content.setAttribute('role', 'dialog');
		content.setAttribute('aria-modal', 'true');
		var h = el('h3', '', title);
		h.id = 'nnAsgModalTitle' + Date.now();
		content.setAttribute('aria-labelledby', h.id);
		content.appendChild(h);
		content.appendChild(body);
		var error = el('div', 'modal-error-message nn-asg-modal-error');
		error.setAttribute('role', 'alert');
		content.appendChild(error);
		var row = el('div', 'modal-buttons');
		var close = function () {
			document.removeEventListener('keydown', onKey);
			overlay.remove();
		};
		var onKey = function (e) { if (e.key === 'Escape') close(); };
		row.appendChild(button(t('cancel'), 'btn-cancel', close));
		actions.forEach(function (a) {
			var b = button(a.label, a.danger ? 'btn-danger' : 'btn-primary', function () {
				error.textContent = '';
				b.disabled = true;
				Promise.resolve(a.run()).then(function (msg) {
					b.disabled = false;
					if (msg === true) close();
					else if (msg) error.textContent = msg;
				});
			});
			if (a.id) b.id = a.id;
			row.appendChild(b);
		});
		content.appendChild(row);
		overlay.appendChild(content);
		overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });
		document.addEventListener('keydown', onKey);
		document.getElementById('nextnotes-root').appendChild(overlay);
		var first = content.querySelector('input, select, textarea, button.btn-primary');
		if (first) first.focus();
		return { close: close, error: function (m) { error.textContent = m; } };
	}

	function field(label, input) {
		var wrap = el('label', 'nn-asg-field');
		wrap.appendChild(el('span', 'nn-asg-field-label', label));
		wrap.appendChild(input);
		return wrap;
	}

	function input(type, value, attrs) {
		var i = el('input');
		i.type = type;
		if (value !== undefined && value !== null) i.value = String(value);
		Object.keys(attrs || {}).forEach(function (k) { i.setAttribute(k, attrs[k]); });
		return i;
	}

	// ---------- Liste ----------

	function load() {
		listEl.setAttribute('aria-busy', 'true');
		return api('GET', 'assignments').then(function (r) {
			listEl.setAttribute('aria-busy', 'false');
			if (!r.ok) {
				say(t('loadFailed'), true);
				return;
			}
			state.list = Array.isArray(r.data.assignments) ? r.data.assignments : [];
			fillTeams();
			renderList();
		});
	}

	function fillTeams() {
		var names = {};
		teacherTeams.forEach(function (tm) { names[tm.id] = tm.name; });
		state.list.forEach(function (a) { names[a.team] = names[a.team] || a.team_name || a.team; });
		while (teamSelect.options.length > 1) teamSelect.remove(1);
		Object.keys(names).sort(function (x, y) { return names[x].localeCompare(names[y]); }).forEach(function (id) {
			var o = el('option', '', names[id]);
			o.value = id;
			teamSelect.appendChild(o);
		});
		teamSelect.value = names[state.team] ? state.team : '';
	}

	function rank(a) {
		return a.status === 'draft' ? 0 : (a.status === 'distributed' ? 1 : 2);
	}

	function renderList() {
		listEl.textContent = '';
		var items = state.list.filter(function (a) { return !state.team || a.team === state.team; });
		items.sort(function (x, y) {
			return rank(x) - rank(y) || (rank(x) === 2 ? y.due_at - x.due_at : x.due_at - y.due_at) || x.title.localeCompare(y.title);
		});
		if (!items.length) {
			listEl.appendChild(el('p', 'empty-message nn-asg-empty', teacherTeams.length ? t('emptyTeacher') : t('empty')));
			return;
		}
		var ul = el('ul', 'nn-asg-items');
		items.forEach(function (a) { ul.appendChild(row(a)); });
		listEl.appendChild(ul);
	}

	function row(a) {
		var li = el('li', 'nn-asg-item');
		li.setAttribute('data-assignment-id', String(a.id));
		var main = el(a.can.review ? 'button' : 'div', 'nn-asg-item-main');
		if (a.can.review) {
			main.type = 'button';
			main.addEventListener('click', function () { openDetail(a.id, true); });
		}
		var head = el('div', 'nn-asg-item-head');
		head.appendChild(el('span', 'nn-asg-item-title', a.title));
		head.appendChild(assignmentChip(a));
		main.appendChild(head);
		var meta = el('div', 'nn-asg-item-meta');
		var due = el('span', 'nn-asg-due' + (a.due_at < now() && a.status !== 'closed' ? ' nn-asg-due-passed' : ''), t('due', { date: fmtDate(a.due_at) }));
		meta.appendChild(due);
		meta.appendChild(el('span', 'nn-asg-item-team', a.team_name));
		if (a.can.review && a.status !== 'draft') {
			var by = a.submissions_by_status || {};
			var open = (by.assigned || 0) + (by.reopened || 0);
			meta.appendChild(el('span', 'nn-asg-counts', [t('countSubmitted', { n: a.submissions_submitted || 0 }), t('countOpen', { n: open }), t('countMissed', { n: by.missed || 0 })].join(' · ')));
		}
		main.appendChild(meta);
		if (a.not_distributed > 0) main.appendChild(el('div', 'nn-asg-hint', t(a.quota_full ? 'notDistributedQuota' : 'notDistributed', { n: a.not_distributed })));
		li.appendChild(main);
		return li;
	}

	function setQuery(key, value) {
		var p = new URLSearchParams(window.location.search);
		['assignment', 'create'].forEach(function (k) { p.delete(k); });
		if (value) p.set(key, value);
		window.history.replaceState(window.history.state, '', window.location.pathname + '?' + p.toString());
	}

	teamSelect.addEventListener('change', function () {
		state.team = teamSelect.value;
		var p = new URLSearchParams(window.location.search);
		if (state.team) p.set('team', state.team);
		else p.delete('team');
		window.history.replaceState(window.history.state, '', window.location.pathname + '?' + p.toString());
		if (!detailEl.hidden) closeDetail();
		renderList();
	});

	// ---------- Lehrkraft: Aufgabe mit Abgaben ----------

	function closeDetail() {
		detailEl.hidden = true;
		detailEl.textContent = '';
		listEl.hidden = false;
		state.current = null;
		setQuery('assignment', '');
		load();
	}

	function openDetail(id, push) {
		if (push) setQuery('assignment', String(id));
		return Promise.all([api('GET', 'assignments/' + id), api('GET', 'assignments/' + id + '/submissions')]).then(function (rs) {
			if (!rs[0].ok || !rs[1].ok) {
				say(t('loadFailed'), true);
				return;
			}
			say('');
			state.current = rs[0].data.assignment;
			state.subs = (rs[1].data.submissions || []).slice().sort(function (x, y) { return String(x.student_name).localeCompare(String(y.student_name)); });
			state.pending = { n: rs[1].data.not_distributed || 0, quota: !!rs[1].data.quota_full };
			listEl.hidden = true;
			detailEl.hidden = false;
			renderDetail();
		});
	}

	function link(label, href, icon, download) {
		var a = el('a', 'btn-secondary nn-asg-link');
		a.href = href;
		if (download) a.setAttribute('download', '');
		var i = el('i', 'lucide ' + icon);
		i.setAttribute('aria-hidden', 'true');
		a.appendChild(i);
		a.appendChild(el('span', '', label));
		return a;
	}

	function renderDetail() {
		var a = state.current;
		detailEl.textContent = '';
		detailEl.appendChild(button(t('back'), 'nn-asg-back', closeDetail, 'lucide-arrow-left'));
		var head = el('div', 'nn-asg-detail-head');
		head.appendChild(el('h2', 'nn-asg-detail-title', a.title));
		head.appendChild(assignmentChip(a));
		detailEl.appendChild(head);
		var meta = el('div', 'nn-asg-item-meta');
		meta.appendChild(el('span', 'nn-asg-due' + (a.due_at < now() && a.status !== 'closed' ? ' nn-asg-due-passed' : ''), t('due', { date: fmtDate(a.due_at) })));
		meta.appendChild(el('span', '', a.team_name));
		if (a.max_points !== null) meta.appendChild(el('span', '', t('points') + ': ' + a.max_points));
		if (a.created_by_name) meta.appendChild(el('span', '', t('owner', { name: a.created_by_name })));
		detailEl.appendChild(meta);

		var bar = el('div', 'nn-asg-actions');
		var open = a.status === 'draft' || a.status === 'distributed';
		if (a.template_note_id) {
			bar.appendChild(link(a.status === 'draft' && a.can.edit ? t('editTemplate') : t('viewTemplate'), baseUrl + 'index.php?note=' + encodeURIComponent(a.template_note_id), a.status === 'draft' && a.can.edit ? 'lucide-pencil' : 'lucide-eye'));
		}
		if (a.can.distribute && open) bar.appendChild(button(t('distribute'), 'btn-primary', function () { distribute(a); }, 'lucide-send'));
		if (a.can.edit && open) bar.appendChild(button(t('extend'), 'btn-secondary', function () { extend(a); }, 'lucide-calendar'));
		if (a.can.close && a.status === 'distributed') bar.appendChild(button(t('closeAssignment'), 'btn-secondary', function () { closeAssignment(a); }, 'lucide-lock'));
		if (a.can.export && a.status !== 'draft') bar.appendChild(link(t('export'), apiBase + '/assignments/' + a.id + '/export', 'lucide-download', true));
		detailEl.appendChild(bar);
		if (state.pending && state.pending.n > 0) detailEl.appendChild(el('div', 'nn-asg-hint', t(state.pending.quota ? 'notDistributedQuota' : 'notDistributed', { n: state.pending.n })));

		var body = el('div', 'nn-asg-detail-body');
		detailEl.appendChild(body);
		renderSubmissions(body);
	}

	function renderSubmissions(body) {
		var a = state.current;
		body.textContent = '';
		body.appendChild(el('h3', 'nn-asg-section', t('submissions')));
		if (!state.subs.length) {
			body.appendChild(el('p', 'nn-asg-empty', t('noSubmissions')));
			return;
		}
		var table = el('table', 'nn-asg-table');
		var thead = el('thead');
		var hr = el('tr');
		[t('student'), t('status'), t('handedInAt'), t('points'), t('feedback')].forEach(function (h) { hr.appendChild(el('th', '', h)); });
		thead.appendChild(hr);
		table.appendChild(thead);
		var tbody = el('tbody');
		state.subs.forEach(function (s, i) {
			var tr = el('tr', 'nn-asg-sub');
			tr.setAttribute('data-submission-id', String(s.id));
			var nameCell = el('td');
			var open = button(s.student_name || '?', 'nn-asg-sub-open', function () { openReview(i); });
			open.setAttribute('aria-label', t('review') + ': ' + (s.student_name || ''));
			nameCell.appendChild(open);
			if (s.student_deleted) nameCell.appendChild(el('span', 'nn-asg-tag', t('deleted')));
			else if (s.left_team_at) nameCell.appendChild(el('span', 'nn-asg-tag', t('left')));
			tr.appendChild(nameCell);
			var st = el('td');
			st.appendChild(chip(s.status, t(SUB_LABEL[s.status] || 'stAssigned')));
			tr.appendChild(st);
			tr.appendChild(el('td', '', fmtDate(s.submitted_at)));
			tr.appendChild(el('td', '', s.points === null ? '' : (a.max_points !== null ? s.points + ' / ' + a.max_points : String(s.points))));
			var fb = el('td');
			if (s.feedback) {
				var i2 = el('i', 'lucide lucide-message-square');
				i2.title = s.feedback;
				i2.setAttribute('aria-label', t('feedback'));
				fb.appendChild(i2);
			}
			tr.appendChild(fb);
			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		body.appendChild(table);
	}

	// Lesemodus: Druckansicht der Kopie (serverseitig bereinigt, requireNote) neben Feedback/Punkten
	function openReview(i) {
		var a = state.current;
		var s = state.subs[i];
		var body = detailEl.querySelector('.nn-asg-detail-body');
		body.textContent = '';
		var nav = el('div', 'nn-asg-review-nav');
		nav.appendChild(button(t('submissions'), 'nn-asg-back', function () { renderSubmissions(body); }, 'lucide-arrow-left'));
		var prev = button(t('previous'), 'btn-secondary', function () { openReview(i - 1); }, 'lucide-chevron-left');
		prev.disabled = i === 0;
		var next = button(t('next'), 'btn-secondary', function () { openReview(i + 1); }, 'lucide-chevron-right');
		next.disabled = i >= state.subs.length - 1;
		nav.appendChild(prev);
		nav.appendChild(next);
		body.appendChild(nav);
		var title = el('div', 'nn-asg-review-head');
		title.appendChild(el('strong', '', s.student_name || '?'));
		title.appendChild(chip(s.status, t(SUB_LABEL[s.status] || 'stAssigned')));
		if (s.submitted_at) title.appendChild(el('span', 'nn-asg-muted', t('handedInAt') + ': ' + fmtDate(s.submitted_at)));
		body.appendChild(title);

		var split = el('div', 'nn-asg-review');
		var viewer = el('div', 'nn-asg-review-doc');
		if (s.note_id) {
			var frame = el('iframe', 'nn-asg-frame');
			frame.title = t('readOnlyCopy');
			frame.src = baseUrl + 'api_export_note.php?id=' + encodeURIComponent(s.note_id) + '&format=html&disposition=inline';
			viewer.appendChild(frame);
		} else {
			viewer.appendChild(el('p', 'nn-asg-empty', t('noCopy')));
		}
		split.appendChild(viewer);
		split.appendChild(feedbackPanel(a, s, i));
		body.appendChild(split);
	}

	function feedbackPanel(a, s, i) {
		var panel = el('div', 'nn-asg-feedback');
		var canAct = !!a.can.edit;
		var fbStates = ['submitted', 'missed', 'returned'];
		var area = el('textarea', 'nn-asg-feedback-text');
		area.rows = 8;
		area.placeholder = t('feedbackPlaceholder');
		area.value = s.feedback || '';
		area.disabled = !canAct || fbStates.indexOf(s.status) === -1;
		panel.appendChild(field(t('feedback'), area));
		var pts = input('number', s.points === null ? '' : s.points, { min: '0', step: '1' });
		if (a.max_points !== null) pts.max = String(a.max_points);
		pts.disabled = area.disabled;
		var ptsField = field(t('points'), pts);
		if (a.max_points !== null) ptsField.appendChild(el('span', 'nn-asg-muted', t('pointsOf', { max: a.max_points })));
		panel.appendChild(ptsField);
		if (s.feedback_by_name) panel.appendChild(el('p', 'nn-asg-muted', t('returnedBy', { name: s.feedback_by_name }) + (s.feedback_at ? ' · ' + fmtDate(s.feedback_at) : '')));
		var msg = el('p', 'nn-asg-feedback-msg');
		msg.setAttribute('role', 'status');

		var payload = function () {
			var out = { feedback: area.value };
			if (pts.value !== '') {
				var n = Number(pts.value);
				if (!Number.isInteger(n) || n < 0 || (a.max_points !== null && n > a.max_points)) {
					msg.textContent = t('invalidPoints', { max: a.max_points === null ? '∞' : a.max_points });
					return null;
				}
				out.points = n;
			}
			return out;
		};
		var after = function (r) {
			if (!r.ok) {
				msg.textContent = errorText(r);
				return;
			}
			state.subs[i] = r.data.submission || state.subs[i];
			openReview(i);
			var m = detailEl.querySelector('.nn-asg-feedback-msg');
			if (m) m.textContent = t('saved');
		};
		var actions = el('div', 'nn-asg-feedback-actions');
		if (canAct && fbStates.indexOf(s.status) !== -1) {
			actions.appendChild(button(t('saveFeedback'), 'btn-secondary', function () {
				var p = payload();
				if (p) api('PUT', 'submissions/' + s.id + '/feedback', p).then(after);
			}));
		}
		if (canAct && (s.status === 'submitted' || s.status === 'missed')) {
			actions.appendChild(button(t('giveBack'), 'btn-primary', function () {
				var p = payload();
				if (p) api('POST', 'submissions/' + s.id + '/return', p).then(after);
			}, 'lucide-undo-2'));
		}
		if (canAct && a.status !== 'closed' && !s.student_deleted && ['submitted', 'missed', 'returned'].indexOf(s.status) !== -1) {
			actions.appendChild(button(t('reopen'), 'btn-secondary', function () { reopen(s, i, after); }, 'lucide-rotate-ccw'));
		}
		panel.appendChild(actions);
		panel.appendChild(msg);
		return panel;
	}

	function reopen(s, i, after) {
		var due = input('datetime-local', '', { id: 'nnAsgReopenDue' });
		var body = el('div');
		body.appendChild(field(t('reopenDue'), due));
		modal(t('reopen') + ': ' + (s.student_name || ''), body, [{ label: t('reopenConfirm'), id: 'nnAsgReopenOk', run: function () {
			var payload = {};
			if (due.value) {
				var ts = fromLocalInput(due.value);
				if (ts === null || ts <= now()) return t('invalidDue');
				payload.due_override_at = ts;
			}
			return api('POST', 'submissions/' + s.id + '/reopen', payload).then(function (r) {
				if (!r.ok) return errorText(r);
				after(r);
				return true;
			});
		} }]);
	}

	function distribute(a) {
		var team = teacherTeams.filter(function (tm) { return tm.id === a.team; })[0];
		var body = el('p', '', t('distributeConfirm', { title: a.title, team: team ? team.name : a.team_name }));
		modal(t('distribute'), body, [{ label: t('distribute'), id: 'nnAsgDistributeOk', run: function () {
			return api('POST', 'assignments/' + a.id + '/distribute').then(function (r) {
				if (!r.ok) return errorText(r);
				var parts = [t('distributed_ok', { n: r.data.created || 0 })];
				if (r.data.pending > 0) parts.push(r.data.error === 'quota_exceeded' ? t('notDistributedQuota', { n: r.data.pending }) : t('distributePending', { n: r.data.pending }));
				openDetail(a.id, false).then(function () { say(parts.join(' '), r.data.error === 'quota_exceeded'); });
				return true;
			});
		} }]);
	}

	function extend(a) {
		var due = input('datetime-local', toLocalInput(a.due_at), { id: 'nnAsgDue' });
		var body = el('div');
		body.appendChild(field(t('dueLabel'), due));
		modal(t('extend'), body, [{ label: t('save'), id: 'nnAsgDueOk', run: function () {
			var ts = fromLocalInput(due.value);
			if (ts === null || ts <= now()) return t('invalidDue');
			return api('PATCH', 'assignments/' + a.id, { due_at: ts }).then(function (r) {
				if (!r.ok) return errorText(r);
				openDetail(a.id, false);
				return true;
			});
		} }]);
	}

	function closeAssignment(a) {
		modal(t('closeAssignment'), el('p', '', t('closeConfirm', { title: a.title })), [{ label: t('closeAssignment'), id: 'nnAsgCloseOk', run: function () {
			return api('POST', 'assignments/' + a.id + '/close').then(function (r) {
				if (!r.ok) return errorText(r);
				openDetail(a.id, false);
				return true;
			});
		} }]);
	}

	// ---------- Erstellen ----------

	function openCreate(noteId) {
		if (!teacherTeams.length) {
			say(t('noTeams'), true);
			return;
		}
		var body = el('div', 'nn-asg-create');
		var team = el('select', 'nn-asg-create-team');
		team.id = 'nnAsgCreateTeam';
		teacherTeams.forEach(function (tm) {
			var o = el('option', '', tm.name);
			o.value = tm.id;
			team.appendChild(o);
		});
		if (state.team && teacherTeams.some(function (tm) { return tm.id === state.team; })) team.value = state.team;
		// aus dem Notiz-Menü: Team des Notiz-Workspace (serverseitig geprüft)
		if (noteId && island.createTeam && teacherTeams.some(function (tm) { return tm.id === island.createTeam; })) team.value = island.createTeam;
		body.appendChild(field(t('team'), team));
		var title = input('text', '', { id: 'nnAsgCreateTitle', maxlength: '255' });
		body.appendChild(field(t('titleLabel'), title));
		var due = input('datetime-local', toLocalInput(defaultDue()), { id: 'nnAsgCreateDue' });
		body.appendChild(field(t('dueLabel'), due));
		var points = input('number', '', { id: 'nnAsgCreatePoints', min: '0', step: '1' });
		body.appendChild(field(t('maxPoints'), points));

		var source = { id: noteId ? Number(noteId) : null, bytes: 0 };
		var sourceBox = el('div', 'nn-asg-source');
		var chosen = el('div', 'nn-asg-source-chosen');
		var search = input('search', '', { id: 'nnAsgCreateSearch', autocomplete: 'off', placeholder: t('sourceSearch'), 'aria-label': t('sourceSearch') });
		var results = el('ul', 'nn-asg-source-results');
		results.setAttribute('role', 'listbox');
		sourceBox.appendChild(chosen);
		sourceBox.appendChild(search);
		sourceBox.appendChild(results);
		body.appendChild(field(t('source'), sourceBox));
		body.appendChild(el('p', 'nn-asg-muted', t('sourceHint')));
		var storage = el('p', 'nn-asg-storage');
		body.appendChild(storage);

		var updateStorage = function () {
			var tm = teacherTeams.filter(function (x) { return x.id === team.value; })[0];
			if (!tm) return;
			var lines = [t('storage', { used: fmtBytes(tm.used), limit: fmtBytes(tm.limit) })];
			if (source.bytes > 0) {
				var need = source.bytes * tm.students;
				lines.push(t('storageNeed', { need: fmtBytes(need), size: fmtBytes(source.bytes), students: tm.students }));
				if (source.bytes >= (island.hintBytes || 10485760)) lines.push(t('storageLarge'));
				if (tm.used + source.bytes * (tm.students + 1) > tm.limit) lines.push(t('storageTooSmall'));
			}
			storage.textContent = lines.join(' ');
			storage.classList.toggle('nn-asg-storage-warn', source.bytes > 0 && tm.used + source.bytes * (tm.students + 1) > tm.limit);
		};
		var choose = function (id, heading) {
			source.id = id;
			source.bytes = 0;
			chosen.textContent = '';
			chosen.appendChild(el('i', 'lucide lucide-file-text'));
			chosen.appendChild(el('span', 'nn-asg-source-title', heading));
			results.textContent = '';
			if (!title.value) title.value = heading;
			api('GET', 'notes/' + id + '/attachments').then(function (r) {
				if (r.ok && source.id === id) {
					source.bytes = (r.data.attachments || []).reduce(function (sum, x) { return sum + (Number(x.file_size || x.size) || 0); }, 0);
					updateStorage();
				}
			});
			updateStorage();
		};
		var timer = null;
		var seq = 0;
		search.addEventListener('input', function () {
			clearTimeout(timer);
			var q = search.value.trim();
			if (q.length < 2) {
				results.textContent = '';
				return;
			}
			timer = setTimeout(function () {
				var mine = ++seq;
				api('GET', 'notes/search?limit=20&q=' + encodeURIComponent(q)).then(function (r) {
					if (mine !== seq) return;
					results.textContent = '';
					var list = r.ok ? (r.data.results || []) : [];
					if (!list.length) results.appendChild(el('li', 'nn-asg-muted', t('sourceNone')));
					list.forEach(function (n) {
						var li = el('li', 'nn-asg-source-result');
						li.setAttribute('role', 'option');
						li.tabIndex = 0;
						li.appendChild(el('span', 'nn-asg-source-title', n.heading));
						li.appendChild(el('span', 'nn-asg-muted', [n.workspace, n.folder].filter(Boolean).join(' / ')));
						var pick = function () { choose(n.id, n.heading); };
						li.addEventListener('click', pick);
						li.addEventListener('keydown', function (e) { if (e.key === 'Enter') pick(); });
						results.appendChild(li);
					});
				});
			}, 250);
		});
		team.addEventListener('change', updateStorage);
		if (source.id) {
			api('GET', 'notes/' + source.id).then(function (r) {
				if (r.ok && r.data.note) choose(source.id, r.data.note.heading || '');
			});
		}
		updateStorage();

		var dialog = modal(t('newAssignment'), body, [{ label: t('create'), id: 'nnAsgCreateOk', run: function () {
			var ts = fromLocalInput(due.value);
			if (!title.value.trim() || !source.id) return t('titleRequired');
			if (ts === null || ts <= now()) return t('invalidDue');
			var payload = { team: team.value, title: title.value.trim(), due_at: ts, source_note_id: source.id };
			if (points.value !== '') {
				var p = Number(points.value);
				if (!Number.isInteger(p) || p < 0) return t('invalidPoints', { max: '∞' });
				payload.max_points = p;
			}
			return api('POST', 'assignments', payload).then(function (r) {
				if (!r.ok) return errorText(r);
				setQuery('create', '');
				openDetail(r.data.assignment.id, true);
				return true;
			});
		} }]);
		return dialog;
	}

	var newBtn = document.getElementById('nnAsgNew');
	if (newBtn) newBtn.addEventListener('click', function () { openCreate(null); });

	load().then(function () {
		var create = params.get('create');
		var id = params.get('assignment');
		if (create && /^\d+$/.test(create)) openCreate(create);
		else if (id && /^\d+$/.test(id)) openDetail(Number(id), false);
	});
})();
