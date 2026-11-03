<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Security;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;

// M3c2 Nacharbeit r1 (B37): Markdown-Handler je Tag vollständig entfernt, ungültiges UTF-8 → 400 statt leerem Inhalt,
// move_notes auf gelöschtes Ziel → 404 ohne Datenänderung. Große Inhalte: Page/ExcalidrawSaveTest::testLarge*.
final class SanitizerB37Test extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';

	private static function content(ApiClient $c, int $note): string {
		return (string)(self::ok($c->call('GET', "/notes/$note"), 'GET Notiz')['note']['content'] ?? '');
	}

	public function testMarkdownRemovesEveryEventHandlerPerTag(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'MD');
		$n = (int)self::ok($a->call('POST', '/notes', ['heading' => 'MD', 'type' => 'markdown', 'content' => 'x', 'workspace' => $ws]), 'MD-Notiz')['note']['id'];
		$cases = [
			'drei Handler' => '<img src="x.png" onerror=a onload=b onclick=c>',
			'Anführungszeichen' => '<img src="x.png" onerror="a()" onload=\'b()\' onclick=c>',
			'> im Wert' => '<img alt="a>b" src="x.png" onerror=alert(1)>',
			'Großschreibung/Slash' => '<IMG/ONERROR=a SRC=x.png ONLOAD=b>',
			'ohne Wert' => '<div onclick>t</div>',
		];
		foreach ($cases as $label => $md) {
			self::ok($a->call('PATCH', "/notes/$n", ['content' => "Text vorher\n\n$md\n\nText nachher", 'workspace' => $ws]), $label);
			$c = self::content($a, $n);
			self::assertStringContainsString('Text vorher', $c, "$label: Inhalt verloren");
			self::assertStringContainsString('Text nachher', $c, "$label: Inhalt verloren");
			self::assertDoesNotMatchRegularExpression('/<[^>]*\son[a-z]+\s*(=|>|\s|\/)/i', $c, "$label: Handler übrig: $c");
		}
	}

	public function testInvalidUtf8TitleIs400(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'U8');
		$bad = "Titel \xC3\x28 \xFF";
		$before = self::dump($a->uid);
		// JSON kann ungültiges UTF-8 nicht tragen → Formular-POST
		$r = $a->form(ApiClient::PREFIX . '/notes', ['heading' => $bad, 'content' => '<p>x</p>', 'workspace' => $ws]);
		self::assertSame(400, $r->status, 'POST /notes ungültiges UTF-8: ' . $r->describe());
		$n = self::newNote($a, $ws, 'Gut', '<p>gut</p>');
		$mid = self::dump($a->uid);
		$r = $a->formMethod('PATCH', ApiClient::PREFIX . "/notes/$n", ['heading' => $bad, 'content' => '<p>gut</p>', 'workspace' => $ws]);
		self::assertSame(400, $r->status, 'PATCH ungültiges UTF-8: ' . $r->describe());
		self::assertSame($mid['notes'], self::dump($a->uid)['notes'], 'PATCH mit ungültigem UTF-8 hat die Notiz verändert');
		$r = $a->form(self::APP . 'workspaces.php', ['action' => 'rename', 'name' => $ws, 'new_name' => $bad]);
		self::assertSame(400, $r->status, 'workspaces.php rename ungültiges UTF-8: ' . $r->describe());
		self::assertContains($ws, self::workspaceNames($a));
		self::assertCount(count($before['notes']) + 1, self::dump($a->uid)['notes']);
	}

	public function testMoveNotesToDeletedTargetIs404(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Quelle');
		[$ws2] = self::newWorkspace($a, 'Ziel');
		self::newNote($a, $ws, 'N1', '<p>1</p>');
		self::newNote($a, $ws, 'N2', '<p>2</p>');
		self::assertSame(200, $a->form(self::APP . 'workspaces.php', ['action' => 'delete', 'name' => $ws2])->status, 'Ziel löschen');
		$before = self::dump($a->uid);
		$r = $a->form(self::APP . 'workspaces.php', ['action' => 'move_notes', 'name' => $ws, 'target' => $ws2]);
		self::assertSame(404, $r->status, 'move_notes auf gelöschtes Ziel: ' . $r->describe());
		self::assertSame($before, self::dump($a->uid), 'move_notes auf gelöschtes Ziel hat Daten verändert');
		$r = $a->form(self::APP . 'workspaces.php', ['action' => 'move_notes', 'name' => $ws, 'target' => $ws]);
		self::assertContains($r->status, [200, 400], 'move_notes auf sich selbst: ' . $r->describe());
		self::assertSame($before['notes'], self::dump($a->uid)['notes'], 'move_notes auf sich selbst hat Notizen verändert');
	}
}
