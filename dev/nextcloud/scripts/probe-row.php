<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Nur Tests: im Container per `php -- insert|count` über stdin ausgeführt.
require '/var/www/html/lib/base.php';

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

$db = Server::get(IDBConnection::class);
$owner = 'm1-probe';

switch ($argv[1] ?? '') {
	case 'insert':
		$qb = $db->getQueryBuilder();
		$qb->insert('nextnotes_workspaces')->values([
			'owner_id' => $qb->createNamedParameter($owner),
			'name' => $qb->createNamedParameter('Probe'),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		break;
	case 'count':
		$qb = $db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('nextnotes_workspaces')
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($owner)));
		echo (int)$qb->executeQuery()->fetchOne() . "\n";
		break;
	default:
		fwrite(STDERR, "Aufruf: probe-row.php insert|count\n");
		exit(2);
}
