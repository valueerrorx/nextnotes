<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\DuplicateService;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\FolderService;
use OCA\NextNotes\Service\InputValidator;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Ordnerrouten aus Upstream FoldersController (ohne notizbezogene, siehe NotesApiController)
class FoldersApiController extends ApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		WorkspaceService $workspaces,
		private FolderService $folders,
		private InputValidator $validator,
		private DuplicateService $duplicates,
	) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function duplicate(int $id): JSONResponse {
		return $this->ok($this->duplicates->duplicateFolder($this->uid(), $id, $this->param('workspace')), 201);
	}

	#[NoAdminRequired]
	public function counts(): JSONResponse {
		return $this->ok(['counts' => $this->folders->counts($this->uid(), $this->param('workspace'))]);
	}

	#[NoAdminRequired]
	public function suggested(): JSONResponse {
		return $this->ok(['folders' => $this->folders->suggested($this->uid(), $this->param('workspace'))]);
	}

	#[NoAdminRequired]
	public function moveFiles(): JSONResponse {
		return $this->ok($this->folders->moveFiles($this->uid(), $this->input()));
	}

	#[NoAdminRequired]
	public function reorder(): JSONResponse {
		return $this->ok($this->folders->reorder($this->uid(), $this->input()));
	}

	#[NoAdminRequired]
	public function restoreDeleted(): JSONResponse {
		return $this->ok($this->folders->restoreDeleted($this->uid(), $this->input()), 201);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		$hierarchical = filter_var($this->param('hierarchical'), FILTER_VALIDATE_BOOLEAN);
		return $this->ok($this->folders->index($this->uid(), $this->param('workspace'), $hierarchical));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->ok(['folder' => $this->folders->show($this->uid(), $id, $this->param('workspace'))]);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		[$data, $status] = $this->folders->create($this->uid(), $this->input());
		return $this->ok($data, $status);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse {
		return $this->ok($this->folders->rename($this->uid(), $id, $this->input()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->ok($this->folders->delete($this->uid(), $id, $this->param('workspace')));
	}

	#[NoAdminRequired]
	public function move(int $id): JSONResponse {
		return $this->ok($this->folders->move($this->uid(), $id, $this->input()));
	}

	#[NoAdminRequired]
	public function archive(int $id): JSONResponse {
		[$data, $status] = $this->folders->archive($this->uid(), $id);
		return $this->ok($data, $status);
	}

	#[NoAdminRequired]
	public function empty(int $id): JSONResponse {
		$count = $this->folders->empty($this->uid(), $id, $this->param('workspace'));
		return $this->ok(['message' => 'Moved ' . $count . ' notes to trash']);
	}

	#[NoAdminRequired]
	public function addTags(int $id): JSONResponse {
		return $this->ok($this->folders->changeTags($this->uid(), $id, $this->input(), true));
	}

	#[NoAdminRequired]
	public function removeTags(int $id): JSONResponse {
		return $this->ok($this->folders->changeTags($this->uid(), $id, $this->input(), false));
	}

	#[NoAdminRequired]
	public function updateIcon(int $id): JSONResponse {
		[$icon, $color] = $this->folders->setIcon($this->uid(), $id, $this->param('icon'), $this->param('icon_color'));
		return $this->ok(['message' => 'Folder icon updated successfully', 'icon' => $icon, 'icon_color' => $color]);
	}

	#[NoAdminRequired]
	public function updateColor(int $id): JSONResponse {
		[$color, $hex] = $this->folders->setColor($this->uid(), $id, $this->param('color'));
		return $this->ok(['message' => 'Folder color updated successfully', 'color' => $color, 'color_hex' => $hex]);
	}

	#[NoAdminRequired]
	public function updatePinned(int $id): JSONResponse {
		$value = $this->flag('pinned');
		$this->folders->setFlag($this->uid(), $id, 'pinned', $value);
		return $this->ok(['message' => 'Folder pinned state updated successfully', 'pinned' => $value]);
	}

	#[NoAdminRequired]
	public function updateFavorite(int $id): JSONResponse {
		$value = $this->flag('favorite');
		$this->folders->setFlag($this->uid(), $id, 'favorite', $value);
		return $this->ok(['message' => 'Folder favorite state updated successfully', 'favorite' => $value]);
	}

	#[NoAdminRequired]
	public function material(int $id): JSONResponse {
		return $this->ok($this->folders->setMaterial($this->uid(), $id, $this->flag('material')));
	}

	#[NoAdminRequired]
	public function noteCount(int $id): JSONResponse {
		return $this->ok($this->folders->noteCount($this->uid(), $id, $this->param('workspace')));
	}

	#[NoAdminRequired]
	public function path(int $id): JSONResponse {
		return $this->ok($this->folders->path($this->uid(), $id, $this->param('workspace')));
	}

	private function flag(string $field): bool {
		$in = $this->input();
		if (!array_key_exists($field, $in)) {
			throw new BadRequestException('Invalid JSON in request body: expected a "' . $field . '" boolean');
		}
		return $this->validator->bool($in[$field], $field);
	}
}
