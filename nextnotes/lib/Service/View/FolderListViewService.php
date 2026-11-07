<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\FolderService;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\IL10N;

/** Daten der Ordner-Seite (Upstream list_folders.php): Ordnerbaum des Workspace mit Anzahl, Favorit, Speed Dial */
class FolderListViewService {
	private const SPEED_DIAL_TAG = 'speed-dial';

	public function __construct(
		private FolderService $folders,
		private NoteMapper $notes,
		private SettingsService $settings,
		private AccessService $access,
		private IL10N $l,
	) {
	}

	/**
	 * Zeilen in Baumreihenfolge (Tiefe für den Einzug), wie die Seitenleiste sortiert
	 * @param array{wsId: int, workspaceKey: string, workspace: \OCA\NextNotes\Db\Workspace, canWrite: bool} $frame
	 * @return array{rows: list<array<string, mixed>>, materialTitle: string, materialInherited: string, canArchive: bool}
	 */
	public function build(string $uid, array $frame): array {
		$byId = $this->folders->byId($frame['wsId']);
		$direct = $byId === [] ? [] : $this->notes->countByFolder($frame['wsId']);
		$speedDial = $byId === [] ? [] : $this->notes->countByFolder($frame['wsId'], self::SPEED_DIAL_TAG);
		$sort = $this->settings->noteListSort($uid);
		// Material (Plan M6 §2.6): Kennzeichen für alle, Schreiben im Zweig nur Lehrkraft; ohne Zusatzabfrage aus den Ordnern
		$ws = $frame['workspace'];
		$material = $ws->getOwnerType() === 'team' && $ws->getKind() === 'normal' ? FolderService::materialSet($byId) : [];
		$restricted = $material !== [] && $frame['canWrite'] && $this->access->materialRestricted($uid, $ws);
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
		$walk = function (int $parent, int $depth, array $seen) use (&$walk, &$rows, $children, $direct, $speedDial, $material, $restricted, $frame): array {
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
					'material' => !isset($material[$id]) ? '' : ((int)$f->getMaterial() === 1 ? 'own' : 'inherited'),
					'writable' => $frame['canWrite'] && !($restricted && isset($material[$id])),
				];
				$total += $count;
				$dial += $dialCount;
			}
			return [$total, $dial];
		};
		$walk(0, 0, []);
		return [
			'rows' => $rows,
			'materialTitle' => $material === [] ? '' : $this->l->t('Material – only teachers can edit'),
			'materialInherited' => $material === [] ? '' : $this->l->t('In a material folder – only teachers can edit'),
			'canArchive' => $frame['workspaceKey'] !== WorkspaceService::ARCHIVE_NAME,
		];
	}
}
