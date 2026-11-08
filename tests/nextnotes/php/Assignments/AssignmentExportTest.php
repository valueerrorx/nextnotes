<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Db\Submission;
use OCA\NextNotes\Service\AssignmentExportService;
use OCA\NextNotes\Tests\Support\Response;
use OCP\IUserManager;
use OCP\Server;

// M6g (SPEC §9.1.6): ZIP der Abgaben – Ordnernamen, status.csv mit Formelschutz, gelöschte Konten, Feedback-Regeln, Rechte, Ratenlimit
final class AssignmentExportTest extends AssignmentTestCase {
	public function testTeacherZip(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 's1' => $s1, 's2' => $s2, 'x' => $x, 'n' => $n, 'aid' => $aid, 'mark' => $mark] = $fx;
		self::setColumn('nextnotes_assignments', $aid, 'max_points', 10);
		$subs = $fx['subs'];
		$sid1 = (int)$subs[$s1->uid]['id'];
		$sid2 = (int)$subs[$s2->uid]['id'];
		$sidX = (int)$subs[$x->uid]['id'];
		// Pfadzeichen und gleiche Namen; Formel im Namen für status.csv
		self::setColumn('nextnotes_submissions', $sid1, 'student_name', '../Eins/x');
		self::setColumn('nextnotes_submissions', $sid2, 'student_name', '=cmd|calc');
		self::ok($s1->call('POST', "/submissions/$sid1/submit"), 'S1 gibt ab');
		self::ok($l->call('POST', "/submissions/$sid1/return", ['feedback' => "Gut $mark", 'points' => 7]), 'Rückgabe');
		self::ok($s2->call('POST', "/submissions/$sid2/submit"), 'S2 gibt ab');
		self::ok($m->call('PUT', "/submissions/$sid2/feedback", ['feedback' => "Entwurf $mark"]), 'Entwurf an S2');

		$r = $l->call('GET', "/assignments/$aid/export");
		self::assertSame(200, $r->status, 'ZIP: ' . $r->describe());
		self::assertStringStartsWith('application/zip', (string)$r->header('Content-Type'));
		self::assertStringContainsString('attachment', (string)$r->header('Content-Disposition'));
		$z = self::unzip($r);
		$names = array_keys($z);
		foreach ($names as $name) {
			self::assertStringNotContainsString('..', $name, "kein Pfadausbruch: $name");
			self::assertStringStartsNotWith('/', $name);
		}
		$dirs = array_values(array_unique(array_filter(array_map(static fn ($p) => str_contains($p, '/') ? strstr($p, '/', true) : null, $names))));
		self::assertCount(3, $dirs, 'ein Ordner je Schüler: ' . implode(', ', $dirs));
		self::assertCount(1, array_filter($dirs, static fn ($d) => str_ends_with($d, '_' . $s1->uid)), 'S1-Ordner mit uid');
		$d1 = array_values(array_filter($dirs, static fn ($d) => str_ends_with($d, '_' . $s1->uid)))[0];
		self::assertStringContainsString("Gut $mark", $z["$d1/feedback.txt"] ?? '', 'feedback.txt S1');
		self::assertStringContainsString('Points: 7', $z["$d1/feedback.txt"] ?? '');
		self::assertNotEmpty(array_filter($names, static fn ($p) => str_starts_with($p, "$d1/attachments/")), 'Anhang der Kopie');
		self::assertNotEmpty(array_filter($names, static fn ($p) => str_starts_with($p, "$d1/") && str_ends_with($p, '.html')), 'Notiz als HTML');
		$html = implode('', array_filter($z, static fn ($v, $k) => str_starts_with($k, "$d1/") && str_ends_with($k, '.html'), ARRAY_FILTER_USE_BOTH));
		self::assertStringContainsString($mark, $html, 'Inhalt der Kopie');

		$csv = $z['status.csv'] ?? '';
		self::assertStringStartsWith('uid,name,status,submitted_at,points', $csv);
		self::assertStringContainsString("'=cmd|calc", $csv, 'Formelschutz');
		self::assertStringNotContainsString(',=cmd', $csv);
		self::assertMatchesRegularExpression('/' . preg_quote($s1->uid, '/') . ',.*,returned,\d{4}-\d\d-\d\dT[^,]+,7/', $csv, 'S1-Zeile');
		self::assertStringContainsString($x->uid . ',', $csv, 'X ohne Abgabe in status.csv');

		// Rechte: Schüler, Nicht-Mitglied → 404; Moderator mit review → 200
		self::resetRateLimits();
		self::assertSame(403, $s1->call('GET', "/assignments/$aid/export")->status, 'Schüler: kein Lehrkraft-ZIP (sieht die Aufgabe, darf nicht exportieren)');
		self::assertSame(404, $n->call('GET', "/assignments/$aid/export")->status, 'Fremder');
		self::assertSame(200, $m->call('GET', "/assignments/$aid/export")->status, 'Co-Lehrkraft');

