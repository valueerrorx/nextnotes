<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Response;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Server;

/**
 * SPEC §13 M6 Krit. 4 (M6h): B (Mitschüler S2, Nicht-Mitglied N) erreicht die Abgabe von A (S1) über keinen Pfad.
 * Sweep über jede Route aus routes.php (API, Seiten, Kompat), Unified Search, Suche, Backlinks, Exporte, Anhänge:
 * jede ID-Stelle mit der passenden S1-ID belegt; nie ein Geheimnis von S1 in der Antwort, nie 5xx, S1-Zustand
 * (Workspace, Notiz, Anhänge, Snapshots, Abgabe, Aufgabe) vorher = nachher; ID-Pfade auf S1-Objekte nie 2xx.
 * Gegenproben: S1 und die Lehrkraft erreichen dieselben Daten (sonst wäre der Sweep leer).
 */
final class AssignmentIsolationTest extends AssignmentTestCase {
	/** @var array<string, mixed> */
	private array $s = [];

	/** S1-Abgabe mit Geheimnis in Titel, Inhalt, Anhang (Name + Bytes), Tag, Feedback; S1 hat abgegeben, L hat zurückgegeben */
	private function seedFixture(): array {
		$fx = $this->distributedFixture();
		['s1' => $s1, 's2' => $s2, 'l' => $l] = $fx;
		$sub1 = $fx['subs'][$s1->uid];
		$sub2 = $fx['subs'][$s2->uid];
		$c1 = (int)$sub1['note_id'];
		$c2 = (int)$sub2['note_id'];
		$prefix = 'Gh' . bin2hex(random_bytes(5));
		$secret = $prefix . 'Zq';
		$fbSecret = 'Fb' . bin2hex(random_bytes(5));
		$tag = 'tg' . bin2hex(random_bytes(4));
		// S1 verweist auf die S2-Kopie (Backlinks der S2-Kopie dürfen S1 nicht zeigen)
		self::ok($s1->call('PATCH', "/notes/$c1", ['heading' => "Titel $secret", 'content' => "<p>$secret</p><p><a href=\"index.php?note=$c2\">x</a> [[Kopie]]</p>"]), 'S1 schreibt');
		$att = (int)self::ok($s1->upload("/notes/$c1/attachments", "datei-$secret.txt", "bytes $secret", [], 'text/plain'), 'S1 Anhang')['attachment_id'];
		$search = self::ok($s1->call('GET', '/notes/search' . self::q(['q' => $prefix])), 'S1 sucht eigene Abgabe')['results'] ?? [];
		$ws1 = null;
		foreach ($search as $r) {
			if ((int)$r['id'] === $c1) {
				$ws1 = (string)$r['workspace'];
			}
		}
		self::assertNotNull($ws1, 'Gegenprobe: S1 findet eigene Abgabe per Suche (Workspace-Schlüssel) ' . json_encode($search));
		self::ok($s1->call('PUT', "/notes/$c1/tags", ['tags' => [$tag], 'workspace' => $ws1]), 'S1 Tag');
		$snaps = self::ok($s1->call('GET', "/notes/$c1/snapshots"), 'S1 Snapshots')['snapshots'] ?? [];
		self::ok($s1->call('POST', "/submissions/{$sub1['id']}/submit"), 'S1 gibt ab');
		self::ok($l->call('POST', "/submissions/{$sub1['id']}/return", ['feedback' => "gut $fbSecret", 'points' => 7]), 'L gibt zurück');
		$note = self::row('nextnotes_notes', $c1);
		$this->s = [
			'c1' => $c1, 'c2' => $c2, 'sid1' => (int)$sub1['id'], 'sid2' => (int)$sub2['id'], 'aid' => $fx['aid'], 'att' => $att,
			'folder' => (int)$note['folder_id'], 'wsId' => (int)$note['workspace_id'], 'ws' => $ws1, 'tag' => $tag,
			'snap' => (string)($snaps[0]['snapshot_key'] ?? '2000-01-01'), 'prefix' => $prefix, 'needles' => [$secret, $fbSecret],
		];
		return $fx;
	}

