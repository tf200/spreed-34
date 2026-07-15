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

	public function claimForUpload(int $id, string $claimToken, \DateTime $now, \DateTime $claimUntil): bool {
		return $this->claim(
			$id,
			[RecordingAiOperation::STATE_QUEUED, RecordingAiOperation::STATE_UPLOADING],
			$claimToken,
			$now,
			$claimUntil,
			RecordingAiOperation::STATE_UPLOADING,
		);
	}

	public function claimForStage(int $id, string $state, string $claimToken, \DateTime $now, \DateTime $claimUntil): bool {
		return $this->claim($id, [$state], $claimToken, $now, $claimUntil);
	}

	public function updateUploadCheckpoint(int $id, string $claimToken, string $gcsObject, \DateTime $updatedAt): bool {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('gcs_object', $query->createNamedParameter($gcsObject))
			->set('updated_at', $query->createNamedParameter($updatedAt, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('state', $query->createNamedParameter(RecordingAiOperation::STATE_UPLOADING)))
			->andWhere($query->expr()->eq('claim_token', $query->createNamedParameter($claimToken)));
		return $query->executeStatement() === 1;
	}

	/**
	 * Persist a stage result only while the caller still owns the claim.
	 */
	public function updateClaimed(RecordingAiOperation $operation, string $claimToken): bool {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('state', $query->createNamedParameter($operation->getState()))
			->set('gcs_object', $query->createNamedParameter($operation->getGcsObject(), $operation->getGcsObject() === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->set('speech_operation', $query->createNamedParameter($operation->getSpeechOperation(), $operation->getSpeechOperation() === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->set('speech_response', $query->createNamedParameter($operation->getSpeechResponse(), $operation->getSpeechResponse() === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->set('transcript', $query->createNamedParameter($operation->getTranscript(), $operation->getTranscript() === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->set('attempts', $query->createNamedParameter($operation->getAttempts(), IQueryBuilder::PARAM_INT))
			->set('next_attempt_at', $query->createNamedParameter($operation->getNextAttemptAt(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->set('last_error_code', $query->createNamedParameter($operation->getLastErrorCode(), $operation->getLastErrorCode() === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->set('last_error_message', $query->createNamedParameter($operation->getLastErrorMessage(), $operation->getLastErrorMessage() === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->set('claim_token', $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('claim_until', $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $query->createNamedParameter($operation->getUpdatedAt(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter((int)$operation->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('claim_token', $query->createNamedParameter($claimToken)));
		return $query->executeStatement() === 1;
	}

	public function clearGcsObject(int $id, string $gcsObject): bool {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('gcs_object', $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('gcs_object', $query->createNamedParameter($gcsObject)));
		return $query->executeStatement() === 1;
	}

	/** @return list<int> */
	public function findDueIds(\DateTimeInterface $now, int $limit = 20): array {
		$query = $this->db->getQueryBuilder();
		$query->select('id')->from($this->getTableName())
			->where($query->expr()->in('state', $query->createNamedParameter([
				RecordingAiOperation::STATE_QUEUED,
				RecordingAiOperation::STATE_UPLOADING,
				RecordingAiOperation::STATE_SUBMITTED,
				RecordingAiOperation::STATE_TRANSCRIBING,
				RecordingAiOperation::STATE_MAPPING,
				RecordingAiOperation::STATE_SUMMARIZING,
			], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->orX(
				$query->expr()->lte('next_attempt_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
				$query->expr()->lte('deadline_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
			))
			->andWhere($query->expr()->orX(
				$query->expr()->isNull('claim_token'),
				$query->expr()->isNull('claim_until'),
				$query->expr()->lte('claim_until', $query->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
			))
			->orderBy('deadline_at', 'ASC')
			->addOrderBy('next_attempt_at', 'ASC')
			->setMaxResults($limit);
		$result = $query->executeQuery();
		$ids = array_map('intval', $result->fetchFirstColumn());
		$result->closeCursor();
		return $ids;
	}

	/**
	 * @param list<string> $states
	 */
	private function claim(
		int $id,
		array $states,
		string $claimToken,
		\DateTime $now,
		\DateTime $claimUntil,
		?string $claimedState = null,
	): bool {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('claim_token', $query->createNamedParameter($claimToken))
			->set('claim_until', $query->createNamedParameter($claimUntil, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->set('updated_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->in('state', $query->createNamedParameter($states, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->orX(
				$query->expr()->lte('next_attempt_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
				$query->expr()->lte('deadline_at', $query->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
			))
			->andWhere($query->expr()->orX(
				$query->expr()->isNull('claim_token'),
				$query->expr()->isNull('claim_until'),
				$query->expr()->lte('claim_until', $query->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
			));
		if ($claimedState !== null) {
			$query->set('state', $query->createNamedParameter($claimedState));
		}
		return $query->executeStatement() === 1;
	}
}
