<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\AppInfo\Application;
use OCP\Util;

/** Skripte/Stylesheets je Ansicht in fester Reihenfolge (SPEC §1.4, M3-Plan §1.2); Dateien aus make build. */
class PageAssets {
	// Reihenfolge wie src/public/index.php:456-475 (Upstream-Bundles), danach Token/Layout
	private const INDEX_STYLES = [
		'nextnotes-core', 'nextnotes-mobile', 'nextnotes-modals', 'nextnotes-dark',
		'vendor/katex.min', 'syntax-highlight', 'nextnotes-tokens', 'nextnotes-layout',
	];
	private const INDEX_SCRIPTS = [
		'nextnotes-bootstrap', 'nextnotes-head',
		'vendor/codemirror-dist/markdown-codemirror.iife', 'lazy-libs',
		'vendor/highlight/highlight.min', 'vendor/highlight/powershell.min', 'syntax-highlight',
		'nextnotes-app', 'nextnotes-late',
	];

	/** Hauptansicht; $lang = Upstream-Sprachcode mit Wörterbuch (PageTranslator::languageFor) */
	public function index(string $lang): void {
		Util::addScript(Application::APP_ID, 'i18n/nextnotes-i18n-' . $lang);
		foreach (self::INDEX_SCRIPTS as $script) {
			Util::addScript(Application::APP_ID, $script);
		}
		foreach (self::INDEX_STYLES as $style) {
			Util::addStyle(Application::APP_ID, $style);
		}
	}

	/** Eigene Seite aus bundles.json pages (revisions, attachments): Adapter, Seitenbündel, Late-Adapter */
	public function page(string $page, string $lang): void {
		Util::addScript(Application::APP_ID, 'i18n/nextnotes-i18n-' . $lang);
		foreach (['nextnotes-bootstrap', 'nextnotes-page-' . $page, 'nextnotes-late'] as $script) {
			Util::addScript(Application::APP_ID, $script);
		}
		foreach (['nextnotes-page-' . $page, 'nextnotes-tokens', 'nextnotes-layout'] as $style) {
			Util::addStyle(Application::APP_ID, $style);
		}
	}
}
