<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;

/** NC-Freigaberegeln für neue Workspace-Empfänger und die Benutzersuche (Plan M5 §2.7, ADR 0014) */
class ShareTargetPolicy {
	public function __construct(
		private IShareManager $shareManager,
		private IGroupManager $groups,
		private IUserManager $users,
	) {
	}

	// Darf $actor überhaupt teilen (Freigabe-API an, nicht über shareapi_exclude_groups ausgeschlossen)
	public function actorMayShare(string $actor): bool {
		return $this->shareManager->shareApiEnabled() && !$this->shareManager->sharingDisabledForUser($actor);
	}

	// Ziel bekannt, aktiv, nicht der Akteur, gemeinsame Gruppe falls verlangt, Aufzählungsregeln wie die NC-Suche (volle UID)
	public function allowed(string $actor, string $target): bool {
		if ($target === '' || $target === $actor || !$this->actorMayShare($actor)) {
			return false;
		}
		$actorUser = $this->users->get($actor);
		$targetUser = $this->users->get($target);
		if (!$actorUser instanceof IUser || !$targetUser instanceof IUser || !$targetUser->isEnabled()) {
			return false;
		}
		if ($this->shareManager->shareWithGroupMembersOnly()) {
			$excluded = $this->shareManager->shareWithGroupMembersOnlyExcludeGroupsList();
			$mine = array_diff($this->groups->getUserGroupIds($actorUser), $excluded);
			if (array_intersect($mine, $this->groups->getUserGroupIds($targetUser)) === []) {
				return false;
			}
		}
		return $this->shareManager->currentUserCanEnumerateTargetUser($actorUser, $targetUser);
	}
}
