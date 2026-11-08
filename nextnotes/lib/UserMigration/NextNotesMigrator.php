<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\UserMigration;

use OCA\NextNotes\Service\UserExportService;
use OCP\IL10N;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\IMigrator;
use OCP\UserMigration\ISizeEstimationMigrator;
use OCP\UserMigration\TMigratorBasicVersionHandling;
use OCP\UserMigration\UserMigrationException;
use Symfony\Component\Console\Output\OutputInterface;

// user_migration (ADR 0008/0009): Export wie occ nextnotes:export-user unter nextnotes/, Import wird übersprungen
class NextNotesMigrator implements IMigrator, ISizeEstimationMigrator {
	use TMigratorBasicVersionHandling;

	public const PATH = 'nextnotes/';

	public function __construct(
		private UserExportService $export,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return 'nextnotes';
	}

	public function getDisplayName(): string {
		return $this->l->t('Next Notes');
	}

	public function getDescription(): string {
		return $this->l->t('Own notes and workspaces, own submissions with feedback (export only)');
	}

	public function getEstimatedExportSize(IUser $user): int|float {
		return (int)ceil($this->export->estimateBytes($user->getUID(), true) / 1024);
	}

	public function export(IUser $user, IExportDestination $exportDestination, OutputInterface $output): void {
		$output->writeln('Exporting Next Notes data…');
		try {
			$this->export->export($user->getUID(), static function (string $path, $stream) use ($exportDestination): void {
				try {
					$exportDestination->addFileAsStream(self::PATH . $path, $stream);
				} finally {
					if (is_resource($stream)) {
						fclose($stream);
					}
				}
			}, true);
		} catch (UserMigrationException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new UserMigrationException('Could not export Next Notes data', 0, $e);
		}
	}

	// true: false ließe den gesamten Kontoimport scheitern (M7-plan r2 Hinweis 1)
	public function canImport(IImportSource $importSource): bool {
		return true;
	}

	public function import(IUser $user, IImportSource $importSource, OutputInterface $output): void {
		$output->writeln('Next Notes: import not supported, skipped');
	}
}
