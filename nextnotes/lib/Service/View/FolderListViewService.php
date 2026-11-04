<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Service\FolderService;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\WorkspaceService;

/** Daten der Ordner-Seite (Upstream list_folders.php): Ordnerbaum des Workspace mit Anzahl, Favorit, Speed Dial */
class FolderListViewService {
	private const SPEED_DIAL_TAG = 'speed-dial';

	public function __construct(
		private FolderService $folders,
		private NoteMapper $notes,
		private SettingsService $settings,
	) {
	}

	/**
	 * Zeilen in Baumreihenfolge (Tiefe für den Einzug), wie die Seitenleiste sortiert
	 * @param array{wsId: int, workspaceKey: string} $frame
	 * @return array{rows: list<array<string, mixed>>, canArchive: bool}
	 */
	public function build(string $uid, array $frame): array {
		$byId = $this->folders->byId($frame['wsId']);
		$direct = $byId === [] ? [] : $this->notes->countByFolder($frame['wsId']);
		$speedDial = $byId === [] ? [] : $this->notes->countByFolder($frame['wsId'], self::SPEED_DIAL_TAG);
		$sort = $this->settings->noteListSort($uid);
		$children = [];
		foreach ($byId as $id => $f) {
			$parent = $f->getParentId();
			// verwaister Elternverweis → Wurzel (wie die Seitenleiste)
			$children[$parent !== null && isset($byId[$parent]) ? $parent : 0][] = $f;
		}
		foreach ($children as &$list) {
			usort($list, static fn (Folder $a, Folder $b) => FolderService::compare($sort, $a, $b));
		}
		unset($list);
		$rows = [];
		$walk = function (int $parent, int $depth, array $seen) use (&$walk, &$rows, $children, $direct, $speedDial): array {
			$total = 0;
			$dial = 0;
			foreach ($children[$parent] ?? [] as $f) {
				$id = (int)$f->getId();
				if (isset($seen[$id])) {
					continue;
				}
				$index = count($rows);
				$rows[] = null;
				[$subTotal, $subDial] = $walk($id, $depth + 1, $seen + [$id => true]);
				$count = ($direct[$id] ?? 0) + $subTotal;
				$dialCount = ($speedDial[$id] ?? 0) + $subDial;
				$rows[$index] = [
					'id' => $id,
					'name' => (string)$f->getName(),
					'depth' => $depth,
					'direct' => $direct[$id] ?? 0,
					'count' => $count,
					'hasChildren' => isset($children[$id]),
					'favorite' => (int)$f->getFavorite() === 1,
					'speedDial' => $count > 0 && $dialCount === $count,
					'iconClass' => IndexViewService::iconClass((string)($f->getIcon() ?? ''), 'lucide-folder'),
					'iconColor' => IndexViewService::iconColor((string)($f->getIconColor() ?? '')),
				];
				$total += $count;
				$dial += $dialCount;
			}
			return [$total, $dial];
		};
		$walk(0, 0, []);
		return [
			'rows' => $rows,
			'canArchive' => $frame['workspaceKey'] !== WorkspaceService::ARCHIVE_NAME,
		];
	}
}
