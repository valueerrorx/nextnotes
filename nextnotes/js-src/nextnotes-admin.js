// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Admin-Formular Next Notes (templates/settings/admin.php): Speichern per POST mit requesttoken, Fehlerliste je Eintrag.
(function () {
	'use strict';

	function init() {
		var root = document.getElementById('nextnotes-admin');
		if (!root) return;
		var button = document.getElementById('nextnotes-admin-save');
		var status = document.getElementById('nextnotes-admin-status');
		var list = document.getElementById('nextnotes-admin-errors');
		var area = document.getElementById('nextnotes-embed-domains');

		function msg(code) {
			var el = root.querySelector('#nextnotes-admin-messages [data-code="' + code + '"]');
			return el ? el.textContent : code;
		}

		function token() {
			return (document.head && document.head.dataset.requesttoken) || (window.OC && window.OC.requestToken) || '';
		}

		function showErrors(errors) {
			list.textContent = '';
			errors.forEach(function (e) {
				var li = document.createElement('li');
				li.textContent = (e.entry ? e.entry + ': ' : '') + msg(e.error || 'invalid');
				list.appendChild(li);
			});
		}

		button.addEventListener('click', function () {
			var body = {
				allowed_iframe_domains: area.value.split(/[\s,]+/).filter(function (s) { return s !== ''; }),
				attachment_max_file_mb: document.getElementById('nextnotes-max-file').value.trim(),
				attachment_quota_mb: document.getElementById('nextnotes-quota').value.trim()
			};
			button.disabled = true;
			status.textContent = '';
			showErrors([]);
			fetch(root.dataset.saveUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: token() },
				body: JSON.stringify(body)
			}).then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (data) {
					return { status: r.status, data: data || {} };
				});
			}).then(function (res) {
				if (res.status === 200 && res.data.success) {
					area.value = (res.data.allowed_iframe_domains || []).join('\n');
					status.textContent = msg('saved');
					return;
				}
				status.textContent = msg('failed');
				showErrors(Array.isArray(res.data.errors) ? res.data.errors : []);
			}).catch(function () {
				status.textContent = msg('failed');
			}).then(function () {
				button.disabled = false;
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
