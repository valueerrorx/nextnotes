<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Service\TeamService;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;

/** Teams-Oberfläche (M5d): Verfügbarkeit, Link „Teams verwalten“ (nur mit Contacts, Q4) und Texte für nextnotes-teams.js */
class TeamsUiConfig {
	public function __construct(
		private TeamService $teams,
		private IAppManager $appManager,
		private IURLGenerator $urls,
		private IL10N $l,
	) {
	}

	// Teams-Oberfläche der Contacts-App, sonst null
	public function contactsUrl(): ?string {
		return $this->teams->isAvailable() && $this->appManager->isEnabledForUser('contacts')
			? $this->urls->linkToRoute('contacts.page.index') : null;
	}

	/** @return array{available: bool, contactsUrl: ?string, txt: array<string, string>} */
	public function config(): array {
		$l = $this->l;
		return [
			'available' => $this->teams->isAvailable(),
			'contactsUrl' => $this->contactsUrl(),
			'txt' => [
				'shareIntro' => $l->t('Search for people to share "{workspace}" with.'),
				'searchPlaceholder' => $l->t('Search people'),
				'searchHint' => $l->t('Type at least 2 characters'),
				'noResults' => $l->t('No people found'),
				'searchError' => $l->t('Search failed'),
				'noRecipients' => $l->t('Not shared with anyone yet'),
				'canEdit' => $l->t('Can edit'),
				'readOnly' => $l->t('Read only'),
				'remove' => $l->t('Remove'),
				'permission' => $l->t('Permission for {user}'),
				'invalidTarget' => $l->t('Sharing with this person is not possible.'),
				'teamWorkspace' => $l->t('Team workspace'),
				'ownerLabel' => $l->t('Workspace for'),
				'private' => $l->t('Only me (private)'),
				'teamOption' => $l->t('Team: {team}'),
				'manageTeams' => $l->t('Manage teams'),
				'convertTitle' => $l->t('Convert to a private workspace'),
				'convertMessage' => $l->t('All notes of this workspace, including those written by other members, will then belong to you. The deleted team keeps no access.'),
				'convertButton' => $l->t('Convert'),
				'convertFailed' => $l->t('The workspace could not be converted.'),
				'deleteMoveTo' => $l->t('Its notes and folders are moved to this workspace of the team:'),
				'deleteLast' => $l->t('This is the last workspace of the team. All its notes, folders and attachments are deleted permanently.'),
				'deleteLastAssignment' => $l->t('This also deletes 1 assignment with all submissions permanently. Export it first on the assignments page.'),
				'deleteLastAssignments' => $l->t('This also deletes {n} assignments with all submissions permanently. Export them first on the assignments page.'),
				'deleteMoveAssignment' => $l->t('Its assignment moves along.'),
				'deleteMoveAssignments' => $l->t('Its {n} assignments move along.'),
				'deleteChanged' => $l->t('The workspace has changed, please try again.'),
				'markMaterial' => $l->t('Mark as material'),
				'unmarkMaterial' => $l->t('Remove material mark'),
				'materialTitle' => $l->t('Material – only teachers can edit'),
				'materialInherited' => $l->t('In a material folder – only teachers can edit'),
				'alreadyMaterial' => $l->t('A parent folder is already material.'),
				'materialFailed' => $l->t('The material mark could not be changed.'),
				'materialReadOnly' => $l->t('Only teachers can change material.'),
				'copiedToPrivate' => $l->t('Copy created in your private workspace "{workspace}".'),
				'openCopy' => $l->t('Open copy'),
				'copyFailed' => $l->t('The note could not be copied.'),
				'assignNote' => $l->t('Hand out as assignment…'),
				'submitTitle' => $l->t('Hand in'),
				'submitConfirm' => $l->t('Hand in "{title}"? Afterwards your copy is read-only.'),
				'unsubmitTitle' => $l->t('Withdraw'),
				'unsubmitConfirm' => $l->t('Withdraw your submission? Until the deadline you can keep working and hand it in again. If you do not hand it in again before the deadline, the assignment counts as not handed in.'),
				'submitFailed' => $l->t('The action failed.'),
				'submissionLocked' => $l->t('This submission can no longer be changed. Reload the page.'),
				'saveFirstFailed' => $l->t('Your latest changes could not be saved. Try again.'),
			],
		];
	}
}
