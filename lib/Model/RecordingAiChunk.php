<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Model;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Transcription of one speech chunk of a participant track.
 *
 * @method int getOperationId()
 * @method void setOperationId(int $value)
 * @method string getChunkId()
 * @method void setChunkId(string $value)
 * @method string getState()
 * @method void setState(string $value)
 * @method int getAttempts()
 * @method void setAttempts(int $value)
 * @method string|null getWords()
 * @method void setWords(?string $value)
 * @method string|null getLastErrorMessage()
 * @method void setLastErrorMessage(?string $value)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $value)
 */
class RecordingAiChunk extends Entity {
	public const STATE_PENDING = 'pending';
	public const STATE_DONE = 'done';
	public const STATE_FAILED = 'failed';

	protected int $operationId = 0;
	protected string $chunkId = '';
	protected string $state = '';
	protected int $attempts = 0;
	protected ?string $words = null;
	protected ?string $lastErrorMessage = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('operationId', Types::BIGINT);
		$this->addType('attempts', Types::INTEGER);
		$this->addType('words', Types::TEXT);
		$this->addType('lastErrorMessage', Types::TEXT);
		$this->addType('updatedAt', Types::DATETIME);
	}
}
