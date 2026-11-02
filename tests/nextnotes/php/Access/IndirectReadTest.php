<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Access;

use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\Server;

/**
 * SPEC §3 (indirekte Lesepfade), Plan Done M2b 1: /notes/resolve (Titel, ID), Backlinks, Graph, /changes,
 * Suche, Anhänge, Snapshots – B sieht nichts von A, weder ohne Freigabe noch nach deren Entzug.
 * Querverweise in beide Richtungen (A-Notiz verweist auf B-Notiz und umgekehrt).
 */
final class IndirectReadTest extends ApiTestCase {
	/** @return string[] Lecks */
	private static function leaks(ApiClient $b, array $fx): array {
		$m = $fx['mark'];
		$errors = [];
		$noMark = static function (string $what, $r) use ($m, &$errors): void {
			if (str_contains($r->body, $m)) {
				$errors[] = "$what: A-Marker sichtbar " . $r->describe();
			}
			if ($r->status >= 500) {
				$errors[] = "$what: Status " . $r->describe();
			}
		};
		$refused = static function (string $what, $r) use ($noMark, &$errors): void {
			$noMark($what, $r);
			if (!in_array($r->status, [403, 404], true)) {
				$errors[] = "$what: erwartet 403/404, erhalten " . $r->describe();
			}
		};
		$gA = $fx['gA'];
		$refused('resolve Titel', $b->call('GET', '/notes/resolve' . self::q(['reference' => "Geheim $m"])));
		$refused('resolve ID', $b->call('GET', '/notes/resolve' . self::q(['reference' => (string)$gA])));
		$refused('resolve ID im eigenen WS', $b->call('GET', '/notes/resolve' . self::q(['reference' => (string)$gA, 'workspace' => $fx['wsB']])));
		$refused('backlinks A-Notiz', $b->call('GET', "/notes/$gA/backlinks"));
		$refused('backlinks A-Notiz all', $b->call('GET', "/notes/$gA/backlinks" . self::q(['all_workspaces' => '1'])));
		foreach ([[], ['workspace' => $fx['wsB']], ['workspace' => $fx['wsB'], 'all_workspaces' => '1'], ['all_workspaces' => '1']] as $q) {
			$r = $b->call('GET', "/notes/{$fx['bN']}/backlinks" . self::q($q));
			$noMark('backlinks eigene B-Notiz ' . json_encode($q), $r);
			if ($r->ok() && array_map(static fn ($x) => (int)$x['id'], $r->json()['backlinks'] ?? []) !== []) {
				$errors[] = 'backlinks eigene B-Notiz listet A-Notiz ' . $r->describe();
			}
		}
		foreach ([[], ['workspace' => $fx['wsB']]] as $q) {
			$r = $b->call('GET', '/graph' . self::q($q));
			$noMark('graph ' . json_encode($q), $r);
			$nodes = array_map(static fn ($n) => (int)$n['id'], $r->json()['nodes'] ?? []);
			foreach (['gA', 'lA', 'zA'] as $k) {
				if (in_array($fx[$k], $nodes, true)) {
					$errors[] = "graph: Knoten $k von A";
				}
			}
			foreach ($r->json()['edges'] ?? [] as $e) {
				if (in_array((int)$e['source'], [$fx['gA'], $fx['lA'], $fx['zA']], true) || in_array((int)$e['target'], [$fx['gA'], $fx['lA'], $fx['zA']], true)) {
					$errors[] = 'graph: Kante zu A-Notiz ' . json_encode($e);
				}
			}
		}
		$r = $b->call('GET', '/changes' . self::q(['workspace' => $fx['wsB'], 'note_ids' => "$gA,{$fx['lA']},{$fx['bN']}"]));
		$noMark('changes', $r);
		$notes = $r->json()['notes'] ?? [];
		foreach (['gA', 'lA'] as $k) {
			$n = $notes[(string)$fx[$k]] ?? null;
			if ($n === null || $n['exists'] !== false || $n['version'] !== 'missing' || $n['content_version'] !== null) {
				$errors[] = "changes: $k nicht als missing " . json_encode($n);
			}
		}
		if (($notes[(string)$fx['bN']]['exists'] ?? false) !== true) {
			$errors[] = 'changes Kontrolle: eigene B-Notiz fehlt';
		}
		$refused('changes mit A-Workspace', $b->call('GET', '/changes' . self::q(['workspace' => $fx['wsA'], 'note_ids' => (string)$gA])));
		// Suchbegriff ohne Marker (die Antwort wiederholt q)
		foreach (['/notes/search' => ['q' => 'Geheim'], '/notes/search/ids' => ['q' => 'geheim inhalt']] as $p => $q) {
			$r = $b->call('GET', $p . self::q($q));
			$noMark($p, $r);
			if (in_array($gA, array_map('intval', $r->json()['ids'] ?? []), true)) {
				$errors[] = "$p: ID von A";
			}
			if (($r->json()['results'] ?? []) !== []) {
				$errors[] = "$p: Treffer " . $r->describe();
			}
		}
		$noMark('with-attachments', $b->call('GET', '/notes/with-attachments'));
		$refused('Anhang-Liste', $b->call('GET', "/notes/$gA/attachments"));
		$refused('Anhang-Download', $b->call('GET', "/notes/$gA/attachments/{$fx['att']}"));
		$refused('Anhang über eigene Notiz', $b->call('GET', "/notes/{$fx['bN']}/attachments/{$fx['att']}"));
		$refused('Snapshots', $b->call('GET', "/notes/$gA/snapshots"));
		$r = $b->call('GET', "/notes/$gA/snapshot" . self::q(['snapshot_key' => $fx['snap']]));
		$noMark('Snapshot lesen', $r);
		if ($r->ok() && ($r->json()['exists'] ?? null) !== false) {
			$errors[] = 'Snapshot lesen: ' . $r->describe();
		}
		$refused('Sperre lesen', $b->call('GET', "/notes/$gA/lock"));
		$refused('Notiz lesen', $b->call('GET', "/notes/$gA"));
		return $errors;
	}

