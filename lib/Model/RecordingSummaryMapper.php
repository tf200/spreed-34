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

/** @template-extends QBMapper<RecordingSummary> */
class RecordingSummaryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'talk_recording_summary', RecordingSummary::class);
	}

	public function findByRecordingFileId(int $recordingFileId): RecordingSummary {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('recording_file_id', $query->createNamedParameter($recordingFileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($query);
	}

	public function deleteByOwner(string $ownerId): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('owner_id', $query->createNamedParameter($ownerId)));
		$query->executeStatement();
	}
}
