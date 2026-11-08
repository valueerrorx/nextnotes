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
		// Aufgabentitel und S1-Ordnername (Ordner je Aufgabe = Titel) als eigene Needles (M7h-fix: ZIP-Export „alle“ schrieb <Titel>/.gitkeep)
		$titleSecret = 'Ti' . bin2hex(random_bytes(5));
		$folderSecret = 'Fo' . bin2hex(random_bytes(5));
		$db = self::db();
		$qb = $db->getQueryBuilder();
		$qb->update('nextnotes_assignments')->set('title', $qb->createNamedParameter("Aufgabe $titleSecret"))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($fx['aid'], IQueryBuilder::PARAM_INT)))->executeStatement();
		$qb = $db->getQueryBuilder();
		$qb->update('nextnotes_folders')->set('name', $qb->createNamedParameter("Aufgabe $titleSecret $folderSecret"))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$note['folder_id'], IQueryBuilder::PARAM_INT)))->executeStatement();
		$this->s = [
			'c1' => $c1, 'c2' => $c2, 'sid1' => (int)$sub1['id'], 'sid2' => (int)$sub2['id'], 'aid' => $fx['aid'], 'att' => $att,
			'folder' => (int)$note['folder_id'], 'wsId' => (int)$note['workspace_id'], 'ws' => $ws1, 'tag' => $tag,
			'snap' => (string)($snaps[0]['snapshot_key'] ?? '2000-01-01'), 'prefix' => $prefix, 'needles' => [$secret, $fbSecret],
			'title' => $titleSecret, 'folderName' => $folderSecret,
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

	/** Needles: Inhalt, Feedback, S1-Ordnername; Aufgabentitel nur, wenn der Aufrufer die Aufgabe nicht sehen darf */
	private function needles(bool $seesAssignment): array {
		return array_merge($this->s['needles'], [$this->s['folderName']], $seesAssignment ? [] : [$this->s['title']]);
	}

	private function leak(Response $r, ?array $needles = null): string {
		$body = str_starts_with($r->body, "PK\x03\x04") ? self::unzipText($r->body)->body : $r->body;
		foreach ($needles ?? $this->s['needles'] as $n) {
			if (str_contains($body, $n) || str_contains(urldecode($body), $n)) {
				return "Geheimnis $n in der Antwort ($r->status)";
			}
		}
		return '';
	}

	/** Sweep für einen Angreifer; $sees: Aufgabe darf er per GET lesen (Mitschüler); S1 selbst (Ex-Mitglied, M7h): ohne eigenen Konto-Dump */
	private function sweep(ApiClient $b, string $s1uid, bool $seesAssignment, array &$errors): int {
		$self = $b->uid === $s1uid;
		$state = fn (): array => $self ? array_diff_key($this->state($s1uid), ['dump' => true]) : $this->state($s1uid);
		$before = $state();
		$needles = $this->needles($seesAssignment);
		$count = 0;
		foreach ($this->requests($s1uid) as [$verb, $path, $strict]) {
			self::resetRateLimits();
			$api = str_starts_with($path, '/api/v1/');
			$queries = $api ? ['', $this->query()] : [$this->query(), self::q(['workspace' => $this->s['ws'], 'note' => $this->s['c1']])];
			if (!$api && $verb === 'GET') {
				// ohne Parameter: „alle“-Wege (z. B. api_export_structured.php ohne workspace)
				$queries[] = '';
			}
			foreach ($queries as $q) {
				$abs = '/index.php/apps/nextnotes' . $path . ($verb === 'GET' || $verb === 'DELETE' ? $q : '');
				$r = $verb === 'GET' || $verb === 'DELETE'
					? $b->absolute($verb, $abs)
					: ($api ? $b->absolute($verb, $abs, $this->body()) : $b->form($abs, array_map(static fn ($v) => is_array($v) ? implode(',', $v) : (string)$v, $this->body())));
				$count++;
				$label = "$b->uid $verb $abs";
				if (($e = $this->leak($r, $needles)) !== '') {
					$errors[] = "$label: $e";
				}
				if ($r->status >= 500) {
					$errors[] = "$label: Serverfehler " . substr($r->describe(), 0, 200);
				}
				$allowed = $seesAssignment && $verb === 'GET' && $path === "/api/v1/assignments/{$this->s['aid']}";
				if ($strict && !$allowed && $r->status >= 200 && $r->status < 300) {
					$errors[] = "$label: $r->status statt Ablehnung";
				}
				$now = $state();
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

	// Ex-Mitglied (M7h, Thomas 2026-11-08): erreicht weder eigene noch fremde Abgaben (404 auf allen Wegen); Lehrkraft liest weiter; eingefroren bleibt es so; Wiedereintritt gibt den Zugriff zurück
	public function testFormerMemberAndFrozen(): void {
		$fx = $this->seedFixture();
		['s1' => $s1, 's2' => $s2, 'l' => $l] = $fx;
		$tpl = $fx['tpl'];
		$c1 = $this->s['c1'];
		$sid1 = $this->s['sid1'];
		$aid = $this->s['aid'];
		// Gegenproben vor dem Austritt: S1-Export „alle“ enthält Ordner und Titel, aktives Mitglied S2 sieht den Titel
		self::resetRateLimits();
		$all = self::unzipText($s1->absolute('GET', self::APP . 'api_export_structured.php')->body)->body;
		self::assertStringContainsString($this->s['folderName'], $all, 'Gegenprobe: S1-Export ohne workspace enthält den Aufgabenordner');
		self::assertStringContainsString($this->s['title'], self::ok($s2->call('GET', "/assignments/$aid"), 'S2 Aufgabe')['assignment']['title'] ?? '', 'Gegenprobe: S2 sieht den Titel');
		self::assertContains($this->s['wsId'], $this->listedOwn($s1->uid), 'Gegenprobe: Schüler-Workspace in listVisible (Speicher, Vorlagen, Schlüssel)');
		$this->teams->remove($l->uid, $fx['memberS1']);
		Teams::reset();
		self::resetRateLimits();
		self::assertNotNull(self::row('nextnotes_submissions', $sid1)['left_team_at'], 'Austrittsmarke gesetzt');
		// Exporte ohne workspace (ZIP „alle“, Anhänge): weder Titel noch Ordner noch Inhalt
		foreach (['api_export_structured.php', 'api_export_entries.php', 'api_export_attachments.php', 'api_export_structured.php?workspace=', 'api_export_entries.php?workspace='] as $p) {
			self::resetRateLimits();
			$r = $s1->absolute('GET', self::APP . $p);
			self::assertLessThan(500, $r->status, "Ex-Mitglied $p");
			self::assertSame('', $this->leak($r, $this->needles(false)), "Ex-Mitglied $p");
		}
		self::assertNotContains($this->s['wsId'], $this->listedOwn($s1->uid), 'Schüler-Workspace nach Austritt nicht in listVisible (Speicher, Vorlagen, Schlüssel)');
		// S1 ehemalig: eigene Abgabe, Aufgabe, Liste, Export, Schüler-Workspace → 404
		foreach (["/notes/$c1", "/notes/$c1/attachments/{$this->s['att']}", "/notes/$c1/attachments", "/submissions/$sid1", "/submissions/$sid1/export",
			"/assignments/$aid", "/notes/{$this->s['c2']}", "/submissions/{$this->s['sid2']}", "/notes/$tpl", "/assignments/$aid/submissions",
			'/notes' . self::q(['workspace' => $this->s['ws']])] as $path) {
			self::expectStatus($s1->call('GET', $path), 404, "Ex-Mitglied S1 GET $path");
		}
		self::expectStatus($s1->call('PATCH', "/notes/$c1", ['content' => '<p>neu</p>']), 404, 'Ex-Mitglied S1 schreibt eigene Abgabe');
		self::expectStatus($s1->call('POST', "/submissions/$sid1/unsubmit"), 404, 'Ex-Mitglied S1 zieht zurück');
		self::assertSame([], self::readPaths($s1, $c1, $this->s['att'], 404), 'Ex-Mitglied S1: alle Lesewege 404');
		$list = self::ok($s1->call('GET', '/assignments'), 'Ex-Mitglied Liste')['assignments'] ?? [];
		self::assertSame([], array_values(array_filter($list, static fn (array $a) => (int)$a['id'] === $aid)), 'Aufgabe nicht in der Liste');
		$errors = [];
		self::assertGreaterThan(150, $this->sweep($s1, $s1->uid, false, $errors), 'Ex-Mitglied-Sweep hat Anfragen gesendet');
		self::assertSame([], $errors, 'Ex-Mitglied-Sweep über alle Routen');
		foreach (['assignments.php', "index.php?note=$c1", 'notes_manager.php?all_workspaces=1', 'attachments_list.php'] as $p) {
			$r = self::page($s1, $p);
			self::assertSame('', $this->leak($r), "Ex-Mitglied Seite $p");
			self::assertLessThan(500, $r->status, "Ex-Mitglied Seite $p");
		}
		$ocs = '/ocs/v2.php/search/providers/nextnotes/search?format=json&term=' . rawurlencode($this->s['prefix']);
		self::assertSame('', $this->leak($s1->absolute('GET', $ocs)), 'Ex-Mitglied Unified Search');
		// Gegenprobe Lehrkraft: Abgabe, Kopie, Export weiter erreichbar
		self::assertStringContainsString($this->s['needles'][1], self::ok($l->call('GET', "/submissions/$sid1"), 'L liest Abgabe')['submission']['feedback'] ?? '');
		self::assertSame(200, $l->call('GET', "/notes/$c1")->status, 'L liest Kopie');
		self::assertSame(200, $l->call('GET', "/assignments/$aid/export")->status, 'L exportiert');
		$r = $s2->call('GET', '/notes/search' . self::q(['q' => $this->s['prefix']]));
		self::assertSame('', $this->leak($r), 'S2 Suche nach Austritt von S1');

		// eingefroren: S2 erreicht S1 nie, S1 (Marke) erreicht die eigene Abgabe weiter nicht, S2 (ohne Marke) liest die eigene
		self::assertSame(0, self::occ('app:disable', 'circles'));
		try {
			sleep(4);
			Teams::reset();
			self::resetRateLimits();
			$errors = [];
			$this->sweep($s2, $s1->uid, true, $errors);
			self::assertSame([], $errors, 'eingefroren: S2-Sweep');
			self::expectStatus($s2->call('GET', "/notes/$tpl"), [403, 404], 'eingefroren: S2 auf Vorlage');
			self::expectStatus($s1->call('GET', "/notes/$c1"), 404, 'eingefroren: Ex-Mitglied S1');
			self::expectStatus($s1->call('GET', "/submissions/$sid1"), 404, 'eingefroren: Ex-Mitglied S1 Abgabe');
			self::assertSame(200, $s2->call('GET', "/notes/{$this->s['c2']}")->status, 'eingefroren: S2 liest eigene Kopie');
		} finally {
			self::occ('app:enable', 'circles');
			sleep(4);
			Teams::reset();
		}

		// Wiedereintritt: Zugriff zurück, Marke beim Abgleich (GET /assignments) gelöscht
		$this->teams->addUser($fx['t'], $l->uid, $s1->uid);
		Teams::reset();
		self::resetRateLimits();
		self::assertSame(200, $s1->call('GET', "/notes/$c1")->status, 'Wiedereintritt: S1 liest eigene Abgabe');
		$list = self::ok($s1->call('GET', '/assignments'), 'Wiedereintritt Liste')['assignments'] ?? [];
		self::assertCount(1, array_filter($list, static fn (array $a) => (int)$a['id'] === $aid), 'Wiedereintritt: Aufgabe wieder in der Liste');
		self::assertNull(self::row('nextnotes_submissions', $sid1)['left_team_at'], 'Wiedereintritt: Marke gelöscht');
		self::assertStringContainsString($this->s['needles'][1], self::ok($s1->call('GET', "/submissions/$sid1"), 'Wiedereintritt Abgabe')['submission']['feedback'] ?? '');
	}

	// Team-Löschung nach Austritt (Hinweis 3): Marke → 404 auch verwaist; Wiedereintritt vor dem Abgleich (Hinweis 1): Löschung entfernt die Marke, Zugriff bleibt
	public function testTeamDeletedAfterLeaveAndRejoin(): void {
		$fx = $this->seedFixture();
		['s1' => $s1, 's2' => $s2, 'l' => $l, 't' => $t] = $fx;
		$sid2 = $this->s['sid2'];
		$memberS2 = null;
		$cm = Teams::cm();
		$cm->startSession($cm->getLocalFederatedUser($l->uid));
		try {
			foreach ($cm->getCircle($t)->getMembers() as $m) {
				if ($m->getUserId() === $s2->uid) {
					$memberS2 = $m->getId();
				}
			}
		} finally {
			$cm->stopSession();
		}
		self::assertNotNull($memberS2, 'Member-ID S2');
		$this->teams->remove($l->uid, $fx['memberS1']);
		$this->teams->remove($l->uid, $memberS2);
		$this->teams->addUser($t, $l->uid, $s2->uid);
		Teams::reset();
		self::assertNotNull(self::row('nextnotes_submissions', $sid2)['left_team_at'], 'S2: Marke vor dem Abgleich noch gesetzt');
		$this->teams->destroy($l->uid, $t, true);
		Server::get(\OCA\NextNotes\Service\WorkspaceService::class)->reset();
		self::resetRateLimits();
		self::assertSame('orphaned', self::teamState($t), 'verwaist');
		self::assertNull(self::row('nextnotes_submissions', $sid2)['left_team_at'], 'S2: Marke bei der Löschung entfernt (Mitglied)');
		self::assertNotNull(self::row('nextnotes_submissions', $this->s['sid1'])['left_team_at'], 'S1: Marke bleibt');
		self::assertSame(200, $s2->call('GET', "/notes/{$this->s['c2']}")->status, 'verwaist: S2 liest eigene Abgabe');
		self::assertSame(200, $s2->call('GET', "/submissions/$sid2")->status, 'verwaist: S2 Abgabe');
		self::expectStatus($s1->call('GET', "/notes/{$this->s['c1']}"), 404, 'verwaist: Ex-Mitglied S1 Notiz');
		self::expectStatus($s1->call('GET', "/submissions/{$this->s['sid1']}"), 404, 'verwaist: Ex-Mitglied S1 Abgabe');
		self::assertSame([], self::readPaths($s1, $this->s['c1'], $this->s['att'], 404), 'verwaist: Ex-Mitglied alle Lesewege 404');
		foreach (['api_export_structured.php', 'api_export_entries.php', 'api_export_attachments.php'] as $p) {
			self::resetRateLimits();
			self::assertSame('', $this->leak($s1->absolute('GET', self::APP . $p), $this->needles(false)), "verwaist: Ex-Mitglied $p");
		}
		self::assertSame(200, $l->call('GET', "/submissions/{$this->s['sid1']}")->status, 'verwaist: Besitzer liest S1-Abgabe');
	}

	/** @return int[] Workspace-IDs aus listVisible (Grundlage für Speicher, Vorlagen, Schlüssel) */
	private function listedOwn(string $uid): array {
		$ws = Server::get(\OCA\NextNotes\Service\WorkspaceService::class);
		$ws->reset();
		return array_map(static fn ($w) => (int)$w->getId(), $ws->listVisible($uid));
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
