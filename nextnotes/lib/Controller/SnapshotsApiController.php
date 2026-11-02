<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\SnapshotService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Snapshots (Upstream SnapshotsController); Auswahl per ?snapshot_key= oder ?date=
class SnapshotsApiController extends ApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		WorkspaceService $workspaces,
		private SnapshotService $snapshots,
		private NoteService $notes,
	) {
		parent::__construct($request, $userSession, $workspaces);
	}

	private function flag(string $name): bool {
		return in_array(strtolower((string)($this->param($name) ?? '0')), ['1', 'true', 'yes'], true);
	}

	private function str(string $name): string {
		$v = $this->param($name);
		return is_scalar($v) ? (string)$v : '';
	}

	private function date(): ?string {
		$v = $this->param('date');
		return is_scalar($v) ? (string)$v : null;
	}

	#[NoAdminRequired]
	public function create(int $id): JSONResponse {
		return $this->ok($this->snapshots->create($this->uid(), $id, $this->flag('manual') || $this->flag('force')));
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse {
		return $this->ok($this->snapshots->list($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->ok($this->snapshots->show($this->uid(), $id, $this->str('snapshot_key'), $this->date()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->ok($this->snapshots->destroy($this->uid(), $id, $this->str('snapshot_key'), $this->str('date')));
	}

	#[NoAdminRequired]
	public function restore(int $id): JSONResponse {
		return $this->ok($this->notes->restoreSnapshot($this->uid(), $id, $this->str('snapshot_key'), $this->date(), $this->flag('meta')));
	}
}
