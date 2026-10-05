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

/** @template-extends QBMapper<RecordingRoomTemplate> */
class RecordingRoomTemplateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'talk_recording_tpl_rooms', RecordingRoomTemplate::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByRoomToken(string $roomToken): RecordingRoomTemplate {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from($this->getTableName())
			->where($query->expr()->eq('room_token', $query->createNamedParameter($roomToken)));
		return $this->findEntity($query);
	}

	public function deleteByRoomToken(string $roomToken): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('room_token', $query->createNamedParameter($roomToken)));
		$query->executeStatement();
	}

	public function deleteByTemplateId(string $templateId): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('template_id', $query->createNamedParameter($templateId)));
		$query->executeStatement();
	}
}
