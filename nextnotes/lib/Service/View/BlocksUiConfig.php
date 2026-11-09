<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCP\IL10N;

/** Texte für nextnotes-blocks.js und die Block-Module (SPEC §4.7), nur Hauptansicht; Tabellenblock-Fehlertexte je ErrorType, Rechenblock je Fehlercode */
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
				'blockDeferred' => $l->t('Not calculated. Click to calculate.'),
				'errDiv0' => $l->t('Division by zero'),
				'errName' => $l->t('Unknown function or name'),
				'errValue' => $l->t('Wrong type of value, for example text instead of a number'),
				'errNum' => $l->t('Invalid number or result out of range'),
				'errRef' => $l->t('Invalid cell reference'),
				'errNa' => $l->t('Value not available'),
				'errCycle' => $l->t('Circular reference: the formula depends on its own result'),
				'errError' => $l->t('Invalid formula'),
				'calc' => $l->t('Calculation'),
				'calcInput' => $l->t('Calculation, one per line'),
				'calcPlaceholder' => $l->t('e.g. a = 3'),
				'calcGraphShow' => $l->t('Show graph'),
				'calcGraphHide' => $l->t('Hide graph'),
				'calcGraphLabel' => $l->t('Graph'),
				'calcGraphHeavy' => $l->t('The graph is too complex to draw.'),
				'calcTooLong' => $l->t('Too long: at most 200 lines, 500 characters per line and 20,000 characters in total. Not saved.'),
				'calcVersion' => $l->t('This calculation block was created with a newer version and can only be viewed.'),
				'calcLoadError' => $l->t('The calculation block could not be loaded.'),
				'calcSkipped' => $l->t('not calculated'),
				'calcGraphDeferred' => $l->t('Graph not drawn. Click to draw.'),
				'calcTrue' => $l->t('true'),
				'calcFalse' => $l->t('false'),
				'calcError' => $l->t('Error'),
				'calcErrSyntax' => $l->t('Input not readable'),
				'calcErrComma' => $l->t('Separate arguments with ;'),
				'calcErrString' => $l->t('Text in quotation marks is not allowed'),
				'calcErrUnknown' => $l->t('Unknown name'),
				'calcErrNotAllowed' => $l->t('Not allowed'),
				'calcErrReserved' => $l->t('Name is reserved'),
				'calcErrDefined' => $l->t('Name is already defined'),
				'calcErrHeavy' => $l->t('Too much computation'),
				'calcErrBig' => $l->t('Number too large (at most 10,000)'),
				'calcErrLong' => $l->t('Line too long (at most 500 characters)'),
				'calcErrArgs' => $l->t('Wrong number of arguments'),
				'calcErrType' => $l->t('Result cannot be shown'),
			],
		];
	}
}
