<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Command\DeleteUserData;
use OCA\NextNotes\Command\ExportUser;
use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\Db\OneNoteItemMapper;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\UserMigration\NextNotesMigrator;
use OCP\Files\Folder;
use OCP\IUserManager;
use OCP\Server;
use OCP\UserMigration\IExportDestination;
use Symfony\Component\Console\Output\BufferedOutput;

// M9a Done 6, 12, 13: Abbruch, Kontolöschung, delete-user-data, export-user mit Bericht, Migrator ohne OneNote
final class LifecycleTest extends OneNoteTestCase {
	private static function rowCount(string $table, string $uid): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table)->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	// Done 6: cancel löscht Token und Lease, auth wird gelöscht; fertige Importe 409
	public function testCancel(): void {
		$u = $this->createUser('cn');
		$svc = $this->connectService(new FakeHttp($this->tokenResponder()));
		$id = $this->signIn($svc, $u->uid);
		Server::get(OneNoteImportMapper::class)->updateWhere($id, $u->uid, ['status' => 'running', 'lease_token' => 'l1', 'lease_until' => $this->now + 100]);
		$r = $svc->cancel($u->uid, $id);
		self::assertSame('cancelled', $r['import']['status']);
		$row = self::row($id);
		foreach (OneNoteImportMapper::SECRET_COLUMNS as $col) {
			self::assertNull($row[$col], $col);
		}
		self::assertNotNull($row['finished_at']);
		self::assertSame('cancelled', $svc->cancel($u->uid, $id)['import']['status'], 'idempotent');
		Server::get(OneNoteImportMapper::class)->updateWhere($id, $u->uid, ['status' => 'done']);
		try {
			$svc->cancel($u->uid, $id);
			self::fail('409 erwartet');
		} catch (ConflictException) {
		}
		$this->now += 10;
		$auth = $svc->connect($u->uid, null)['import_id'];
		self::assertNull($svc->cancel($u->uid, $auth)['import']);
		self::assertNull(self::row($auth));
		// Ansicht ohne Geheimnisse
		$view = json_encode($svc->index($u->uid)) ?: '';
		foreach (['access_enc', 'refresh_enc', 'state_hash', 'pkce_enc', 'ms_account', 'lease_token', 'cursor', 'selection'] as $k) {
			self::assertStringNotContainsString($k, $view);
		}
	}

	// Done 6: Kontolöschung löscht beide Tabellen der uid
	public function testAccountDeletion(): void {
		$u = $this->createUser('ad');
		$id = $this->signIn($this->connectService(new FakeHttp($this->tokenResponder())), $u->uid);
		self::insertItem($u->uid, $id);
		$other = $this->createUser('ao');
		$oid = self::insertImport($other->uid, 'done');
		Server::get(IUserManager::class)->get($u->uid)->delete();
		self::assertSame(0, self::rowCount(OneNoteImportMapper::TABLE, $u->uid));
		self::assertSame(0, self::rowCount(OneNoteItemMapper::TABLE, $u->uid));
		self::assertNotNull(self::row($oid), 'fremde Zeile bleibt');
	}

	// Done 12: gelöschte UID nur mit OneNote-Zeilen → „Daten vorhanden“, dann gelöscht
	public function testDeleteUserDataOnlyOneNoteRows(): void {
		$uid = 'nn-ghost-' . bin2hex(random_bytes(4));
		$id = self::insertImport($uid, 'failed');
		self::insertItem($uid, $id);
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $uid]);
		self::assertSame(1, $rc, $out);
		self::assertStringContainsString('nextnotes_onenote_imports.uid: 1', $out);
		self::assertStringContainsString('nextnotes_onenote_items.uid: 1', $out);
		self::assertSame(1, self::rowCount(OneNoteImportMapper::TABLE, $uid), 'ohne Bestätigung unverändert');
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $uid, '--user-deleted' => true]);
		self::assertSame(0, $rc, $out);
		self::assertSame(0, self::rowCount(OneNoteImportMapper::TABLE, $uid));
		self::assertSame(0, self::rowCount(OneNoteItemMapper::TABLE, $uid));
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $uid]);
		self::assertSame(0, $rc, $out);
		self::assertStringContainsString('nothing to do', $out);
	}

	// Done 13: export-user mit onenote-imports.json (Bericht), ohne Token, state, ms_account, Cursor, Items; Migrator ohne OneNote
	public function testExportAndMigrator(): void {
		$u = $this->createUser('ex');
		$id = $this->signIn($this->connectService(new FakeHttp($this->tokenResponder())), $u->uid);
		self::insertItem($u->uid, $id);
		$report = json_encode([['title' => 'Mathe – Brüche', 'reason' => 'too_large']]);
		Server::get(OneNoteImportMapper::class)->updateWhere($id, $u->uid, ['status' => 'running', 'report' => (string)$report, 'cursor' => '{"queue":["CURSOR-MARK"]}',
			'selection' => '[{"root":"me","notebook":"SEL-MARK"}]', 'pages_done' => 3, 'lease_token' => 'LEASE-MARK', 'state_hash' => 'STATE-MARK']);
		$row = self::row($id);
		$vault = Server::get(\OCA\NextNotes\OneNote\TokenVault::class);
		$secrets = ['CURSOR-MARK', 'SEL-MARK', 'LEASE-MARK', 'STATE-MARK', (string)$row['ms_account'], (string)$row['access_enc'], (string)$row['refresh_enc'],
			(string)$vault->open($row['access_enc']), (string)$vault->open($row['refresh_enc']), 'mock-access-', 'mock-refresh-', 'source_hash', 'target_id'];

		$target = sys_get_temp_dir() . '/nn-onenote-export-' . bin2hex(random_bytes(4)) . '.zip';
		try {
			[$rc, $out] = self::runCommand(ExportUser::class, ['uid' => $u->uid, 'target' => $target]);
			self::assertSame(0, $rc, $out);
			$zip = new \ZipArchive();
			self::assertTrue($zip->open($target) === true);
			$json = (string)$zip->getFromName('onenote-imports.json');
			$all = '';
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$all .= (string)$zip->getFromIndex($i);
			}
			$zip->close();
		} finally {
			@unlink($target);
		}
		$data = json_decode($json, true);
		self::assertIsArray($data);
		self::assertCount(1, $data['imports']);
		$imp = $data['imports'][0];
		self::assertSame('running', $imp['status']);
		self::assertSame(3, $imp['pages_done']);
		self::assertSame('Mathe – Brüche', $imp['report'][0]['title'], 'Bericht mit Seitentitel');
		foreach (['access_enc', 'refresh_enc', 'state_hash', 'pkce_enc', 'ms_account', 'cursor', 'selection', 'lease_token'] as $k) {
			self::assertArrayNotHasKey($k, $imp);
		}
		foreach ($secrets as $s) {
			self::assertStringNotContainsString($s, $json, "export-user enthält $s");
		}

		// Migrator: nichts von OneNote
		$dest = new class implements IExportDestination {
			/** @var array<string, string> */
			public array $files = [];
			public function addFileContents(string $path, string $content): void {
				$this->files[$path] = $content;
			}
			public function addFileAsStream(string $path, $stream): void {
				$this->files[$path] = (string)stream_get_contents($stream);
			}
			public function copyFolder(Folder $folder, string $destinationPath, ?callable $nodeFilter = null): void {
				throw new \LogicException('unused');
			}
			public function setMigratorVersions(array $versions): void {
			}
			public function close(): void {
			}
		};
		Server::get(NextNotesMigrator::class)->export(Server::get(IUserManager::class)->get($u->uid), $dest, new BufferedOutput());
		foreach ($dest->files as $path => $bytes) {
			self::assertStringNotContainsStringIgnoringCase('onenote', $path);
			foreach (array_merge($secrets, ['Mathe – Brüche']) as $s) {
				self::assertStringNotContainsString($s, $bytes, "Migrator: $path enthält $s");
			}
		}
	}

	// export-user: Konto nur mit OneNote-Zeilen hat Daten
	public function testExportOnlyOneNote(): void {
		$uid = 'nn-ghost-' . bin2hex(random_bytes(4));
		self::insertImport($uid, 'done', ['report' => '[]']);
		$target = sys_get_temp_dir() . '/nn-onenote-export-' . bin2hex(random_bytes(4)) . '.zip';
		try {
			[$rc, $out] = self::runCommand(ExportUser::class, ['uid' => $uid, 'target' => $target]);
			self::assertSame(0, $rc, $out);
			self::assertFileExists($target);
		} finally {
			@unlink($target);
			Server::get(OneNoteImportMapper::class)->deleteByUid($uid);
		}
	}
}
