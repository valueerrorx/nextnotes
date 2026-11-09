<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\Db\OneNoteItemMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\OneNote\Convert\PageConverter;
use OCA\NextNotes\OneNote\GraphClient;
use OCA\NextNotes\OneNote\HttpGate;
use OCA\NextNotes\OneNote\OneNoteConfig;
use OCA\NextNotes\OneNote\OneNoteImportService;
use OCA\NextNotes\OneNote\OneNoteNotifications;
use OCA\NextNotes\Service\FolderService;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\Transaction;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use OCP\Server;

// M9b: Import-Dienst im Prozess mit FakeGraph (Graph) und tokenResponder (Login), Zeit gestellt, ohne Pausen
abstract class ImportTestCase extends OneNoteTestCase {
	protected FakeGraph $graph;
	protected FakeHttp $http;

	protected function setUp(): void {
		parent::setUp();
		$this->graph = new FakeGraph();
		$token = $this->tokenResponder();
		$this->http = new FakeHttp(function (string $method, string $url, array $options) use ($token) {
			return parse_url($url, PHP_URL_HOST) === HttpGate::GRAPH_HOST ? $this->graph->respond($url) : $token($method, $url, $options);
		});
	}

	protected function importer(): OneNoteImportService {
		$imports = Server::get(OneNoteImportMapper::class);
		$graph = new class(new HttpGate($this->http), $imports, $this->time(), $this->logger()) extends GraphClient {
			protected function pause(float $seconds): void {
			}
		};
		return new OneNoteImportService(
			Server::get(OneNoteConfig::class), $imports, Server::get(OneNoteItemMapper::class), $this->connectService($this->http), $graph,
			Server::get(PageConverter::class), Server::get(WorkspaceService::class), Server::get(FolderService::class), Server::get(NoteService::class),
			Server::get(WorkspaceMapper::class), Server::get(FolderMapper::class), Server::get(NoteMapper::class), Server::get(Transaction::class),
			Server::get(ISecureRandom::class), $this->time(), Server::get(IUserManager::class), Server::get(IAppManager::class),
			Server::get(IFactory::class), Server::get(OneNoteNotifications::class), $this->logger(),
		);
	}

	/** Angemeldet, Auswahl gespeichert (queued); liefert die Import-ID */
	protected function started(string $uid, array $selection): int {
		$id = $this->signIn($this->connectService($this->http), $uid);
		$this->importer()->start($uid, $id, $selection);
		return $id;
	}

	/** Läufe, bis der Import nicht mehr queued/running ist oder $max erreicht; Zeit rückt je Lauf vor */
	protected function runUntilDone(int $id, int $max = 20, int $step = 3700): string {
		for ($i = 0; $i < $max; $i++) {
			$this->importer()->runDue();
			$status = (string)self::row($id)['status'];
			if (!in_array($status, ['queued', 'running'], true)) {
				return $status;
			}
			$this->now += $step;
		}
		return (string)self::row($id)['status'];
	}

	/** @return list<array<string, mixed>> Notizen in eigenen Workspaces */
	protected static function notesOf(string $uid): array {
		$ws = self::ownedWorkspaceIds($uid);
		if ($ws === []) {
			return [];
		}
		$qb = self::db()->getQueryBuilder();
		$qb->select('id', 'heading', 'workspace_id', 'folder_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'display_order', 'content')
			->from('nextnotes_notes')
			->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($ws, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id');
		return $qb->executeQuery()->fetchAllAssociative();
	}

	/** @return list<array<string, mixed>> */
	protected static function workspacesOf(string $uid): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from('nextnotes_workspaces')
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($uid)))->orderBy('id');
		return $qb->executeQuery()->fetchAllAssociative();
	}

	/** @return list<array<string, mixed>> */
	protected static function foldersIn(int $wsId): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from('nextnotes_folders')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($wsId)))->orderBy('id');
		return $qb->executeQuery()->fetchAllAssociative();
	}

	/** @return list<array<string, mixed>> */
	protected static function report(int $id): array {
		return (array)json_decode((string)self::row($id)['report'], true);
	}

	protected static function assertNoSecrets(int $id): void {
		$row = self::row($id);
		foreach (['access_enc', 'refresh_enc', 'lease_token', 'lease_until'] as $col) {
			self::assertNull($row[$col], $col);
		}
	}

	/** Notizbuch mit $sections Abschnitten zu je $pages Seiten */
	protected function notebook(string $root, string $id, int $sections, int $pages): void {
		$s = [];
		for ($i = 0; $i < $sections; $i++) {
			$s[] = ['id' => "$id-s$i", 'displayName' => "Abschnitt $i", 'pages' => FakeGraph::pages("$id-s$i-p", $pages)];
		}
		$this->graph->notebooks[$root][] = ['id' => $id, 'displayName' => "Buch $id", 'sections' => $s, 'sectionGroups' => []];
	}
}
