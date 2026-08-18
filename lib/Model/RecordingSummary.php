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
 * @method int getRecordingFileId()
 * @method void setRecordingFileId(int $value)
 * @method string getOwnerId()
 * @method void setOwnerId(string $value)
 * @method int|null getTemplateId()
 * @method void setTemplateId(?int $value)
 * @method string getTemplateName()
 * @method void setTemplateName(string $value)
 * @method string getInstructions()
 * @method void setInstructions(string $value)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $value)
 */
class RecordingSummary extends Entity {
	protected int $recordingFileId = 0;
	protected string $ownerId = '';
	protected ?int $templateId = null;
	protected string $templateName = '';
	protected string $instructions = '';
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('recordingFileId', Types::BIGINT);
		$this->addType('templateId', Types::BIGINT);
		$this->addType('instructions', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME);
	}
}
