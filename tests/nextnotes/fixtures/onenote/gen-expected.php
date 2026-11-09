<?php
// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M9c: Golden-Ausgabe des OneNote-Konverters (Host-PHP, OCP-Schnittstellen aus .ref). Aufruf: php gen-expected.php <repo> > expected.json
// Erzeugte Datei vor dem Einchecken lesen: sie ist die Erwartung, nicht nur ein Abzug
// html in eigener Serialisierung (Golden::canonical): gleich unter libxml 2.9.14 (NC-Image) und 2.15 (Host); Gegenprobe im Image:
// docker run --rm -v <repo>:/r:ro --entrypoint php nextcloud:35 /r/tests/nextnotes/fixtures/onenote/gen-expected.php /r | diff - expected.json
declare(strict_types=1);

$repo = rtrim($argv[1] ?? '', '/');
if (!is_dir($repo . '/nextnotes/lib') || !is_dir($repo . '/.ref/nextcloud-server/lib/public')) {
	fwrite(STDERR, "Aufruf: php gen-expected.php <repo> (mit .ref/nextcloud-server)\n");
	exit(2);
}
spl_autoload_register(static function (string $class) use ($repo): void {
	foreach (['OCA\\NextNotes\\' => $repo . '/nextnotes/lib/', 'OCP\\' => $repo . '/.ref/nextcloud-server/lib/public/'] as $prefix => $dir) {
		if (str_starts_with($class, $prefix) && is_file($file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php')) {
			require $file;
		}
	}
});
require __DIR__ . '/golden.php';

$sanitizer = new \OCA\NextNotes\Service\HtmlSanitizer(new class extends \OCA\NextNotes\Service\EmbedDomainService {
	public function __construct() {
	}

	public function domains(): array {
		return [];
	}
});
echo json_encode(\OCA\NextNotes\Tests\OneNote\Golden::all(new \OCA\NextNotes\OneNote\Convert\PageConverter($sanitizer), new \OCA\NextNotes\Tests\OneNote\GoldenL10N(), __DIR__),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
