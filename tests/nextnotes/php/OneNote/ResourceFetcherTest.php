<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\OneNote\Convert\ConvertResource;
use OCA\NextNotes\OneNote\GraphException;
use OCA\NextNotes\OneNote\HttpResult;
use OCA\NextNotes\OneNote\OneNoteConfig;
use OCA\NextNotes\OneNote\ResourceFetcher;
use OCA\NextNotes\Service\AttachmentService;
use OCP\IAppConfig;
use OCP\ITempManager;
use OCP\Server;

// M9d Done 1–7: Ressourcen als Anhänge (Bild inline, Datei als Link), Grenzen nach dem Dekodieren, Typen, Quote, fremde Hosts, Grenze je Seite
final class ResourceFetcherTest extends ImportTestCase {
	/** @var list<string> alle Temp-Dateien des Abrufs */
	private array $temps = [];
	private int $pageBytes = ResourceFetcher::PAGE_BYTES;

	protected function tearDown(): void {
		$cfg = Server::get(IAppConfig::class);
		foreach (['attachment_max_file_mb', 'attachment_quota_mb'] as $k) {
			$cfg->deleteKey('nextnotes', $k);
		}
		parent::tearDown();
	}

	protected function fetcher(): ResourceFetcher {
		$temps = &$this->temps;
		$inner = Server::get(ITempManager::class);
		$recording = new class($inner, $temps) implements ITempManager {
			public function __construct(private ITempManager $inner, private array &$paths) {
			}

			public function getTemporaryFile(string $postFix = ''): string|false {
				$p = $this->inner->getTemporaryFile($postFix);
				if ($p !== false) {
					$this->paths[] = $p;
				}
				return $p;
			}

			public function getTemporaryFolder(string $postFix = ''): string|false {
				return $this->inner->getTemporaryFolder($postFix);
			}

			public function clean() {
				$this->inner->clean();
			}

			public function cleanOld() {
				$this->inner->cleanOld();
			}

			public function getTempBaseDir() {
				return $this->inner->getTempBaseDir();
			}
		};
		$bytes = $this->pageBytes;
		return new class($recording, Server::get(AttachmentService::class), $bytes) extends ResourceFetcher {
			public function __construct(ITempManager $t, AttachmentService $a, private int $bytes) {
				parent::__construct($t, $a);
			}

			protected function pageBytes(): int {
				return $this->bytes;
			}
		};
	}

	private function page(string $html, int $pages = 1): void {
		$this->notebook('me', 'nbR', 1, $pages);
		$this->graph->notebooks['me'][0]['sections'][0]['pages'][0]['html'] = $html;
	}

	private static function img(string $id): string {
		return '<p><img src="' . FakeGraph::resourceUrl($id) . '" alt="Bild ' . $id . '"></p>';
	}

	private static function obj(string $id, string $name, string $type = 'application/pdf'): string {
		return '<p><object data-attachment="' . $name . '" type="' . $type . '" data="' . FakeGraph::resourceUrl($id) . '"></object></p>';
	}

