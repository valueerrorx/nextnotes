<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Admin;

use OCA\NextNotes\Command\Purge;
use OCA\NextNotes\Tests\Assignments\AssignmentTestCase;
use OCP\IConfig;
use OCP\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// M7b: occ-Befehle direkt ausführen, Zustand der App (Tabellen, IAppData, Werte, Benachrichtigungen) als Prüfsumme
abstract class AdminTestCase extends AssignmentTestCase {
	// Spalten, die eine UID als Recht oder Bezug tragen (SPEC §2 UID-Regel, M6 Abgaben/Aufgaben)
	protected const UID_COLUMNS = [
		'nextnotes_workspaces' => ['owner_id', 'created_by'],
		'nextnotes_ws_shares' => ['share_with', 'created_by'],
		'nextnotes_notes' => ['created_by', 'updated_by'],
		'nextnotes_attachments' => ['created_by'],
		'nextnotes_snapshots' => ['created_by'],
		'nextnotes_reminders' => ['user_id'],
		'nextnotes_locks' => ['holder_uid'],
		'nextnotes_mentions_handled' => ['user_id'],
		'nextnotes_assignments' => ['owner_uid', 'created_by'],
		'nextnotes_submissions' => ['student_uid', 'feedback_by'],
	];

	/**
	 * @param array<string, mixed> $args
	 * @param string|null $answer Eingabe für eine Rückfrage (interaktiv), sonst --no-interaction
	 * @return array{0: int, 1: string}
	 */
	protected static function runCommand(string $class, array $args = [], ?string $answer = null): array {
		/** @var Command $cmd */
		$cmd = Server::get($class);
		$cmd->setHelperSet(new HelperSet([new QuestionHelper()]));
		$input = new ArrayInput($args);
		if ($answer !== null) {
			$stream = fopen('php://memory', 'r+b');
			fwrite($stream, $answer . "\n");
			rewind($stream);
			$input->setStream($stream);
		} else {
			$input->setInteractive(false);
		}
		$out = new BufferedOutput();
		$rc = $cmd->run($input, $out);
		return [$rc, $out->fetch()];
	}

	/** @return list<string> „tabelle.spalte (n)“ mit Wert = uid */
	protected static function uidHits(string $uid): array {
		$hits = [];
		foreach (self::UID_COLUMNS as $table => $cols) {
			foreach ($cols as $col) {
				$n = self::countRows($table, $col, $uid);
				if ($n > 0) {
					$hits[] = "$table.$col ($n)";
				}
			}
		}
		return $hits;
	}

	/** @return array<string, string> Tabelle → Prüfsumme aller Zeilen (unabhängig von der Reihenfolge) */
	protected static function tableSums(): array {
		$out = [];
		foreach (Purge::TABLES as $table) {
			$qb = self::db()->getQueryBuilder();
			$rows = array_map(static fn (array $r): string => json_encode(array_map(static fn ($v) => $v === null ? null : (string)$v, $r)) ?: '', $qb->select('*')->from($table)->executeQuery()->fetchAllAssociative());
			sort($rows);
			$out[$table] = count($rows) . ':' . md5(implode("\n", $rows));
		}
		return $out;
	}

	protected static function appDataDir(): string {
		$cfg = Server::get(IConfig::class);
		return rtrim($cfg->getSystemValueString('datadirectory'), '/') . '/appdata_' . $cfg->getSystemValueString('instanceid') . '/nextnotes';
	}

	/** @return list<string> Dateien unter IAppData der App mit Größe */
	protected static function appDataFiles(): array {
		$dir = self::appDataDir();
		if (!is_dir($dir)) {
			return [];
		}
		$out = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
			$out[] = substr((string)$f->getPathname(), strlen($dir)) . ':' . $f->getSize();
		}
		sort($out);
		return $out;
	}

	protected static function notificationCount(?string $uid = null): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('notifications')->where($qb->expr()->eq('app', $qb->createNamedParameter('nextnotes')));
		if ($uid !== null) {
			$qb->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($uid)));
		}
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * ZIP-Datei samt innerer ZIPs: Pfad → Inhalt (innere als „äußerer/pfad.zip!innerer/pfad“)
	 * @return array<string, string>
	 */
	protected static function readZip(string $path): array {
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path) === true, "ZIP lesbar: $path");
		$out = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$data = (string)$zip->getFromIndex($i);
			$out[$name] = $data;
			if (str_ends_with($name, '.zip')) {
				foreach (self::readZipBytes($data) as $inner => $content) {
					$out[$name . '!' . $inner] = $content;
				}
			}
		}
		$zip->close();
		return $out;
	}

	/** @return array<string, string> */
	protected static function readZipBytes(string $bytes): array {
		$tmp = (string)tempnam(sys_get_temp_dir(), 'nnzip');
		file_put_contents($tmp, $bytes);
		try {
			return self::readZip($tmp);
		} finally {
			unlink($tmp);
		}
	}
}
