<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Migration\Version000800Date20261112000000;
use OCA\NextNotes\Service\AssignmentDeletion;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Schema\ITable;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;

// M6-wsbind Done 2 (ADR 0021): preSchemaChange löscht Altaufgaben über AssignmentDeletion::purge, nur wenn die Spalte fehlt; Leerprüfung bricht ab
final class AssignmentMigrationTest extends AssignmentTestCase {
	// Schema vor 0.9.3 simuliert: Tabelle da, Spalte workspace_id fehlt (nach der Installation existiert sie, H2)
	private function schemaWithout(bool $hasColumn = false): \Closure {
		$table = $this->createStub(ITable::class);
		$table->method('hasColumn')->willReturnCallback(static fn (string $c) => $c === 'workspace_id' ? $hasColumn : true);
		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);
		return static fn () => $schema;
	}

	private static function appFileExists(int $noteId, int $attachmentId): bool {
		try {
			Server::get(IAppDataFactory::class)->get('nextnotes')->getFolder('attachments')->getFolder((string)$noteId)->getFile((string)$attachmentId);
			return true;
		} catch (NotFoundException) {
			return false;
		}
	}

	/** @return int[] */
	private static function ids(string $table, string $col, int $value): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	private static function allAssignments(): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('nextnotes_assignments');
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function testPreSchemaChangePurgesAssignments(): void {
		$fx = $this->distributedFixture();
		['aid' => $aid, 'tpl' => $tpl, 'wsId' => $wsId, 'src' => $src] = $fx;
		$notes = [$tpl];
		$studentWs = [];
		foreach ($fx['subs'] as $sub) {
			$notes[] = (int)$sub['note_id'];
			$studentWs[] = (int)self::row('nextnotes_notes', (int)$sub['note_id'])['workspace_id'];
		}
		$files = [];
		foreach ($notes as $note) {
			foreach (self::ids('nextnotes_attachments', 'note_id', $note) as $att) {
				$files[] = [$note, $att];
				self::assertTrue(self::appFileExists($note, $att), "Datei $note/$att vorher");
			}
		}
		$folders = self::ids('nextnotes_folders', 'assignment_id', $aid);
		self::assertNotEmpty($files);
		self::assertNotEmpty($folders);
		$output = $this->createStub(IOutput::class);

		// Spalte vorhanden (Neuinstallation/nach Upgrade) → nichts
		$migration = Server::get(Version000800Date20261112000000::class);
		$migration->preSchemaChange($output, $this->schemaWithout(true), []);
		self::assertNotNull(self::row('nextnotes_assignments', $aid), 'Spalte vorhanden: nichts gelöscht');

		// Spalte fehlt → alle Aufgaben über purge weg
		$migration->preSchemaChange($output, $this->schemaWithout(), []);
		self::assertSame(0, self::allAssignments(), 'Tabelle leer');
		self::assertSame(0, self::countRows('nextnotes_submissions', 'assignment_id', $aid), 'Abgaben weg');
		foreach ($notes as $note) {
			self::assertNull(self::row('nextnotes_notes', $note), "Notiz $note weg");
			self::assertSame(0, self::countRows('nextnotes_attachments', 'note_id', $note), "Anhang-Zeilen $note weg");
			self::assertSame(0, self::countRows('nextnotes_snapshots', 'note_id', $note), "Snapshots $note weg");
		}
		foreach ($files as [$note, $att]) {
			self::assertFalse(self::appFileExists($note, $att), "Datei $note/$att weg");
		}
		foreach ($folders as $folder) {
			self::assertNull(self::row('nextnotes_folders', $folder), "Aufgaben-Ordner $folder weg");
		}
		foreach (array_unique($studentWs) as $sws) {
			self::assertNull(self::row('nextnotes_workspaces', $sws), "Schüler-Workspace $sws weg");
		}
		self::assertNotNull(self::row('nextnotes_workspaces', $wsId), 'Team-Workspace bleibt');
		self::assertNotNull(self::row('nextnotes_notes', $src), 'Quelle bleibt');
		$this->assignmentIds = [];
	}

	public function testRemainingRowAborts(): void {
		$fx = $this->fixture(false);
		// Löschpfad ohne Wirkung → Zeile bleibt → Abbruch, nichts weiter geändert
		$noop = $this->createStub(AssignmentDeletion::class);
		$noop->method('purge')->willReturn(false);
		$migration = new Version000800Date20261112000000($noop, Server::get(IDBConnection::class));
		try {
			$migration->preSchemaChange($this->createStub(IOutput::class), $this->schemaWithout(), []);
			self::fail('RuntimeException erwartet');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('upgrade aborted', $e->getMessage());
		}
		self::assertNotNull(self::row('nextnotes_assignments', $fx['aid']), 'Aufgabe bleibt');
	}
}
