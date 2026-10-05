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
 * The summary template used for the recordings of a conversation.
 *
 * @method string getRoomToken()
 * @method void setRoomToken(string $value)
 * @method string getTemplateId()
 * @method void setTemplateId(string $value)
 * @method string getActorId()
 * @method void setActorId(string $value)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $value)
 */
class RecordingRoomTemplate extends Entity {
	protected string $roomToken = '';
	protected string $templateId = '';
	protected string $actorId = '';
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('id', Types::BIGINT);
		$this->addType('updatedAt', Types::DATETIME);
	}
}
