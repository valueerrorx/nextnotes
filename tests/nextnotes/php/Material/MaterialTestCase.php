<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Material;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Response;
use OCA\NextNotes\Tests\Support\TeamTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

// Basis M6a: Team T (L Owner = Lehrkraft, S Mitglied Level 1), Team-Workspace W mit Material-Ordner M und M/U
abstract class MaterialTestCase extends TeamTestCase {
	protected const APP = '/index.php/apps/nextnotes/';
	protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	/**
	 * n1 (Anhang, Snapshot) in M/U, Aufgabenliste tl und Excalidraw ex in M, alles von L; sN eigene Notiz von S an der Wurzel
	 * @return array<string, mixed>
	 */
	protected function fixture(): array {
		$l = $this->createUser('ml');
		$s = $this->createUser('ms');
		$t = $this->teams->create($l->uid, 'Mat ' . bin2hex(random_bytes(3)));
		$memberS = $this->teams->addUser($t, $l->uid, $s->uid);
		[$ws, $wsId] = self::teamWs($l, $t, 'Mat');
		$mark = 'Mat' . bin2hex(random_bytes(4));
		$m = self::folder($l, $ws, "M $mark");
		$u = self::folder($l, $ws, 'U', $m);
		$n1 = self::newNote($l, $ws, "N1 $mark", "<p>n1 $mark</p>", $u);
		$att = (int)self::ok($l->upload("/notes/$n1/attachments", 'bild.png', (string)base64_decode(self::PNG), [], 'image/png'), 'Anhang')['attachment_id'];
		$snap = (string)self::ok($l->call('POST', "/notes/$n1/snapshot?manual=1"), 'Snapshot')['snapshot_key'];
		$tasks = json_encode([['id' => 1, 'text' => "Task $mark", 'completed' => false]], JSON_THROW_ON_ERROR);
		$tl = (int)self::ok($l->call('POST', '/notes', ['heading' => "Tasks $mark", 'type' => 'tasklist', 'content' => $tasks, 'folder_id' => $m, 'workspace' => $ws]), 'Aufgabenliste')['note']['id'];
		$ex = (int)self::ok($l->call('POST', '/notes', ['heading' => "Ex $mark", 'type' => 'excalidraw', 'content' => '', 'folder_id' => $m, 'workspace' => $ws]), 'Excalidraw')['note']['id'];
		$sN = self::newNote($s, $ws, "S $mark", '<p>s</p>');
		self::ok($l->call('PUT', "/folders/$m/material", ['material' => true]), 'Kennzeichen setzen');
		return ['l' => $l, 's' => $s, 't' => $t, 'memberS' => $memberS, 'ws' => $ws, 'wsId' => $wsId, 'mark' => $mark,
			'm' => $m, 'u' => $u, 'n1' => $n1, 'att' => $att, 'snap' => $snap, 'tl' => $tl, 'ex' => $ex, 'sN' => $sN];
	}

	protected static function folder(ApiClient $c, string $ws, string $name, ?int $parent = null): int {
		$body = ['folder_name' => $name, 'workspace' => $ws] + ($parent !== null ? ['parent_folder_id' => $parent] : []);
		return (int)self::ok($c->call('POST', '/folders', $body), "Ordner $name")['folder_id'];
	}

	protected static function denied(Response $r, string $what, int $status = 403): void {
		self::assertSame($status, $r->status, "$what: erwartet $status, erhalten " . substr($r->describe(), 0, 300));
	}

	/** @return array<string, mixed>|null Notizzeile ohne Suchtext */
	protected static function note(int $id): ?array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('workspace_id', 'folder_id', 'heading', 'content', 'type', 'favorite', 'pinned', 'icon', 'color', 'speed_dial_order', 'display_order',
			'trashed_at', 'trash_material', 'updated_at', 'updated_by')
			->from('nextnotes_notes')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetchAssociative();
		return $row === false ? null : array_map(static fn ($v) => $v === null ? null : (string)$v, $row);
	}

	/** @return array<string, mixed>|null */
	protected static function folderRow(int $id): ?array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('workspace_id', 'parent_id', 'name', 'display_order', 'pinned', 'favorite', 'icon', 'color', 'material', 'is_diary')
			->from('nextnotes_folders')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetchAssociative();
		return $row === false ? null : array_map(static fn ($v) => $v === null ? null : (string)$v, $row);
	}

	/** @return int[] Ordner-IDs eines Workspace */
	protected static function folderIds(int $wsId): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_folders')->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($wsId, IQueryBuilder::PARAM_INT)))->orderBy('id');
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/** @return int[] Notiz-IDs eines Workspace (auch Papierkorb) */
	protected static function noteIds(int $wsId): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_notes')->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($wsId, IQueryBuilder::PARAM_INT)))->orderBy('id');
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	protected static function setColumn(string $table, int $id, string $col, mixed $value): void {
		$qb = self::db()->getQueryBuilder();
		$qb->update($table)->set($col, $qb->createNamedParameter($value))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	protected static function excalidraw(ApiClient $c, int $note, string $text): Response {
		$data = json_encode(['type' => 'excalidraw', 'elements' => [['id' => 'e1', 'type' => 'text', 'text' => $text]], 'appState' => new \stdClass(), 'files' => new \stdClass()], JSON_THROW_ON_ERROR);
		return $c->form(self::APP . 'api_save_excalidraw.php', ['note_id' => $note, 'diagram_data' => $data], true);
	}
}
