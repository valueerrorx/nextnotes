<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCP\IL10N;

/** Texte für nextnotes-blocks.js und die Block-Module (SPEC §4.7), nur Hauptansicht */
class BlocksUiConfig {
	public function __construct(
		private IL10N $l,
	) {
	}

	/** @return array{txt: array<string, string>} */
	public function config(): array {
		$l = $this->l;
		return [
			'txt' => [
				'menu' => $l->t('Insert math'),
				'formula' => $l->t('Formula'),
				'formulaBlock' => $l->t('Formula block'),
				'editFormula' => $l->t('Edit formula'),
				'apply' => $l->t('Apply'),
				'cancel' => $l->t('Cancel'),
				'delete' => $l->t('Delete'),
				'asBlock' => $l->t('On its own line'),
				'loading' => $l->t('Loading the formula editor …'),
				'loadError' => $l->t('The formula editor could not be loaded.'),
			],
		];
	}
}
