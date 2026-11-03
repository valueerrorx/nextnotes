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

	window.poznoteEnsureScript = ensureScript;
	window.poznoteEnsureMermaid = function () {
		return typeof window.mermaid !== 'undefined' ? Promise.resolve() : ensureScript('js/mermaid/mermaid.min.js');
	};
	window.poznoteEnsureKatex = function () {
		return typeof window.katex !== 'undefined' ? Promise.resolve() : ensureScript('js/katex/katex.min.js');
	};
})();
