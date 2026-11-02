<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#index', 'url' => '/index.php', 'verb' => 'GET', 'postfix' => 'php'],
		// Spike ADR 0004: .php-URL im App-Pfad
		['name' => 'page#dashboard', 'url' => '/dashboard.php', 'verb' => 'GET'],
		// API v1 (M2a), Reihenfolge wie src/public/api/v1/index.php
		['name' => 'notes_api#reorder', 'url' => '/api/v1/notes/reorder', 'verb' => 'POST'],
		['name' => 'notes_api#clearFavorites', 'url' => '/api/v1/notes/favorites/clear', 'verb' => 'POST'],
		['name' => 'notes_api#templates', 'url' => '/api/v1/notes/templates', 'verb' => 'GET'],
		['name' => 'notes_api#index', 'url' => '/api/v1/notes', 'verb' => 'GET'],
		['name' => 'notes_api#show', 'url' => '/api/v1/notes/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#create', 'url' => '/api/v1/notes', 'verb' => 'POST'],
		['name' => 'notes_api#update', 'url' => '/api/v1/notes/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#destroy', 'url' => '/api/v1/notes/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#restore', 'url' => '/api/v1/notes/{id}/restore', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#beacon', 'url' => '/api/v1/notes/{id}/beacon', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#updateTags', 'url' => '/api/v1/notes/{id}/tags', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#updateIcon', 'url' => '/api/v1/notes/{id}/icon', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#updateColor', 'url' => '/api/v1/notes/{id}/color', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#toggleFavorite', 'url' => '/api/v1/notes/{id}/favorite', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#markOpened', 'url' => '/api/v1/notes/{id}/opened', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#updatePinned', 'url' => '/api/v1/notes/{id}/pinned', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#updateContentWidth', 'url' => '/api/v1/notes/{id}/content-width', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#updateTitleStyle', 'url' => '/api/v1/notes/{id}/title-style', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#moveToFolder', 'url' => '/api/v1/notes/{id}/folder', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#removeFromFolder', 'url' => '/api/v1/notes/{id}/remove-folder', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#archive', 'url' => '/api/v1/notes/{id}/archive', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'notes_api#kanbanCompleted', 'url' => '/api/v1/notes/{id}/kanban-completed', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#counts', 'url' => '/api/v1/folders/counts', 'verb' => 'GET'],
		['name' => 'folders_api#suggested', 'url' => '/api/v1/folders/suggested', 'verb' => 'GET'],
		['name' => 'folders_api#moveFiles', 'url' => '/api/v1/folders/move-files', 'verb' => 'POST'],
		['name' => 'folders_api#reorder', 'url' => '/api/v1/folders/reorder', 'verb' => 'POST'],
		['name' => 'folders_api#restoreDeleted', 'url' => '/api/v1/folders/restore', 'verb' => 'POST'],
		['name' => 'folders_api#index', 'url' => '/api/v1/folders', 'verb' => 'GET'],
		['name' => 'folders_api#show', 'url' => '/api/v1/folders/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#create', 'url' => '/api/v1/folders', 'verb' => 'POST'],
		['name' => 'folders_api#update', 'url' => '/api/v1/folders/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#destroy', 'url' => '/api/v1/folders/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#move', 'url' => '/api/v1/folders/{id}/move', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#archive', 'url' => '/api/v1/folders/{id}/archive', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#empty', 'url' => '/api/v1/folders/{id}/empty', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#addTags', 'url' => '/api/v1/folders/{id}/tags', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#removeTags', 'url' => '/api/v1/folders/{id}/tags', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#updateIcon', 'url' => '/api/v1/folders/{id}/icon', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#updateColor', 'url' => '/api/v1/folders/{id}/color', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#updatePinned', 'url' => '/api/v1/folders/{id}/pinned', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#updateFavorite', 'url' => '/api/v1/folders/{id}/favorite', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#noteCount', 'url' => '/api/v1/folders/{id}/notes', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'folders_api#path', 'url' => '/api/v1/folders/{id}/path', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'trash_api#index', 'url' => '/api/v1/trash', 'verb' => 'GET'],
		['name' => 'trash_api#empty', 'url' => '/api/v1/trash', 'verb' => 'DELETE'],
		['name' => 'trash_api#destroy', 'url' => '/api/v1/trash/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'workspaces_api#index', 'url' => '/api/v1/workspaces', 'verb' => 'GET'],
		['name' => 'workspaces_api#store', 'url' => '/api/v1/workspaces', 'verb' => 'POST'],
		['name' => 'workspaces_api#update', 'url' => '/api/v1/workspaces/{name}', 'verb' => 'PATCH', 'requirements' => ['name' => '.+']],
		['name' => 'workspaces_api#destroy', 'url' => '/api/v1/workspaces/{name}', 'verb' => 'DELETE', 'requirements' => ['name' => '.+']],
		['name' => 'tags_api#index', 'url' => '/api/v1/tags', 'verb' => 'GET'],
		['name' => 'tags_api#rename', 'url' => '/api/v1/tags/{tag}', 'verb' => 'PATCH', 'requirements' => ['tag' => '[^/]+']],
		['name' => 'tags_api#destroy', 'url' => '/api/v1/tags/{tag}', 'verb' => 'DELETE', 'requirements' => ['tag' => '[^/]+']],
	],
];
