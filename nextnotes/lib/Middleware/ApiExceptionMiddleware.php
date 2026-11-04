<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Middleware;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Controller\AttachmentsApiController;
use OCA\NextNotes\Controller\CompatController;
use OCA\NextNotes\Controller\TagsApiController;
use OCA\NextNotes\Controller\WorkspacesApiController;
use OCA\NextNotes\Service\Exception\ApiException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\DB\Exception as DbException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

// Nur eigene Fehler und DB-Sperr-Timeouts → Upstream-JSON; alles andere (CSRF, Login, …) unverändert weiter
class ApiExceptionMiddleware extends Middleware {
	private const RETRY_AFTER = 2;
	// Download-Links: Navigation bekommt eine Fehlerseite statt JSON (M4d-fix); Rate-Limit-429 liefert NC selbst (core/429)
	private const EXPORT_METHODS = ['downloadNote', 'exportNote', 'exportFolder', 'exportEntries', 'exportStructured', 'exportAttachments'];

	public function __construct(
		private IRequest $request,
		private IL10N $l,
		private IURLGenerator $urls,
	) {
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception) {
		if ($controller instanceof CompatController && in_array($methodName, self::EXPORT_METHODS, true) && $this->isNavigation()) {
			$page = $this->exportErrorPage($exception);
			if ($page !== null) {
				return $page;
			}
		}
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
		if ($controller instanceof WorkspacesApiController || $controller instanceof TagsApiController || $controller instanceof AttachmentsApiController || $controller instanceof CompatController) {
			$body['message'] = $message;
		}
		return new JSONResponse($body + $extra, $status);
	}

	// Browser-Navigation (Link, Adresszeile): Accept mit text/html, kein XHR; fetch() schickt */*
	private function isNavigation(): bool {
		return stripos($this->request->getHeader('Accept'), 'text/html') !== false
			&& strcasecmp($this->request->getHeader('X-Requested-With'), 'XMLHttpRequest') !== 0;
	}

	private function exportErrorPage(\Exception $exception): ?TemplateResponse {
		$status = match (true) {
			$exception instanceof ApiException => $exception->getStatus(),
			$exception instanceof DoesNotExistException => 404,
			default => 0,
		};
		$message = match ($status) {
			429 => $this->l->t('Another export is still running – please wait a moment and try again.'),
			413 => $this->l->t('Export too large (max. 1 GiB).'),
			404 => $this->l->t('Not found or no access.'),
			default => null,
		};
		if ($message === null) {
			return null;
		}
		$response = new TemplateResponse(Application::APP_ID, 'export_error', [
			'status' => $status, 'message' => $message, 'backUrl' => $this->urls->linkToRoute(Application::APP_ID . '.page.index'),
		], TemplateResponse::RENDER_AS_GUEST, $status);
		if ($status === 429) {
			$response->addHeader('Retry-After', '60');
		}
		return $response;
	}
}
