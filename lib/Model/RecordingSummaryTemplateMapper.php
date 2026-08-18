<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Model;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<RecordingSummaryTemplate> */
class RecordingSummaryTemplateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'talk_recording_templates', RecordingSummaryTemplate::class);
	}

	/** @return list<RecordingSummaryTemplate> */
	public function findAllByOwner(string $ownerId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('owner_id', $query->createNamedParameter($ownerId)))
			->orderBy('updated_at', 'DESC');
		return $this->findEntities($query);
	}

	public function findByIdAndOwner(string $id, string $ownerId): RecordingSummaryTemplate {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->eq('owner_id', $query->createNamedParameter($ownerId)));
		return $this->findEntity($query);
	}

	public function deleteByOwner(string $ownerId): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('owner_id', $query->createNamedParameter($ownerId)));
		$query->executeStatement();
	}
}
