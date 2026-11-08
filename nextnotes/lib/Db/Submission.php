<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_submissions (SPEC §2.1, §9.2)
/**
 * @method ?int getAssignmentId()
 * @method void setAssignmentId(?int $v)
 * @method ?string getStudentUid()
 * @method void setStudentUid(?string $v)
 * @method ?string getStudentName()
 * @method void setStudentName(?string $v)
 * @method ?string getStudentUidFormer()
 * @method void setStudentUidFormer(?string $v)
 * @method ?int getStudentDeletedAt()
 * @method void setStudentDeletedAt(?int $v)
 * @method ?int getNoteId()
 * @method void setNoteId(?int $v)
 * @method ?string getStatus()
 * @method void setStatus(?string $v)
 * @method ?int getDueOverrideAt()
 * @method void setDueOverrideAt(?int $v)
 * @method ?int getSubmittedAt()
 * @method void setSubmittedAt(?int $v)
 * @method ?int getReturnedAt()
 * @method void setReturnedAt(?int $v)
 * @method ?string getFeedback()
 * @method void setFeedback(?string $v)
 * @method ?int getPoints()
 * @method void setPoints(?int $v)
 * @method ?string getFeedbackBy()
 * @method void setFeedbackBy(?string $v)
 * @method ?string getFeedbackByName()
 * @method void setFeedbackByName(?string $v)
 * @method ?int getFeedbackAt()
 * @method void setFeedbackAt(?int $v)
 * @method ?int getLeftTeamAt()
 * @method void setLeftTeamAt(?int $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 * @method ?int getUpdatedAt()
 * @method void setUpdatedAt(?int $v)
 */
class Submission extends Entity {
	protected $assignmentId;
	protected $studentUid;
	protected $studentName;
	protected $studentUidFormer;
	protected $studentDeletedAt;
	protected $noteId;
	protected $status;
	protected $dueOverrideAt;
	protected $submittedAt;
	protected $returnedAt;
	protected $feedback;
	protected $points;
	protected $feedbackBy;
	protected $feedbackByName;
	protected $feedbackAt;
	protected $leftTeamAt;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('assignmentId', ColumnType::Integer);
		$this->addType('studentUid', ColumnType::String);
		$this->addType('studentName', ColumnType::String);
		$this->addType('studentUidFormer', ColumnType::String);
		$this->addType('studentDeletedAt', ColumnType::Integer);
		$this->addType('noteId', ColumnType::Integer);
		$this->addType('status', ColumnType::String);
		$this->addType('dueOverrideAt', ColumnType::Integer);
		$this->addType('submittedAt', ColumnType::Integer);
		$this->addType('returnedAt', ColumnType::Integer);
		$this->addType('feedback', ColumnType::String);
		$this->addType('points', ColumnType::Integer);
		$this->addType('feedbackBy', ColumnType::String);
		$this->addType('feedbackByName', ColumnType::String);
		$this->addType('feedbackAt', ColumnType::Integer);
		$this->addType('leftTeamAt', ColumnType::Integer);
		$this->addType('createdAt', ColumnType::Integer);
		$this->addType('updatedAt', ColumnType::Integer);
	}
}
