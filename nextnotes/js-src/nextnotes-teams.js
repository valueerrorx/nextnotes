/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Teams-Oberfläche (M5d, Plan M5 §2.7) nach nextnotes-app.js: Freigabe-Dialog mit Suche, Team-Auswahl beim Anlegen,
// Team-Kennzeichen im Workspace-Menü, Löschen eines Team-Workspace (Q2), Umwandeln aus dem Banner.
// Ersetzt bzw. umhüllt Upstream-Globals (bundles.json adapterHooks); Texte aus NC-l10n (config.teams.txt).
(function () {
	'use strict';

	var config = (window.NEXTNOTES && window.NEXTNOTES.config) || {};
	var teams = config.teams || {};
	var txt = teams.txt || {};

	function t(key, vars) {
		var s = String(txt[key] || key);
		Object.keys(vars || {}).forEach(function (k) { s = s.split('{' + k + '}').join(String(vars[k])); });
		return s;
	}

	function upstream(key, fallback) {
		return typeof window.wsTr === 'function' ? window.wsTr(key, {}, fallback) : (typeof wsTr === 'function' ? wsTr(key, {}, fallback) : fallback);
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined) node.textContent = text;
		return node;
	}

	function readJson(resp) {
		return resp.json().catch(function () { return {}; }).then(function (data) {
			return { status: resp.status, data: data || {} };
		});
	}

	// ---------- Freigabe-Dialog (ersetzt showWorkspaceShareOptionsModal aus workspaces-share.js) ----------

	function parseRecipients(button) {
		try {
			var list = JSON.parse(button.getAttribute('data-allowed-users') || '[]');
			return Array.isArray(list) ? list.filter(function (u) { return u && typeof u.uid === 'string'; }).map(function (u) {
				return { uid: u.uid, displayName: String(u.displayName || u.uid), permission: u.permission === 'read' ? 'read' : 'write' };
			}) : [];
		} catch (e) {
			return [];
		}
	}

	function showShareModal(button) {
		var workspaceName = button.getAttribute('data-ws') || '';
		var isExistingShare = button.getAttribute('data-shared') === '1';
		var recipients = parseRecipients(button);
		var searchTimer = null;
		var searchSeq = 0;

		var modal = el('div', 'modal shared-edit-token-modal nn-share-modal');
		modal.style.display = 'flex';
		var content = el('div', 'modal-content shared-edit-token-modal-content');
		content.setAttribute('role', 'dialog');
		content.setAttribute('aria-modal', 'true');
		var title = el('h3', '', upstream('workspaces.share.confirm.title', 'Share workspace'));
		title.id = 'nnShareTitle';
		content.setAttribute('aria-labelledby', title.id);
		content.appendChild(title);
		content.appendChild(el('p', 'nn-share-intro', t('shareIntro', { workspace: workspaceName })));

		var search = el('input', 'nn-share-search');
		search.type = 'search';
		search.autocomplete = 'off';
		search.placeholder = t('searchPlaceholder');
		search.setAttribute('aria-label', t('searchPlaceholder'));
		content.appendChild(search);
		var results = el('ul', 'nn-share-results');
		results.setAttribute('role', 'listbox');
		content.appendChild(results);
		var status = el('div', 'nn-share-status', t('searchHint'));
		status.setAttribute('aria-live', 'polite');
		content.appendChild(status);

		var list = el('ul', 'nn-share-recipients');
		content.appendChild(list);
		var error = el('div', 'modal-error-message nn-share-error');
		content.appendChild(error);

		function renderRecipients() {
			list.innerHTML = '';
			if (recipients.length === 0) {
				list.appendChild(el('li', 'nn-share-empty', t('noRecipients')));
				return;
			}
			recipients.forEach(function (r) {
				var row = el('li', 'nn-share-recipient');
				row.setAttribute('data-uid', r.uid);
				row.appendChild(el('span', 'nn-share-name', r.displayName));
				var select = el('select', 'nn-share-permission');
				select.setAttribute('aria-label', t('permission', { user: r.displayName }));
				[['write', t('canEdit')], ['read', t('readOnly')]].forEach(function (o) {
					var opt = el('option', '', o[1]);
					opt.value = o[0];
					select.appendChild(opt);
				});
				select.value = r.permission;
				select.addEventListener('change', function () { r.permission = select.value === 'read' ? 'read' : 'write'; });
				row.appendChild(select);
				var remove = el('button', 'nn-share-remove');
				remove.type = 'button';
				remove.title = t('remove');
				remove.setAttribute('aria-label', t('remove') + ': ' + r.displayName);
				remove.appendChild(el('i', 'lucide lucide-x'));
				remove.addEventListener('click', function () {
					recipients = recipients.filter(function (x) { return x.uid !== r.uid; });
					renderRecipients();
					search.focus();
				});
				row.appendChild(remove);
				list.appendChild(row);
			});
		}

		function renderResults(users) {
			results.innerHTML = '';
			var fresh = users.filter(function (u) { return !recipients.some(function (r) { return r.uid === u.uid; }); });
			status.textContent = fresh.length === 0 ? t('noResults') : '';
			fresh.forEach(function (u) {
				var item = el('li', 'nn-share-result');
				item.setAttribute('role', 'option');
				item.tabIndex = 0;
				item.setAttribute('data-uid', u.uid);
				item.appendChild(el('i', 'lucide lucide-user'));
				item.appendChild(el('span', '', u.displayName));
				function pick() {
					recipients.push({ uid: u.uid, displayName: u.displayName, permission: 'write' });
					results.innerHTML = '';
					status.textContent = '';
					search.value = '';
					renderRecipients();
					search.focus();
				}
				item.addEventListener('click', pick);
				item.addEventListener('keydown', function (e) {
					if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); }
				});
				results.appendChild(item);
			});
		}

		function runSearch() {
			var q = search.value.trim();
			results.innerHTML = '';
			if (q.length < 2) {
				status.textContent = t('searchHint');
				return;
			}
			var seq = ++searchSeq;
			fetch('api/v1/users/search?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
				.then(readJson)
				.then(function (r) {
					if (seq !== searchSeq) return;
					if (r.status !== 200 || !r.data.success) {
						status.textContent = t('searchError');
						return;
					}
					renderResults(Array.isArray(r.data.users) ? r.data.users : []);
				})
				.catch(function () { if (seq === searchSeq) status.textContent = t('searchError'); });
		}

		search.addEventListener('input', function () {
			clearTimeout(searchTimer);
			searchTimer = setTimeout(runSearch, 300);
		});
		search.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') {
				var first = results.querySelector('.nn-share-result');
				if (first) { e.preventDefault(); first.focus(); }
			}
		});

		var actions = el('div', 'shared-edit-token-modal-actions');
		var cancelBtn = el('button', 'btn btn-secondary', upstream('common.cancel', 'Cancel'));
		cancelBtn.type = 'button';
		var unshareBtn = null;
		if (isExistingShare) {
			unshareBtn = el('button', 'btn btn-danger', upstream('workspaces.share.actions.disable', 'Unshare'));
			unshareBtn.type = 'button';
		}
		var saveBtn = el('button', 'btn btn-primary', upstream('common.save', 'Save'));
		saveBtn.type = 'button';

		function close() {
			clearTimeout(searchTimer);
			if (modal.parentNode) modal.parentNode.removeChild(modal);
		}

		function submit(params, busy) {
			busy.forEach(function (b) { if (b) b.disabled = true; });
			error.textContent = '';
			error.style.display = 'none';
			var body = new URLSearchParams(params);
			fetch('workspaces.php', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
				body: body.toString()
			})
				.then(readJson)
				.then(function (r) {
					busy.forEach(function (b) { if (b) b.disabled = false; });
					if (r.status === 200 && r.data.success) {
						if (typeof window.updateWorkspaceShareToggleButton === 'function') window.updateWorkspaceShareToggleButton(button, r.data);
						if (typeof window.showWorkspaceShareToast === 'function') window.showWorkspaceShareToast(r.data.message || '', 'success');
						close();
						return;
					}
					error.textContent = r.data.error === 'Invalid share target' ? t('invalidTarget') : upstream('workspaces.share.errors.save_failed', 'Failed to update workspace sharing');
					error.style.display = 'block';
				})
				.catch(function () {
					busy.forEach(function (b) { if (b) b.disabled = false; });
					error.textContent = upstream('workspaces.share.errors.save_failed', 'Failed to update workspace sharing');
					error.style.display = 'block';
				});
		}

		cancelBtn.addEventListener('click', close);
		if (unshareBtn) {
			unshareBtn.addEventListener('click', function () {
				submit({ action: 'unshare_workspace', name: workspaceName }, [saveBtn, unshareBtn]);
			});
		}
		saveBtn.addEventListener('click', function () {
			submit({
				action: 'share_workspace',
				name: workspaceName,
				allowed_users: JSON.stringify(recipients.map(function (r) { return { uid: r.uid, permission: r.permission }; }))
			}, [saveBtn, unshareBtn]);
		});

		var pressedOnBackdrop = false;
		modal.addEventListener('mousedown', function (e) { pressedOnBackdrop = e.target === modal; });
		modal.addEventListener('click', function (e) {
			if (e.target === modal && pressedOnBackdrop) close();
			pressedOnBackdrop = false;
		});
		modal.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { e.stopPropagation(); close(); }
		});

		actions.appendChild(cancelBtn);
		if (unshareBtn) actions.appendChild(unshareBtn);
		actions.appendChild(saveBtn);
		content.appendChild(actions);
		modal.appendChild(content);
		document.body.appendChild(modal);
		renderRecipients();
		search.focus();
	}

	if (typeof window.showWorkspaceShareOptionsModal === 'function') {
		window.showWorkspaceShareOptionsModal = showShareModal;
	}

	// ---------- Workspace-Menü: Team-Kennzeichen (umhüllt displayWorkspaceMenu aus workspaces-core.js) ----------

	if (typeof window.displayWorkspaceMenu === 'function') {
		var displayMenu = window.displayWorkspaceMenu;
		window.displayWorkspaceMenu = function (menu, workspaces) {
			displayMenu.apply(this, arguments);
			(workspaces || []).forEach(function (ws) {
				if (!ws || !ws.team) return;
				menu.querySelectorAll('.workspace-menu-item[data-workspace-name]').forEach(function (item) {
					if (item.getAttribute('data-workspace-name') !== ws.name || item.querySelector('.nn-team-mark')) return;
					var mark = el('span', 'workspace-menu-shared-owner nn-team-mark');
					mark.title = t('teamWorkspace');
					mark.appendChild(el('i', 'lucide lucide-users'));
					mark.appendChild(el('span', '', ws.team));
					item.appendChild(mark);
				});
			});
		};
	}

	// ---------- Neuer Workspace: Team-Auswahl (umhüllt openCreateWorkspaceModal aus workspaces-create.js) ----------

	var teamList = null;
	function loadTeams() {
		if (!teamList) {
			teamList = fetch('api/v1/teams', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
				.then(readJson)
				.then(function (r) { return r.status === 200 && Array.isArray(r.data.teams) ? r.data.teams : []; })
				.catch(function () { teamList = null; return []; });
		}
		return teamList;
	}

	if (typeof window.openCreateWorkspaceModal === 'function') {
		var openCreate = window.openCreateWorkspaceModal;
		window.openCreateWorkspaceModal = function () {
			var result = openCreate.apply(this, arguments);
			var group = document.getElementById('createWorkspaceTeamGroup');
			var select = document.getElementById('createWorkspaceTeamSelect');
			if (!group || !select || !teams.available) return result;
			select.value = '';
			var link = document.getElementById('createWorkspaceManageTeams');
			if (link && teams.contactsUrl) {
				link.href = teams.contactsUrl;
				link.hidden = false;
				group.hidden = false;
			}
			loadTeams().then(function (list) {
				Array.prototype.slice.call(select.querySelectorAll('option[value]:not([value=""])')).forEach(function (o) { o.remove(); });
				list.forEach(function (team) {
					var opt = el('option', '', t('teamOption', { team: team.name }));
					opt.value = team.id;
					select.appendChild(opt);
				});
				if (list.length > 0) group.hidden = false;
			});
			return result;
		};
	}

	// ---------- Team-Workspace löschen (Q2; umhüllt handleDeleteButtonClick aus workspaces-actions.js) ----------

	if (typeof window.handleDeleteButtonClick === 'function') {
		var handleDelete = window.handleDeleteButtonClick;
		window.handleDeleteButtonClick = function (e) {
			var result = handleDelete.apply(this, arguments);
			var button = e.target && e.target.closest ? e.target.closest('.workspace-list .btn-delete') : null;
			var modal = document.getElementById('deleteModal');
			if (!button || !modal || button.disabled) return result;
			var info = document.getElementById('nnDeleteTeamInfo');
			var ownHint = modal.querySelector('p.text-danger');
			var raw = button.getAttribute('data-team-targets');
			if (raw === null) {
				if (info) info.remove();
				if (ownHint) ownHint.hidden = false;
				return result;
			}
			var targets = [];
			try { targets = JSON.parse(raw) || []; } catch (err) { targets = []; }
			if (ownHint) ownHint.hidden = true;
			if (!info) {
				info = el('div', 'nn-delete-team-info');
				info.id = 'nnDeleteTeamInfo';
				var anchor = modal.querySelector('.form-group');
				anchor.parentNode.insertBefore(info, anchor);
			}
			info.innerHTML = '';
			var select = null;
			if (targets.length > 0) {
				info.appendChild(el('p', '', t('deleteMoveTo')));
				select = el('select', 'nn-delete-team-target');
				select.setAttribute('aria-label', t('deleteMoveTo'));
				targets.forEach(function (name) {
					var opt = el('option', '', name);
					opt.value = name;
					select.appendChild(opt);
				});
				info.appendChild(select);
			} else {
				info.appendChild(el('p', 'text-danger', t('deleteLast')));
			}
			var name = button.getAttribute('data-ws') || '';
			var input = document.getElementById('confirmDeleteInput');
			var confirmBtn = document.getElementById('confirmDeleteBtn');
			confirmBtn.onclick = function () {
				if (input.value.trim() !== name) return;
				confirmBtn.disabled = true;
				var params = { action: 'delete', name: name };
				if (select) params.target = select.value; else params.confirm = '1';
				fetch('workspaces.php', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
					body: new URLSearchParams(params).toString()
				})
					.then(readJson)
					.then(function (r) {
						if (r.status === 200 && r.data.success) {
							if (typeof window.showAjaxAlert === 'function') window.showAjaxAlert(upstream('workspaces.alerts.deleted_success', 'Workspace deleted successfully'), 'success');
							modal.style.display = 'none';
							setTimeout(function () { window.location.reload(); }, 600);
							return;
						}
						confirmBtn.disabled = false;
						if (typeof window.showAjaxAlert === 'function') window.showAjaxAlert(upstream('workspaces.alerts.delete_error', 'Error deleting workspace'), 'danger');
					})
					.catch(function () {
						confirmBtn.disabled = false;
						if (typeof window.showAjaxAlert === 'function') window.showAjaxAlert(upstream('workspaces.alerts.delete_error', 'Error deleting workspace'), 'danger');
					});
			};
			return result;
		};
	}

	// ---------- Banner „verwaist“: in privaten Workspace umwandeln (alle Notizen gehen mit, Plan M5 §2.4) ----------

	document.addEventListener('click', function (e) {
		var button = e.target && e.target.closest ? e.target.closest('.nn-team-convert') : null;
		if (!button || button.disabled) return;
		var key = button.getAttribute('data-ws') || '';
		var ask = window.modalAlert && typeof window.modalAlert.confirm === 'function'
			? window.modalAlert.confirm(t('convertMessage'), t('convertTitle'), { confirmText: t('convertButton') })
			: Promise.resolve(window.confirm(t('convertMessage')));
		ask.then(function (ok) {
			if (!ok) return;
			button.disabled = true;
			fetch('api/v1/workspaces/' + encodeURIComponent(key) + '/make-private', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Accept': 'application/json' }
			})
				.then(readJson)
				.then(function (r) {
					if (r.status === 200 && r.data.success && r.data.name) {
						window.location.href = 'index.php?workspace=' + encodeURIComponent(r.data.name);
						return;
					}
					button.disabled = false;
					if (window.modalAlert) window.modalAlert.alert(t('convertFailed'), 'error');
				})
				.catch(function () {
					button.disabled = false;
					if (window.modalAlert) window.modalAlert.alert(t('convertFailed'), 'error');
				});
		});
	});
})();
