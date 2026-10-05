<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Model;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<RecordingSummaryTemplate> */
class RecordingSummaryTemplateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'talk_recording_templates', RecordingSummaryTemplate::class);
	}

	/** @return list<RecordingSummaryTemplate> */
	public function findPersonal(string $ownerId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('owner_id', $query->createNamedParameter($ownerId)))
			->andWhere($query->expr()->eq('scope', $query->createNamedParameter(RecordingSummaryTemplate::SCOPE_USER)))
			->orderBy('name', 'ASC');
		return $this->findEntities($query);
	}

	/** @return list<RecordingSummaryTemplate> */
	public function findOrganization(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('scope', $query->createNamedParameter(RecordingSummaryTemplate::SCOPE_ORGANIZATION)))
			->orderBy('name', 'ASC');
		return $this->findEntities($query);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(string $id): RecordingSummaryTemplate {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('id', $query->createNamedParameter($id)));
		return $this->findEntity($query);
	}

	/**
	 * Deletes the personal templates of the user. Organization templates stay
	 * when the administrator who created them is deleted.
	 */
	public function deleteByOwner(string $ownerId): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('owner_id', $query->createNamedParameter($ownerId)))
			->andWhere($query->expr()->eq('scope', $query->createNamedParameter(RecordingSummaryTemplate::SCOPE_USER)));
		$query->executeStatement();
	}
}
