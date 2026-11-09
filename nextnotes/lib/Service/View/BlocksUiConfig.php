<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCP\IL10N;

/** Texte für nextnotes-blocks.js und die Block-Module (SPEC §4.7), nur Hauptansicht; Tabellenblock-Fehlertexte je ErrorType */
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
				'formulaTooLong' => $l->t('The formula is too long (at most 10,000 characters).'),
				'sheet' => $l->t('Calculation table'),
				'sheetRow' => $l->t('Row'),
				'sheetCol' => $l->t('Column'),
				'sheetAddRow' => $l->t('Add row at the end'),
				'sheetAddCol' => $l->t('Add column at the end'),
				'sheetRowAbove' => $l->t('Insert row above'),
				'sheetRowBelow' => $l->t('Insert row below'),
				'sheetColLeft' => $l->t('Insert column left'),
				'sheetColRight' => $l->t('Insert column right'),
				'sheetDelRow' => $l->t('Delete row'),
				'sheetDelCol' => $l->t('Delete column'),
				'sheetBold' => $l->t('Bold'),
				'sheetDecLess' => $l->t('Fewer decimal places'),
				'sheetDecMore' => $l->t('More decimal places'),
				'sheetPercent' => $l->t('Percent'),
				'sheetMore' => $l->t('More actions'),
				'sheetFormulaBar' => $l->t('Cell content'),
				'sheetCut' => $l->t('Pasted data was cut to 50 rows and 26 columns.'),
				'sheetTooBig' => $l->t('The table is too large. The change was not applied.'),
				'sheetTextLong' => $l->t('Cell text was shortened to 1,000 characters.'),
				'sheetCellLong' => $l->t('A cell would exceed 1,000 characters. The change was not applied.'),
				'sheetHeavy' => $l->t('The table contains too many cell references. The change was not applied.'),
				'sheetVersion' => $l->t('This table was created with a newer version and can only be viewed.'),
				'sheetLimit' => $l->t('This table exceeds the size limit and can only be viewed.'),
				'sheetRef' => $l->t('Apostrophes and exclamation marks are only allowed inside closed quotation marks. The change was not applied.'),
				'sheetLoadError' => $l->t('The calculation table could not be loaded.'),
				'errDiv0' => $l->t('Division by zero'),
				'errName' => $l->t('Unknown function or name'),
				'errValue' => $l->t('Wrong type of value, for example text instead of a number'),
				'errNum' => $l->t('Invalid number or result out of range'),
				'errRef' => $l->t('Invalid cell reference'),
				'errNa' => $l->t('Value not available'),
				'errCycle' => $l->t('Circular reference: the formula depends on its own result'),
				'errError' => $l->t('Invalid formula'),
			],
		];
	}
}
