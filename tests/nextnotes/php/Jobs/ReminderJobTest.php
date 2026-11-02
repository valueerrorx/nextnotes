<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Jobs;

use OCA\NextNotes\BackgroundJob\ReminderJob;
use OCA\NextNotes\Db\ReminderMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCA\NextNotes\Notification\Notifier;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\ReminderService;
use OCA\NextNotes\Service\TaskService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use OCP\Server;
use Psr\Log\LoggerInterface;

// SPEC §5 ReminderJob, §6, §13 M2.7; Plan Done M2c 3: Zeitmock, genau eine Benachrichtigung, Wiederholung, Leserecht/Entzug
final class ReminderJobTest extends ApiTestCase {
	private int $now = 0;
	/** Basiszeit weit in der Zukunft: echter Cron im Container sendet diese Erinnerungen nie */
	private int $t0 = 0;

	protected function setUp(): void {
		parent::setUp();
		// Wie cron.php (lädt alle Apps): ohne geladene notifications-App verwirft IManager::notify still
		Server::get(IAppManager::class)->loadApp('notifications');
		$this->t0 = (intdiv(time(), 60) + 60 * 24 * 365 * 10) * 60;
	}

	private function runJob(int $now): void {
		$this->now = $now;
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$service = new ReminderService(
			Server::get(ReminderMapper::class), Server::get(AccessService::class), Server::get(TaskService::class),
			Server::get(INotificationManager::class), $time, Server::get(IDateTimeZone::class), Server::get(LoggerInterface::class),
		);
		$job = new ReminderJob($time, $service);
		$job->start(Server::get(IJobList::class));
	}

	private static function iso(int $ts): string {
		return gmdate('Y-m-d\TH:i:s\Z', $ts);
	}