		// gelöschtes Konto: Abgabe bleibt im ZIP (frühere uid)
		self::assertTrue(Server::get(IUserManager::class)->get($s2->uid)->delete(), 'Kontolöschung S2');
		self::resetRateLimits();
		$z3 = self::unzip($l->call('GET', "/assignments/$aid/export"));
		self::assertNotEmpty(array_filter(array_keys($z3), static fn ($p) => str_contains($p, '_' . $s2->uid . '/')), 'Abgabe gelöschtes Konto enthalten');
		self::assertStringContainsString($s2->uid, $z3['status.csv'] ?? '');
	}

	public function testStudentExportAndRateLimit(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 's1' => $s1, 's2' => $s2, 'aid' => $aid, 'mark' => $mark] = $fx;
		$sid1 = (int)$fx['subs'][$s1->uid]['id'];
		$sid2 = (int)$fx['subs'][$s2->uid]['id'];
		self::resetRateLimits();
		self::ok($s1->call('POST', "/submissions/$sid1/submit"), 'abgeben');
		self::ok($m->call('PUT', "/submissions/$sid1/feedback", ['feedback' => "Entwurf $mark"]), 'Entwurf');
		$z = self::unzip($s1->call('GET', "/submissions/$sid1/export"));
		self::assertArrayNotHasKey('feedback.txt', $z, 'Entwurf erst nach Rückgabe');
		self::assertNotEmpty(array_filter(array_keys($z), static fn ($p) => str_ends_with($p, '.html')), 'eigene Notiz');
		self::ok($l->call('POST', "/submissions/$sid1/return", ['feedback' => "Fertig $mark"]), 'Rückgabe');
		$z = self::unzip($s1->call('GET', "/submissions/$sid1/export"));
		self::assertStringContainsString("Fertig $mark", $z['feedback.txt'] ?? '', 'Feedback nach Rückgabe');
		self::assertSame(404, $s1->call('GET', "/submissions/$sid2/export")->status, 'fremde Abgabe');
		self::assertSame(404, $fx['n']->call('GET', "/submissions/$sid1/export")->status, 'Fremder');
		self::assertSame(200, $l->call('GET', "/submissions/$sid2/export")->status, 'Lehrkraft einzeln');

		// 10/min je Benutzer
		self::resetRateLimits();
		$codes = [];
		for ($i = 0; $i < 11; $i++) {
			$codes[] = $l->call('GET', "/assignments/$aid/export")->status;
		}
		self::assertSame(array_fill(0, 10, 200), array_slice($codes, 0, 10), 'erste 10');
		self::assertSame(429, $codes[10], '11. Export');
		self::resetRateLimits();
	}

	// gleicher Name und gleiche uid (z. B. frühere uid) → _<Abgabe-ID>, Pfadzeichen ersetzt
	public function testDirNames(): void {
		$m = new \ReflectionMethod(AssignmentExportService::class, 'dirName');
		$used = [];
		$mk = static function (int $id, string $name, ?string $uid, ?string $former = null): Submission {
			$s = new Submission();
			$s->setId($id);
			$s->setStudentName($name);
			$s->setStudentUid($uid);
			$s->setStudentUidFormer($former);
			return $s;
		};
		$a = $m->invokeArgs(null, [$mk(5, '../Eins/x', 'u1'), &$used]);
		$b = $m->invokeArgs(null, [$mk(6, '../Eins/x', null, 'u1'), &$used]);
		$c = $m->invokeArgs(null, [$mk(7, '../eins/X', 'u1'), &$used]);
		foreach ([$a, $b, $c] as $d) {
			self::assertStringNotContainsString('..', $d);
			self::assertSame(1, substr_count($d, '/'), "nur Ordner-Trenner: $d");
		}
		self::assertSame(3, count(array_unique([strtolower($a), strtolower($b), strtolower($c)])), "$a $b $c");
		self::assertStringEndsWith('_6/', $b);
		self::assertStringEndsWith('_7/', $c);
	}

	public function testCsvGuard(): void {
		$csv = AssignmentExportService::csv([['=1+1', '+a', '-b', '@c', "\td", "\re", 'ok', '', 'a=b']]);
		self::assertSame("'=1+1,'+a,'-b,'@c,\"'\td\",\"'\re\",ok,,a=b\n", $csv);
	}

	/** @return array<string, string> Eintrag => Inhalt */
	private static function unzip(Response $r): array {
		self::assertSame(200, $r->status, 'ZIP: ' . substr($r->describe(), 0, 300));
		$path = tempnam(sys_get_temp_dir(), 'nnzip');
		file_put_contents($path, $r->body);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path) === true, 'ZIP lesbar');
		$out = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$out[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		unlink($path);
		return $out;
	}
}
