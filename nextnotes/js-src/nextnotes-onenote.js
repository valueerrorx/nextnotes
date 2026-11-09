// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// OneNote-Import (templates/onenote.php, Plan M9): Verbinden (Top-Level-Navigation zu Microsoft), Neuanmeldung, Abbrechen; POST mit requesttoken.
(function () {
	'use strict';

	function init() {
		var root = document.getElementById('nextnotes-onenote');
		if (!root) return;
		var errorBox = document.getElementById('nextnotes-onenote-error');

		function msg(code) {
			var el = root.querySelector('#nextnotes-onenote-messages [data-code="' + code + '"]');
			return el ? el.textContent : '';
		}

		function token() {
			return (document.head && document.head.dataset.requesttoken) || (window.OC && window.OC.requestToken) || '';
		}

		function post(url, body) {
			return fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: token() },
				body: JSON.stringify(body || {})
			}).then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (data) {
					return { status: r.status, data: data || {} };
				});
			});
		}

		function fail(res) {
			var code = res && res.data && res.data.code;
			errorBox.textContent = (code && msg(code)) || msg('error');
		}

		function busy(button, on) {
			root.querySelectorAll('button[data-action]').forEach(function (b) { b.disabled = on; });
		}

		root.addEventListener('click', function (ev) {
			var button = ev.target.closest('button[data-action]');
			if (!button) return;
			var action = button.dataset.action;
			var id = root.dataset.importId;
			errorBox.textContent = '';
			busy(button, true);
			var req;
			if (action === 'connect' || action === 'reauth') {
				req = post(root.dataset.connectUrl, action === 'reauth' ? { import_id: Number(id) } : {}).then(function (res) {
					if (res.status === 200 && res.data.authorize_url) {
						window.location.assign(res.data.authorize_url);
						return;
					}
					fail(res);
					busy(button, false);
				});
			} else if (action === 'cancel') {
				req = post(root.dataset.importsUrl + '/' + encodeURIComponent(id) + '/cancel').then(function (res) {
					if (res.status === 200) {
						window.location.assign(window.location.pathname + '?import=' + encodeURIComponent(id));
						return;
					}
					fail(res);
					busy(button, false);
				});
			} else {
				busy(button, false);
				return;
			}
			req.catch(function () {
				fail(null);
				busy(button, false);
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
