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

/** @template-extends QBMapper<RecordingArtifact> */
class RecordingArtifactMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'talk_recording_artifacts', RecordingArtifact::class);
	}

	public function findById(string $id): RecordingArtifact {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('id', $query->createNamedParameter($id)));
		return $this->findEntity($query);
	}

	public function findByRecordingAndType(int $recordingFileId, string $type): RecordingArtifact {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('recording_file_id', $query->createNamedParameter($recordingFileId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter($type)));
		return $this->findEntity($query);
	}

	/** @return list<RecordingArtifact> */
	public function findUnpublishedByOwnerAndRoom(string $ownerId, string $roomToken): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('owner_id', $query->createNamedParameter($ownerId)))
			->andWhere($query->expr()->eq('room_token', $query->createNamedParameter($roomToken)))
			->andWhere($query->expr()->neq('state', $query->createNamedParameter(RecordingArtifact::STATE_PUBLISHED)))
			->orderBy('updated_at', 'DESC');
		return $this->findEntities($query);
	}

	public function claimForEditing(string $id, string $ownerId, string $expectedEtag, string $claimToken, \DateTime $updatedAt): bool {
		$leaseExpiredAt = (clone $updatedAt)->modify('-5 minutes');
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('state', $query->createNamedParameter(RecordingArtifact::STATE_EDITING))
			->set('claim_token', $query->createNamedParameter($claimToken))
			->set('updated_at', $query->createNamedParameter($updatedAt, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->eq('owner_id', $query->createNamedParameter($ownerId)))
			->andWhere($query->expr()->orX(
				$query->expr()->eq('state', $query->createNamedParameter(RecordingArtifact::STATE_DRAFT)),
				$query->expr()->andX(
					$query->expr()->eq('state', $query->createNamedParameter(RecordingArtifact::STATE_EDITING)),
					$query->expr()->lt('updated_at', $query->createNamedParameter($leaseExpiredAt, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
				),
			))
			->andWhere($query->expr()->eq('source_etag', $query->createNamedParameter($expectedEtag)));
		return $query->executeStatement() === 1;
	}

	public function finishEditing(string $id, string $ownerId, string $claimToken, string $etag, \DateTime $updatedAt): bool {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('state', $query->createNamedParameter(RecordingArtifact::STATE_DRAFT))
			->set('claim_token', $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('source_etag', $query->createNamedParameter($etag))
			->set('updated_at', $query->createNamedParameter($updatedAt, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->eq('owner_id', $query->createNamedParameter($ownerId)))
			->andWhere($query->expr()->eq('state', $query->createNamedParameter(RecordingArtifact::STATE_EDITING)))
			->andWhere($query->expr()->eq('claim_token', $query->createNamedParameter($claimToken)));
		return $query->executeStatement() === 1;
	}

	public function claimForPublishing(string $id, string $ownerId, string $expectedEtag, string $claimToken, \DateTime $updatedAt): bool {
		$leaseExpiredAt = (clone $updatedAt)->modify('-5 minutes');
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('state', $query->createNamedParameter(RecordingArtifact::STATE_PUBLISHING))
			->set('claim_token', $query->createNamedParameter($claimToken))
			->set('updated_at', $query->createNamedParameter($updatedAt, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->eq('owner_id', $query->createNamedParameter($ownerId)))
			->andWhere($query->expr()->orX(
				$query->expr()->eq('state', $query->createNamedParameter(RecordingArtifact::STATE_DRAFT)),
				$query->expr()->andX(
					$query->expr()->eq('state', $query->createNamedParameter(RecordingArtifact::STATE_PUBLISHING)),
					$query->expr()->lt('updated_at', $query->createNamedParameter($leaseExpiredAt, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
				),
			))
			->andWhere($query->expr()->eq('source_etag', $query->createNamedParameter($expectedEtag)));
		return $query->executeStatement() === 1;
	}

	public function updatePublication(string $id, string $claimToken, ?int $fileId, ?int $shareId, ?int $messageId, \DateTime $updatedAt): bool {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('published_file_id', $query->createNamedParameter($fileId, $fileId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('published_share_id', $query->createNamedParameter($shareId, $shareId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('published_message_id', $query->createNamedParameter($messageId, $messageId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter($updatedAt, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->eq('state', $query->createNamedParameter(RecordingArtifact::STATE_PUBLISHING)))
			->andWhere($query->expr()->eq('claim_token', $query->createNamedParameter($claimToken)));
		return $query->executeStatement() === 1;
	}

	public function finishPublishing(string $id, string $claimToken, \DateTime $updatedAt): bool {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('state', $query->createNamedParameter(RecordingArtifact::STATE_PUBLISHED))
			->set('claim_token', $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $query->createNamedParameter($updatedAt, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->eq('state', $query->createNamedParameter(RecordingArtifact::STATE_PUBLISHING)))
			->andWhere($query->expr()->eq('claim_token', $query->createNamedParameter($claimToken)))
			->andWhere($query->expr()->isNotNull('published_file_id'))
			->andWhere($query->expr()->isNotNull('published_message_id'));
		return $query->executeStatement() === 1;
	}

	public function clearSourceFile(string $id, int $sourceFileId, \DateTime $updatedAt): bool {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('source_file_id', $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $query->createNamedParameter($updatedAt, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->eq('state', $query->createNamedParameter(RecordingArtifact::STATE_PUBLISHED)))
			->andWhere($query->expr()->eq('source_file_id', $query->createNamedParameter($sourceFileId, IQueryBuilder::PARAM_INT)));
		return $query->executeStatement() === 1;
	}

	public function releaseClaim(string $id, string $claimToken, string $etag, \DateTime $updatedAt): void {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('state', $query->createNamedParameter(RecordingArtifact::STATE_DRAFT))
			->set('claim_token', $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('source_etag', $query->createNamedParameter($etag))
			->set('updated_at', $query->createNamedParameter($updatedAt, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->eq('claim_token', $query->createNamedParameter($claimToken)));
		$query->executeStatement();
	}

	public function reconcileSourceEtag(string $id, string $expectedEtag, string $etag, \DateTime $updatedAt): bool {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('source_etag', $query->createNamedParameter($etag))
			->set('updated_at', $query->createNamedParameter($updatedAt, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->eq('state', $query->createNamedParameter(RecordingArtifact::STATE_DRAFT)))
			->andWhere($query->expr()->eq('source_etag', $query->createNamedParameter($expectedEtag)));
		return $query->executeStatement() === 1;
	}

	public function findMessageIdByReference(int $roomId, string $referenceId): ?int {
		$query = $this->db->getQueryBuilder();
		$query->select('id')->from('comments')
			->where($query->expr()->eq('object_type', $query->createNamedParameter('chat')))
			->andWhere($query->expr()->eq('object_id', $query->createNamedParameter((string)$roomId)))
			->andWhere($query->expr()->eq('reference_id', $query->createNamedParameter($referenceId)))
			->setMaxResults(1);
		$result = $query->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		return $id === false ? null : (int)$id;
	}

	public function deleteByOwner(string $ownerId): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('owner_id', $query->createNamedParameter($ownerId)));
		$query->executeStatement();
	}

	public function deleteByRoomToken(string $roomToken): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('room_token', $query->createNamedParameter($roomToken)));
		$query->executeStatement();
	}
}
