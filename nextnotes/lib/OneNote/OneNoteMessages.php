<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use OCP\IL10N;

// Feste Meldungen für Callback-Codes und error_code (Plan M9 §4.2); nie Text von Microsoft
final class OneNoteMessages {
	// Callback error= → Meldungscode (§4.2.2)
	public const CALLBACK_ERRORS = [
		'access_denied' => 'cancelled',
		'consent_required' => 'consent',
		'interaction_required' => 'consent',
		'invalid_scope' => 'permissions',
		'unauthorized_client' => 'permissions',
	];
	public const CODES = ['cancelled', 'consent', 'permissions', 'failed', 'config_changed', 'account_mismatch', 'not_configured',
		'invalid_client', 'invalid_grant', 'user_disabled', 'app_disabled', 'quota', 'graph_not_found', 'graph_10008'];

	public static function forCallbackError(string $error): string {
		return self::CALLBACK_ERRORS[$error] ?? 'failed';
	}

	public static function forError(string $code, IL10N $l): string {
		return match ($code) {
			'cancelled' => $l->t('Sign-in cancelled.'),
			'consent' => $l->t('Consent is missing. Please ask your administrator.'),
			'permissions' => $l->t('Check the permissions in the app registration (administrator).'),
			'failed' => $l->t('Sign-in failed.'),
			'config_changed' => $l->t('The administrator changed the OneNote configuration. Please start the import again; pages already imported are skipped.'),
			'account_mismatch' => $l->t('Please sign in with the same Microsoft account as at the start.'),
			'not_configured' => $l->t('The OneNote import is not set up on this server.'),
			'invalid_client' => $l->t('The OneNote configuration is invalid (e.g. expired client secret). Please contact your administrator.'),
			'invalid_grant' => $l->t('Your Microsoft sign-in has expired. Please sign in again.'),
			'user_disabled' => $l->t('Your account is disabled.'),
			'app_disabled' => $l->t('You no longer have access to Next Notes.'),
			'quota' => $l->t('Not enough storage for attachments.'),
			'graph_not_found', 'graph_10008' => $l->t('Microsoft could not provide the notebook.'),
			default => '',
		};
	}
}
