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
 * @method int getSourceFileId()
 * @method void setSourceFileId(int $value)
 * @method int|null getPublishedFileId()
 * @method void setPublishedFileId(?int $value)
 * @method int|null getPublishedMessageId()
 * @method void setPublishedMessageId(?int $value)
 * @method int|null getPublishedShareId()
 * @method void setPublishedShareId(?int $value)
 * @method string getOwnerId()
 * @method void setOwnerId(string $value)
 * @method string getRoomToken()
 * @method void setRoomToken(string $value)
 * @method string getType()
 * @method void setType(string $value)
 * @method string getState()
 * @method void setState(string $value)
 * @method string getSourceEtag()
 * @method void setSourceEtag(string $value)
 * @method string|null getClaimToken()
 * @method void setClaimToken(?string $value)
 * @method int getNotificationTimestamp()
 * @method void setNotificationTimestamp(int $value)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $value)
 */
class RecordingArtifact extends SnowflakeAwareEntity {
	public const TYPE_TRANSCRIPT = 'transcript';
	public const TYPE_SUMMARY = 'summary';

	public const STATE_DRAFT = 'draft';
	public const STATE_EDITING = 'editing';
	public const STATE_PUBLISHING = 'publishing';
	public const STATE_PUBLISHED = 'published';

	protected int $recordingFileId = 0;
	protected int $sourceFileId = 0;
	protected ?int $publishedFileId = null;
	protected ?int $publishedMessageId = null;
	protected ?int $publishedShareId = null;
	protected string $ownerId = '';
	protected string $roomToken = '';
	protected string $type = '';
	protected string $state = self::STATE_DRAFT;
	protected string $sourceEtag = '';
	protected ?string $claimToken = null;
	protected int $notificationTimestamp = 0;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		foreach (['recordingFileId', 'sourceFileId', 'publishedFileId', 'publishedMessageId', 'publishedShareId', 'notificationTimestamp'] as $field) {
			$this->addType($field, Types::BIGINT);
		}
		$this->addType('updatedAt', Types::DATETIME);
	}
}
