<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Model;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<RecordingAiOperation> */
class RecordingAiOperationMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'talk_recording_ai', RecordingAiOperation::class);
	}

	/** @throws DoesNotExistException */
	public function findByRecordingFileId(int $recordingFileId): RecordingAiOperation {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('recording_file_id', $query->createNamedParameter($recordingFileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($query);
	}

	public function findById(int $id): RecordingAiOperation {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($query);
	}

	/** @return list<RecordingAiOperation> */
	public function findDue(\DateTimeInterface $now, int $limit = 20): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->in('state', $query->createNamedParameter([
				RecordingAiOperation::STATE_QUEUED,
				RecordingAiOperation::STATE_UPLOADING,
				RecordingAiOperation::STATE_SUBMITTED,
				RecordingAiOperation::STATE_TRANSCRIBING,
				RecordingAiOperation::STATE_MAPPING,
				RecordingAiOperation::STATE_SUMMARIZING,
			], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->lte('next_attempt_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->orderBy('next_attempt_at', 'ASC')->setMaxResults($limit);
		return $this->findEntities($query);
	}
}
