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
	],
];
