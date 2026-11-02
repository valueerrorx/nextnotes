<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\ReminderMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;

// Aufgaben im JSON-Inhalt von Tasklist-Notizen (Upstream TasksController, lib/tasklists.php, lib/checklists.php)
class TaskService {
	private const TASK_FIELDS = ['completed' => 'completed', 'important' => 'important', 'reminder' => 'dueReminder', 'reminder_email' => 'dueReminderEmail'];

	public function __construct(
		private NoteMapper $notes,
		private NoteService $noteService,
		private AccessService $access,
		private WorkspaceService $workspaces,
		private FolderService $folders,
		private ReminderMapper $reminders,
		private Transaction $tx,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
	) {
	}

	// ---------- Lesen ----------

	/** @return array{notes: list<array<string, mixed>>, checklists: list<array<string, mixed>>} GET /tasks */
	public function index(string $uid, mixed $wsKey): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		$wsIds = $ws !== null ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid);
		// Checklisten nur aus Notizen, die Kästchen enthalten können (Vorfilter in SQL)
		$list = array_merge(
			$this->notes->findList($wsIds, ['types' => ['tasklist']], true),
			$this->notes->findList($wsIds, ['types' => ['note', 'markdown'], 'content_any' => ['checklist-checkbox', '[ ]', '[x]', '[X]']], true),
		);
		usort($list, static fn (Note $a, Note $b) => [(int)$b->getUpdatedAt(), (int)$b->getId()] <=> [(int)$a->getUpdatedAt(), (int)$a->getId()]);
		$paths = [];
		$notes = [];
		$checklists = [];
		foreach ($list as $n) {
			$type = (string)$n->getType();
			$wsId = (int)$n->getWorkspaceId();
			$paths[$wsId] ??= $this->folders->byId($wsId);
			$folderId = $n->getFolderId();
			$folder = $folderId !== null && isset($paths[$wsId][$folderId]) ? $paths[$wsId][$folderId] : null;
			$row = [
				'id' => (int)$n->getId(),
				'heading' => (string)($n->getHeading() ?? ''),
				'folder' => $folder !== null ? (string)$folder->getName() : '',
				'folder_id' => $folderId,
				'folder_path' => $folderId !== null ? (string)FolderService::pathOf($folderId, $paths[$wsId]) : '',
				'workspace' => (string)$this->workspaces->keyFor($uid, $wsId),
				'updated' => NoteSerializer::date($n->getUpdatedAt()),
				'favorite' => (int)$n->getFavorite() === 1,
			];
			if ($type === 'tasklist') {
				$notes[] = $row + ['tasks' => self::cleanTasks((string)$n->getContent())];
				continue;
			}
			$items = self::checklistItems((string)$n->getContent(), $type);
			if ($items !== []) {
				$checklists[] = $row + ['type' => $type, 'tasks' => array_map(static fn (array $i) => [
					'id' => $i['index'], 'text' => $i['text'], 'completed' => $i['completed'],
				], $items)];
			}
		}
		return ['notes' => $notes, 'checklists' => $checklists];
	}

	/** @return array<string, mixed> GET /notes/{id}/tasks */
	public function listForNote(string $uid, int $id): array {
		$note = $this->tasklist($uid, $id, 'read');
		return ['note_id' => $id, 'heading' => (string)$note->getHeading(), 'tasks' => self::cleanTasks((string)$note->getContent())];
	}

	// ---------- Schreiben ----------

	/** @return array<string, mixed> POST /notes/{id}/tasks */
	public function create(string $uid, int $id, array $in): array {
		$this->tasklist($uid, $id, 'write');
		$text = isset($in['text']) && is_scalar($in['text']) ? trim((string)$in['text']) : '';
		if ($text === '') {
			throw new BadRequestException('text is required');
		}
		$fields = $this->taskFields($in, true);
		return $this->mutate($uid, $id, function (array $tasks, Note $note) use ($uid, $id, $text, $fields) {
			$task = array_merge(['id' => $this->newId($tasks), 'text' => $text, 'noteId' => $id, 'completed' => false, 'important' => false, 'dueAt' => null], $fields);
			$tasks[] = $task;
			return [self::regroup($tasks), ['note_id' => $id, 'task' => $task], function () use ($uid, $id, $task) {
				if (!empty($task['dueReminder'])) {
					$this->syncReminder($uid, $id, $task);
				}
			}];
		});
	}

	/** @return array<string, mixed> PATCH /notes/{id}/tasks/{taskId} */
	public function update(string $uid, int $id, string $taskId, array $in): array {
		$this->tasklist($uid, $id, 'write');
		$fields = $this->taskFields($in, false);
		if (array_key_exists('text', $in)) {
			if (!is_scalar($in['text']) || trim((string)$in['text']) === '') {
				throw new BadRequestException('text cannot be empty');
			}
			$fields['text'] = trim((string)$in['text']);
		}
		return $this->mutate($uid, $id, function (array $tasks) use ($uid, $id, $taskId, $fields) {
			$index = self::findTask($tasks, $taskId);
			$previous = $tasks[$index];
			$task = array_merge($previous, $fields);
			// Erledigen einer wiederkehrenden Aufgabe öffnet ihr nächstes Vorkommen
			$next = null;
			if (!empty($task['completed']) && empty($previous['completed'])) {
				$next = $this->nextOccurrence($uid, $task);
				if ($next !== null) {
					$next = ['id' => $this->newId($tasks)] + $next;
					unset($task['dueRecurrence']);
				}
			}
			if (!empty($task['completed'])) {
				$task['dueReminder'] = false;
			}
			$due = $task['dueAt'] ?? null;
			if (!empty($task['dueReminder']) && (!is_string($due) || $due === '')) {
				throw new BadRequestException('reminder requires due_at');
			}
			array_splice($tasks, $index, 1, $next !== null ? [$next, $task] : [$task]);
			$out = ['note_id' => $id, 'task' => $task];
			if ($next !== null) {
				$out['next_task'] = $next;
			}
			return [self::regroup($tasks), $out, function () use ($uid, $id, $previous, $task, $next) {
				if (self::reminderChanged($previous, $task)) {
					$this->syncReminder($uid, $id, $task);
				}
				if ($next !== null && !empty($next['dueReminder'])) {
					$this->syncReminder($uid, $id, $next);
				}
			}];
		});
	}

	/** @return array<string, mixed> DELETE /notes/{id}/tasks/{taskId} */
	public function delete(string $uid, int $id, string $taskId): array {
		$this->tasklist($uid, $id, 'write');
		return $this->mutate($uid, $id, function (array $tasks) use ($id, $taskId) {
			array_splice($tasks, self::findTask($tasks, $taskId), 1);
			return [$tasks, ['note_id' => $id, 'task_id' => $taskId], fn () => $this->reminders->deletePendingForTask($id, $taskId)];
		});
	}

	/** @return array<string, mixed> POST …/subtasks */
	public function createSubtask(string $uid, int $id, string $taskId, array $in): array {
		$text = isset($in['text']) && is_scalar($in['text']) ? trim((string)$in['text']) : '';
		if ($text === '') {
			throw new BadRequestException('text is required');
		}
		return $this->writeSubtasks($uid, $id, $taskId, function (array $subtasks) use ($in, $text) {
			$sub = ['id' => $this->newId($subtasks), 'text' => $text, 'completed' => array_key_exists('completed', $in) && filter_var($in['completed'], FILTER_VALIDATE_BOOLEAN)];
			$subtasks[] = $sub;
			return [$subtasks, ['subtask' => $sub]];
		});
	}

	/** @return array<string, mixed> PATCH …/subtasks/{subtaskId} */
	public function updateSubtask(string $uid, int $id, string $taskId, string $subtaskId, array $in): array {
		return $this->writeSubtasks($uid, $id, $taskId, function (array $subtasks) use ($in, $subtaskId) {
			$key = self::findSubtask($subtasks, $subtaskId);
			if (!array_key_exists('text', $in) && !array_key_exists('completed', $in)) {
				throw new BadRequestException('text or completed is required');
			}
			if (array_key_exists('text', $in)) {
				if (!is_scalar($in['text']) || trim((string)$in['text']) === '') {
					throw new BadRequestException('text cannot be empty');
				}
				$subtasks[$key]['text'] = trim((string)$in['text']);
			}
			if (array_key_exists('completed', $in)) {
				$subtasks[$key]['completed'] = (bool)filter_var($in['completed'], FILTER_VALIDATE_BOOLEAN);
			}
			$s = $subtasks[$key];
			return [$subtasks, ['subtask' => ['id' => $s['id'] ?? null, 'text' => is_scalar($s['text'] ?? null) ? (string)$s['text'] : '', 'completed' => !empty($s['completed'])]]];
		});
	}

	/** @return array<string, mixed> DELETE …/subtasks/{subtaskId} */
	public function deleteSubtask(string $uid, int $id, string $taskId, string $subtaskId): array {
		return $this->writeSubtasks($uid, $id, $taskId, function (array $subtasks) use ($subtaskId) {
			array_splice($subtasks, self::findSubtask($subtasks, $subtaskId), 1);
			return [$subtasks, ['subtask_id' => $subtaskId]];
		});
	}

	/**
	 * Wiederkehrende Aufgaben-Erinnerung: Fälligkeit auf den nächsten Auslöser (Wanduhr des Besitzers) setzen
	 * Ohne Schreibrecht bleibt der Inhalt unverändert, die Erinnerung läuft weiter
	 * @return bool false, wenn Notiz/Aufgabe weg oder erledigt
	 */
	public function advanceDue(string $uid, int $noteId, string $taskId, int $newTrigger): bool {
		return $this->tx->run(function () use ($uid, $noteId, $taskId, $newTrigger) {
			$this->notes->lockRow($noteId);
			try {
				$note = $this->notes->find($noteId);
			} catch (\OCP\AppFramework\Db\DoesNotExistException) {
				return false;
			}
			if ($note->getType() !== 'tasklist' || $note->getTrashedAt() !== null) {
				return false;
			}
			$tasks = self::rawTasks((string)$note->getContent());
			$index = self::taskIndex($tasks, $taskId);
			if ($index === null || !empty($tasks[$index]['completed'])) {
				return false;
			}
			$due = $tasks[$index]['dueAt'] ?? null;
			if ($this->access->noteLevel($uid, $note) >= AccessService::WRITE
				&& is_string($due) && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $due, $m)) {
				$tasks[$index]['dueAt'] = Recurrence::timestampToDue($newTrigger, $this->timeZone->getTimeZone(false, $uid), isset($m[1]));
				$this->noteService->saveContent($note, self::encode($tasks), null);
			}
			return true;
		});
	}

	// Aufgaben-Erinnerung nur für bestehende Aufgabe einer Tasklist (404 sonst)
	public function requireTask(Note $note, string $taskId): void {
		if ($note->getType() !== 'tasklist') {
			throw new BadRequestException('Note is not a tasklist');
		}
		self::findTask(self::rawTasks((string)$note->getContent()), $taskId);
	}

	// ---------- Hilfen ----------

	private function tasklist(string $uid, int $id, string $perm): Note {
		$note = $this->access->requireNote($uid, $id, $perm);
		if ($note->getType() !== 'tasklist') {
			throw new BadRequestException('Note is not a tasklist');
		}
		return $note;
	}

	/**
	 * Lesen–Ändern–Schreiben unter Zeilensperre; $change liefert [Aufgaben, Antwort, Nacharbeit]
	 * @return array<string, mixed>
	 */
	private function mutate(string $uid, int $id, callable $change): array {
		return $this->tx->run(function () use ($uid, $id, $change) {
			$this->notes->lockRow($id);
			$note = $this->notes->find($id);
			[$tasks, $out, $after] = $change(self::rawTasks((string)$note->getContent()), $note);
			$this->noteService->saveContent($note, self::encode($tasks), $uid);
			$after();
			return $out;
		});
	}

	/** @return array<string, mixed> */
	private function writeSubtasks(string $uid, int $id, string $taskId, callable $change): array {
		$this->tasklist($uid, $id, 'write');
		return $this->mutate($uid, $id, function (array $tasks) use ($id, $taskId, $change) {
			$index = self::findTask($tasks, $taskId);
			$stored = $tasks[$index]['subtasks'] ?? [];
			[$subtasks, $response] = $change($this->ensureSubtaskIds(is_array($stored) ? $stored : []));
			if ($subtasks === []) {
				unset($tasks[$index]['subtasks']);
			} else {
				$tasks[$index]['subtasks'] = array_values($subtasks);
			}
			$out = array_merge(['note_id' => $id, 'task_id' => self::idString($tasks[$index]['id'])], $response, ['subtasks' => self::visibleSubtasks($tasks[$index])]);
			return [$tasks, $out, static fn () => null];
		});
	}

	/** @return array<string, mixed> Felder aus dem Body → gespeicherte Schlüssel */
	private function taskFields(array $in, bool $create): array {
		$fields = [];
		foreach (self::TASK_FIELDS as $key => $stored) {
			if (array_key_exists($key, $in)) {
				$fields[$stored] = (bool)filter_var($in[$key], FILTER_VALIDATE_BOOLEAN);
			}
		}
		if (array_key_exists('due_at', $in)) {
			$due = $in['due_at'];
			if ($due === null || $due === '') {
				$fields['dueAt'] = null;
				$fields['dueReminder'] = false;
			} elseif (is_string($due) && preg_match('/^(\d{4}-\d{2}-\d{2})(T\d{2}:\d{2})?$/', trim($due), $m)) {
				if (!InputValidator::validDateTime($m[0])) {
					throw new BadRequestException('Invalid due_at date');
				}
				$fields['dueAt'] = $m[1] . ($m[2] ?? '');
			} else {
				throw new BadRequestException("Invalid due_at format (expected 'YYYY-MM-DD' or 'YYYY-MM-DDTHH:MM')");
			}
		}
		if (array_key_exists('recurrence', $in)) {
			$rec = Recurrence::normalize($in['recurrence']);
			if ($rec === false) {
				throw new BadRequestException('Invalid recurrence format (expected e.g. "30i", "1h", "1d", "2w", "3m", "1y")');
			}
			$fields['dueRecurrence'] = $rec;
		}
		if ($create && !empty($fields['dueReminder']) && empty($fields['dueAt'])) {
			throw new BadRequestException('reminder requires due_at');
		}
		return $fields;
	}

	private static function reminderChanged(array $before, array $after): bool {
		foreach (['dueAt', 'dueReminder', 'dueReminderEmail', 'dueRecurrence', 'completed', 'text'] as $k) {
			$old = $before[$k] ?? null;
			$new = $after[$k] ?? null;
			if ($k === 'dueReminder' || $k === 'completed') {
				$old = !empty($old);
				$new = !empty($new);
			}
			if ($old !== $new) {
				return true;
			}
		}
		return false;
	}

	// Erinnerung der Aufgabe für den Aufrufer setzen bzw. offene Erinnerungen der Aufgabe entfernen
	private function syncReminder(string $uid, int $noteId, array $task): void {
		$taskId = self::idString($task['id'] ?? null);
		if ($taskId === '') {
			return;
		}
		$due = $task['dueAt'] ?? null;
		if (empty($task['dueReminder']) || !is_string($due) || $due === '' || !empty($task['completed'])) {
			$this->reminders->deletePendingForTask($noteId, $taskId);
			return;
		}
		$trigger = Recurrence::dueToTimestamp($due, $this->timeZone->getTimeZone(false, $uid));
		if ($trigger === null) {
			return;
		}
		if ($this->reminders->atLimit($uid, $noteId, $taskId)) {
			throw new BadRequestException('too many reminders');
		}
		$rec = Recurrence::normalize($task['dueRecurrence'] ?? null);
		$this->reminders->replace($uid, $noteId, $taskId, $trigger, (string)($task['text'] ?? ''), is_string($rec) ? $rec : null, $this->time->getTime());
	}

	// Upstream poznoteNextTaskOccurrence: Wanduhr-Rechnung (lokal, ohne Sommerzeit-Versatz)
	private function nextOccurrence(string $uid, array $task): ?array {
		$rule = $task['dueRecurrence'] ?? null;
		$due = $task['dueAt'] ?? null;
		if (!is_string($rule) || !preg_match(Recurrence::PATTERN, $rule) || !is_string($due)) {
			return null;
		}
		$utc = new \DateTimeZone('UTC');
		$dueTs = Recurrence::dueToTimestamp($due, $utc);
		if ($dueTs === null) {
			return null;
		}
		$nowLocal = (new \DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($this->timeZone->getTimeZone(false, $uid))->format('Y-m-d H:i');
		$nowTs = (new \DateTimeImmutable($nowLocal, $utc))->getTimestamp();
		$nextTs = Recurrence::nextAfter($dueTs, $rule, max($nowTs, $dueTs));
		if ($nextTs === null) {
			return null;
		}
		$next = $task;
		unset($next['id']);
		$next['completed'] = false;
		$next['dueAt'] = Recurrence::timestampToDue($nextTs, $utc, strlen($due) > 10);
		if (isset($next['subtasks']) && is_array($next['subtasks'])) {
			foreach ($next['subtasks'] as $k => $s) {
				if (is_array($s)) {
					$next['subtasks'][$k]['completed'] = false;
				}
			}
		}
		return $next;
	}

	// Frontend-Format: Zeitstempel in ms mit Nachkommastellen, eindeutig in der Liste
	private function newId(array $items): float {
		$taken = [];
		foreach ($items as $item) {
			if (isset($item['id'])) {
				$taken[self::idString($item['id'])] = true;
			}
		}
		do {
			$candidate = round($this->time->getTime() * 1000 + random_int(0, 999)) + random_int(1, 999999) / 1000000;
		} while (isset($taken[self::idString($candidate)]));
		return $candidate;
	}

	// Upstream ensureTasklistSubtaskIds
	private function ensureSubtaskIds(array $subtasks): array {
		$subtasks = array_values(array_filter($subtasks, 'is_array'));
		$seen = [];
		foreach ($subtasks as $s) {
			if (isset($s['id']) && is_numeric($s['id'])) {
				$seen[self::idString($s['id'])] = true;
			}
		}
		$kept = [];
		foreach ($subtasks as $k => $s) {
			$id = $s['id'] ?? null;
			$key = is_numeric($id) ? self::idString($id) : '';
			if ($key === '' || isset($kept[$key])) {
				$subtasks[$k]['id'] = $this->newId(array_map(static fn ($x) => ['id' => $x], array_keys($seen)));
				$key = self::idString($subtasks[$k]['id']);
				$seen[$key] = true;
			}
			$kept[$key] = true;
		}
		return $subtasks;
	}

	// Gleitkomma-IDs mit voller Genauigkeit wie im JSON
	public static function idString(mixed $id): string {
		if (is_float($id)) {
			return (string)json_encode($id);
		}
		return is_scalar($id) ? (string)$id : '';
	}

	private static function taskIndex(array $tasks, string $taskId): ?int {
		$needle = trim($taskId);
		foreach ($tasks as $i => $t) {
			if (isset($t['id']) && self::idString($t['id']) === $needle) {
				return (int)$i;
			}
		}
		return null;
	}

	private static function findTask(array $tasks, string $taskId): int {
		return self::taskIndex($tasks, $taskId) ?? throw new NotFoundException('Task not found');
	}

	private static function findSubtask(array $subtasks, string $subtaskId): int {
		$needle = trim($subtaskId);
		foreach ($subtasks as $k => $s) {
			if ($needle !== '' && is_array($s) && isset($s['id']) && self::idString($s['id']) === $needle) {
				return (int)$k;
			}
		}
		throw new NotFoundException('Subtask not found');
	}

	// Reihenfolge der Oberfläche: wichtig-offen, offen, erledigt
	private static function regroup(array $tasks): array {
		$groups = [[], [], []];
		foreach ($tasks as $t) {
			$groups[!empty($t['completed']) ? 2 : (!empty($t['important']) ? 0 : 1)][] = $t;
		}
		return array_merge(...$groups);
	}

	private static function encode(array $tasks): string {
		return (string)json_encode(array_values($tasks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	/** @return list<array<string, mixed>> gespeicherte Aufgaben (Upstream normalizeTasklistJsonContent) */
	public static function rawTasks(string $content): array {
		$content = trim((string)preg_replace('/^\xEF\xBB\xBF/', '', $content));
		$decodedHtml = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$stripped = trim(strip_tags((string)preg_replace('/<\?xml[^>]*\?>/i', '', $decodedHtml)));
		$candidates = [$content, $decodedHtml, $stripped];
		foreach ([$content, $decodedHtml, $stripped] as $v) {
			$a = strpos($v, '[');
			$b = strrpos($v, ']');
			if ($a !== false && $b !== false && $b > $a) {
				$candidates[] = substr($v, $a, $b - $a + 1);
			}
		}
		foreach (array_unique($candidates) as $c) {
			$decoded = $c === '' ? null : json_decode($c, true);
			if (is_array($decoded) && isset($decoded['tasks']) && is_array($decoded['tasks'])) {
				$decoded = $decoded['tasks'];
			}
			if (!is_array($decoded) || ($decoded !== [] && !array_is_list($decoded))) {
				continue;
			}
			$out = [];
			foreach ($decoded as $t) {
				if (!is_array($t)) {
					continue;
				}
				$t['completed'] = !empty($t['completed']) || !empty($t['checked']) || !empty($t['done']);
				if (!isset($t['text']) && isset($t['content'])) {
					$t['text'] = (string)$t['content'];
				}
				unset($t['checked'], $t['done']);
				if (array_key_exists('subtasks', $t)) {
					$t['subtasks'] = self::normalizeSubtasks($t['subtasks']);
				}
				$out[] = $t;
			}
			return $out;
		}
		return [];
	}

	private static function normalizeSubtasks(mixed $subtasks): array {
		if (!is_array($subtasks)) {
			return [];
		}
		$out = [];
		foreach ($subtasks as $s) {
			if (!is_array($s)) {
				continue;
			}
			$s['completed'] = !empty($s['completed']) || !empty($s['checked']) || !empty($s['done']);
			if (!isset($s['text']) && isset($s['content'])) {
				$s['text'] = (string)$s['content'];
			}
			unset($s['checked'], $s['done'], $s['subtasks']);
			$out[] = $s;
		}
		return $out;
	}

	/** @return list<array{id: mixed, text: string, completed: bool}> Upstream getTasklistSubtasks */
	private static function visibleSubtasks(array $task): array {
		$out = [];
		foreach (self::normalizeSubtasks($task['subtasks'] ?? null) as $s) {
			$text = is_scalar($s['text'] ?? null) ? trim((string)$s['text']) : '';
			if ($text !== '') {
				$out[] = ['id' => is_scalar($s['id'] ?? null) ? $s['id'] : null, 'text' => $text, 'completed' => $s['completed']];
			}
		}
		return $out;
	}

	/** @return list<array<string, mixed>> Antwortform (Upstream decodeTasks) */
	public static function cleanTasks(string $content): array {
		$out = [];
		foreach (self::rawTasks($content) as $t) {
			$text = $t['text'] ?? ($t['content'] ?? '');
			$due = $t['dueAt'] ?? null;
			$due = (is_string($due) && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?/', $due, $dm)) ? substr($due, 0, empty($dm[1]) ? 10 : 16) : null;
			$clean = [
				'id' => is_scalar($t['id'] ?? null) ? $t['id'] : null,
				'text' => is_scalar($text) ? (string)$text : '',
				'completed' => !empty($t['completed']),
				'important' => !empty($t['important']),
				'dueAt' => $due,
				'dueReminder' => !empty($t['dueReminder']),
			];
			if (array_key_exists('dueReminderEmail', $t)) {
				$clean['dueReminderEmail'] = !empty($t['dueReminderEmail']);
			}
			if (is_string($t['dueRecurrence'] ?? null) && preg_match(Recurrence::PATTERN, $t['dueRecurrence'])) {
				$clean['dueRecurrence'] = $t['dueRecurrence'];
			}
			$subs = self::visibleSubtasks($t);
			if ($subs !== []) {
				$clean['subtasks'] = $subs;
			}
			$out[] = $clean;
		}
		return $out;
	}

	/** @return list<array{index: int, text: string, completed: bool}> Upstream extractNoteChecklistItems */
	public static function checklistItems(string $content, string $type): array {
		if ($type === 'markdown') {
			if ($content === '' || (!str_contains($content, '[ ]') && stripos($content, '[x]') === false)) {
				return [];
			}
			$items = [];
			$fence = false;
			foreach (explode("\n", $content) as $no => $raw) {
				$line = rtrim($raw, "\r");
				if (preg_match('/^\s*(```|~~~)/', $line)) {
					$fence = !$fence;
					continue;
				}
				if ($fence || !preg_match('/^(\s*)[\*\-\+]\s+\[([ xX])\]\s+(.+)$/', $line, $m)) {
					continue;
				}
				$text = (string)preg_replace('/!?\[([^\]]*)\]\((?:[^()]|\([^()]*\))*\)/', '$1', $m[3]);
				$text = trim((string)preg_replace('/\s+/u', ' ', str_replace(['**', '__', '~~', '`'], '', $text)));
				if ($text !== '') {
					$items[] = ['index' => (int)$no, 'text' => $text, 'completed' => strtolower($m[2]) === 'x'];
				}
			}
			return $items;
		}
		if ($type !== 'note' || stripos($content, 'checklist-checkbox') === false || !preg_match_all('/<input\b[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
			return [];
		}
		$items = [];
		$index = 0;
		foreach ($matches[0] as [$tag, $offset]) {
			if (!preg_match('/\bclass\s*=\s*["\']([^"\']*)["\']/i', $tag, $cm) || !preg_match('/(?:^|\s)checklist-checkbox(?:\s|$)/', $cm[1])) {
				continue;
			}
			$rest = substr($content, $offset + strlen($tag));
			$end = preg_match('/<(?:ul|ol)\b|<\/li\s*>|<input\b/i', $rest, $em, PREG_OFFSET_CAPTURE) ? $em[0][1] : strlen($rest);
			$text = html_entity_decode(strip_tags(substr($rest, 0, $end)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$text = trim((string)preg_replace('/\s+/u', ' ', str_replace(["\u{200B}", "\u{00A0}"], ' ', $text)));
			$completed = preg_match('/\sdata-checked=["\']?([01])/i', $tag, $dc) ? $dc[1] === '1' : (bool)preg_match('/\schecked(?=[\s>=\/])/i', $tag);
			if ($text !== '') {
				$items[] = ['index' => $index, 'text' => $text, 'completed' => $completed];
			}
			$index++;
		}
		return $items;
	}
}
