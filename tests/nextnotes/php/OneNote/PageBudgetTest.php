<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\OneNote\RateBudget;
use OCP\Server;

// M9d Done 8, 9: Seite immer ganz (über das Laufbudget hinaus), Seitenstart nach freiem Stundenfenster, 429 mitten in der Seite
final class PageBudgetTest extends ImportTestCase {
	private function bigPage(int $resources, int $pages = 2): void {
		$this->notebook('me', 'nbB', 1, $pages);
		$html = '';
		for ($i = 0; $i < $resources; $i++) {
			$this->graph->resources["b$i"] = FakeGraph::png($i);
			$html .= '<p><img src="' . FakeGraph::resourceUrl("b$i") . '"></p>';
		}
		$this->graph->notebooks['me'][0]['sections'][0]['pages'][0]['html'] = '<div>' . $html . '</div>';
	}

	private static function attachmentCount(string $uid): int {
		$notes = array_map('intval', array_column(self::notesOf($uid), 'id'));
		return $notes === [] ? 0 : array_sum(array_map('count', Server::get(AttachmentMapper::class)->findForNotes($notes)));
	}

	// Done 8: 80 Ressourcen bei vollem Budget → in einem Lauf ganz angelegt (> 50 Anfragen), danach keine neue Seite
	public function testEightyResourcesInOneRun(): void {
		$u = $this->createUser('b8');
		$this->bigPage(80);
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbB', 'items' => null]]);
		$this->importer()->runDue();
		$row = self::row($id);
		self::assertSame('queued', $row['status'], 'Lauf endet nach der Seite');
		self::assertCount(1, self::notesOf($u->uid));
		self::assertSame(80, self::attachmentCount($u->uid));
		self::assertSame(80, (int)$row['resources_done']);
		self::assertSame(0, $this->graph->count('me/onenote/pages/nbB-s0-p-1'), 'keine neue Seite nach > 50 Anfragen');
		self::assertGreaterThan(50, $this->graph->count());
		$this->runUntilDone($id);
		self::assertCount(2, self::notesOf($u->uid));
	}

	// Done 8: nur 60 freie Anfragen im Fenster → Lauf endet nach dem Inhaltsabruf ohne Anhänge; später ganz, ohne Duplikate
	public function testWindowTooSmallDefersPage(): void {
		$u = $this->createUser('b6');
		$this->bigPage(80, 1);
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbB', 'items' => null]]);
		// 290 Anfragen in der laufenden Minute verbraucht: Struktur + Liste + Inhalt lassen 57 frei
		Server::get(OneNoteImportMapper::class)->updateWhere($id, $u->uid,
			['rate_buckets' => RateBudget::encode([intdiv($this->now, 60) => RateBudget::MAX - 60])]);
		$this->importer()->runDue();
		$row = self::row($id);
		self::assertSame('queued', $row['status']);
		self::assertSame(1, $this->graph->count('me/onenote/pages/nbB-s0-p-0/content'));
		self::assertSame(0, $this->graph->count('me/onenote/resources'), 'keine Ressource vor freiem Fenster');
		self::assertCount(0, self::notesOf($u->uid));
		self::assertGreaterThan($this->now, (int)$row['next_run_at'], 'nächster Lauf erst nach Freiwerden');
		self::assertSame(0, (int)$row['backoff_level'], 'kein Fehler');
		$this->now = (int)$row['next_run_at'];
		$this->importer()->runDue();
		self::assertCount(1, self::notesOf($u->uid));
		self::assertSame(80, self::attachmentCount($u->uid));
		self::assertSame(2, $this->graph->count('me/onenote/pages/nbB-s0-p-0/content'), 'Inhalt einmal erneut');
		self::assertSame(80, $this->graph->count('me/onenote/resources'));
		// Abschluss im nächsten Lauf (Laufbudget nach der Seite verbraucht)
		self::assertSame('done', $this->runUntilDone($id));
		self::assertCount(1, self::notesOf($u->uid), 'keine Duplikate');
	}

	// Done 9: 429 mitten in der Seite → Seite verworfen, keine Reste; nächster Lauf legt sie ganz an; backoff bleibt bis dahin
	public function testThrottleMidPage(): void {
		$u = $this->createUser('b9');
		$this->bigPage(5, 1);
		$this->graph->errors['me/onenote/resources/b3/$value'] = [429];
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbB', 'items' => null]]);
		$this->importer()->runDue();
		$row = self::row($id);
		self::assertSame(['queued', 1], [$row['status'], (int)$row['backoff_level']]);
		self::assertCount(0, self::notesOf($u->uid));
		self::assertSame(0, (int)$row['resources_done']);
		self::assertSame(0, $this->graph->count('me/onenote/resources/b4'), 'kein Folgeaufruf nach 429');
		$this->now = (int)$row['next_run_at'];
		$this->importer()->runDue();
		$row = self::row($id);
		self::assertSame(['done', 0], [$row['status'], (int)$row['backoff_level']]);
		self::assertCount(1, self::notesOf($u->uid));
		self::assertSame(5, self::attachmentCount($u->uid));
	}

	// Done 9: dieselbe Seite bekommt zehnmal 429/5xx an derselben Stelle → failed; Strukturschritte setzen das Backoff nicht zurück
	public function testRepeatedFailureAtSamePlaceFails(): void {
		$u = $this->createUser('bf');
		$this->bigPage(3, 1);
		$this->graph->errors['me/onenote/resources/b1/$value'] = [429, 503, 429, 500, 429, 502, 429, 429, 503, 429, 429];
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbB', 'items' => null]]);
		$status = $this->runUntilDone($id, 15);
		self::assertSame('failed', $status);
		self::assertSame('graph_unavailable', self::row($id)['error_code']);
		self::assertCount(0, self::notesOf($u->uid));
		self::assertNoSecrets($id);
		self::assertSame(10, $this->graph->count('me/onenote/resources/b1'));
	}
}
