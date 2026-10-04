<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Model;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<RecordingAiChunk> */
class RecordingAiChunkMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'talk_recording_ai_chunks', RecordingAiChunk::class);
	}

	/** @return list<RecordingAiChunk> */
	public function findByOperation(int $operationId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('operation_id', $query->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($query);
	}

	public function deleteByOperation(int $operationId): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('operation_id', $query->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)));
		$query->executeStatement();
	}
}
