/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Teams-Oberfläche (M5d, Plan M5 §2.7) nach nextnotes-app.js: Freigabe-Dialog mit Suche, Team-Auswahl beim Anlegen,
// Team-Kennzeichen im Workspace-Menü, Löschen eines Team-Workspace (Q2), Umwandeln aus dem Banner.
// Material (M6b, Plan M6 §2.6): Kennzeichen im Ordner-Menü, Schreibaktionen ohne Recht ausgeblendet, Drop-Abwehr, Duplikat (Q1).
// Aufgaben (M6g): Notiz-Menü „Als Aufgabe austeilen…“ für Lehrkräfte im Team-Workspace; Editor-Banner Abgeben/Zurückziehen (M6g2).
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
			// Aufgaben des Workspace (ADR 0021): ziehen mit um bzw. werden mit gelöscht
			var assignments = Number(button.getAttribute('data-team-assignments')) || 0;
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
				if (assignments > 0) info.appendChild(el('p', 'nn-delete-team-assignments', t(assignments === 1 ? 'deleteMoveAssignment' : 'deleteMoveAssignments', { n: assignments })));
			} else {
				info.appendChild(el('p', 'text-danger', t('deleteLast')));
				if (assignments > 0) info.appendChild(el('p', 'text-danger nn-delete-team-assignments', t(assignments === 1 ? 'deleteLastAssignment' : 'deleteLastAssignments', { n: assignments })));
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
						if (typeof window.showAjaxAlert === 'function') window.showAjaxAlert(r.status === 409 ? t('deleteChanged') : upstream('workspaces.alerts.delete_error', 'Error deleting workspace'), 'danger');
					})
					.catch(function () {
						confirmBtn.disabled = false;
						if (typeof window.showAjaxAlert === 'function') window.showAjaxAlert(r.status === 409 ? t('deleteChanged') : upstream('workspaces.alerts.delete_error', 'Error deleting workspace'), 'danger');
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

	// ---------- Abgabe-Banner (M6g2): erst ungespeicherte Änderungen sichern, dann abgeben bzw. zurückziehen, Seite neu laden (Server rendert Nur-Lesen) ----------

	function flushSave(noteId) {
		return new Promise(function (resolve) {
			var dirty = typeof window.hasUnsavedChangesOnScreen === 'function' && window.hasUnsavedChangesOnScreen(noteId);
			if (!dirty || typeof window.saveNoteImmediately !== 'function') {
				resolve(true);
				return;
			}
			var done = false;
			var finish = function (ok) {
				if (done) return;
				done = true;
				clearTimeout(timer);
				resolve(ok);
			};
			var timer = setTimeout(function () { finish(false); }, 10000);
			window.saveNoteImmediately({ onSaved: function () { finish(true); } });
		});
	}

	document.addEventListener('click', function (e) {
		var button = e.target && e.target.closest ? e.target.closest('.nn-asg-submit, .nn-asg-unsubmit') : null;
		if (!button || button.disabled) return;
		var sid = button.getAttribute('data-submission-id') || '';
		if (!/^\d+$/.test(sid)) return;
		var undo = button.classList.contains('nn-asg-unsubmit');
		var titleEl = button.closest('.nn-asg-banner') && button.closest('.nn-asg-banner').querySelector('.nn-asg-banner-title');
		var message = undo ? t('unsubmitConfirm') : t('submitConfirm', { title: titleEl ? titleEl.textContent : '' });
		var ask = window.modalAlert && typeof window.modalAlert.confirm === 'function'
			? window.modalAlert.confirm(message, undo ? t('unsubmitTitle') : t('submitTitle'), { confirmText: undo ? t('unsubmitTitle') : t('submitTitle') })
			: Promise.resolve(window.confirm(message));
		ask.then(function (ok) {
			if (!ok) return;
			button.disabled = true;
			(undo ? Promise.resolve(true) : flushSave(button.getAttribute('data-note-id') || '')).then(function (saved) {
				if (!saved) {
					button.disabled = false;
					alertError(t('saveFirstFailed'));
					return;
				}
				fetch('api/v1/submissions/' + sid + '/' + (undo ? 'unsubmit' : 'submit'), {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Accept': 'application/json' }
				})
					.then(readJson)
					.then(function (r) {
						if (r.status === 200 && r.data.success !== false) {
							window.location.reload();
							return;
						}
						button.disabled = false;
						alertError(r.data && r.data.code === 'submission_locked' ? t('submissionLocked') : t('submitFailed'));
					})
					.catch(function () {
						button.disabled = false;
						alertError(t('submitFailed'));
					});
			});
		});
	});

	// ---------- Material-Ordner (M6b): Rechte je Ordner/Notiz aus data-nn-* (IndexViewService, FolderListViewService) ----------

	// Schreibaktionen der Upstream-Menüs (folders_display.php, list_folders.php); Lesen, Kanban, Herunterladen bleiben
	var FOLDER_WRITE = ['create-note-in-folder', 'move-folder-files', 'move-entire-folder', 'duplicate-folder', 'copy-folder', 'cut-folder',
		'paste-into-folder', 'tag-folder-notes', 'favorite-folder', 'change-folder-icon', 'rename-folder',
		'archive-folder', 'delete-folder'];
	var NOTE_WRITE = ['rename-note', 'toggle-favorite', 'create-note-shortcut', 'show-move-folder-dialog', 'copy-note',
		'cut-note', 'paste-into-note-folder', 'archive-note', 'delete-note', 'duplicate-note'];

	function alertError(message) {
		if (window.modalAlert && typeof window.modalAlert.alert === 'function') window.modalAlert.alert(message, 'error');
		else window.alert(message);
	}

	function hideWriteItems(menu, actions, keep) {
		menu.querySelectorAll('[data-action]').forEach(function (item) {
			var action = item.getAttribute('data-action');
			if (actions.indexOf(action) !== -1 && action !== keep) item.classList.add('nn-write-hidden');
		});
		if (typeof window.syncActionsMenuSeparators === 'function') window.syncActionsMenuSeparators(menu);
	}

	function resetWriteItems(menu) {
		menu.querySelectorAll('.nn-write-hidden').forEach(function (item) { item.classList.remove('nn-write-hidden'); });
		var mark = menu.querySelector('.nn-material-item');
		if (mark) mark.remove();
		var assign = menu.querySelector('.nn-assign-item');
		if (assign) assign.remove();
	}

	// M6g: „Als Aufgabe austeilen…“ (Lehrkraft im Team-Workspace, data-nn-teacher am body) öffnet den Erstellen-Dialog der Aufgaben-Seite
	function assignItem(menu, toggle) {
		var noteId = toggle.getAttribute('data-note-id') || '';
		if (document.body.getAttribute('data-nn-teacher') !== '1' || !/^\d+$/.test(noteId)) return;
		var item = el('div', 'note-actions-menu-item nn-assign-item');
		item.setAttribute('role', 'menuitem');
		item.tabIndex = 0;
		item.appendChild(el('i', 'lucide lucide-graduation-cap'));
		item.appendChild(el('span', '', t('assignNote')));
		var run = function (e) {
			e.preventDefault();
			e.stopPropagation();
			var query = 'create=' + encodeURIComponent(noteId) + '&workspace=' + encodeURIComponent(document.body.getAttribute('data-workspace') || '');
			window.location.href = String(config.baseUrl || '') + 'assignments.php?' + query;
		};
		item.addEventListener('click', run);
		item.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') run(e); });
		var anchor = menu.querySelector('[data-action="duplicate-note"]');
		if (anchor) menu.insertBefore(item, anchor);
		else menu.appendChild(item);
	}

	// M6i-fix: Wechsel ohne Reload ersetzt nur #left_col; Leisten-Eintrag „Aufgaben“ und data-nn-teacher folgen dem Marker .nn-ws-scope
	function syncWorkspaceScope() {
		var scope = document.querySelector('#left_col .nn-ws-scope');
		if (!scope) return;
		document.body.setAttribute('data-nn-teacher', scope.getAttribute('data-nn-teacher') === '1' ? '1' : '0');
		var rail = document.getElementById('iconSidebarAssignmentsBtn');
		var hide = scope.getAttribute('data-nn-assignments') !== '1';
		if (rail && rail.hidden !== hide) rail.hidden = hide;
	}
	var leftCol = document.getElementById('left_col');
	if (leftCol && typeof MutationObserver === 'function') new MutationObserver(syncWorkspaceScope).observe(leftCol, { childList: true });

	function materialItem(menu, toggle) {
		var state = toggle.getAttribute('data-nn-material') || '';
		// geerbt: Kennzeichen nur am obersten Ordner (409 already_material)
		if (toggle.getAttribute('data-nn-can-mark') !== '1' || state === 'inherited') return;
		var on = state === 'own';
		var item = el('div', 'folder-actions-menu-item nn-material-item');
		item.setAttribute('role', 'menuitem');
		item.tabIndex = 0;
		item.appendChild(el('i', 'lucide lucide-book-open'));
		item.appendChild(el('span', '', on ? t('unmarkMaterial') : t('markMaterial')));
		var folderId = toggle.getAttribute('data-folder-id') || '';
		var run = function (e) {
			e.preventDefault();
			e.stopPropagation();
			if (typeof window.closeFolderActionsMenu === 'function') window.closeFolderActionsMenu();
			setMaterial(folderId, !on);
		};
		item.addEventListener('click', run);
		item.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') run(e); });
		var anchor = menu.querySelector('[data-action="rename-folder"]');
		if (anchor && anchor.nextSibling) menu.insertBefore(item, anchor.nextSibling);
		else menu.appendChild(item);
	}

	function setMaterial(folderId, on) {
		fetch('api/v1/folders/' + encodeURIComponent(folderId) + '/material', {
			method: 'PUT',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
			body: JSON.stringify({ material: on })
		})
			.then(readJson)
			.then(function (r) {
				if (r.status === 200 && r.data.success !== false) {
					// Baum neu vom Server: Symbol auch an Unterordnern, Rechte unverändert für die Lehrkraft
					if (typeof window.refreshNotesListAfterFolderAction === 'function') window.refreshNotesListAfterFolderAction(null);
					else window.location.reload();
					return;
				}
				var code = r.data && (r.data.code || (r.data.details && r.data.details.code));
				alertError(r.status === 409 || code === 'already_material' ? t('alreadyMaterial') : (r.status === 403 ? t('materialReadOnly') : t('materialFailed')));
			})
			.catch(function () { alertError(t('materialFailed')); });
	}

	if (typeof window.populateFolderActionsMenu === 'function') {
		var populateFolder = window.populateFolderActionsMenu;
		window.populateFolderActionsMenu = function (menu, toggle) {
			resetWriteItems(menu);
			var result = populateFolder.apply(this, arguments);
			if (toggle && toggle.getAttribute('data-nn-write') === '0') hideWriteItems(menu, FOLDER_WRITE, null);
			if (toggle) materialItem(menu, toggle);
			return result;
		};
	}

	if (typeof window.populateNoteActionsMenu === 'function') {
		var populateNote = window.populateNoteActionsMenu;
		window.populateNoteActionsMenu = function (menu, toggle) {
			resetWriteItems(menu);
			var result = populateNote.apply(this, arguments);
			// Material ohne Schreibrecht: Duplizieren bleibt (Kopie in den privaten Workspace, Q1)
			if (toggle && toggle.getAttribute('data-nn-write') === '0') {
				hideWriteItems(menu, NOTE_WRITE, toggle.getAttribute('data-nn-material-copy') === '1' ? 'duplicate-note' : null);
			}
			if (toggle) assignItem(menu, toggle);
			return result;
		};
	}

	// Ordnerliste (list_folders.js öffnet Menü bzw. Dialog im eigenen Klick-Handler; dieser läuft danach)
	document.addEventListener('click', function (e) {
		var button = e.target && e.target.closest ? e.target.closest('[data-action="open-folder-actions-modal"][data-nn-write]') : null;
		if (!button) return;
		['folderRowActionsMenu', 'folder-actions-menu'].forEach(function (id) {
			var menu = document.getElementById(id);
			if (!menu) return;
			resetWriteItems(menu);
			if (button.getAttribute('data-nn-write') === '0') hideWriteItems(menu, FOLDER_WRITE, null);
		});
	});

	// Ziehen ohne Schreibrecht: events-drag-drop.js setzt draggable immer; Start vor den Upstream-Handlern abbrechen
	document.addEventListener('dragstart', function (e) {
		var t = e.target && e.target.closest ? e.target : null;
		if (!t || !t.closest('#left_col')) return;
		var folder = t.closest('.folder-toggle') ? t.closest('.folder-header[data-nn-write]') : null;
		var row = folder ? null : t.closest('.note-list-item');
		var toggle = row ? row.querySelector('.note-actions-toggle[data-nn-write]') : null;
		var owner = folder || toggle;
		if (!owner || owner.getAttribute('data-nn-write') !== '0') return;
		e.preventDefault();
		e.stopPropagation();
	}, true);

	// Ablegen auf Ordner ohne Schreibrecht: kein dragover-Ziel, kein Drop-Request (Server antwortet sonst 403)
	function deniedDrop(e) {
		var target = e.target && e.target.closest ? e.target.closest('#left_col .folder-header[data-nn-write]') : null;
		return !!target && target.getAttribute('data-nn-write') === '0';
	}
	document.addEventListener('dragover', function (e) {
		if (!deniedDrop(e)) return;
		e.stopPropagation();
		if (e.dataTransfer) e.dataTransfer.dropEffect = 'none';
	}, true);
	document.addEventListener('drop', function (e) {
		if (!deniedDrop(e)) return;
		e.preventDefault();
		e.stopPropagation();
	}, true);

	// Tasklist ohne Schreibrecht (Lesefreigabe, Material): P11 setzt den Zustand vor dem Rendern; danach Eingaben sperren
	if (typeof window.renderTaskList === 'function') {
		var renderTasks = window.renderTaskList;
		window.renderTaskList = function (noteId) {
			var result = renderTasks.apply(this, arguments);
			var entry = document.getElementById('entry' + noteId);
			if (entry && entry.hasAttribute('data-nextnotes-readonly')) {
				entry.querySelectorAll('input, textarea, select, button:not(.task-due-chip)').forEach(function (c) {
					c.disabled = true;
					c.setAttribute('aria-disabled', 'true');
				});
			}
			return result;
		};
	}

	// Aufgaben-Erinnerung ohne Schreibrecht (Thomas 2026-11-08): Termin-Chip öffnet den Fälligkeits-Dialog nur für die eigene Erinnerung;
	// Datum, Uhrzeit, Wiederholung und Entfernen gesperrt (dueAt braucht Schreibrecht), kein Speichern der Aufgabe, nur /task-reminder
	var dueTrigger = null;
	document.addEventListener('click', function (e) {
		dueTrigger = e.target && e.target.closest ? e.target.closest('.task-due-chip') : null;
	}, true);
	function readonlyTask(noteId) {
		var entry = document.getElementById('entry' + noteId);
		if (entry && entry.hasAttribute('data-nextnotes-readonly')) return true;
		var row = dueTrigger && dueTrigger.closest('.tasks-task-item');
		return !!(row && row.querySelector('.tasks-task-checkbox:disabled'));
	}
	if (typeof window.openTaskDueModal === 'function') {
		var openDue = window.openTaskDueModal;
		window.openTaskDueModal = function (opts) {
			var modal = document.getElementById('taskDueModal');
			var reminderOnly = !!(opts && opts.task && modal && readonlyTask(opts.noteId));
			if (reminderOnly && !opts.task.dueAt) return;
			if (modal) {
				modal.classList.toggle('nn-reminder-only', reminderOnly);
				['taskDueDateBtn', 'taskDueTimeBtn', 'taskDueRepeatSelect', 'taskDueRepeatInterval', 'taskDueRepeatUnit'].forEach(function (id) {
					var c = document.getElementById(id);
					if (c) c.disabled = reminderOnly;
				});
			}
			if (!reminderOnly) return openDue.call(this, opts);
			// Schalter zeigt die eigene Erinnerung, nicht die Einstellung der Aufgabe (M6e2); Dialog öffnet nach der Antwort
			var self = this;
			var taskId = String(opts.taskId !== undefined ? opts.taskId : opts.task.id);
			var open = function (own) {
				var task = own === null ? opts.task : Object.assign({}, opts.task, { dueReminder: own });
				openDue.call(self, Object.assign({}, opts, { task: task, onSave: null }));
			};
			fetch('api/v1/notes/' + encodeURIComponent(opts.noteId) + '/task-reminder?' + new URLSearchParams({ task_id: taskId }).toString(), {
				credentials: 'same-origin',
				headers: { 'Accept': 'application/json' }
			})
				.then(readJson)
				.then(function (r) { open(r.status === 200 ? !!r.data.reminder_at : null); })
				.catch(function () { open(null); });
		};
	}

	// Nur-Lesen (P11) sperrt die ganze Werkzeugleiste; Lesewerkzeuge (Erinnerung, Suchen ohne Ersetzen, Verlauf, Info, Ordnerpfad) und bei Material Duplizieren (Q1) wieder freigeben
	function releaseControl(c) {
		if (!c || !c.hasAttribute('data-note-lock-managed')) return;
		if ('disabled' in c) c.disabled = c.getAttribute('data-note-lock-prev-disabled') === '1';
		c.style.pointerEvents = c.getAttribute('data-note-lock-prev-pointer-events') || '';
		c.style.opacity = c.getAttribute('data-note-lock-prev-opacity') || '';
		c.classList.remove('note-lock-disabled');
		c.removeAttribute('aria-disabled');
		['managed', 'prev-disabled', 'prev-pointer-events', 'prev-opacity'].forEach(function (k) { c.removeAttribute('data-note-lock-' + k); });
	}
	function personalControls() {
		document.querySelectorAll('.noteentry[data-nextnotes-readonly][data-note-id]').forEach(function (entry) {
			var card = document.getElementById('note' + entry.getAttribute('data-note-id'));
			if (!card) return;
			var sel = '.note-edit-toolbar :is(.btn-reminder, .btn-snapshot, .btn-search-replace, .btn-search-replace-format, .btn-toolbar-overflow, .mobile-more-btn), '
				+ '.mobile-toolbar-menu :is([data-action="show-snapshot"], [data-action="show-note-info"], [data-selector=".btn-search-replace"]), '
				+ '.search-replace-controls :is(.search-replace-input, .search-replace-prev-btn, .search-replace-next-btn, .search-replace-close-btn), .note-tags-row [data-action="reveal-folder-in-tree"]';
			if (entry.hasAttribute('data-nn-material-copy')) sel += ', .note-edit-toolbar .btn-duplicate, .mobile-toolbar-menu [data-selector=".btn-duplicate"]';
			card.querySelectorAll(sel).forEach(releaseControl);
		});
	}
	// nach note-edit-lock.js registriert → läuft nach P11 (Seitenaufbau, Notiz-Wechsel, bfcache)
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', personalControls);
	else personalControls();
	document.addEventListener('noteLoaded', personalControls);
	window.addEventListener('pageshow', function (e) { if (e && e.persisted) personalControls(); });

	// Duplikat einer Material-Notiz ohne Schreibrecht (Q1): Kopie im privaten Workspace, Hinweis mit Link, Ansicht bleibt
	function copyNotice(workspace, noteId) {
		var old = document.querySelector('.nn-copy-notice');
		if (old) old.remove();
		var box = el('div', 'nn-copy-notice');
		box.setAttribute('role', 'status');
		box.setAttribute('aria-live', 'polite');
		box.appendChild(el('span', '', t('copiedToPrivate', { workspace: workspace })));
		var link = el('a', 'nn-copy-open', t('openCopy'));
		link.href = 'index.php?' + new URLSearchParams({ workspace: workspace, note: String(noteId) }).toString();
		box.appendChild(link);
		var close = el('button', 'nn-copy-close');
		close.type = 'button';
		close.setAttribute('aria-label', upstream('common.close', 'Close'));
		close.appendChild(el('i', 'lucide lucide-x'));
		close.addEventListener('click', function () { box.remove(); });
		box.appendChild(close);
		document.body.appendChild(box);
		setTimeout(function () { if (box.parentNode) box.remove(); }, 12000);
	}

	if (typeof window.duplicateNote === 'function') {
		var duplicate = window.duplicateNote;
		window.duplicateNote = function (noteId) {
			var id = String(noteId || '').replace(/[^0-9]/g, '');
			if (!id || !document.querySelector('[data-nn-material-copy="1"][data-note-id="' + id + '"]')) return duplicate.apply(this, arguments);
			// ohne folder_id: Server legt die Kopie im privaten Standard-Workspace an (Plan M6 §8)
			fetch('api/v1/notes/' + id + '/duplicate', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
				body: '{}'
			})
				.then(readJson)
				.then(function (r) {
					if (r.status === 200 && r.data.id && r.data.workspace) copyNotice(r.data.workspace, r.data.id);
					else alertError(t('copyFailed'));
				})
				.catch(function () { alertError(t('copyFailed')); });
		};
	}
})();