	/** Zustand der S1-Abgabe (Workspace, Ordner, Notiz, Anhänge, Snapshots, Tags, Abgabe, Aufgabe) */
	private function state(string $uid): array {
		$rows = static function (string $table, string $col, int $id): array {
			$qb = self::db()->getQueryBuilder();
			$qb->select('*')->from($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->orderBy('id');
			return array_map(static fn (array $r) => array_map(static fn ($v) => $v === null ? null : (string)$v, $r), $qb->executeQuery()->fetchAllAssociative());
		};
		$note = $rows('nextnotes_notes', 'id', $this->s['c1']);
		foreach ($note as &$n) {
			unset($n['opened_at']);
		}
		return [
			'dump' => self::dump($uid), 'note' => $note, 'ws' => $rows('nextnotes_workspaces', 'id', $this->s['wsId']),
			'folder' => $rows('nextnotes_folders', 'id', $this->s['folder']), 'att' => $rows('nextnotes_attachments', 'note_id', $this->s['c1']),
			'snap' => $rows('nextnotes_snapshots', 'note_id', $this->s['c1']), 'sub' => $rows('nextnotes_submissions', 'id', $this->s['sid1']),
			'asg' => $rows('nextnotes_assignments', 'id', $this->s['aid']),
		];
	}

	/** Kandidaten für {id} je Pfad-Präfix (S1-Objekte) */
	private function idsFor(string $url): array {
		return match (true) {
			str_starts_with($url, '/api/v1/notes/'), str_starts_with($url, '/api/v1/mentions/') => [$this->s['c1']],
			str_starts_with($url, '/api/v1/folders/') => [$this->s['folder']],
			str_starts_with($url, '/api/v1/workspaces/') => [$this->s['wsId']],
			str_starts_with($url, '/api/v1/assignments/') => [$this->s['aid']],
			str_starts_with($url, '/api/v1/submissions/') => [$this->s['sid1']],
			default => [$this->s['c1'], $this->s['folder'], $this->s['wsId'], $this->s['sid1'], $this->s['att']],
		};
	}

	/** @return list<array{string, string, bool}> [Verb, Pfad ab /api/v1 bzw. App-Wurzel, strikt (ID-Pfad auf S1-Objekt)] */
	private function requests(string $s1uid): array {
		$file = Server::get(IAppManager::class)->getAppPath('nextnotes') . '/appinfo/routes.php';
		$routes = (static fn () => require $file)();
		$out = [];
		foreach ($routes['routes'] as $r) {
			$verb = strtoupper($r['verb'] ?? 'GET');
			$url = $r['url'];
			$fixed = [
				'{noteId}' => (string)$this->s['c1'], '{attachmentId}' => (string)$this->s['att'], '{taskId}' => '1', '{subtaskId}' => '1',
				'{name}' => rawurlencode($this->s['ws']), '{ownerId}' => rawurlencode($s1uid), '{username}' => rawurlencode($s1uid),
				'{tag}' => rawurlencode($this->s['tag']), '{key}' => 'x',
			];
			$base = strtr($url, $fixed);
			$strictBase = $base !== $url && preg_match('/\{(noteId|attachmentId|name)\}/', $url) === 1;
			if (str_contains($base, '{id}')) {
				// strikt nur, wenn {id} sicher ein S1-Objekt ist (Präfix bekannt); sonst (z. B. /reminders/{id}) nur Leck/Zustand prüfen
				$ids = $this->idsFor($base);
				foreach ($ids as $id) {
					$out[] = [$verb, str_replace('{id}', (string)$id, $base), count($ids) === 1];
				}
			} else {
				$out[] = [$verb, $base, $strictBase];
			}
		}
		return $out;
	}

	private function body(): array {
		$c1 = $this->s['c1'];
		return ['note_id' => $c1, 'id' => $c1, 'target_note_id' => $c1, 'linked_note_id' => $c1, 'folder_id' => $this->s['folder'], 'parent_folder_id' => $this->s['folder'],
			'workspace' => $this->s['ws'], 'note_ids' => [$c1], 'ids' => [$c1], 'heading' => 'x', 'content' => '<p>x</p>', 'tags' => ['x'], 'confirm' => true,
			'feedback' => 'x', 'points' => 1, 'attachment_id' => $this->s['att'], 'snapshot_key' => $this->s['snap'], 'editor_session_id' => 'iso', 'due_at' => time() + 99999];
	}

	private function query(): string {
		return self::q(['note' => $this->s['c1'], 'note_id' => $this->s['c1'], 'id' => $this->s['c1'], 'folder_id' => $this->s['folder'], 'folder' => $this->s['folder'],
			'workspace' => $this->s['ws'], 'attachment_id' => $this->s['att'], 'q' => $this->s['prefix'], 'search' => $this->s['prefix'], 'tag' => $this->s['tag'],
			'reference' => (string)$this->s['c1'], 'note_ids' => (string)$this->s['c1'], 'snapshot_key' => $this->s['snap'], 'all_workspaces' => '1']);
	}

	private function leak(Response $r): string {
		foreach ($this->s['needles'] as $n) {
			if (str_contains($r->body, $n) || str_contains(urldecode($r->body), $n)) {
				return "Geheimnis $n in der Antwort ($r->status)";
			}
		}
		return '';
	}

	/** Sweep für einen Angreifer; $sees: Aufgabe darf er per GET lesen (Mitschüler) */
	private function sweep(ApiClient $b, string $s1uid, bool $seesAssignment, array &$errors): int {
		$before = $this->state($s1uid);
		$count = 0;
		foreach ($this->requests($s1uid) as [$verb, $path, $strict]) {
			self::resetRateLimits();
			$api = str_starts_with($path, '/api/v1/');
			foreach ($api ? ['', $this->query()] : [$this->query(), self::q(['workspace' => $this->s['ws'], 'note' => $this->s['c1']])] as $q) {
				$abs = '/index.php/apps/nextnotes' . $path . ($verb === 'GET' || $verb === 'DELETE' ? $q : '');
				$r = $verb === 'GET' || $verb === 'DELETE'
					? $b->absolute($verb, $abs)
					: ($api ? $b->absolute($verb, $abs, $this->body()) : $b->form($abs, array_map(static fn ($v) => is_array($v) ? implode(',', $v) : (string)$v, $this->body())));
				$count++;
				$label = "$b->uid $verb $abs";
				if (($e = $this->leak($r)) !== '') {
					$errors[] = "$label: $e";
				}
				if ($r->status >= 500) {
					$errors[] = "$label: Serverfehler " . substr($r->describe(), 0, 200);
				}
				$allowed = $seesAssignment && $verb === 'GET' && $path === "/api/v1/assignments/{$this->s['aid']}";
				if ($strict && !$allowed && $r->status >= 200 && $r->status < 300) {
					$errors[] = "$label: $r->status statt Ablehnung";
				}
				$now = $this->state($s1uid);
				if ($now !== $before) {
					$errors[] = "$label: S1-Abgabe verändert";
					$before = $now;
				}
			}
		}
		return $count;
	}

	public function testNoPathReachesForeignSubmission(): void {
		$fx = $this->seedFixture();
		['s1' => $s1, 's2' => $s2, 'n' => $n, 'l' => $l] = $fx;
		$errors = [];
		$sent = $this->sweep($s2, $s1->uid, true, $errors) + $this->sweep($n, $s1->uid, false, $errors);
		self::assertGreaterThan(300, $sent, 'Sweep hat Anfragen gesendet');
		self::assertSame([], $errors);
		// S1 unverändert abgegeben + zurückgegeben (Angriffe haben nichts ausgelöst)
		self::assertSame('returned', (string)self::row('nextnotes_submissions', $this->s['sid1'])['status']);
	}

	// Suche, Unified Search, Backlinks, Exporte, Anhang, Kopie der S2 – gezielt mit Gegenprobe (S1 bzw. Lehrkraft erreicht sie)
	public function testSearchExportBacklinksWithControls(): void {
		$fx = $this->seedFixture();
		['s1' => $s1, 's2' => $s2, 'n' => $n, 'l' => $l, 'm' => $m] = $fx;
		self::resetRateLimits();
		[$secret, $fb] = $this->s['needles'];
		$c1 = $this->s['c1'];
		$sid1 = $this->s['sid1'];
		$ocs = '/ocs/v2.php/search/providers/nextnotes/search?format=json&term=' . rawurlencode($this->s['prefix']);
		$checks = [
			'Suche' => fn (ApiClient $c) => $c->call('GET', '/notes/search' . self::q(['q' => $this->s['prefix']])),
			'Suche alle Workspaces' => fn (ApiClient $c) => $c->call('GET', '/notes/search' . self::q(['q' => $this->s['prefix'], 'all_workspaces' => '1'])),
			'Unified Search' => fn (ApiClient $c) => $c->absolute('GET', $ocs),
			'Notiz' => fn (ApiClient $c) => $c->call('GET', "/notes/$c1"),
			'Anhang' => fn (ApiClient $c) => $c->call('GET', "/notes/$c1/attachments/{$this->s['att']}"),
			'Anhangliste' => fn (ApiClient $c) => $c->call('GET', "/notes/$c1/attachments"),
			'Abgabe' => fn (ApiClient $c) => $c->call('GET', "/submissions/$sid1"),
			'Abgabe-Export' => fn (ApiClient $c) => $c->call('GET', "/submissions/$sid1/export"),
		];
		foreach ($checks as $what => $fn) {
			foreach (['S2' => $s2, 'N' => $n] as $who => $c) {
				$r = $fn($c);
				self::assertSame('', $this->leak($r), "$who $what");
				self::assertLessThan(500, $r->status, "$who $what: " . $r->describe());
			}
		}
		foreach (['Notiz', 'Anhang', 'Anhangliste', 'Abgabe', 'Abgabe-Export'] as $what) {
			self::expectStatus($checks[$what]($s2), [403, 404], "S2 $what");
		}
		// Gegenproben: S1 findet/liest, Lehrkraft liest; ZIP der Lehrkraft enthält die Abgabe
		self::assertStringContainsString($secret, $checks['Suche']($s1)->body, 'S1 Suche');
		self::assertStringContainsString($secret, $checks['Notiz']($s1)->body, 'S1 Notiz');
		self::assertStringContainsString($secret, $checks['Anhang']($s1)->body, 'S1 Anhang');
		self::assertStringContainsString($fb, $checks['Abgabe']($s1)->body, 'S1 sieht Feedback nach Rückgabe');
		self::assertStringContainsString($secret, $checks['Notiz']($m)->body, 'Lehrkraft M liest die Kopie');
		$zip = $l->call('GET', "/assignments/{$this->s['aid']}/export");
		self::assertSame(200, $zip->status, 'Lehrkraft ZIP ' . substr($zip->describe(), 0, 200));
		// S2: Aufgaben-ZIP verweigert, eigener Export ohne S1
		self::expectStatus($s2->call('GET', "/assignments/{$this->s['aid']}/export"), [403, 404], 'S2 Aufgaben-ZIP');
		$own = $s2->call('GET', "/submissions/{$this->s['sid2']}/export");
		self::assertSame(200, $own->status, 'S2 eigener Export');
		self::assertSame('', $this->leak($own), 'S2 eigener Export ohne S1');
		self::assertSame('', $this->leak(self::unzipText($own->body)), 'S2 eigener Export (entpackt) ohne S1');
		// Backlinks der S2-Kopie (S1 verweist darauf): S1 nie sichtbar
		foreach ([[], ['all_workspaces' => '1']] as $q) {
			$r = $s2->call('GET', "/notes/{$this->s['c2']}/backlinks" . self::q($q));
			self::assertSame('', $this->leak($r), 'Backlinks S2-Kopie ' . json_encode($q));
			self::assertStringNotContainsString('"id":' . $c1, $r->body, 'Backlinks S2-Kopie ohne S1-ID');
		}
		// Aufgabe für S2: eigene Abgabe, nichts von S1
		$a = $s2->call('GET', "/assignments/{$this->s['aid']}");
		self::assertSame(200, $a->status);
		self::assertSame('', $this->leak($a), 'Aufgabe für S2');
		$list = $s2->call('GET', '/assignments');
		self::assertSame('', $this->leak($list), 'Aufgabenliste für S2');
		self::assertStringNotContainsString('"id":' . $sid1 . ',', $list->body, 'Aufgabenliste ohne S1-Abgabe-ID');
		// Seiten der Schüler-Oberfläche
		foreach (['assignments.php', "index.php?note=$c1", 'dashboard.php', 'notes_manager.php?all_workspaces=1', 'attachments_list.php'] as $p) {
			$r = self::page($s2, $p);
			self::assertSame('', $this->leak($r), "S2 Seite $p");
			self::assertLessThan(500, $r->status, "S2 Seite $p");
		}
	}

	// /notes/resolve per Titel (Titel enthält das Geheimnis, Echo zulässig): S2 erhält nie die S1-Kopie
	public function testResolveByTitle(): void {
		$fx = $this->seedFixture();
		$heading = (string)self::row('nextnotes_notes', $this->s['c1'])['heading'];
		foreach (['S2' => $fx['s2'], 'N' => $fx['n']] as $who => $c) {
			foreach ([[], ['workspace' => $this->s['ws']]] as $q) {
				$r = $c->call('GET', '/notes/resolve' . self::q(['reference' => $heading] + $q));
				self::assertLessThan(500, $r->status, "$who resolve Titel");
				if ($r->ok()) {
					self::assertNotSame($this->s['c1'], (int)($r->json()['id'] ?? 0), "$who resolve Titel liefert S1-Kopie");
				}
				self::assertStringNotContainsString("<p>{$this->s['needles'][0]}</p>", $r->body, "$who resolve Titel: Inhalt");
			}
		}
		// Gegenprobe per ID (per Titel löst die eigene Abgabe nicht auf: Beobachtung, keine Spec-Vorgabe)
		self::assertSame($this->s['c1'], (int)self::ok($fx['s1']->call('GET', '/notes/resolve' . self::q(['reference' => (string)$this->s['c1']])), 'Gegenprobe S1 per ID')['id']);
	}

	// Ex-Mitglied (Krit. 7: liest nur die eigene Abgabe) und eingefrorenes Team (Circles aus): fremde Abgabe und Vorlage nie
	public function testFormerMemberAndFrozen(): void {
		$fx = $this->seedFixture();
		['s1' => $s1, 's2' => $s2, 'l' => $l] = $fx;
		$tpl = $fx['tpl'];
		$this->teams->remove($l->uid, $fx['memberS1']);
		Teams::reset();
		self::resetRateLimits();
		// S1 ehemalig: eigene Abgabe lesbar, nicht schreibbar; S2-Abgabe und Vorlage nie
		self::assertSame(200, $s1->call('GET', "/notes/{$this->s['c1']}")->status, 'Ex-Mitglied S1 liest eigene Abgabe');
		self::expectStatus($s1->call('PATCH', "/notes/{$this->s['c1']}", ['content' => '<p>neu</p>']), [403, 404], 'Ex-Mitglied S1 schreibt eigene Abgabe');
		self::expectStatus($s1->call('GET', "/notes/{$this->s['c2']}"), [403, 404], 'Ex-Mitglied S1 auf S2-Kopie');
		self::expectStatus($s1->call('GET', "/submissions/{$this->s['sid2']}"), [403, 404], 'Ex-Mitglied S1 auf S2-Abgabe');
		self::expectStatus($s1->call('GET', "/notes/$tpl"), [403, 404], 'Ex-Mitglied S1 auf Vorlage');
		self::expectStatus($s1->call('GET', "/assignments/{$this->s['aid']}/submissions"), [403, 404], 'Ex-Mitglied S1 Abgabenliste');
		$r = $s2->call('GET', '/notes/search' . self::q(['q' => $this->s['prefix']]));
		self::assertSame('', $this->leak($r), 'S2 Suche nach Austritt von S1');

		// eingefroren: S2 erreicht S1 weiterhin nie
		self::assertSame(0, self::occ('app:disable', 'circles'));
		try {
			sleep(4);
			Teams::reset();
			self::resetRateLimits();
			$errors = [];
			$this->sweep($s2, $s1->uid, true, $errors);
			self::assertSame([], $errors, 'eingefroren: S2-Sweep');
			self::expectStatus($s2->call('GET', "/notes/$tpl"), [403, 404], 'eingefroren: S2 auf Vorlage');
		} finally {
			self::occ('app:enable', 'circles');
			sleep(4);
			Teams::reset();
		}
	}

	private static function occ(string ...$args): int {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return $rc;
	}

	/** ZIP-Inhalt als Text (Dateinamen + entpackte Einträge), damit komprimierte Geheimnisse sichtbar werden */
	private static function unzipText(string $bytes): Response {
		$tmp = tempnam(sys_get_temp_dir(), 'nnzip');
		file_put_contents($tmp, $bytes);
		$text = '';
		$zip = new \ZipArchive();
		if ($zip->open($tmp) === true) {
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$text .= $zip->getNameIndex($i) . "\n" . $zip->getFromIndex($i) . "\n";
			}
			$zip->close();
		} else {
			$text = $bytes;
		}
		unlink($tmp);
		return new Response(200, $text, []);
	}
}
