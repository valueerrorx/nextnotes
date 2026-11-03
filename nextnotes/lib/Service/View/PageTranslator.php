<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\AppInfo\Application;
use OCP\App\IAppManager;

/** Upstream t()/t_h() für Templates (src/lib/i18n.php); Wörterbücher aus i18n/ (Build). */
class PageTranslator {
	private string $lang = 'en';
	/** @var array<string, array<string, mixed>> */
	private array $dicts = [];

	public function __construct(
		private IAppManager $appManager,
	) {
	}

	// SPEC §1.4: de_DE → de, zh_CN → zh-cn, pt_BR → pt, ohne Wörterbuch → en
	public function languageFor(string $ncLanguage): string {
		$lang = strtolower(str_replace('_', '-', trim($ncLanguage)));
		foreach ([$lang, explode('-', $lang)[0]] as $candidate) {
			if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $candidate) && is_file($this->file($candidate))) {
				return $candidate;
			}
		}
		return 'en';
	}

	public function setLanguage(string $lang): void {
		$this->lang = $lang;
	}

	public function language(): string {
		return $this->lang;
	}

	/** @param array<string, scalar> $vars */
	public function t(string $key, array $vars = [], ?string $default = null): string {
		$text = $this->lookup($this->lang, $key) ?? $this->lookup('en', $key) ?? $default ?? $key;
		foreach ($vars as $k => $v) {
			$text = str_replace('{{' . $k . '}}', (string)$v, $text);
		}
		return $text;
	}

	/** @param array<string, scalar> $vars */
	public function h(string $key, array $vars = [], ?string $default = null): string {
		return htmlspecialchars($this->t($key, $vars, $default), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private function lookup(string $lang, string $key): ?string {
		$cur = $this->dict($lang);
		foreach (explode('.', $key) as $part) {
			if (!is_array($cur) || !array_key_exists($part, $cur)) {
				return null;
			}
			$cur = $cur[$part];
		}
		return is_string($cur) ? $cur : null;
	}

	/** @return array<string, mixed> */
	private function dict(string $lang): array {
		if (!isset($this->dicts[$lang])) {
			$file = $this->file($lang);
			$data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
			$this->dicts[$lang] = is_array($data) ? $data : [];
		}
		return $this->dicts[$lang];
	}

	private function file(string $lang): string {
		return $this->appManager->getAppPath(Application::APP_ID) . '/i18n/' . $lang . '.json';
	}
}
