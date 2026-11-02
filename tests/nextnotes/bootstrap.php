<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Läuft im NC-Container als www-data (dev/nextcloud/run-phpunit.sh)
define('PHPUNIT_RUN', 1);
require_once '/var/www/html/lib/base.php';
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('nextnotes');
require_once __DIR__ . '/vendor/autoload.php';
