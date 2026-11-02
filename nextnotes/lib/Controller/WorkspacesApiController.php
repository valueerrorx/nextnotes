<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\Exception\BadRequestException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;

class WorkspacesApiController extends ApiController {
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->uid();
		return $this->ok(['workspaces' => $this->workspaces->index($uid), 'username' => $uid, 'acting_as' => null]);
	}

	#[NoAdminRequired]
	public function store(): JSONResponse {
		$in = $this->input();
		if (trim((string)($in['name'] ?? '')) === '') {
			throw new BadRequestException('name is required');
		}
		[$ws, $tags] = $this->workspaces->create($this->uid(), $in['name'], $in['tags'] ?? [], array_key_exists('color', $in), $in['color'] ?? null);
		return $this->ok(['name' => $ws->getName(), 'tags' => $tags, 'color' => $ws->getColor()], 201);
	}

	#[NoAdminRequired]
	public function update(string $name): JSONResponse {
		$in = $this->input();
		unset($in['name']);
		return $this->ok($this->workspaces->update($this->uid(), $name, $in));
	}

	#[NoAdminRequired]
	public function destroy(string $name): JSONResponse {
		$this->workspaces->delete($this->uid(), $name);
		return $this->ok();
	}
}
