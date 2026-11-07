<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Command;

use OCA\NextNotes\Service\TeamCleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// occ nextnotes:team:orphan-check (SPEC §8): Existenz aller Teams prüfen; fehlende sofort verwaisen, nie löschen
class TeamOrphanCheck extends Command {
	public function __construct(
		private TeamCleanupService $cleanup,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextnotes:team:orphan-check')
			->setDescription('Check whether the teams used by Next Notes still exist; missing teams are marked as deleted (their workspaces are deleted after 30 days)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list, change nothing')
			->addOption('force', null, InputOption::VALUE_NONE, 'Also mark teams as deleted when more teams are missing than the safety limit allows');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		$force = (bool)$input->getOption('force');
		$result = $this->cleanup->check($dryRun, $force);
		if ($result['rows'] !== []) {
			$table = new Table($output);
			$table->setHeaders(['Team', 'Name', 'State', 'Result', $dryRun ? 'Would' : 'Action']);
			foreach ($result['rows'] as $r) {
				$table->addRow([$r['team'], $r['name'], $r['state'], $r['result'], $r['action']]);
			}
			$table->render();
		}
		if ($result['error']) {
			$output->writeln('<error>Teams are not available or every team lookup failed; nothing was changed. Check "occ circles:check".</error>');
			return 1;
		}
		$errors = count(array_filter($result['rows'], static fn (array $r) => $r['result'] === TeamCleanupService::ERROR));
		if ($errors > 0) {
			$output->writeln(sprintf('<comment>%d teams could not be checked (result "error") and were skipped.</comment>', $errors));
		}
		$output->writeln(sprintf('%d active teams checked, %d missing.', $result['checked'], $result['missing']));
		if ($result['suspicious'] && !$force) {
			$output->writeln('<comment>More teams are missing than the safety limit allows; none were marked as deleted. Check "occ circles:check"; use --force if the teams really were deleted.</comment>');
			return 2;
		}
		if ($dryRun) {
			$output->writeln('Dry run: nothing was changed.');
		}
		return 0;
	}
}
