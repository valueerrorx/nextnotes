/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Druckansicht (templates/export_print.php), ersetzt das Inline-Skript von Upstream generatePrintHtml:
// Lese-Einstellungen am body, KaTeX/Mermaid nachladen, Callouts beim Drucken öffnen, window.poznotePrintReady.
(function () {
	'use strict';

	var config = {};
	try {
		config = JSON.parse((document.getElementById('nextnotes-print-config') || {}).textContent || '{}');
	} catch (e) {
		config = {};
	}
	var assetBase = String(config.assetBase || '').replace(/\/$/, '');
	var version = String(config.version || '');
	(config.bodyClasses || []).forEach(function (c) { document.body.classList.add(c); });
	if (config.fontSize) document.documentElement.style.setProperty('--note-font-size', config.fontSize);

	var pending = {};
	function load(path) {
		if (!pending[path]) {
			pending[path] = new Promise(function (resolve) {
				var script = document.createElement('script');
				script.src = assetBase + '/js/vendor/' + path + (version ? '?v=' + encodeURIComponent(version) : '');
				script.onload = resolve;
				// Bibliothek fehlt: Blöcke bleiben Text
				script.onerror = resolve;
				document.head.appendChild(script);
			});
		}
		return pending[path];
	}
	// math-renderer.js lädt KaTeX über diesen Haken
	window.poznoteEnsureKatex = function () {
		return typeof window.katex !== 'undefined' ? Promise.resolve() : load('katex/katex.min.js');
	};

	var entry = document.querySelector('#nextnotes-root .noteentry');

	function renderMath() {
		if (!entry || !entry.querySelector('.math-block, .math-inline')) return Promise.resolve();
		return window.poznoteEnsureKatex().then(function () {
			if (typeof window.renderMathInElement === 'function') window.renderMathInElement(entry);
		});
	}

	function renderDiagrams() {
		var nodes = entry ? entry.querySelectorAll('.mermaid') : [];
		if (!nodes.length) return Promise.resolve();
		return load('mermaid/mermaid.min.js').then(function () {
			if (typeof window.mermaid === 'undefined') return;
			var cfg = { startOnLoad: false };
			try {
				if (typeof window.poznoteMermaidTheme === 'function') cfg = window.poznoteMermaidTheme().config;
			} catch (e) {}
			window.mermaid.initialize(cfg);
			return window.mermaid.run({ nodes: nodes, suppressErrors: true });
		}).catch(function () {});
	}

	// Eingeklappte Callouts druckten nur ihren Titel: zum Drucken öffnen, danach wieder schließen
	var folded = [];
	window.addEventListener('beforeprint', function () {
		Array.prototype.forEach.call(document.querySelectorAll('details.callout:not([open])'), function (el) {
			el.open = true;
			folded.push(el);
		});
	});
	window.addEventListener('afterprint', function () {
		folded.forEach(function (el) { el.open = false; });
		folded = [];
	});

	window.poznotePrintReady = Promise.all([
		renderMath(),
		renderDiagrams(),
		document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()
	]);
})();
