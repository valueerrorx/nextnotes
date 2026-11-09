<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Command;

use OCA\NextNotes\OneNote\ConnectService;
use OCA\NextNotes\Service\UserExportService;
use OCP\ITempManager;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// occ nextnotes:export-user <uid> <target.zip> (SPEC §8, ADR 0008): Datenauskunft ohne fremde Daten
class ExportUser extends Command {
	public function __construct(
		private UserExportService $export,
		private ITempManager $temp,
		private IUserManager $users,
		private ConnectService $oneNote,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextnotes:export-user')
			->setDescription('Export the Next Notes data of a user (own workspaces, own submissions with feedback once returned, OneNote import reports) into a ZIP file')
			->addArgument('uid', InputArgument::REQUIRED, 'User ID')
			->addArgument('target', InputArgument::REQUIRED, 'Path of the ZIP file to create (must not exist)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('uid');
		$target = (string)$input->getArgument('target');
		if ($uid === '') {
			$output->writeln('<error>Missing user ID.</error>');
			return 1;
		}
		// echte UID bei abweichender Schreibweise; gelöschtes Konto: Eingabe wie angegeben
		$uid = $this->users->get($uid)?->getUID() ?? $uid;
		if (file_exists($target)) {
			$output->writeln('<error>Target ' . $target . ' already exists.</error>');
			return 1;
		}
		// OneNote-Importe mit Bericht (Plan M9 §7, Thomas 2026-11-08); ohne Token, state, ms_account, Cursor und Items
		$oneNote = $this->oneNote->exportRows($uid);
		if (!$this->export->hasData($uid) && $oneNote === []) {
			$output->writeln('No Next Notes data for ' . $uid . ', nothing written.');
			return 0;
		}
		$zip = new \ZipArchive();
		if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
			$output->writeln('<error>Cannot create ' . $target . '.</error>');
			return 1;
		}
		$staged = [];
		$parts = 0;
		try {
			$parts = $this->export->export($uid, function (string $path, $stream) use ($zip, &$staged): void {
				// Teile als Datei ablegen; ZipArchive liest sie erst beim Schließen
				$tmp = $this->temp->getTemporaryFile('.zip');
				$out = $tmp !== false ? fopen($tmp, 'wb') : false;
				if ($out === false) {
					fclose($stream);
					throw new \RuntimeException('Cannot stage export part');
				}
				$ok = stream_copy_to_stream($stream, $out);
				fclose($stream);
				fclose($out);
				if ($ok === false) {
					throw new \RuntimeException('Cannot stage export part');
				}
				$staged[] = $tmp;
				$zip->addFile($tmp, $path);
			});
			if ($oneNote !== []) {
				$zip->addFromString('onenote-imports.json', (string)json_encode(['imports' => $oneNote], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
				$parts++;
			}
			if (!$zip->close()) {
				throw new \RuntimeException('Cannot write ' . $target);
			}
		} catch (\Throwable $e) {
			@$zip->unchangeAll();
			@$zip->close();
			@unlink($target);
			$output->writeln('<error>Export failed: ' . $e->getMessage() . '</error>');
			return 1;
		} finally {
			foreach ($staged as $tmp) {
				@unlink($tmp);
			}
		}
		$output->writeln(sprintf('Exported %d part(s) for %s to %s.', $parts, $uid, $target));
		return 0;
	}
}
