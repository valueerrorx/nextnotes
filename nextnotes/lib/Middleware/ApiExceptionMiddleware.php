<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Middleware;

use OCA\NextNotes\Controller\TagsApiController;
use OCA\NextNotes\Controller\WorkspacesApiController;
use OCA\NextNotes\Service\Exception\ApiException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;

// Nur eigene Fehler → Upstream-JSON; alles andere (CSRF, Login, …) unverändert weiter
class ApiExceptionMiddleware extends Middleware {
	public function afterException(Controller $controller, string $methodName, \Exception $exception) {
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
		// Upstream Workspaces/Tags liefern den Text zusätzlich unter „message“
		if ($controller instanceof WorkspacesApiController || $controller instanceof TagsApiController) {
			$body['message'] = $message;
		}
		return new JSONResponse($body + $extra, $status);
	}
}
