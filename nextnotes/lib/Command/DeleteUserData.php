<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Command;

use OCA\NextNotes\Service\UserDataService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

// occ nextnotes:delete-user-data <uid> (SPEC §8, ADR 0008): nur deaktivierte oder gelöschte Konten, Ablauf wie Kontolöschung
class DeleteUserData extends Command {
	public function __construct(
		private UserDataService $userData,
		private IUserManager $users,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextnotes:delete-user-data')
			->setDescription('Delete the Next Notes data of a disabled or deleted account like an account deletion (submissions stay for the teachers)')
			->addArgument('uid', InputArgument::REQUIRED, 'User ID')
			->addOption('user-deleted', null, InputOption::VALUE_NONE, 'Confirm that an account which the user backend does not find is really deleted (LDAP/SAML)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('uid');
		if ($uid === '') {
			$output->writeln('<error>Missing user ID.</error>');
			return 1;
		}
		$user = $this->users->get($uid);
		// Backend findet Konten ohne Rücksicht auf Groß-/Kleinschreibung; Daten tragen die echte UID
		$uid = $user?->getUID() ?? $uid;
		if ($user !== null && $user->isEnabled()) {
			$output->writeln('<error>Account ' . $uid . ' is active. Disable or delete the account first; nothing was changed.</error>');
			return 1;
		}
		$counts = $this->userData->dataCounts($uid);
		if ($user === null && $counts === []) {
			$output->writeln('User ' . $uid . ' not found and no Next Notes data left; nothing to do.');
			return 0;
		}
		if ($user === null && !$this->confirmMissing($uid, $counts, $input, $output)) {
			return 1;
		}
		$workspaces = $this->userData->deleteForAdmin($uid, $user?->getDisplayName());
		$output->writeln(sprintf('Next Notes data of %s deleted (%d private workspace(s)); submissions stay for the teachers.', $uid, $workspaces));
		return 0;
	}

	/** @param array<string, int> $counts */
	private function confirmMissing(string $uid, array $counts, InputInterface $input, OutputInterface $output): bool {
		$output->writeln('<comment>User ' . $uid . ' is unknown to the user backend, but Next Notes still holds data:</comment>');
		foreach ($counts as $where => $n) {
			$output->writeln("  $where: $n");
		}
		$output->writeln('<comment>If the user backend (LDAP/SAML) is only unreachable, this deletes the data of an active account.</comment>');
		if ($input->getOption('user-deleted')) {
			return true;
		}
		if (!$input->isInteractive()) {
			$output->writeln('<error>Run again with --user-deleted once you are sure the account is deleted; nothing was changed.</error>');
			return false;
		}
		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');
		$answer = $helper->ask($input, $output, new Question('Type the user ID to confirm: '));
		if ($answer !== $uid) {
			$output->writeln('<error>Not confirmed; nothing was changed.</error>');
			return false;
		}
		return true;
	}
}