	public function testIndirectPathsWithoutShareAndAfterRevoke(): void {
		$a = $this->createUser('ia');
		$b = $this->createUser('ib');
		$m = 'Mk' . bin2hex(random_bytes(4));
		[$wsA, $wsAId] = self::newWorkspace($a, 'IndA');
		[$wsB] = self::newWorkspace($b, 'IndB');
		$bN = self::newNote($b, $wsB, 'B Ziel', '<p>b</p>');
		$gA = self::newNote($a, $wsA, "Geheim $m", "<p>Inhalt $m</p>");
		$lA = self::newNote($a, $wsA, "Verweis $m", "<p><a href=\"index.php?note=$gA\">x</a> $m</p>");
		// A verweist auf B's Notiz (Leck über B's Backlinks/Graph)
		$zA = self::newNote($a, $wsA, "ZuB $m", "<p><a href=\"index.php?note=$bN\">b</a> <span data-note-id=\"$bN\">b</span> $m</p>");
		// B verweist auf A's Notiz (Leck über B's Graph)
		self::newNote($b, $wsB, 'B Verweis', "<p><a href=\"index.php?note=$gA\">a</a></p>");
		$att = (int)self::ok($a->upload("/notes/$gA/attachments", "datei-$m.txt", "inhalt $m"), 'Anhang')['attachment_id'];
		$snap = (string)self::ok($a->call('POST', "/notes/$gA/snapshot?manual=1"), 'Snapshot')['snapshot_key'];
		self::ok($a->call('POST', "/notes/$gA/lock", ['editor_session_id' => 'ind-s1']), 'Sperre');
		$fx = ['mark' => $m, 'wsA' => $wsA, 'wsB' => $wsB, 'gA' => $gA, 'lA' => $lA, 'zA' => $zA, 'bN' => $bN, 'att' => $att, 'snap' => $snap];

		self::assertSame([], self::leaks($b, $fx), 'ohne Freigabe');

		// Kontrolle mit Freigabe: dieselben Pfade liefern A's Daten (Detektor greift)
		$svc = Server::get(WorkspaceService::class);
		$svc->share($a->uid, $wsAId, $b->uid);
		self::assertSame($gA, (int)self::ok($b->call('GET', '/notes/resolve' . self::q(['reference' => "Geheim $m"])), 'resolve Titel mit Freigabe')['id']);
		self::assertContains($lA, array_map(static fn ($x) => (int)$x['id'], self::ok($b->call('GET', "/notes/$gA/backlinks"), 'backlinks mit Freigabe')['backlinks']));
		self::assertContains($zA, array_map(static fn ($x) => (int)$x['id'], self::ok($b->call('GET', "/notes/$bN/backlinks" . self::q(['all_workspaces' => '1'])), 'B-backlinks mit Freigabe')['backlinks']));
		self::assertContains($gA, array_map(static fn ($x) => (int)$x['id'], self::ok($b->call('GET', '/notes/search' . self::q(['q' => 'Geheim'])), 'Suche mit Freigabe')['results']));
		self::assertContains($gA, self::ok($b->call('GET', '/notes/search/ids' . self::q(['q' => 'geheim inhalt'])), 'Such-IDs mit Freigabe')['ids']);
		self::assertSame("inhalt $m", $b->call('GET', "/notes/$gA/attachments/$att")->body, 'Anhang mit Freigabe');
		self::assertNotSame([], self::leaks($b, $fx), 'Detektor erkennt Freigabe nicht');

		$svc->unshare($a->uid, $wsAId, $b->uid);
		self::assertSame([], self::leaks($b, $fx), 'nach Entzug der Freigabe');
	}
}
