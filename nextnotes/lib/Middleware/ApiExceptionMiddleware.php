<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Middleware;

use OCA\NextNotes\Controller\AttachmentsApiController;
use OCA\NextNotes\Controller\TagsApiController;
use OCA\NextNotes\Controller\WorkspacesApiController;
use OCA\NextNotes\Service\Exception\ApiException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\DB\Exception as DbException;

// Nur eigene Fehler und DB-Sperr-Timeouts → Upstream-JSON; alles andere (CSRF, Login, …) unverändert weiter
class ApiExceptionMiddleware extends Middleware {
	private const RETRY_AFTER = 2;

	public function afterException(Controller $controller, string $methodName, \Exception $exception) {
		// Sperr-Timeout/Deadlock (v. a. SQLite „database is locked“) ist vorübergehend: 503 statt 500
		if ($exception instanceof DbException
			&& in_array($exception->getReason(), [DbException::REASON_LOCK_WAIT_TIMEOUT, DbException::REASON_DEADLOCK], true)) {
			$response = new JSONResponse(['success' => false, 'error' => 'Database busy, please retry'], 503);
			$response->addHeader('Retry-After', (string)self::RETRY_AFTER);
			return $response;
		}
		if ($exception instanceof ApiException) {
			$status = $exception->getStatus();
			$extra = $exception->getExtra();
		} elseif ($exception instanceof DoesNotExistException) {
			$status = 404;
			$extra = [];
		} else {
			throw $exception;
		}
		$message = $exception instanceof DoesNotExistException ? 'Not found' : $exception->getMessage();
		$body = ['success' => false, 'error' => $message];
		// Upstream Workspaces/Tags/Attachments liefern den Text zusätzlich unter „message“
		if ($controller instanceof WorkspacesApiController || $controller instanceof TagsApiController || $controller instanceof AttachmentsApiController) {
			$body['message'] = $message;
		}
		return new JSONResponse($body + $extra, $status);
	}
}
