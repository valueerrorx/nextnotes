<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Command;

use OCA\NextNotes\Db\Assignment;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Service\AssignmentOwnership;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// occ nextnotes:assignment:transfer <assignment-id|--all-unowned> <uid> [--force] (SPEC §9.7, ADR 0008 c)
class AssignmentTransfer extends Command {
	public function __construct(
		private AssignmentMapper $assignments,
		private AssignmentOwnership $ownership,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextnotes:assignment:transfer')
			->setDescription('Make a user responsible for an assignment (active team: the user must be a moderator or higher there)')
			->addArgument('assignment-id', InputArgument::OPTIONAL, 'Assignment ID (omit with --all-unowned)')
			->addArgument('uid', InputArgument::OPTIONAL, 'New responsible user')
			->addOption('all-unowned', null, InputOption::VALUE_NONE, 'All assignments without a responsible user')
			->addOption('force', null, InputOption::VALUE_NONE, 'Allow any existing user for assignments of deleted, frozen or unknown teams');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$all = (bool)$input->getOption('all-unowned');
		$first = $input->getArgument('assignment-id');
		$uid = $input->getArgument('uid');
		if ($all) {
			// mit --all-unowned ist das einzige Argument die UID
			[$uid, $extra] = $uid === null ? [$first, null] : [$uid, $first];
			if ($extra !== null) {
				$output->writeln('<error>Use either an assignment ID or --all-unowned.</error>');
				return 1;
			}
		}
		if (!is_string($uid) || $uid === '') {
			$output->writeln('<error>Missing user ID.</error>');
			return 1;
		}
		if ($all) {
			$list = $this->assignments->findOwned(null);
		} else {
			$id = filter_var($first, FILTER_VALIDATE_INT);
			if ($id === false || $id <= 0) {
				$output->writeln('<error>Missing or invalid assignment ID.</error>');
				return 1;
			}
			try {
				$list = [$this->assignments->find($id)];
			} catch (DoesNotExistException) {
				$output->writeln('<error>Assignment ' . $id . ' not found.</error>');
				return 1;
			}
		}
		$failed = 0;
		foreach ($list as $a) {
			$failed += $this->transfer($a, $uid, (bool)$input->getOption('force'), $output) ? 0 : 1;
		}
		$output->writeln(sprintf('%d of %d assignments transferred.', count($list) - $failed, count($list)));
		return $failed === 0 ? 0 : 1;
	}

	private function transfer(Assignment $a, string $uid, bool $force, OutputInterface $output): bool {
		try {
			$r = $this->ownership->adminTransfer($a, $uid, $force);
			$output->writeln(sprintf('Assignment %d (team %s): %s → %s', (int)$a->getId(), $r['state'], $r['from'] ?? '-', $r['to']));
			return true;
		} catch (\InvalidArgumentException $e) {
			$output->writeln(sprintf('<error>Assignment %d: %s</error>', (int)$a->getId(), $e->getMessage()));
			return false;
		}
	}
}