	/** @return list<array<string, mixed>> */
	private static function attachmentsOf(int $noteId): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from(AttachmentMapper::TABLE)->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId)))->orderBy('id');
		return $qb->executeQuery()->fetchAllAssociative();
	}

	private function importOne(string $uid): int {
		$id = $this->started($uid, [['root' => 'me', 'notebook' => 'nbR', 'items' => null]]);
		$this->runUntilDone($id);
		return $id;
	}

	private function assertNoTemps(): void {
		self::assertNotSame([], $this->temps, 'Temp-Dateien benutzt');
		foreach ($this->temps as $p) {
			self::assertFileDoesNotExist($p, 'Temp-Datei gelöscht');
		}
	}

	// Done 1, 2: PNG und JPEG als Bild-Anhänge in Dokumentreihenfolge, PDF als Anhang mit Link; Datei-Inhalt unverändert
	public function testImagesAndPdfBecomeAttachments(): void {
		$u = $this->createUser('ri');
		$this->graph->resources = ['png1' => FakeGraph::png(), 'jpg1' => FakeGraph::jpeg(), 'pdf1' => FakeGraph::pdf()];
		$this->page('<div><p>Vorher</p>' . self::img('png1') . self::img('jpg1') . self::obj('pdf1', 'Handout.pdf') . '<p>Nachher</p></div>');
		$id = $this->importOne($u->uid);
		self::assertSame('done', self::row($id)['status']);
		$note = self::notesOf($u->uid)[0];
		$att = self::attachmentsOf((int)$note['id']);
		self::assertSame(['image/png', 'image/jpeg', 'application/pdf'], array_column($att, 'mime'));
		self::assertSame('Handout.pdf', $att[2]['original_name']);
		self::assertSame($u->uid, $att[0]['created_by']);
		$content = (string)$note['content'];
		$n = (int)$note['id'];
		$p1 = strpos($content, 'src="/api/v1/notes/' . $n . '/attachments/' . $att[0]['id'] . '"');
		$p2 = strpos($content, 'src="/api/v1/notes/' . $n . '/attachments/' . $att[1]['id'] . '"');
		self::assertNotFalse($p1, $content);
		self::assertNotFalse($p2, $content);
		self::assertLessThan($p2, $p1, 'Reihenfolge wie im Dokument');
		self::assertStringContainsString('<a href="/api/v1/notes/' . $n . '/attachments/' . $att[2]['id'] . '">Handout.pdf</a>', $content);
		self::assertStringNotContainsString('graph.microsoft.com', $content);
		self::assertStringNotContainsString('#nn-onenote-res', $content);
		$file = Server::get(AttachmentService::class)->fileFor(Server::get(AttachmentMapper::class)->findOnNote((int)$att[2]['id'], $n));
		self::assertSame(FakeGraph::pdf(), $file->getContent());
		$row = self::row($id);
		self::assertSame([3, 0], [(int)$row['resources_done'], (int)$row['resources_failed']]);
		self::assertNoTemps();
	}

	// Done 3: endlose Ressource über attachment_max_file_mb → Abbruch beim Lesen (≤ Grenze + 64 KiB), Platzhalter, Bericht
	public function testEndlessResourceIsCut(): void {
		Server::get(IAppConfig::class)->setValueInt('nextnotes', 'attachment_max_file_mb', 1);
		$u = $this->createUser('re');
		$chunk = str_repeat('A', 65536);
		$this->graph->resources = ['big1' => new FakeResponse(200, '', ['Content-Type' => 'application/octet-stream'], true, static function () use ($chunk): \Generator {
			while (true) {
				yield $chunk;
			}
		}), 'png1' => FakeGraph::png()];
		$this->page('<div>' . self::obj('big1', 'gross.bin', 'application/octet-stream') . self::img('png1') . '</div>');
		$id = $this->importOne($u->uid);
		self::assertSame('done', self::row($id)['status']);
		self::assertLessThanOrEqual(1048576 + 65536 + 65536, $this->http->written, 'gelesen ≤ Grenze + Puffer');
		$note = self::notesOf($u->uid)[0];
		self::assertSame(['image/png'], array_column(self::attachmentsOf((int)$note['id']), 'mime'));
		self::assertStringContainsString('[File not imported: gross.bin]', (string)$note['content']);
		self::assertSame([['t' => 'Seite nbR-s0-p 0', 'r' => 'resources', 'n' => 1]], self::report($id));
		self::assertSame(1, (int)self::row($id)['resources_failed']);
		self::assertNoTemps();
	}

	// Done 4: SVG und Text mit Bild-Typ geben keinen Bild-Anhang; gesperrte Dateiendung keinen Datei-Anhang
	public function testWrongTypesGiveNoImage(): void {
		$u = $this->createUser('rt');
		$this->graph->resources = [
			'svg1' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
			'txt1' => "kein Bild\n",
			'sh1' => "#!/bin/sh\necho x\n",
		];
		$this->page('<div>' . self::img('svg1') . self::img('txt1') . self::obj('sh1', 'run.sh', 'text/x-sh') . '</div>');
		$id = $this->importOne($u->uid);
		$note = self::notesOf($u->uid)[0];
		self::assertSame([], self::attachmentsOf((int)$note['id']));
		self::assertSame(2, substr_count((string)$note['content'], '[Image not imported]'));
		self::assertStringNotContainsString('<script', (string)$note['content']);
		self::assertSame(3, (int)self::row($id)['resources_failed']);
		self::assertNoTemps();
	}

	// Done 5: Ressourcen + HTML über dem freien Platz → failed/quota vor der Transaktion; frühere Seiten bleiben, keine Reste
	public function testQuotaFailsBeforeTransaction(): void {
		Server::get(IAppConfig::class)->setValueInt('nextnotes', 'attachment_quota_mb', 1);
		$u = $this->createUser('rq');
		$this->graph->resources = ['ok1' => FakeGraph::png(400000), 'big2' => FakeGraph::png(800000)];
		$this->notebook('me', 'nbR', 1, 2);
		$pages = &$this->graph->notebooks['me'][0]['sections'][0]['pages'];
		$pages[0]['html'] = self::img('ok1');
		$pages[1]['html'] = self::img('big2');
		$id = $this->importOne($u->uid);
		$row = self::row($id);
		self::assertSame(['failed', 'quota'], [$row['status'], $row['error_code']]);
		self::assertNoSecrets($id);
		$notes = self::notesOf($u->uid);
		self::assertCount(1, $notes, 'frühere Seite bleibt, laufende Seite nicht angelegt');
		self::assertCount(1, self::attachmentsOf((int)$notes[0]['id']));
		self::assertSame(1, $this->graph->count('me/onenote/resources/big2'), 'abgerufen, dann verworfen');
		self::assertNoTemps();
	}

	// Done 5: kein freier Platz → failed/quota vor dem Inhaltsabruf
	public function testNoFreeSpaceStopsBeforeContent(): void {
		Server::get(IAppConfig::class)->setValueInt('nextnotes', 'attachment_quota_mb', 1);
		$u = $this->createUser('rz');
		[$ws] = self::newWorkspace($u, 'Voll');
		$n = self::newNote($u, $ws, 'Voll', '<p>v</p>');
		self::ok($u->upload("/notes/$n/attachments", 'voll.bin', str_repeat('v', 1048576)), 'Quote genau ausgeschöpft');
		$this->graph->resources = ['png1' => FakeGraph::png()];
		$this->page(self::img('png1'));
		$id = $this->importOne($u->uid);
		self::assertSame(['failed', 'quota'], [self::row($id)['status'], self::row($id)['error_code']]);
		self::assertSame(0, $this->graph->count('me/onenote/pages'), 'kein Inhaltsabruf ohne freien Platz');
		self::assertSame(0, $this->graph->count('me/onenote/resources'));
	}

	// Done 6: fremder Host und www.onenote.com → kein Abruf, Platzhalter; 404/403 → Platzhalter, keine 404-Serie
	public function testForeignHostsAndMissing(): void {
		$u = $this->createUser('rf');
		$this->graph->resources = ['e403' => FakeHttp::json(403, ['error' => ['code' => '40001']])];
		$this->page('<div><p><img src="https://evil.example/v1.0/me/onenote/resources/x/$value"></p>'
			. '<p><img src="https://www.onenote.com/api/v1.0/me/notes/resources/y/$value"></p>' . self::img('e404') . self::img('e403') . '</div>');
		$id = $this->importOne($u->uid);
		$row = self::row($id);
		self::assertSame(['done', 4, 0], [$row['status'], (int)$row['resources_failed'], (int)$row['not_found_streak']]);
		self::assertSame(2, $this->graph->count('me/onenote/resources'), 'nur die zwei Graph-Ressourcen');
		foreach ($this->http->urls() as $url) {
			self::assertStringNotContainsString('evil.example', $url);
			self::assertStringNotContainsString('onenote.com', $url);
		}
		self::assertSame(4, substr_count((string)self::notesOf($u->uid)[0]['content'], '[Image not imported]'));
	}

	// Done 7: 101 Ressourcen → 100 Anhänge + 1 Platzhalter; Summe über der Seitengrenze → überschießende Platzhalter, Seite angelegt
	public function testPageLimits(): void {
		$u = $this->createUser('rl');
		$html = '';
		for ($i = 0; $i < 101; $i++) {
			$this->graph->resources["r$i"] = FakeGraph::png($i);
			$html .= self::img("r$i");
		}
		$this->page('<div>' . $html . '</div>');
		$id = $this->importOne($u->uid);
		$note = self::notesOf($u->uid)[0];
		self::assertCount(100, self::attachmentsOf((int)$note['id']));
		self::assertSame(1, substr_count((string)$note['content'], '[Image not imported]'));
		self::assertSame(100, $this->graph->count('me/onenote/resources'));
		self::assertSame([100, 1], [(int)self::row($id)['resources_done'], (int)self::row($id)['resources_failed']]);
	}

	public function testPageByteSum(): void {
		$this->pageBytes = 2_500_000;
		$u = $this->createUser('rb');
		$html = '';
		foreach (['a', 'b', 'c', 'd'] as $k) {
			$this->graph->resources[$k] = FakeGraph::png(1_000_000);
			$html .= self::img($k);
		}
		$this->graph->resources['e'] = FakeGraph::png(10);
		$html .= self::img('e');
		$this->page('<div>' . $html . '</div>');
		$id = $this->importOne($u->uid);
		$note = self::notesOf($u->uid)[0];
		// a, b passen; c sprengt die Summe (Abbruch), d ebenso; e (klein) passt noch
		self::assertCount(3, self::attachmentsOf((int)$note['id']));
		self::assertSame(2, substr_count((string)$note['content'], '[Image not imported]'));
		self::assertSame('done', self::row($id)['status']);
		self::assertNoTemps();
	}

	// Review M9d-r1 Hinweis 2: CJK-Dateiname byte-genau auf 255 Bytes gekürzt, Endung bleibt, Anhang statt Platzhalter
	public function testLongMultibyteFileName(): void {
		$u = $this->createUser('rn');
		$this->graph->resources = ['pdf1' => FakeGraph::pdf()];
		$this->page('<div>' . self::obj('pdf1', str_repeat('漢', 120) . '.pdf') . '</div>');
		$id = $this->importOne($u->uid);
		$note = self::notesOf($u->uid)[0];
		$att = self::attachmentsOf((int)$note['id']);
		self::assertCount(1, $att);
		$name = (string)$att[0]['original_name'];
		self::assertSame(str_repeat('漢', 83) . '.pdf', $name);
		self::assertLessThanOrEqual(255, strlen($name));
		self::assertSame([1, 0], [(int)self::row($id)['resources_done'], (int)self::row($id)['resources_failed']]);
	}

	// Review M9d-r1 Hinweis 1: Timeout je Ressource ≤ 240 s (Lease 300 s); Zeitüberschreitung → Platzhalter, schneller Netzfehler → Backoff
	public function testResourceTimeoutBelowLease(): void {
		$f = new class(Server::get(ITempManager::class), Server::get(AttachmentService::class)) extends ResourceFetcher {
			public float $t = 1000.0;

			protected function clock(): float {
				return $this->t;
			}
		};
		$res = [new ConvertResource(0, 'r0', ConvertResource::KIND_FILE, 'a.pdf', ''), new ConvertResource(1, 'r1', ConvertResource::KIND_FILE, 'b.pdf', '')];
		$seen = [];
		$batch = $f->fetch('me', $res, function (string $url, string $file, int $limit, int $timeout) use ($f, &$seen): HttpResult {
			$seen[] = $timeout;
			$f->t += $timeout;
			throw new GraphException('network');
		});
		self::assertSame([ResourceFetcher::RESOURCE_SECONDS, ResourceFetcher::RESOURCE_SECONDS], $seen);
		self::assertLessThan(\OCA\NextNotes\OneNote\GraphClient::LEASE_SECONDS, ResourceFetcher::RESOURCE_SECONDS);
		self::assertSame([0 => 'page_limit', 1 => 'page_limit'], $batch->failed);
		self::assertSame([], $batch->files);
		$this->expectException(GraphException::class);
		$f->fetch('me', $res, static function (): HttpResult {
			throw new GraphException('network');
		});
	}

	// Review M9d-r1 Blocker: Job-Lauf löscht verwaiste oc_tmp_*.nn-onenote älter als 1 h, auch bei abgeschalteter Funktion
	public function testStaleTempFilesRemoved(): void {
		$dir = rtrim((string)Server::get(ITempManager::class)->getTempBaseDir(), '/');
		$tag = 'nntest' . bin2hex(random_bytes(4));
		$old = "$dir/oc_tmp_{$tag}old" . ResourceFetcher::TEMP_SUFFIX;
		$fresh = "$dir/oc_tmp_{$tag}new" . ResourceFetcher::TEMP_SUFFIX;
		$other = "$dir/oc_tmp_{$tag}other.nextnotes-export";
		$target = "$dir/{$tag}target" . ResourceFetcher::TEMP_SUFFIX;
		$link = "$dir/oc_tmp_{$tag}link" . ResourceFetcher::TEMP_SUFFIX;
		$folder = "$dir/oc_tmp_{$tag}dir" . ResourceFetcher::TEMP_SUFFIX;
		try {
			foreach ([$old, $fresh, $other, $target] as $p) {
				file_put_contents($p, 'x');
			}
			symlink($target, $link);
			mkdir($folder);
			$this->now = time() + 2 * ResourceFetcher::TEMP_MAX_AGE;
			touch($fresh, $this->now - 60);
			touch($target, $this->now - 60);
			// Funktion aus: Aufräumen läuft trotzdem
			Server::get(IAppConfig::class)->deleteKey('nextnotes', OneNoteConfig::KEY_CLIENT_ID);
			self::assertFalse(Server::get(OneNoteConfig::class)->enabled());
			$this->importer()->runDue();
			self::assertFileDoesNotExist($old);
			self::assertFileExists($fresh, 'jünger als 1 h');
			self::assertFileExists($other, 'anderes Suffix');
			self::assertTrue(is_link($link), 'Symlink bleibt');
			self::assertFileExists($target, 'Ziel des Symlinks bleibt');
			self::assertDirectoryExists($folder, 'nur reguläre Dateien');
		} finally {
			foreach ([$old, $fresh, $other, $target, $link] as $p) {
				if (is_link($p) || is_file($p)) {
					unlink($p);
				}
			}
			if (is_dir($folder)) {
				rmdir($folder);
			}
		}
	}
}
