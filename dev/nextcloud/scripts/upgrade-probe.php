<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Nur Tests (M7e, upgrade-path.sh): im Container per `php -- <befehl> …` über stdin ausgeführt.
// jobs <Klasse…>       oc_jobs der App = genannte TimedJobs (keine fehlenden, keine verwaisten, keine doppelten); Exit 1 bei Abweichung
// migrations           ausgeführte Migrationen der App, je Zeile eine Version
// team <owner> <name> <mitglieder-json>   Team über CirclesManager anlegen, gibt die singleId aus
// note-ws <id>         workspace_id einer Notiz (leer, wenn keine)
// ws-exists <id>       1/0: Workspace-Zeile vorhanden
// break-jobs <Klasse>  Gegenprobe: Job entfernen
// break-schema         Gegenprobe: zusätzliche Spalte in nextnotes_workspaces
require '/var/www/html/lib/base.php';

use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Server;

$db = Server::get(IDBConnection::class);
$cmd = $argv[1] ?? '';
$args = array_slice($argv, 2);

switch ($cmd) {
	case 'jobs':
		$want = array_values(array_unique($args));
		$qb = $db->getQueryBuilder();
		$res = $qb->select('class', 'argument')->from('jobs')->executeQuery();
		$have = [];
		while ($row = $res->fetch()) {
			$c = ltrim((string)$row['class'], '\\');
			if (str_starts_with($c, 'OCA\\NextNotes\\')) {
				$have[] = $c;
			}
		}
		$res->closeCursor();
		$errors = [];
		foreach ($want as $c) {
			if (!in_array($c, $have, true)) {
				$errors[] = "fehlt: $c";
			}
		}
		foreach (array_count_values($have) as $c => $n) {
			if (!class_exists($c)) {
				$errors[] = "verwaist (Klasse fehlt): $c";
			} elseif (in_array($c, $want, true)) {
				if ($n > 1) {
					$errors[] = "doppelt ($n×): $c";
				}
			} elseif (!is_subclass_of($c, QueuedJob::class)) {
				// QueuedJobs mit Argument (z. B. Kontodaten-Löschung) sind Laufzeit-Jobs, alle anderen müssen in info.xml stehen
				$errors[] = (is_subclass_of($c, TimedJob::class) ? 'TimedJob nicht in info.xml: ' : 'unerwartet: ') . $c;
			}
		}
		sort($have);
		echo 'Jobs der App in oc_jobs: ' . count($have) . ' (' . implode(', ', array_map(static fn ($c) => substr($c, strrpos($c, '\\') + 1), $have)) . ")\n";
		foreach ($errors as $e) {
			echo "FAIL Job $e\n";
		}
		exit($errors === [] ? 0 : 1);
	case 'migrations':
		$qb = $db->getQueryBuilder();
		$res = $qb->select('version')->from('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('nextnotes')))->executeQuery();
		$v = [];
		while (($x = $res->fetchOne()) !== false) {
			$v[] = (string)$x;
		}
		$res->closeCursor();
		sort($v);
		echo implode("\n", $v) . ($v === [] ? '' : "\n");
		exit(0);
	case 'team':
		[$owner, $name, $members] = $args + ['', '', '{}'];
		$cm = Server::get(\OCA\Circles\CirclesManager::class);
		$cm->startSession($cm->getLocalFederatedUser($owner));
		try {
			$circle = $cm->createCircle($name, $cm->getLocalFederatedUser($owner), false, false, false);
			foreach (json_decode($members, true, 4, JSON_THROW_ON_ERROR) as $uid => $level) {
				$m = $cm->addMember($circle->getSingleId(), $cm->getLocalFederatedUser((string)$uid));
				if ((int)$level !== 1) {
					$cm->levelMember($m->getId(), (int)$level);
				}
			}
			echo $circle->getSingleId();
		} finally {
			$cm->stopSession();
		}
		exit(0);
	case 'note-ws':
		$qb = $db->getQueryBuilder();
		$qb->select('workspace_id')->from('nextnotes_notes')->where($qb->expr()->eq('id', $qb->createNamedParameter((int)($args[0] ?? 0), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		echo (string)($qb->executeQuery()->fetchOne() ?: '');
		exit(0);
	case 'ws-exists':
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from('nextnotes_workspaces')->where($qb->expr()->eq('id', $qb->createNamedParameter((int)($args[0] ?? 0), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		echo $qb->executeQuery()->fetchOne() === false ? '0' : '1';
		exit(0);
	case 'break-jobs':
		Server::get(IJobList::class)->remove($args[0] ?? '');
		echo 'Gegenprobe: Job entfernt: ' . ($args[0] ?? '') . "\n";
		exit(0);
	case 'break-schema':
		// Standard-SQL, auf SQLite, MariaDB und PostgreSQL gleich
		$db->executeStatement('ALTER TABLE *PREFIX*nextnotes_workspaces ADD nn_upgrade_probe INTEGER NULL');
		echo "Gegenprobe: Spalte nextnotes_workspaces.nn_upgrade_probe angelegt\n";
		exit(0);
	default:
		fwrite(STDERR, "Aufruf: upgrade-probe.php jobs|migrations|team|note-ws|ws-exists|break-jobs|break-schema …\n");
		exit(2);
}
