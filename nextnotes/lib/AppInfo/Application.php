<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\AppInfo;

use OCA\NextNotes\Listener\UserDeletedListener;
use OCA\NextNotes\Middleware\ApiExceptionMiddleware;
use OCA\NextNotes\Middleware\InputEncodingMiddleware;
use OCA\NextNotes\Notification\Notifier;
use OCA\NextNotes\Search\NotesProvider;
use OCA\NextNotes\Teams\WorkspaceResourceProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
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
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerTeamResourceProvider(WorkspaceResourceProvider::class);
	}

	public function boot(IBootContext $context): void {
	}
}
