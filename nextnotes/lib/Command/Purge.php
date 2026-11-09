<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Command;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\LockMapper;
use OCA\NextNotes\Db\MentionHandledMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\Db\OneNoteItemMapper;
use OCA\NextNotes\Db\ReminderMapper;
use OCA\NextNotes\Db\SnapshotMapper;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Db\TeamMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

// occ nextnotes:purge (SPEC §8, ADR 0008 Nachtrag M7b): löscht alle Daten der App inkl. Abgaben; vor occ app:remove
class Purge extends Command {
	public const TABLES = [
		WorkspaceMapper::TABLE, TeamMapper::TABLE, WsShareMapper::TABLE, FolderMapper::TABLE, NoteMapper::TABLE,
		NoteTagMapper::TABLE, AttachmentMapper::TABLE, SnapshotMapper::TABLE, ReminderMapper::TABLE, LockMapper::TABLE,
		MentionHandledMapper::TABLE, AssignmentMapper::TABLE, SubmissionMapper::TABLE,
		OneNoteImportMapper::TABLE, OneNoteItemMapper::TABLE,
	];
	// vom Kern verwaltet: ohne sie gilt die App als nicht installiert und app:remove scheitert
	public const CORE_KEYS = ['enabled', 'installed_version', 'types'];

	public function __construct(
		private IDBConnection $db,
		private IAppData $appData,
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private INotificationManager $notifications,
		private IJobList $jobs,
		private IConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextnotes:purge')
			->setDescription('Delete ALL Next Notes data of all users and teams, including submissions under retention. Run before occ app:remove; without --force only counts')
			->addOption('force', null, InputOption::VALUE_NONE, 'Really delete (asks for the app ID)')
			->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'App ID as confirmation without prompt (with --force)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->config->getSystemValueBool('maintenance', false)) {
			$output->writeln('<comment>Warning: maintenance mode is off (occ app commands do not run in maintenance mode). Users can still write Next Notes data while purging; run it when nobody uses the app, e.g. after occ app:enable nextnotes --groups admin.</comment>');
		}
		$this->report($output);
		if (!$input->getOption('force')) {
			$output->writeln('Nothing was deleted. Use --force to delete everything listed above.');
			return 0;
		}
		if (!$this->confirmed($input, $output)) {
			$output->writeln('<error>Not confirmed; nothing was deleted.</error>');
			return 1;
		}
		foreach (self::TABLES as $table) {
			$this->db->getQueryBuilder()->delete($table)->executeStatement();
		}
		foreach ($this->appFolders() as $folder) {
			$folder->delete();
		}
		$n = $this->notifications->createNotification();
		$n->setApp(Application::APP_ID);
		$this->notifications->markProcessed($n);
		foreach ($this->jobClasses() as $class) {
			$this->jobs->remove($class);
		}
		$this->userConfig->deleteApp(Application::APP_ID);
		foreach ($this->ownAppKeys() as $key) {
			$this->appConfig->deleteKey(Application::APP_ID, $key);
		}
		$output->writeln('All Next Notes data deleted. Now run: occ app:remove ' . Application::APP_ID);
		return 0;
	}

	private function report(OutputInterface $output): void {
		$output->writeln('Next Notes data:');
		foreach (self::TABLES as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))->from($table);
			$output->writeln(sprintf('  table %s: %d row(s)', $table, (int)$qb->executeQuery()->fetchOne()));
		}
		$folders = array_map(static fn (ISimpleFolder $f) => $f->getName(), $this->appFolders());
		$output->writeln('  app data folders: ' . ($folders === [] ? '-' : implode(', ', $folders)));
		$output->writeln('  users with settings: ' . count($this->userConfig->getUserIds(Application::APP_ID)));
		$output->writeln('  app settings: ' . count($this->ownAppKeys()));
		$jobs = 0;
		foreach ($this->jobClasses() as $class) {
			foreach ($this->jobs->getJobsIterator($class, null, 0) as $job) {
				$jobs++;
			}
		}
		$output->writeln('  background jobs: ' . $jobs);
		$output->writeln('  notifications of the app: all (removed with --force)');
	}

	private function confirmed(InputInterface $input, OutputInterface $output): bool {
		$given = $input->getOption('confirm');
		if ($given !== null) {
			return $given === Application::APP_ID;
		}
		if (!$input->isInteractive()) {
			$output->writeln('With --no-interaction pass --confirm=' . Application::APP_ID . '.');
			return false;
		}
		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');
		$output->writeln('<comment>This deletes all notes, workspaces, assignments and submissions of all users and teams. It cannot be undone.</comment>');
		return $helper->ask($input, $output, new Question('Type the app ID (' . Application::APP_ID . ') to confirm: ')) === Application::APP_ID;
	}

	/** @return ISimpleFolder[] */
	private function appFolders(): array {
		try {
			return $this->appData->getDirectoryListing();
		} catch (FilesNotFoundException) {
			return [];
		}
	}

	/** @return string[] */
	private function ownAppKeys(): array {
		return array_values(array_diff($this->appConfig->getKeys(Application::APP_ID), self::CORE_KEYS));
	}

	/** @return list<class-string> alle Job-Klassen der App (lib/BackgroundJob) */
	public static function jobClasses(): array {
		$out = [];
		foreach ((array)glob(dirname(__DIR__) . '/BackgroundJob/*.php') as $file) {
			$out[] = 'OCA\\NextNotes\\BackgroundJob\\' . basename((string)$file, '.php');
		}
		return $out;
	}
}