	/** Zeilen in oc_notifications (App nextnotes) für Empfänger + Notiz */
	private static function notifications(string $uid, int $noteId, ?string $subject = null): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('note')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$noteId)));
		if ($subject !== null) {
			$qb->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($subject)));
		}
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<array<string, mixed>> Erinnerungszeilen eines Empfängers zu einer Notiz */
	private static function reminderRows(string $uid, int $noteId): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from('nextnotes_reminders')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		return $qb->executeQuery()->fetchAllAssociative();
	}

	private static function mentionRows(string $uid, int $noteId): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('nextnotes_mentions_handled')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private static function notification(string $uid, int $noteId, string $subject = 'reminder'): INotification {
		$n = Server::get(INotificationManager::class)->createNotification();
		$n->setApp('nextnotes')->setUser($uid)->setDateTime(new \DateTime())
			->setObject('note', (string)$noteId)->setSubject($subject, ['message' => 'm', 'task_id' => null]);
		return $n;
	}

	private static function assertAlreadyProcessed(string $uid, int $noteId, string $what): void {
		try {
			Server::get(Notifier::class)->prepare(self::notification($uid, $noteId), 'en');
			self::fail("$what: prepare ohne Leserecht liefert Anzeige statt AlreadyProcessedException");
		} catch (AlreadyProcessedException) {
			self::assertTrue(true);
		}
	}

	/** Workspace von $owner an $target freigeben; liefert [Name, ID] */
	private static function sharedWorkspace(ApiClient $owner, string $target): array {
		[$name, $id] = self::newWorkspace($owner, 'Geteilt');
		Server::get(WorkspaceService::class)->share($owner->uid, $id, $target);
		return [$name, $id];
	}

	public function testJobRegistered(): void {
		self::assertTrue(Server::get(IJobList::class)->has(ReminderJob::class, null), 'ReminderJob nicht registriert');
	}

	public function testDueReminderNotifiesExactlyOnce(): void {
		$a = $this->createUser('rj');
		[$ws] = self::newWorkspace($a, 'Rem');
		$due = self::newNote($a, $ws, 'Fällig', '<p>x</p>');
		$later = self::newNote($a, $ws, 'Später', '<p>y</p>');
		self::ok($a->call('POST', "/notes/$due/reminder", ['reminder_at' => self::iso($this->t0)]), 'Erinnerung fällig');
		self::ok($a->call('POST', "/notes/$later/reminder", ['reminder_at' => self::iso($this->t0 + 3600)]), 'Erinnerung später');
		self::assertSame(gmdate('Y-m-d H:i:s', $this->t0), self::ok($a->call('GET', "/notes/$due/reminder"), 'GET')['reminder_at']);

		$this->runJob($this->t0 - 1);
		self::assertSame(0, self::notifications($a->uid, $due), 'vor Fälligkeit gesendet');

		$this->runJob($this->t0 + 60);
		self::assertSame(1, self::notifications($a->uid, $due, 'reminder'), 'fällige Erinnerung: genau eine Benachrichtigung');
		self::assertSame(0, self::notifications($a->uid, $later), 'nicht fällige Erinnerung gesendet');
		self::assertNull(self::ok($a->call('GET', "/notes/$due/reminder"), 'GET nach Versand')['reminder_at'], 'SPEC §2.2: nach Versand null');

		$this->runJob($this->t0 + 120);
		self::assertSame(1, self::notifications($a->uid, $due), 'zweiter Joblauf sendet erneut');
		self::assertSame(0, self::notifications($a->uid, $later));

		// Notifier: Betreff de/en, Link auf die Notiz
		$n = Server::get(Notifier::class)->prepare(self::notification($a->uid, $due), 'en');
		self::assertSame('Reminder: Fällig', $n->getParsedSubject());
		self::assertStringContainsString('note=' . $due, $n->getLink());
		$n = Server::get(Notifier::class)->prepare(self::notification($a->uid, $due), 'de');
		self::assertSame('Erinnerung: Fällig', $n->getParsedSubject());
		$n = Server::get(Notifier::class)->prepare(self::notification($a->uid, $due, 'task_reminder'), 'de');
		self::assertSame('Aufgaben-Erinnerung in Fällig', $n->getParsedSubject());
		$this->expectException(UnknownNotificationException::class);
		Server::get(Notifier::class)->prepare(self::notification($a->uid, $due, 'unbekannt'), 'en');
	}

	public function testRecurringReminderCreatesNextOccurrence(): void {
		$a = $this->createUser('rr');
		[$ws] = self::newWorkspace($a, 'Rec');
		$n = self::newNote($a, $ws, 'Täglich', '<p>x</p>');
		self::ok($a->call('POST', "/notes/$n/reminder", ['reminder_at' => self::iso($this->t0), 'recurrence' => '1d']), 'Erinnerung 1d');

		$this->runJob($this->t0 + 60);
		self::assertSame(1, self::notifications($a->uid, $n));
		$pending = array_values(array_filter(self::reminderRows($a->uid, $n), static fn ($r) => $r['sent_at'] === null));
		self::assertCount(1, $pending, 'Wiederholung: genau eine nächste Zeile');
		self::assertSame($this->t0 + 86400, (int)$pending[0]['trigger_at']);
		self::assertSame(gmdate('Y-m-d H:i:s', $this->t0 + 86400), self::ok($a->call('GET', "/notes/$n/reminder"), 'GET')['reminder_at']);

		$this->runJob($this->t0 + 120);
		self::assertSame(1, self::notifications($a->uid, $n), 'nächste Zeile vor Fälligkeit gesendet');
		$this->runJob($this->t0 + 86400 + 60);
		self::assertSame(2, self::notifications($a->uid, $n), 'zweite Fälligkeit nicht gesendet');
	}

	// B23: Aufgaben-IDs mit voller Genauigkeit; wiederkehrende Aufgaben-Erinnerung verschiebt dueAt der richtigen Aufgabe
	public function testRecurringTaskReminderB23(): void {
		$a = $this->createUser('rt');
		[$ws] = self::newWorkspace($a, 'Tasks');
		$tl = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Liste', 'type' => 'tasklist', 'content' => '[]', 'workspace' => $ws]), 'tasklist')['note']['id'];
		$due = gmdate('Y-m-d\TH:i', $this->t0);
		$task = self::ok($a->call('POST', "/notes/$tl/tasks", ['text' => 'Woche', 'due_at' => $due, 'reminder' => true, 'recurrence' => '1w']), 'Aufgabe')['task'];
		$other = self::ok($a->call('POST', "/notes/$tl/tasks", ['text' => 'Andere', 'due_at' => $due]), 'Aufgabe 2')['task'];
		$tid = TaskService::idString($task['id']);
		self::assertGreaterThan(14, strlen(preg_replace('/\D/', '', $tid)), "Aufgaben-ID $tid hat ≤ 14 Stellen, B23 nicht abgedeckt");
		$rows = self::reminderRows($a->uid, $tl);
		self::assertCount(1, $rows, 'Aufgabe mit reminder=true legt Erinnerung an');
		self::assertSame($tid, (string)$rows[0]['task_id']);
		self::assertSame($this->t0, (int)$rows[0]['trigger_at']);

		$this->runJob($this->t0 + 60);
		self::assertSame(1, self::notifications($a->uid, $tl, 'task_reminder'));
		$tasks = array_column(self::ok($a->call('GET', "/notes/$tl/tasks"), 'tasks')['tasks'], null, 'text');
		self::assertSame(gmdate('Y-m-d\TH:i', $this->t0 + 7 * 86400), $tasks['Woche']['dueAt'], 'B23: dueAt nicht verschoben');
		self::assertSame($due, $tasks['Andere']['dueAt'], 'falsche Aufgabe verschoben');
		self::assertSame(TaskService::idString($other['id']), TaskService::idString($tasks['Andere']['id']));
		$pending = array_values(array_filter(self::reminderRows($a->uid, $tl), static fn ($r) => $r['sent_at'] === null));
		self::assertCount(1, $pending);
		self::assertSame($this->t0 + 7 * 86400, (int)$pending[0]['trigger_at']);

		// erledigt → keine weitere Wiederholung
		self::ok($a->call('PATCH', "/notes/$tl/tasks/$tid", ['completed' => true]), 'erledigt');
		$this->runJob($this->t0 + 7 * 86400 + 60);
		self::assertSame(1, self::notifications($a->uid, $tl), 'erledigte Aufgabe erinnert');
	}

	public function testReminderOnForeignNoteImpossible(): void {
		$a = $this->createUser('ra');
		$b = $this->createUser('rb');
		[$ws] = self::newWorkspace($a, 'Privat');
		$n = self::newNote($a, $ws, 'Geheim A', '<p>x</p>');
		$r = $b->call('POST', "/notes/$n/reminder", ['reminder_at' => self::iso($this->t0)]);
		self::assertSame(404, $r->status, $r->describe());
		self::assertStringNotContainsString('Geheim A', $r->body);
		self::assertSame(404, $b->call('POST', "/notes/$n/task-reminder", ['task_id' => '1', 'reminder_at' => self::iso($this->t0)])->status);
		self::assertSame([], self::reminderRows($b->uid, $n));
	}

	// Freigabe-Entzug über unshare: fällige Zeile weg, Joblauf sendet nichts, gesendete Benachrichtigung verschwindet
	public function testUnshareRemovesRemindersAndMentionState(): void {
		$a = $this->createUser('ua');
		$b = $this->createUser('ub');
		[$ws, $wsId] = self::sharedWorkspace($a, $b->uid);
		$n1 = self::newNote($a, $ws, 'Eins', '<p>x</p>');
		$n2 = self::newNote($a, $ws, 'Zwei', "<p>Hallo @{$b->uid} bitte</p>");
		$wsB = self::ok($b->call('GET', '/workspaces'), 'B workspaces');
		$keyB = null;
		foreach ($wsB['workspaces'] as $w) {
			if (str_starts_with($w['name'], $ws)) {
				$keyB = $w['name'];
			}
		}
		self::assertNotNull($keyB, 'B sieht freigegebenen Workspace nicht');
		self::ok($b->call('POST', "/notes/$n1/reminder", ['reminder_at' => self::iso($this->t0)]), 'B Erinnerung n1');
		self::ok($b->call('POST', "/notes/$n2/reminder", ['reminder_at' => self::iso($this->t0 + 3600)]), 'B Erinnerung n2');
		self::ok($a->call('POST', "/notes/$n2/reminder", ['reminder_at' => self::iso($this->t0 + 3600)]), 'A Erinnerung n2');
		self::ok($b->call('POST', "/mentions/{$a->uid}/$n2/handled"), 'B Mention erledigt');
		self::assertSame(1, self::mentionRows($b->uid, $n2));

		$this->runJob($this->t0 + 60);
		self::assertSame(1, self::notifications($b->uid, $n1), 'Kontrolle: B erhält Benachrichtigung mit Leserecht');
		Server::get(Notifier::class)->prepare(self::notification($b->uid, $n1), 'en');

		Server::get(WorkspaceService::class)->unshare($a->uid, $wsId, $b->uid);
		self::assertSame([], self::reminderRows($b->uid, $n1), 'gesendete Erinnerung von B bleibt');
		self::assertSame([], self::reminderRows($b->uid, $n2), 'fällige Erinnerung von B bleibt');
		self::assertSame(0, self::mentionRows($b->uid, $n2), 'Mention-Status von B bleibt');
		self::assertCount(1, self::reminderRows($a->uid, $n2), 'Erinnerung des Besitzers gelöscht');
		self::assertAlreadyProcessed($b->uid, $n1, 'nach unshare');

		$this->runJob($this->t0 + 3600 + 60);
		self::assertSame(0, self::notifications($b->uid, $n2), 'nach Entzug gesendet');
		self::assertSame(1, self::notifications($a->uid, $n2), 'Besitzer erhält keine Benachrichtigung');
		self::assertSame(404, $b->call('GET', "/notes/$n1/reminder")->status);
	}

	// Freigabe per Mapper ohne unshare entfernt: Job prüft selbst, prepare verweigert
	public function testShareRemovedWithoutUnshare(): void {
		$a = $this->createUser('ma');
		$b = $this->createUser('mb');
		[$ws, $wsId] = self::sharedWorkspace($a, $b->uid);
		$n1 = self::newNote($a, $ws, 'Gesendet', '<p>x</p>');
		$n2 = self::newNote($a, $ws, 'Offen', '<p>y</p>');
		self::ok($b->call('POST', "/notes/$n1/reminder", ['reminder_at' => self::iso($this->t0)]), 'B n1');
		self::ok($b->call('POST', "/notes/$n2/reminder", ['reminder_at' => self::iso($this->t0 + 3600)]), 'B n2');
		$this->runJob($this->t0 + 60);
		self::assertSame(1, self::notifications($b->uid, $n1));

		self::assertSame(1, Server::get(WsShareMapper::class)->deleteUserShare($wsId, $b->uid));
		Server::get(AccessService::class)->resetCache();
		self::assertAlreadyProcessed($b->uid, $n1, 'Freigabe per Mapper entfernt');
		$this->runJob($this->t0 + 3600 + 60);
		self::assertSame(0, self::notifications($b->uid, $n2), 'Job sendet ohne Leserecht');
		self::assertSame([], self::reminderRows($b->uid, $n2), 'Job löscht Zeile ohne Leserecht nicht');
	}

	// Endgültiges Löschen: Benachrichtigungen verschwinden (markProcessed), Erinnerungszeilen weg
	public function testPurgeRemovesNotifications(): void {
		$a = $this->createUser('pa');
		[$ws] = self::newWorkspace($a, 'Purge');
		$n = self::newNote($a, $ws, 'Weg', '<p>x</p>');
		self::ok($a->call('POST', "/notes/$n/reminder", ['reminder_at' => self::iso($this->t0), 'recurrence' => '1d']), 'Erinnerung');
		$this->runJob($this->t0 + 60);
		self::assertSame(1, self::notifications($a->uid, $n));
		self::ok($a->call('DELETE', "/notes/$n" . self::q(['workspace' => $ws])), 'Papierkorb');
		self::ok($a->call('DELETE', "/trash/$n"), 'endgültig');
		self::assertSame(0, self::notifications($a->uid, $n), 'Benachrichtigung nach Purge');
		self::assertSame([], self::reminderRows($a->uid, $n));
		self::assertAlreadyProcessed($a->uid, $n, 'Notiz gelöscht');
	}

	// Erinnerung ohne Notiz → gelöscht, keine Benachrichtigung, Job läuft weiter
	public function testOrphanReminderRowIsDropped(): void {
		$a = $this->createUser('oa');
		Server::get(ReminderMapper::class)->replace($a->uid, 999999999, null, $this->t0, 'x', null, $this->t0);
		$this->runJob($this->t0 + 60);
		self::assertSame(0, self::notifications($a->uid, 999999999));
		self::assertSame([], self::reminderRows($a->uid, 999999999));
	}

	// Lead-Zusatz: Validierung /task-reminder
	public function testTaskReminderValidation(): void {
		$a = $this->createUser('tv');
		[$ws] = self::newWorkspace($a, 'TV');
		$tl = (int)self::ok($a->call('POST', '/notes', ['heading' => 'L', 'type' => 'tasklist', 'content' => '[]', 'workspace' => $ws]), 'tl')['note']['id'];
		$tid = TaskService::idString(self::ok($a->call('POST', "/notes/$tl/tasks", ['text' => 'x']), 'task')['task']['id']);
		$plain = self::newNote($a, $ws, 'Text', '<p>x</p>');
		$at = self::iso($this->t0);
		$r = $a->call('POST', "/notes/$tl/task-reminder", ['task_id' => str_repeat('1', 65), 'reminder_at' => $at]);
		self::assertSame(400, $r->status, 'task_id 65 Zeichen: ' . $r->describe());
		$r = $a->call('POST', "/notes/$plain/task-reminder", ['task_id' => $tid, 'reminder_at' => $at]);
		self::assertSame(400, $r->status, 'keine Tasklist: ' . $r->describe());
		$r = $a->call('POST', "/notes/$tl/task-reminder", ['task_id' => '123.5', 'reminder_at' => $at]);
		self::assertSame(404, $r->status, 'unbekannte Aufgabe: ' . $r->describe());
		self::assertSame([], self::reminderRows($a->uid, $tl));
		self::ok($a->call('POST', "/notes/$tl/task-reminder", ['task_id' => $tid, 'reminder_at' => $at]), 'gültig');
		self::assertCount(1, self::reminderRows($a->uid, $tl));
	}

	// Lead-Zusatz: höchstens 1000 offene Erinnerungen je Benutzer
	public function testPendingLimit(): void {
		$a = $this->createUser('pl');
		[$ws] = self::newWorkspace($a, 'PL');
		$n = self::newNote($a, $ws, 'Viele', '<p>x</p>');
		$n2 = self::newNote($a, $ws, 'Neu', '<p>y</p>');
		self::ok($a->call('POST', "/notes/$n/reminder", ['reminder_at' => self::iso($this->t0)]), 'erste');
		$mapper = Server::get(ReminderMapper::class);
		self::db()->beginTransaction();
		for ($i = 1; $i < 1000; $i++) {
			$mapper->replace($a->uid, $n, 'k' . $i, $this->t0, 'x', null, $this->t0);
		}
		self::db()->commit();
		self::assertCount(1000, self::reminderRows($a->uid, $n));
		$r = $a->call('POST', "/notes/$n2/reminder", ['reminder_at' => self::iso($this->t0)]);
		self::assertSame(400, $r->status, '1001. offene Erinnerung: ' . $r->describe());
		self::assertSame([], self::reminderRows($a->uid, $n2));
		self::ok($a->call('POST', "/notes/$n/reminder", ['reminder_at' => self::iso($this->t0 + 60)]), 'Ersetzen einer vorhandenen bleibt erlaubt');
	}

	// Lead-Zusatz: mehr als eine Charge (200) fälliger Erinnerungen in einem Lauf
	public function testMoreThanBatchInOneRun(): void {
		$a = $this->createUser('mb');
		[$ws] = self::newWorkspace($a, 'MB');
		$n = self::newNote($a, $ws, 'Charge', '<p>x</p>');
		$mapper = Server::get(ReminderMapper::class);
		self::db()->beginTransaction();
		for ($i = 0; $i < 250; $i++) {
			$mapper->replace($a->uid, $n, 'b' . $i, $this->t0, 'x', null, $this->t0);
		}
		self::db()->commit();
		$this->runJob($this->t0 + 60);
		self::assertSame(250, self::notifications($a->uid, $n, 'task_reminder'), 'nicht alle fälligen in einem Lauf');
		self::assertSame([], array_values(array_filter(self::reminderRows($a->uid, $n), static fn ($r) => $r['sent_at'] === null)));
	}

	// Lead-Zusatz: Empfänger mit nur Leserecht; wiederkehrende Aufgaben-Erinnerung ändert die Notiz nicht
	public function testRecurringTaskReminderReadOnlyRecipient(): void {
		$a = $this->createUser('ro');
		$b = $this->createUser('rr');
		[$ws, $wsId] = self::sharedWorkspace($a, $b->uid);
		$tl = (int)self::ok($a->call('POST', '/notes', ['heading' => 'L', 'type' => 'tasklist', 'content' => '[]', 'workspace' => $ws]), 'tl')['note']['id'];
		$tid = TaskService::idString(self::ok($a->call('POST', "/notes/$tl/tasks", ['text' => 'Wöchentlich', 'due_at' => gmdate('Y-m-d\TH:i', $this->t0)]), 'task')['task']['id']);
		self::ok($b->call('POST', "/notes/$tl/task-reminder", ['task_id' => $tid, 'reminder_at' => self::iso($this->t0), 'recurrence' => '1d']), 'B task-reminder');
		// Level herabsetzen: write → read
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_ws_shares')->set('permission', $qb->createNamedParameter('read'))
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($wsId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($b->uid)))->executeStatement();
		Server::get(AccessService::class)->resetCache();
		self::assertSame(403, $b->call('POST', "/notes/$tl/tasks", ['text' => 'x'])->status, 'Kontrolle: nur Leserecht');
		$before = self::dump($a->uid)['notes'];
		$this->runJob($this->t0 + 60);
		self::assertSame(1, self::notifications($b->uid, $tl, 'task_reminder'));
		self::assertSame($before, self::dump($a->uid)['notes'], 'Joblauf ändert Notiz trotz nur Leserecht des Empfängers');
		$pending = array_values(array_filter(self::reminderRows($b->uid, $tl), static fn ($r) => $r['sent_at'] === null));
		self::assertCount(1, $pending, 'nächste Erinnerung fehlt');
		self::assertSame($this->t0 + 86400, (int)$pending[0]['trigger_at']);
	}

	// Lead-Zusatz: Wiederholung 1d über Sommerzeitbeginn (Europe/Berlin, 29.03.2037) → gleiche Ortszeit
	public function testDailyRecurrenceAcrossDst(): void {
		$a = $this->createUser('ds');
		Server::get(IConfig::class)->setUserValue($a->uid, 'core', 'timezone', 'Europe/Berlin');
		[$ws] = self::newWorkspace($a, 'DST');
		$n = self::newNote($a, $ws, 'Sommerzeit', '<p>x</p>');
		self::ok($a->call('POST', "/notes/$n/reminder", ['reminder_at' => '2037-03-28T08:00:00', 'recurrence' => '1d']), 'Erinnerung lokal');
		$tz = new \DateTimeZone('Europe/Berlin');
		$first = (new \DateTimeImmutable('2037-03-28 08:00:00', $tz))->getTimestamp();
		self::assertSame($first, (int)self::reminderRows($a->uid, $n)[0]['trigger_at'], 'ohne Zone in Benutzerzeitzone');
		$this->runJob($first + 60);
		$pending = array_values(array_filter(self::reminderRows($a->uid, $n), static fn ($r) => $r['sent_at'] === null));
		self::assertCount(1, $pending);
		$next = (new \DateTimeImmutable('@' . (int)$pending[0]['trigger_at']))->setTimezone($tz);
		self::assertSame('2037-03-29 08:00', $next->format('Y-m-d H:i'), 'Ortszeit nach Sommerzeitbeginn verschoben');
	}
}
