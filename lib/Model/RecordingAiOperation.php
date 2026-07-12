<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Model;

use OCP\AppFramework\Db\SnowflakeAwareEntity;
use OCP\DB\Types;

/**
 * @method int getRecordingFileId()
 * @method void setRecordingFileId(int $value)
 * @method string getOwnerId()
 * @method void setOwnerId(string $value)
 * @method string getRoomToken()
 * @method void setRoomToken(string $value)
 * @method string getState()
 * @method void setState(string $value)
 * @method string|null getGcsObject()
 * @method void setGcsObject(?string $value)
 * @method string|null getSpeechOperation()
 * @method void setSpeechOperation(?string $value)
 * @method string|null getSpeechResponse()
 * @method void setSpeechResponse(?string $value)
 * @method string|null getTranscript()
 * @method void setTranscript(?string $value)
 * @method int getAttempts()
 * @method void setAttempts(int $value)
 * @method \DateTime getNextAttemptAt()
 * @method void setNextAttemptAt(\DateTime $value)
 * @method \DateTime getDeadlineAt()
 * @method void setDeadlineAt(\DateTime $value)
 * @method string|null getLastErrorCode()
 * @method void setLastErrorCode(?string $value)
 * @method string|null getLastErrorMessage()
 * @method void setLastErrorMessage(?string $value)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $value)
 */
class RecordingAiOperation extends SnowflakeAwareEntity {
	public const STATE_QUEUED = 'queued';
	public const STATE_UPLOADING = 'uploading';
	public const STATE_SUBMITTED = 'submitted';
	public const STATE_TRANSCRIBING = 'transcribing';
	public const STATE_MAPPING = 'mapping';
	public const STATE_SUMMARIZING = 'summarizing';
	public const STATE_COMPLETED = 'completed';
	public const STATE_FAILED = 'failed';
	public const STATE_CANCELLED = 'cancelled';

	protected int $recordingFileId = 0;
	protected string $ownerId = '';
	protected string $roomToken = '';
	protected string $state = '';
	protected ?string $gcsObject = null;
	protected ?string $speechOperation = null;
	protected ?string $speechResponse = null;
	protected ?string $transcript = null;
	protected int $attempts = 0;
	protected ?\DateTime $nextAttemptAt = null;
	protected ?\DateTime $deadlineAt = null;
	protected ?string $lastErrorCode = null;
	protected ?string $lastErrorMessage = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('recordingFileId', Types::BIGINT);
		$this->addType('attempts', Types::INTEGER);
		$this->addType('speechResponse', Types::TEXT);
		$this->addType('transcript', Types::TEXT);
		foreach (['nextAttemptAt', 'deadlineAt', 'updatedAt'] as $field) {
			$this->addType($field, Types::DATETIME);
		}
	}
}
