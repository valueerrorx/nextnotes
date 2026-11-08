<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Admin;

use OCA\NextNotes\Command\ExportUser;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\UserMigration\NextNotesMigrator;
use OCP\Files\Folder;
use OCP\IUserManager;
use OCP\Server;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use Symfony\Component\Console\Output\BufferedOutput;

// M7b Done 1 und 8: occ nextnotes:export-user und NextNotesMigrator liefern nur eigene Daten, Feedback erst ab returned
final class UserExportTest extends AdminTestCase {
	/** @var string[] */
	private array $files = [];

	protected function tearDown(): void {
		foreach ($this->files as $f) {
			@unlink($f);
		}
		parent::tearDown();
	}

	private function target(): string {
		$f = sys_get_temp_dir() . '/nn-export-' . bin2hex(random_bytes(6)) . '.zip';
		$this->files[] = $f;
		return $f;
	}

	/** @return array<string, string> */
	private function exportOf(string $uid): array {
		$target = $this->target();
		[$rc, $out] = self::runCommand(ExportUser::class, ['uid' => $uid, 'target' => $target]);
		self::assertSame(0, $rc, $out);
		self::assertFileExists($target, $out);
		return self::readZip($target);
	}

	/** @return array<string, mixed> S1 mit eigenen und fremden Daten; S1 hat abgegeben, Entwurf-Feedback ohne Rückgabe */
	private function scenario(): array {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 's1' => $s1, 's2' => $s2, 'n' => $n, 'ws' => $teamWs] = $fx;
		$mk = 'ux' . bin2hex(random_bytes(4));
		$sid1 = (int)$fx['subs'][$s1->uid]['id'];
		$sid2 = (int)$fx['subs'][$s2->uid]['id'];
		$note1 = (int)$fx['subs'][$s1->uid]['note_id'];
		$note2 = (int)$fx['subs'][$s2->uid]['note_id'];
		self::ok($s1->call('PATCH', "/notes/$note1", ['content' => "<p>eigene-abgabe-$mk</p>"]), 'S1 arbeitet');
		self::ok($s1->call('POST', "/submissions/$sid1/submit"), 'S1 gibt ab');
		self::ok($m->call('PUT', "/submissions/$sid1/feedback", ['feedback' => "entwurf-$mk"]), 'Entwurf an S1');
		self::ok($s2->call('PATCH', "/notes/$note2", ['content' => "<p>fremde-abgabe-$mk</p>"]), 'S2 arbeitet');
		self::ok($s2->call('POST', "/submissions/$sid2/submit"), 'S2 gibt ab');
		self::ok($l->call('POST', "/submissions/$sid2/return", ['feedback' => "fremdes-feedback-$mk"]), 'Rückgabe an S2');
		// eigene Notiz mit Anhang, fremder privater Workspace, geteilter Workspace, Team-Workspace
		[$own] = self::newWorkspace($s1, 'Eigen');
		$ownNote = self::newNote($s1, $own, "Eigen $mk", "<p>eigene-notiz-$mk</p>");
		self::ok($s1->upload("/notes/$ownNote/attachments", 'eigen.txt', "eigener-anhang-$mk"), 'eigener Anhang');
		[$foreign] = self::newWorkspace($n, 'Fremd');
		self::newNote($n, $foreign, "Fremd $mk", "<p>fremd-privat-$mk</p>");
		[$shared, $sharedId] = self::newWorkspace($n, 'Geteilt');
		self::newNote($n, $shared, "Geteilt $mk", "<p>fremd-geteilt-$mk</p>");
		Server::get(WorkspaceService::class)->share($n->uid, $sharedId, $s1->uid);
		self::newNote($s1, $teamWs, "Team $mk", "<p>team-notiz-$mk</p>");
		return $fx + ['mk' => $mk, 'sid1' => $sid1, 'sid2' => $sid2];
	}

	/** @param array<string, string> $zip */
	private static function text(array $zip): string {
		return implode("\n", array_map(static fn ($k, $v) => $k . "\n" . $v, array_keys($zip), $zip));
	}

	public function testExportOnlyOwnData(): void {
		$fx = $this->scenario();
		['s1' => $s1, 's2' => $s2, 'l' => $l, 'mk' => $mk, 'sid1' => $sid1, 'mark' => $mark] = $fx;
		$zip = $this->exportOf($s1->uid);
		$all = self::text($zip);
		foreach (["eigene-notiz-$mk", "eigener-anhang-$mk", "eigene-abgabe-$mk"] as $want) {
			self::assertStringContainsString($want, $all, "eigene Daten: $want");
		}
		foreach (["fremd-privat-$mk", "fremd-geteilt-$mk", "team-notiz-$mk", "fremde-abgabe-$mk", "fremdes-feedback-$mk", "quelle $mark", "entwurf-$mk"] as $deny) {
			self::assertStringNotContainsString($deny, $all, "nicht im Export: $deny");
		}
		self::assertNotEmpty(array_filter(array_keys($zip), static fn ($p) => str_starts_with($p, 'workspaces/') && str_ends_with($p, '.zip')), 'Workspace-Teil');
		self::assertNotEmpty(array_filter(array_keys($zip), static fn ($p) => str_starts_with($p, "submissions/{$sid1}_") && !str_contains($p, '!')), 'Abgabe-Teil');
		self::assertSame([], array_filter(array_keys($zip), static fn ($p) => str_ends_with($p, '!feedback.txt')), 'Feedback erst ab returned');

		// nach Rückgabe: Feedback dabei
		self::ok($l->call('POST', "/submissions/$sid1/return", ['feedback' => "rueckgabe-$mk"]), 'Rückgabe an S1');
		$zip = $this->exportOf($s1->uid);
		$fb = array_filter($zip, static fn ($k) => str_ends_with($k, '!feedback.txt'), ARRAY_FILTER_USE_KEY);
		self::assertCount(1, $fb, 'feedback.txt nach Rückgabe');
		self::assertStringContainsString("rueckgabe-$mk", (string)array_values($fb)[0]);

		// Gegenprobe: der Detektor findet fremde Abgaben (Export von S2 enthält sie)
		self::assertStringContainsString("fremde-abgabe-$mk", self::text($this->exportOf($s2->uid)), 'Gegenprobe Detektor');
	}

	// Gelöschte UID: Abgabe nur noch über student_uid_former → keine Daten, keine Datei
	public function testDeletedUidHasNoData(): void {
		$fx = $this->scenario();
		$uid = $fx['s1']->uid;
		self::assertTrue(Server::get(IUserManager::class)->get($uid)->delete(), 'Kontolöschung');
		self::assertSame($uid, self::row('nextnotes_submissions', $fx['sid1'])['student_uid_former'], 'Kontrolle: frühere UID gesetzt');
		$target = $this->target();
		[$rc, $out] = self::runCommand(ExportUser::class, ['uid' => $uid, 'target' => $target]);
		self::assertSame(0, $rc, $out);
		self::assertStringContainsString('No Next Notes data', $out);
		self::assertFileDoesNotExist($target);
	}

	public function testExistingTargetRefused(): void {
		$c = $this->createUser('ue');
		[$ws] = self::newWorkspace($c, 'W');
		self::newNote($c, $ws, 'x', '<p>x</p>');
		$target = $this->target();
		file_put_contents($target, 'alt');
		[$rc, $out] = self::runCommand(ExportUser::class, ['uid' => $c->uid, 'target' => $target]);
		self::assertSame(1, $rc, $out);
		self::assertSame('alt', file_get_contents($target), 'vorhandene Datei unverändert');
	}

	// Done 8: Migrator schreibt dieselben Teile mit gleichen Inhalten unter nextnotes/; Import übersprungen, canImport true
	public function testMigratorMatchesExportUser(): void {
		$fx = $this->scenario();
		$uid = $fx['s1']->uid;
		$cli = $this->exportOf($uid);
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
		$migrator = Server::get(NextNotesMigrator::class);
		$user = Server::get(IUserManager::class)->get($uid);
		$migrator->export($user, $dest, new BufferedOutput());
		$mig = [];
		foreach ($dest->files as $path => $bytes) {
			self::assertStringStartsWith(NextNotesMigrator::PATH, $path);
			$rel = substr($path, strlen(NextNotesMigrator::PATH));
			$mig[$rel] = $bytes;
			foreach (self::readZipBytes($bytes) as $inner => $content) {
				$mig[$rel . '!' . $inner] = $content;
			}
		}
		// ZIP-Bytes enthalten Zeitstempel: Teile und Einträge vergleichen, nicht die äußeren Bytes
		$strip = static fn (array $z): array => array_filter($z, static fn ($k) => str_contains($k, '!'), ARRAY_FILTER_USE_KEY);
		self::assertSame(array_keys(array_diff_key($cli, $strip($cli))), array_keys(array_diff_key($mig, $strip($mig))), 'gleiche Teile');
		$norm = static fn (array $z): array => array_map(static fn (string $v): string => (string)preg_replace('/\d{4}-\d\d-\d\d[ _]\d\d:?\d\d:?\d\d/', 'DATE', $v), $strip($z));
		$a = $norm($cli);
		$b = $norm($mig);
		ksort($a);
		ksort($b);
		self::assertSame($a, $b, 'gleiche Inhalte');
		self::assertGreaterThan(0, $migrator->getEstimatedExportSize($user), 'Größenschätzung');

		$source = $this->createStub(IImportSource::class);
		self::assertTrue($migrator->canImport($source), 'canImport');
		$out = new BufferedOutput();
		$migrator->import($user, $source, $out);
		self::assertStringContainsString('import not supported, skipped', $out->fetch());
	}

	// Review r1 Blocker 2: abweichende Schreibweise findet das Konto, Export unter der echten UID
	public function testUidOtherCase(): void {
		$fx = $this->scenario();
		$uid = $fx['s1']->uid;
		self::assertNotSame($uid, strtoupper($uid), 'Kontrolle: Schreibweise weicht ab');
		$all = self::text($this->exportOf(strtoupper($uid)));
		foreach (["eigene-notiz-{$fx['mk']}", "eigene-abgabe-{$fx['mk']}"] as $want) {
			self::assertStringContainsString($want, $all, "eigene Daten: $want");
		}
	}

	public function testUnknownUser(): void {
		$target = $this->target();
		[$rc, $out] = self::runCommand(ExportUser::class, ['uid' => 'nn-nobody-' . bin2hex(random_bytes(4)), 'target' => $target]);
		self::assertSame(0, $rc, $out);
		self::assertFileDoesNotExist($target);
	}
}
