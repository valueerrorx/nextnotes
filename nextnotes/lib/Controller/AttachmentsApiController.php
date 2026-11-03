<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Http\AttachmentResponse;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Anhänge (Upstream AttachmentsController); Fehler-JSON trägt zusätzlich „message“ (Middleware)
class AttachmentsApiController extends ApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		WorkspaceService $workspaces,
		private AttachmentService $attachments,
	) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function index(int $noteId): JSONResponse {
		return $this->ok($this->attachments->index($this->uid(), $noteId, $this->param('workspace')));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 300)]
	public function store(int $noteId): JSONResponse {
		$file = $this->request->getUploadedFile('file');
		return $this->ok($this->attachments->store($this->uid(), $noteId, is_array($file) ? $file : null, $this->param('workspace')), 201);
	}

	// Wird als <img src>/<audio src> geladen → ohne CSRF-Token; nur Lesen
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $noteId, int $attachmentId): FileDisplayResponse {
		[$attachment, $file] = $this->attachments->download($this->uid(), $noteId, $attachmentId, $this->param('workspace'));
		$download = !in_array((string)($this->param('download') ?? '0'), ['', '0'], true);
		return AttachmentResponse::build($attachment, $file, $download);
	}

	#[NoAdminRequired]
	public function destroy(int $noteId, int $attachmentId): JSONResponse {
		return $this->ok($this->attachments->destroy($this->uid(), $noteId, $attachmentId, $this->param('workspace')));
	}

	#[NoAdminRequired]
	public function move(int $noteId, int $attachmentId): JSONResponse {
		$in = $this->input();
		unset($in['noteId'], $in['attachmentId']);
		return $this->ok($this->attachments->move($this->uid(), $noteId, $attachmentId, $in));
	}
}
