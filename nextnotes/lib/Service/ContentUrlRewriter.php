<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCP\IURLGenerator;

// SPEC §10: gespeichert wird die Upstream-Form (/api/v1/…), ausgeliefert mit App-Basis
class ContentUrlRewriter {
	private ?string $base = null;

	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	// App-Basis mit Schrägstrich am Ende, z. B. /index.php/apps/nextnotes/
	public function base(): string {
		if ($this->base === null) {
			$this->base = rtrim($this->urlGenerator->linkToRoute('nextnotes.page.index'), '/') . '/';
		}
		return $this->base;
	}

	public function toClient(string $content): string {
		if ($content === '' || (!str_contains($content, '/api/v1/') && !str_contains($content, '/audio_player.php'))) {
			return $content;
		}
		return preg_replace('#(^|["\'(\s=])/(api/v1/|audio_player\.php)#', '$1' . $this->base() . '$2', $content) ?? $content;
	}

	public function toServer(string $content): string {
		if ($content === '') {
			return $content;
		}
		// Alte Anhangs-URLs → kanonische Form
		$content = preg_replace(
			'#(?:[^"\'\s()<>]*/)?api_attachments\.php\?action=download&(?:amp;)?note_id=(\d+)&(?:amp;)?attachment_id=(\d+)#i',
			'/api/v1/notes/$1/attachments/$2',
			$content,
		) ?? $content;
		if (!str_contains($content, '/apps/nextnotes/')) {
			return $content;
		}
		return preg_replace(
			'#(^|["\'(\s=])(?:https?://[^/"\'\s()<>]+)?(?:/[^"\'\s()<>]*?)?/apps/nextnotes/(api/v1/|audio_player\.php)#i',
			'$1/$2',
			$content,
		) ?? $content;
	}
}
