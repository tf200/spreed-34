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
 * @method string getOwnerId()
 * @method void setOwnerId(string $value)
 * @method string getName()
 * @method void setName(string $value)
 * @method string getScope()
 * @method void setScope(string $value)
 * @method string|null getDescription()
 * @method void setDescription(?string $value)
 * @method string|null getDefinition()
 * @method void setDefinition(?string $value)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $value)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $value)
 */
class RecordingSummaryTemplate extends Entity {
	public const SCOPE_USER = 'user';
	public const SCOPE_ORGANIZATION = 'organization';

	protected string $ownerId = '';
	protected string $scope = '';
	protected string $name = '';
	protected ?string $description = null;
	protected ?string $definition = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('id', Types::BIGINT);
		$this->addType('definition', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}
}
