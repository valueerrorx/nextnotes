<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\AppInfo;

use OCA\NextNotes\Listener\BeforeUserDeletedListener;
use OCA\NextNotes\Listener\CircleDestroyedListener;
use OCA\NextNotes\Listener\MembershipsRemovedListener;
use OCA\NextNotes\Listener\UserDeletedListener;
use OCA\NextNotes\Middleware\ApiExceptionMiddleware;
use OCA\NextNotes\Middleware\InputEncodingMiddleware;
use OCA\NextNotes\Notification\Notifier;
use OCA\NextNotes\Search\NotesProvider;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Teams\WorkspaceResourceProvider;
use OCA\NextNotes\UserMigration\NextNotesMigrator;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'nextnotes';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Reihenfolge: ApiException zuerst, damit sie den 400 aus InputEncoding in JSON übersetzt
		$context->registerMiddleware(ApiExceptionMiddleware::class);
		$context->registerMiddleware(InputEncodingMiddleware::class);
		$context->registerSearchProvider(NotesProvider::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerEventListener(BeforeUserDeletedEvent::class, BeforeUserDeletedListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerTeamResourceProvider(WorkspaceResourceProvider::class);
		$context->registerUserMigrator(NextNotesMigrator::class);
		// Circles-Events als Namen: App läuft auch ohne Circles (M5b)
		$context->registerEventListener(TeamService::EVENT_DESTROYING, CircleDestroyedListener::class);
		$context->registerEventListener(TeamService::EVENT_DESTROYED, CircleDestroyedListener::class);
		$context->registerEventListener(TeamService::EVENT_MEMBERSHIPS_REMOVED, MembershipsRemovedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
