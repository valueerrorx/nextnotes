<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\ChangesService;
use OCA\NextNotes\Service\DuplicateService;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotImplementedException;
use OCA\NextNotes\Service\InputValidator;
use OCA\NextNotes\Service\LockService;
use OCA\NextNotes\Service\NoteSerializer;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\SearchService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Notizrouten aus Upstream NotesController und notizbezogene aus FoldersController
class NotesApiController extends ApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		WorkspaceService $workspaces,
		private NoteService $notes,
		private InputValidator $validator,
		private SearchService $search,
		private ChangesService $changes,
		private LockService $locks,
		private DuplicateService $duplicates,
		private AttachmentService $attachments,
		private AccessService $access,
	) {
		parent::__construct($request, $userSession, $workspaces);
	}

	// Editor-Sitzung aus Header oder Body (Upstream getEditorSessionId)
	private function editorSession(): string {
		$fromBody = $this->param('editor_session_id');
		if (is_scalar($fromBody) && trim((string)$fromBody) !== '') {
			return trim((string)$fromBody);
		}
		return trim($this->request->getHeader('X-Editor-Session-ID'));
	}

	#[NoAdminRequired]
	public function resolve(): JSONResponse {
		return $this->ok($this->search->resolve($this->uid(), $this->param('reference'), $this->param('workspace')));
	}

	#[NoAdminRequired]
	public function search(): JSONResponse {
		return $this->ok($this->search->search($this->uid(), $this->input()));
	}

	#[NoAdminRequired]
	public function searchIds(): JSONResponse {
		return $this->ok($this->search->ids($this->uid(), $this->input()));
	}

	#[NoAdminRequired]
	public function withAttachments(): JSONResponse {
		return $this->ok($this->attachments->withAttachments($this->uid(), $this->param('workspace')));
	}

	#[NoAdminRequired]
	public function changes(): JSONResponse {
		return $this->ok($this->changes->changes($this->uid(), $this->param('workspace'), $this->param('note_ids')));
	}

	// takeover wird ignoriert (ADR 0005, SPEC §11.2 B13)
	#[NoAdminRequired]
	public function acquireLock(int $id): JSONResponse {
		return $this->ok($this->locks->acquire($this->uid(), $id, $this->editorSession()));
	}

	#[NoAdminRequired]
	public function lockStatus(int $id): JSONResponse {
		return $this->ok($this->locks->status($this->uid(), $id, $this->editorSession()));
	}

	#[NoAdminRequired]
	public function heartbeatLock(int $id): JSONResponse {
		$data = $this->locks->acquire($this->uid(), $id, $this->editorSession());
		return $this->ok(['lock' => $data['lock']]);
	}

	#[NoAdminRequired]
	public function releaseLock(int $id): JSONResponse {
		return $this->ok(['released' => $this->locks->release($this->uid(), $id, $this->editorSession())]);
	}

	#[NoAdminRequired]
	public function duplicate(int $id): JSONResponse {
		$in = $this->input();
		unset($in['id']);
		return $this->ok($this->duplicates->duplicateNote($this->uid(), $id, $in));
	}

	#[NoAdminRequired]
	public function reorder(): JSONResponse {
		return $this->ok($this->notes->reorder($this->uid(), $this->input()));
	}

	#[NoAdminRequired]
	public function clearFavorites(): JSONResponse {
		return $this->ok($this->notes->clearFavorites($this->uid(), $this->param('workspace')));
	}

	#[NoAdminRequired]
	public function templates(): JSONResponse {
		return $this->ok($this->notes->templates($this->uid(), $this->param('workspace')));
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->ok($this->notes->index($this->uid(), $this->input()));
	}

	// ETag = Version + Antwort-Prüfsumme; NotModifiedMiddleware antwortet bei passendem If-None-Match mit 304
	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		[$note, $version] = $this->notes->show($this->uid(), $id, $this->param('workspace'));
		$response = $this->ok(['note' => $note]);
		$response->setETag(NoteSerializer::etag($version, $note));
		return $response;
	}

	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->ok($this->notes->create($this->uid(), $this->input()), 201);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse {
		$in = $this->input();
		unset($in['id']);
		$data = $this->notes->update($this->uid(), $id, $in, (string)$this->request->getHeader('If-Match'), $this->editorSession());
		$response = $this->ok($data);
		$response->setETag(NoteSerializer::etag($data['note']['version'], $data['note']));
		return $response;
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		$permanent = filter_var($this->param('permanent') ?? false, FILTER_VALIDATE_BOOLEAN);
		return $this->ok($this->notes->delete($this->uid(), $id, $permanent, $this->param('workspace')));
	}

	#[NoAdminRequired]
	public function restore(int $id): JSONResponse {
		return $this->ok($this->notes->restore($this->uid(), $id, $this->param('workspace')));
	}

	#[NoAdminRequired]
	public function beacon(int $id): JSONResponse {
		$this->notes->beacon($this->uid(), $id, $this->param('content'), $this->editorSession());
		return $this->ok(['id' => $id]);
	}

	#[NoAdminRequired]
	public function updateTags(int $id): JSONResponse {
		$tags = $this->notes->setTags($this->uid(), $id, $this->input());
		return $this->ok(['message' => 'Tags updated successfully', 'note' => ['id' => $id, 'tags' => $tags]]);
	}

	#[NoAdminRequired]
	public function updateIcon(int $id): JSONResponse {
		[$icon, $color] = $this->notes->setIcon($this->uid(), $id, $this->param('icon'), $this->param('icon_color'));
		return $this->ok(['message' => 'Note icon updated successfully', 'icon' => $icon, 'icon_color' => $color]);
	}

	#[NoAdminRequired]
	public function updateColor(int $id): JSONResponse {
		$color = $this->notes->setColor($this->uid(), $id, $this->param('color'));
		return $this->ok(['message' => 'Note color updated successfully', 'color' => $color, 'color_hex' => $this->validator->colorHex($color)]);
	}

	#[NoAdminRequired]
	public function toggleFavorite(int $id): JSONResponse {
		return $this->ok(['is_favorite' => $this->notes->toggleFavorite($this->uid(), $id, $this->param('workspace'))]);
	}

	#[NoAdminRequired]
	public function markOpened(int $id): JSONResponse {
		$this->notes->markOpened($this->uid(), $id);
		return $this->ok(['note_id' => $id]);
	}

	#[NoAdminRequired]
	public function updatePinned(int $id): JSONResponse {
		$in = $this->input();
		if (!array_key_exists('pinned', $in)) {
			throw new BadRequestException('Invalid JSON in request body: expected a "pinned" boolean');
		}
		$pinned = $this->validator->bool($in['pinned'], 'pinned');
		$this->notes->setPinned($this->uid(), $id, $pinned);
		return $this->ok(['message' => 'Note pinned state updated successfully', 'pinned' => $pinned]);
	}

	#[NoAdminRequired]
	public function updateContentWidth(int $id): JSONResponse {
		$in = $this->input();
		if (!array_key_exists('content_width', $in)) {
			throw new BadRequestException('Invalid JSON in request body: expected a "content_width" percentage or null');
		}
		$width = $in['content_width'];
		if ($width !== null) {
			$width = filter_var($width, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 100]]);
			if ($width === false) {
				throw new BadRequestException('Invalid value for "content_width": expected a percentage between 10 and 100, or null');
			}
		}
		$this->notes->setContentWidth($this->uid(), $id, $width);
		return $this->ok(['message' => 'Note content width updated successfully', 'content_width' => $width]);
	}

	#[NoAdminRequired]
	public function updateTitleStyle(int $id): JSONResponse {
		$style = $this->notes->setTitleStyle($this->uid(), $id, $this->input());
		return $this->ok(['message' => 'Note title style updated successfully'] + $style);
	}

	#[NoAdminRequired]
	public function moveToFolder(int $id): JSONResponse {
		$in = $this->input();
		unset($in['id']);
		return $this->ok($this->notes->moveToFolder($this->uid(), $id, $in));
	}

	#[NoAdminRequired]
	public function removeFromFolder(int $id): JSONResponse {
		$this->notes->removeFromFolder($this->uid(), $id, $this->param('workspace'));
		return $this->ok(['message' => 'Note removed from folder successfully', 'share_delta' => 0]);
	}

	#[NoAdminRequired]
	public function archive(int $id): JSONResponse {
		return $this->ok($this->notes->archive($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function kanbanCompleted(int $id): JSONResponse {
		$in = $this->input();
		if (!array_key_exists('completed', $in)) {
			throw new BadRequestException('Missing "completed" field');
		}
		$completed = filter_var($in['completed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		if ($completed === null) {
			throw new BadRequestException('Field "completed" must be a boolean');
		}
		$this->notes->setKanbanCompleted($this->uid(), $id, $completed);
		return $this->ok(['note_id' => $id, 'completed' => $completed]);
	}

	// Konvertierung folgt mit dem Export in M4 (SPEC §1.2.5); Rechte wie Upstream vorher geprüft
	#[NoAdminRequired]
	public function convert(int $id): JSONResponse {
		$this->access->requireNote($this->uid(), $id, 'write');
		throw new NotImplementedException('not_implemented');
	}

	#[NoAdminRequired]
	public function convertHtml(): JSONResponse {
		$this->uid();
		throw new NotImplementedException('not_implemented');
	}

	#[NoAdminRequired]
	public function convertMarkdown(): JSONResponse {
		$this->uid();
		throw new NotImplementedException('not_implemented');
	}
}
