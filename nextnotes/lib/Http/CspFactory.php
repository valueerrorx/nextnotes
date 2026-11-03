<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Http;

use OCA\NextNotes\AppInfo\Application;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\Exceptions\AppConfigException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/** Einzige Quelle für CSP-Abweichungen (ADR 0012); ohne ADR keine Ausnahme. */
class CspFactory {
	// Ansichten mit Notizanzeige: Audio-Player-iframe (self) und Admin-Embed-Domains
	private const NOTE_VIEWS = ['index', 'revisions', 'kanban_content'];

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function forView(string $view): ContentSecurityPolicy {
		$csp = new ContentSecurityPolicy();
		if (in_array($view, self::NOTE_VIEWS, true)) {
			$csp->addAllowedFrameDomain("'self'");
			foreach ($this->embedDomains() as $domain) {
				$csp->addAllowedFrameDomain('https://' . $domain);
			}
		}
		return $csp;
	}

	/** @return list<string> Hostnamen aus allowed_iframe_domains (Default leer, Admin-Formular M4) */
	public function embedDomains(): array {
		try {
			$domains = $this->appConfig->getValueArray(Application::APP_ID, 'allowed_iframe_domains', []);
		} catch (AppConfigException $e) {
			// falsch gespeicherter Typ (z. B. occ config:app:set ohne --type=array): keine Embeds statt HTTP 500
			$this->logger->warning('nextnotes: allowed_iframe_domains ist keine Liste, ignoriert', ['exception' => $e]);
			return [];
		}
		$out = [];
		foreach ($domains as $domain) {
			$domain = is_string($domain) ? strtolower(trim($domain)) : '';
			if (preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain)) {
				$out[] = $domain;
			}
		}
		return array_values(array_unique($out));
	}
}
