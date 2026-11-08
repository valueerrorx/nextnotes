<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Http;

use OCA\NextNotes\Service\EmbedDomainService;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\FeaturePolicy;

/** Einzige Quelle für CSP- und Feature-Policy-Abweichungen (ADR 0012); ohne ADR keine Ausnahme. */
class CspFactory {
	// Ansichten mit Notizanzeige: iframes (self) und Admin-Embed-Domains (EmbedDomainService); export_print = Druckansicht (M4c), trash = Vorschau (M4e), diary = Journal (M4f)
	private const NOTE_VIEWS = ['index', 'revisions', 'kanban_content', 'export_print', 'trash', 'diary'];
	// Nur iframes (self): PDF-Vorschau der Anhang-Seite (P13, ADR 0012 Nachtrag M3c1), Lesemodus der Abgaben (ADR 0012 Nachtrag M6g)
	private const SELF_FRAME_VIEWS = ['attachments', 'assignments'];
	// Mikrofon (self) nur für die Audio-Aufnahme im Editor (ADR 0012 Nachtrag M3-fix4)
	private const MICROPHONE_VIEWS = ['index'];

	public function __construct(
		private EmbedDomainService $embedDomains,
	) {
	}

	public function forView(string $view): ContentSecurityPolicy {
		$csp = new ContentSecurityPolicy();
		if (in_array($view, self::NOTE_VIEWS, true)) {
			$csp->addAllowedFrameDomain("'self'");
			// je Eintrag genau https://<host>, keine Subdomains (B41)
			foreach ($this->embedDomains->domains() as $domain) {
				$csp->addAllowedFrameDomain('https://' . $domain);
			}
		} elseif (in_array($view, self::SELF_FRAME_VIEWS, true)) {
			$csp->addAllowedFrameDomain("'self'");
		}
		return $csp;
	}

	public function featurePolicyForView(string $view): FeaturePolicy {
		$policy = new FeaturePolicy();
		if (in_array($view, self::MICROPHONE_VIEWS, true)) {
			$policy->addAllowedMicrophoneDomain("'self'");
		}
		return $policy;
	}
}
